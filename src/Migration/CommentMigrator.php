<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\Logger;

/**
 * Comments + commentmeta phase of the migration (PLAN.md §5 step 5,
 * §7.4).
 * Copies every included site's comments into the destination single
 * site.
 *
 * What this phase does per comment:
 *  - Rewrites comment_post_ID through the posts IdMap.
 *    Falls back to the attachments IdMap.
 *    Comments can attach to attachment posts, and MediaMigrator
 *    records those under 'attachment'.
 *    A comment whose post did not migrate is dropped.
 *    That includes excluded post types, trashed posts, and unmapped
 *    attachments.
 *    It is counted as comments_post_unmapped.
 *    It is never inserted orphaned, never silent.
 *  - Rewrites registered user_id through the users IdMap
 *    (network-global, site ID 0).
 *    An unmapped registered user (conflict, author_mapping alias,
 *    ...) falls back to 0.
 *    The comment stays visible as a guest.
 *    It still carries its author name/email.
 *    It is counted as user_id_fallback.
 *    Guest comments (user_id = 0) copy unchanged.
 *  - Inserts comment_parent = 0.
 *    Threaded replies are resolved in a per-site second pass through
 *    the comments IdMap, once every comment of the site is mapped.
 *    This mirrors PostMigrator's two-pass post_parent handling.
 *    A reply whose parent did not migrate is left at 0.
 *    WordPress shows such a reply as top-level.
 *    It is counted as parents_unresolved.
 *
 * Approval policy (confirmed 2026-10-16):
 *  - comment_approved '1' (approved) and '0' (pending) migrate.
 *  - 'spam' and 'trash' are discarded as non-content, the same way
 *    PostMigrator discards trashed posts.
 *    They are counted as spam_skipped / trash_skipped.
 *    The discard is visible, never silent.
 *  - Any other unexpected value is skipped.
 *    It is counted and warned about once per site.
 *
 * commentmeta copies key-for-key.
 * Values run through SerializedDataRewriter::rewriteAny().
 * Serialized/JSON plugin data keeps correct byte-length prefixes.
 * Integer references keyed 'comment_id' / 'user_id' inside serialized
 * values (Akismet's akismet_history, ...) are remapped through the
 * comment/user IdMaps.
 * Any other integer is never touched.
 * IdMap returns null for unmigrated IDs, so ratings/counts cannot be
 * corrupted by blind ID rewriting.
 * Plugin-owned commentmeta needing deeper rewriting is Phase 7
 * adapter work, same as postmeta.
 *
 * The destination posts' comment_count needs no refresh.
 * PostMigrator copied it from the source.
 * WordPress only counts approved comments.
 * That is exactly the set this phase migrates.
 *
 * Idempotent like the other phases.
 * Every row is recorded in IdMap/MigrationTable ('comment').
 * A prewarmed re-run skips.
 *
 * @phpstan-type CommentMigrationReport array{
 *     comments_migrated: int,
 *     comments_skipped: int,
 *     comments_failed: int,
 *     comments_post_unmapped: int,
 *     meta_rows_written: int,
 *     parents_fixed: int,
 *     parents_unresolved: int,
 *     user_id_fallback: int,
 *     spam_skipped: int,
 *     trash_skipped: int,
 *     warnings: array<int, string>,
 *     by_site: array<int, array{comments: int}>
 * }
 *
 * @package MergeMultisite
 */
final class CommentMigrator {

	/**
	 * The comment_approved values that migrate.
	 * They are '1' (approved) and '0' (pending).
	 * 'spam' and 'trash' are discarded by policy.
	 * Anything else is anomalous and skipped with a warning.
	 *
	 * @var string[]
	 */
	private const MIGRATED_COMMENT_APPROVED = array( '1', '0' );

	public function __construct(
		private readonly ?SerializedDataRewriter $rewriter = null,
	) {
	}

	/**
	 * Runs the comment migration phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return CommentMigrationReport
	 *
	 * @throws \Throwable When destination database writes fail (rolled back first).
	 */
	public function migrate(
		Connection $source,
		Connection $destination,
		MergeConfig $config,
		array $sites,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		Logger $logger
	): array {
		$rewriter = $this->rewriter ?? new SerializedDataRewriter();

		$destCommentsTable = $destination->siteTable( 'comments', 1 );
		$destCommentMetaTable = $destination->siteTable( 'commentmeta', 1 );

		$report = array(
			'comments_migrated' => 0,
			'comments_skipped' => 0,
			'comments_failed' => 0,
			'comments_post_unmapped' => 0,
			'meta_rows_written' => 0,
			'parents_fixed' => 0,
			'parents_unresolved' => 0,
			'user_id_fallback' => 0,
			'spam_skipped' => 0,
			'trash_skipped' => 0,
			'warnings' => array(),
			'by_site' => array(),
		);
		$warnings = array(); // collected separately so warn() never widens $report's shape

		foreach ( $sites as $site ) {
			$blogId = $site->blogId;
			$commentsTable = $source->siteTable( 'comments', $blogId );
			$commentMetaTable = $source->siteTable( 'commentmeta', $blogId );

			// oldId => ['new_id' => int, 'old_parent' => int], resolved
			// in the second pass once every comment of this site is in
			// the IdMap (mirrors PostMigrator's $parentFixups).
			$parentFixups = array();

			// CommentQueryHelper keyset-paginates ALL comments
			// (spam/trash included); the approval filter is applied
			// in-memory so the discarded counts are exact.
			foreach ( CommentQueryHelper::commentBatches( $source, $commentsTable, max( 1, $config->batchSize ) ) as $batch ) {
				if ( ! $dryRun ) {
					$destination->beginTransaction();
				}

				try {
					$eligible = array_values(
						array_filter(
							$batch,
							static fn ( array $row ): bool => in_array( (string) $row['comment_approved'], self::MIGRATED_COMMENT_APPROVED, true )
						)
					);

					$discarded = $this->countDiscarded( $batch, $eligible, $report );
					if ( $discarded['count'] > 0 ) {
						$this->warn(
							$warnings,
							$logger,
							sprintf(
								'Site %d: %d comment(s) with unexpected comment_approved value(s) (%s) were skipped.',
								$blogId,
								$discarded['count'],
								implode( ', ', $discarded['values'] )
							)
						);
					}

					$ids = array_map( static fn ( array $row ): int => (int) $row['comment_ID'], $eligible );
					$metaByComment = CommentQueryHelper::fetchMetaForComments( $source, $commentMetaTable, $ids );

					foreach ( $eligible as $row ) {
						$oldId = (int) $row['comment_ID'];

						if ( $idMap->has( 'comment', $blogId, $oldId ) ) {
							++$report['comments_skipped'];
							continue;
						}

						// The comment's post must exist on the
						// destination; a comment pointing at an
						// unmigrated post would be invisible in
						// wp-admin (comments are listed per post).
						$newPostId = $idMap->get( 'post', $blogId, (int) $row['comment_post_ID'] )
							?? $idMap->get( 'attachment', $blogId, (int) $row['comment_post_ID'] );
						if ( $newPostId === null ) {
							++$report['comments_post_unmapped'];
							$this->warn(
								$warnings,
								$logger,
								sprintf( 'Comment %d (site %d): its post %d was not migrated; comment dropped.', $oldId, $blogId, (int) $row['comment_post_ID'] )
							);
							continue;
						}

						if ( $dryRun ) {
							++$report['comments_migrated'];
							$report['by_site'][ $blogId ]['comments'] = ( $report['by_site'][ $blogId ]['comments'] ?? 0 ) + 1;
							continue;
						}

						try {
							$newId = $this->insertComment( $destination, $destCommentsTable, $row, $newPostId, $blogId, $idMap, $report );
							$this->insertCommentMeta( $destination, $destCommentMetaTable, $newId, $metaByComment[ $oldId ] ?? array(), $blogId, $idMap, $rewriter, $report );
						} catch ( \Throwable $e ) {
							++$report['comments_failed'];
							$this->warn( $warnings, $logger, sprintf( 'Comment %d (site %d) failed: %s', $oldId, $blogId, $e->getMessage() ) );
							continue;
						}

						$idMap->set( 'comment', $blogId, $oldId, $newId );
						$mapTable->record( $destination, 'comment', $blogId, $oldId, $newId );

						$oldParent = (int) $row['comment_parent'];
						if ( $oldParent > 0 ) {
							$parentFixups[ $oldId ] = array(
								'new_id' => $newId,
								'old_parent' => $oldParent,
							);
						}

						++$report['comments_migrated'];
						$report['by_site'][ $blogId ]['comments'] = ( $report['by_site'][ $blogId ]['comments'] ?? 0 ) + 1;
					}

					if ( ! $dryRun ) {
						$destination->commit();
					}
				} catch ( \Throwable $e ) {
					if ( $destination->inTransaction() ) {
						$destination->rollBack();
					}
					$logger->error( sprintf( 'Comment migration failed, rolled back: %s', $e->getMessage() ) );
					throw $e;
				}
			}

			// Second pass per site: threaded replies now that every
			// comment of this site is mapped.
			$this->fixParents( $destination, $destCommentsTable, $blogId, $parentFixups, $idMap, $dryRun, $report, $warnings, $logger );
		}

		$report['warnings'] = $warnings;

		$logger->info(
			sprintf(
				'Comment migration%s: %d comment(s) migrated, %d skipped (already mapped), %d failed, %d dropped (post not migrated), %d meta row(s), %d parent fixup(s), %d unresolved parent(s), %d user_id fallback(s), %d spam skipped, %d trash skipped.',
				$dryRun ? ' (dry run)' : '',
				$report['comments_migrated'],
				$report['comments_skipped'],
				$report['comments_failed'],
				$report['comments_post_unmapped'],
				$report['meta_rows_written'],
				$report['parents_fixed'],
				$report['parents_unresolved'],
				$report['user_id_fallback'],
				$report['spam_skipped'],
				$report['trash_skipped']
			)
		);

		return $report;
	}

	/**
	 * Count the comments a batch filters out by the approval policy.
	 * 'spam' and 'trash' are discarded, counted, never silent.
	 * Any other value is skipped too.
	 * It is returned as a distinct-value list.
	 * The caller warns once per site about anomalous data.
	 *
	 * @param array<int, array<string, mixed>> $batch
	 * @param array<int, array<string, mixed>> $eligible
	 * @param CommentMigrationReport           $report
	 *
	 * @return array{values: string[], count: int}
	 */
	private function countDiscarded( array $batch, array $eligible, array &$report ): array {
		$eligibleIds = array();
		foreach ( $eligible as $row ) {
			$eligibleIds[ (int) $row['comment_ID'] ] = true;
		}

		$unexpected = array();
		$unexpectedCount = 0;
		foreach ( $batch as $row ) {
			if ( isset( $eligibleIds[ (int) $row['comment_ID'] ] ) ) {
				continue;
			}

			$approved = (string) $row['comment_approved'];
			if ( $approved === 'spam' ) {
				++$report['spam_skipped'];
			} elseif ( $approved === 'trash' ) {
				++$report['trash_skipped'];
			} else {
				++$unexpectedCount;
				++$report['comments_skipped'];
				$unexpected[ $approved ] = true;
			}
		}

		return array(
			'values' => array_keys( $unexpected ),
			'count' => $unexpectedCount,
		);
	}

	/**
	 * Insert one comment row with comment_parent = 0.
	 * The parent is resolved in the second pass.
	 * user_id is remapped through the users IdMap.
	 * An unmapped registered user falls back to 0 (guest).
	 * The comment stays visible with its author name/email intact.
	 *
	 * @param array<string, mixed>   $row
	 * @param CommentMigrationReport $report
	 */
	private function insertComment(
		Connection $destination,
		string $commentsTable,
		array $row,
		int $newPostId,
		int $blogId,
		IdMap $idMap,
		array &$report
	): int {
		$userId = (int) $row['user_id'];
		if ( $userId > 0 ) {
			$mappedUserId = $idMap->get( 'user', 0, $userId );
			if ( $mappedUserId === null ) {
				++$report['user_id_fallback'];
				$userId = 0;
			} else {
				$userId = $mappedUserId;
			}
		}

		$destination->execute(
			"INSERT INTO {$commentsTable} (
                comment_post_ID, comment_author, comment_author_email, comment_author_url,
                comment_author_IP, comment_date, comment_date_gmt, comment_content,
                comment_karma, comment_approved, comment_agent, comment_type,
                comment_parent, user_id
            ) VALUES (
                :post_id, :author, :email, :url,
                :ip, :date, :date_gmt, :content,
                :karma, :approved, :agent, :type,
                0, :user_id
            )",
			array(
				'post_id'  => $newPostId,
				'author'   => (string) $row['comment_author'],
				'email'    => (string) $row['comment_author_email'],
				'url'      => (string) $row['comment_author_url'],
				'ip'       => (string) $row['comment_author_IP'],
				'date'     => (string) $row['comment_date'],
				'date_gmt' => (string) $row['comment_date_gmt'],
				'content'  => (string) $row['comment_content'],
				'karma'    => (int) $row['comment_karma'],
				'approved' => (string) $row['comment_approved'],
				'agent'    => (string) $row['comment_agent'],
				'type'     => (string) $row['comment_type'],
				'user_id'  => $userId,
			)
		);

		return $destination->lastInsertId();
	}

	/**
	 * Copy all source commentmeta onto the new comment.
	 * Values run through SerializedDataRewriter::rewriteAny().
	 * Serialized/JSON plugin data keeps correct byte-length prefixes.
	 * Integer references keyed 'comment_id' / 'user_id' are remapped.
	 * That includes Akismet's akismet_history.
	 * Any other integer is untouched.
	 * IdMap returns null for unmigrated IDs.
	 * Plugin ratings/counts cannot be corrupted by blind ID rewriting.
	 *
	 * @param array<string, string[]> $meta
	 * @param CommentMigrationReport  $report
	 */
	private function insertCommentMeta(
		Connection $destination,
		string $commentMetaTable,
		int $newCommentId,
		array $meta,
		int $blogId,
		IdMap $idMap,
		SerializedDataRewriter $rewriter,
		array &$report
	): void {
		foreach ( $meta as $metaKey => $values ) {
			foreach ( $values as $value ) {
				$destination->execute(
					"INSERT INTO {$commentMetaTable} (comment_id, meta_key, meta_value) VALUES (:comment_id, :key, :value)",
					array(
						'comment_id' => $newCommentId,
						'key'        => $metaKey,
						'value'      => $rewriter->rewriteAny(
							$value,
							static function ( mixed $value, string|int $key, array $path ) use ( $blogId, $idMap ): mixed {
								if ( ! is_int( $value ) ) {
									return $value;
								}
								if ( $key === 'comment_id' ) {
									return $idMap->get( 'comment', $blogId, $value ) ?? $value;
								}
								if ( $key === 'user_id' ) {
									return $idMap->get( 'user', 0, $value ) ?? $value;
								}

								return $value;
							}
						),
					)
				);
				++$report['meta_rows_written'];
			}
		}
	}

	/**
	 * Second pass: resolve every comment_parent (inserted as 0).
	 * The comments IdMap resolves them once all of a site's comments
	 * exist.
	 * Unresolved parents are left at 0.
	 * That includes parents that were spam/trash or belong to an
	 * unmigrated post.
	 * WordPress shows such a reply as top-level.
	 * It is warned about.
	 *
	 * @param array<int, array{new_id:int, old_parent:int}> $parentFixups
	 * @param CommentMigrationReport                        $report
	 * @param string[]                                      $warnings
	 *
	 * @throws \Throwable When the parent-fixup transaction fails (rolled back first).
	 */
	private function fixParents(
		Connection $destination,
		string $commentsTable,
		int $blogId,
		array $parentFixups,
		IdMap $idMap,
		bool $dryRun,
		array &$report,
		array &$warnings,
		Logger $logger
	): void {
		$updates = array();

		foreach ( $parentFixups as $fixup ) {
			$newParent = $idMap->get( 'comment', $blogId, $fixup['old_parent'] );
			if ( $newParent === null ) {
				++$report['parents_unresolved'];
				$this->warn(
					$warnings,
					$logger,
					sprintf( 'Comment %d (site %d): parent comment %d was not migrated; comment_parent left 0.', $fixup['new_id'], $blogId, $fixup['old_parent'] )
				);
				continue;
			}

			$updates[] = array(
				'id' => $fixup['new_id'],
				'parent' => $newParent,
			);
		}

		if ( $dryRun || $updates === array() ) {
			return;
		}

		$destination->beginTransaction();
		try {
			foreach ( $updates as $update ) {
				$destination->execute(
					"UPDATE {$commentsTable} SET comment_parent = :parent WHERE comment_ID = :id",
					array(
						'parent' => $update['parent'],
						'id'     => $update['id'],
					)
				);
				++$report['parents_fixed'];
			}
			$destination->commit();
		} catch ( \Throwable $e ) {
			if ( $destination->inTransaction() ) {
				$destination->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Log a warning and record it in the report.
	 *
	 * @param string[] $warnings
	 */
	private function warn( array &$warnings, Logger $logger, string $message ): void {
		$warnings[] = $message;
		$logger->warning( $message );
	}
}
