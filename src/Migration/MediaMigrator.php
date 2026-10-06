<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\FileHasher;
use MergeMultisite\Support\Logger;

/**
 * Media phase of the migration (PLAN.md §7.2): copies each included
 * site's uploaded files into the destination uploads directory and
 * recreates the `attachment` posts + postmeta there.
 *
 * Behaviour per the plan:
 *  - Source layouts auto-detected via UploadsPathResolver (modern
 *    `sites/{blog_id}/`, bare `uploads/` for the main site, legacy
 *    `blogs.dir/{blog_id}/files/`).
 *  - De-duplication by size + SHA-256 fingerprint: identical files
 *    sharing a destination path collapse to one physical file, and a
 *    file already identical on the destination is reused rather than
 *    copied again (idempotent re-runs).
 *  - Same destination path with different content => every file in the
 *    group is renamed `{basename}_site{blog_id}.{ext}`; thumbnail
 *    variants keep WordPress's `-{W}x{H}` suffix last
 *    (`logo-150x150.png` => `logo_site7-150x150.png`) via
 *    MediaCollisionPlan::renamedVariantFilename().
 *  - `_wp_attached_file` is rewritten to the destination-relative path
 *    and `_wp_attachment_metadata` is re-serialized with the new `file`
 *    value plus per-size renamed filenames (SerializedDataRewriter, so
 *    string-length prefixes stay correct).
 *  - Attachment post_parent is written as 0; PostMigrator (Phase 5)
 *    repairs parents once the posts IdMap exists.
 *
 * Idempotent: every migrated attachment is recorded in IdMap +
 * MigrationTable ('attachment', blogId, oldPostId => newPostId), so a
 * prewarmed re-run skips already-migrated rows instead of
 * double-inserting (PLAN.md §10).
 *
 * @package MergeMultisite
 */
final class MediaMigrator {

	/**
	 * Safety bound on destination-name alternatives when a target is
	 * already occupied by different content (`_site7`, `_site7-2`, ...).
	 */
	private const MAX_NAME_ALTERNATIVES = 9;

	private readonly MediaCollisionPlan $collisions;
	private readonly SerializedDataRewriter $rewriter;
	private readonly FileHasher $hasher;

	public function __construct(
		private readonly UploadsPathResolver $sourceUploads,
		private readonly string $destinationUploadsPath,
		?MediaCollisionPlan $collisions = null,
		?SerializedDataRewriter $rewriter = null,
		?FileHasher $hasher = null,
	) {
		$this->collisions = $collisions ?? new MediaCollisionPlan();
		$this->rewriter = $rewriter ?? new SerializedDataRewriter();
		$this->hasher = $hasher ?? new FileHasher();
	}

	/**
	 * Runs the media migration phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return array{
	 *     attachments_migrated: int,
	 *     attachments_skipped: int,
	 *     attachments_failed: int,
	 *     files_copied: int,
	 *     files_reused: int,
	 *     files_renamed: int,
	 *     files_missing: int,
	 *     warnings: string[],
	 *     by_site: array<int, array{attachments: int}>
	 * }
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
		$destBase = rtrim( $this->destinationUploadsPath, '/' );

		$report = array(
			'attachments_migrated' => 0,
			'attachments_skipped'  => 0,
			'attachments_failed'   => 0,
			'files_copied'         => 0,
			'files_reused'         => 0,
			'files_renamed'        => 0,
			'files_missing'        => 0,
			'warnings'             => array(),
			'by_site'              => array(),
		);
		$warnings = array(); // collected separately so warn() never widens $report's shape

		// 1. Gather attachment post rows + all postmeta per site, then
		// expand each attachment into its physical file list (the main
		// file plus the generated-size variants named in
		// _wp_attachment_metadata).
		$attachments = array();
		$files = array();
		foreach ( $sites as $site ) {
			$posts = $this->fetchAttachments( $source, $site->blogId );
			if ( $posts === array() ) {
				continue;
			}

			$metaByPost = PostQueryHelper::fetchMetaForPosts(
				$source,
				$source->siteTable( 'postmeta', $site->blogId ),
				array_map( 'intval', array_keys( $posts ) )
			);

			foreach ( $posts as $post ) {
				$postId = (int) $post['ID'];
				$meta = $metaByPost[ $postId ] ?? array();
				$relative = isset( $meta['_wp_attached_file'][0] ) ? (string) $meta['_wp_attached_file'][0] : '';

				$attachment = array(
					'site'     => $site,
					'post'     => $post,
					'meta'     => $meta,
					'relative' => $relative,
					'files'    => array(),
				);

				if ( $relative === '' ) {
					$this->warn( $warnings, $logger, sprintf( 'Attachment %d (site %d) has no _wp_attached_file.', $postId, $site->blogId ) );
					$attachments[] = $attachment;
					continue;
				}

				$mainPath = $this->sourceUploads->resolve( $site->blogId, $relative );
				if ( $mainPath === null ) {
					// Post still migrates (its meta survives); the file is
					// simply absent, matching its state on the source.
					$this->warn( $warnings, $logger, sprintf( 'Attachment %d (site %d): file missing on disk: %s', $postId, $site->blogId, $relative ) );
					++$report['files_missing'];
					$attachments[] = $attachment;
					continue;
				}

				$attachment['files'][] = array(
					'kind'     => 'main',
					'relative' => $relative,
					'path'     => $mainPath,
				);

				foreach ( $this->variantBasenames( (string) ( $meta['_wp_attachment_metadata'][0] ?? '' ) ) as $basename ) {
					$variantPath = dirname( $mainPath ) . '/' . $basename;
					if ( ! is_file( $variantPath ) ) {
						$this->warn( $warnings, $logger, sprintf( 'Attachment %d (site %d): size variant missing on disk: %s', $postId, $site->blogId, dirname( $relative ) . '/' . $basename ) );
						++$report['files_missing'];
						continue;
					}

					$attachment['files'][] = array(
						'kind'     => 'variant',
						'relative' => ( dirname( $relative ) === '.' ? '' : dirname( $relative ) . '/' ) . $basename,
						'path'     => $variantPath,
					);
				}

				foreach ( $attachment['files'] as $file ) {
					$file['blog_id'] = $site->blogId;
					$file['post_id'] = $postId;
					$file['attachment_index'] = count( $attachments );
					$files[] = $file;
				}

				$attachments[] = $attachment;
			}
		}

		// 2. Fingerprint every file once, plan destination targets
		// (cross-site dedup + collision renames), then resolve against
		// what already exists on the destination.
		$fingerprints = array();
		foreach ( $files as $file ) {
			$fingerprints[] = $this->hasher->fingerprint( $file['path'] )->toKey();
		}

		$targets = $this->planTargets( $files, $fingerprints );

		$claimed = array(); // destination-relative target => fingerprint (this run)
		$filesByAttachment = array();
		foreach ( $files as $index => $file ) {
			$file['outcome'] = $this->placeOnDestination(
				$file,
				$fingerprints[ $index ],
				$targets[ $index ]['target'],
				$destBase,
				$claimed
			);

			switch ( $file['outcome']['action'] ) {
				case 'copy':
					++$report['files_copied'];
					if ( $file['outcome']['target'] !== $file['relative'] ) {
						++$report['files_renamed'];
					}
					break;
				case 'reuse':
					++$report['files_reused'];
					break;
				default:
					$this->warn( $warnings, $logger, sprintf( 'Could not place file (site %d, post %d): %s -- destination name conflicts at every alternative.', $file['blog_id'], $file['post_id'], $file['relative'] ) );
			}

			$filesByAttachment[ $file['attachment_index'] ][] = $file;
		}

		// 3. Per attachment: copy planned files, insert the post + meta,
		// record the mapping. Batched transactions per the execution
		// model (PLAN.md §10); file copies are not transactional, so
		// they happen before the post row is written.
		foreach ( array_chunk( $attachments, max( 1, $config->batchSize ), true ) as $batch ) {
			if ( ! $dryRun ) {
				$destination->beginTransaction();
			}

			try {
				foreach ( $batch as $attachmentIndex => $attachment ) {
					$postId = (int) $attachment['post']['ID'];
					$blogId = $attachment['site']->blogId;

					if ( $idMap->has( 'attachment', $blogId, $postId ) ) {
						++$report['attachments_skipped'];
						continue;
					}

					// Destination-relative outcome per source relative path.
					$outcomeByRelative = array();
					foreach ( $filesByAttachment[ $attachmentIndex ] ?? array() as $file ) {
						$outcomeByRelative[ $file['relative'] ] = $file['outcome'];
					}

					$mainOutcome = $attachment['relative'] !== '' ? ( $outcomeByRelative[ $attachment['relative'] ] ?? null ) : null;
					$newRelative = $mainOutcome !== null ? $mainOutcome['target'] : $attachment['relative'];

					$renamedVariants = array();
					foreach ( $outcomeByRelative as $relative => $outcome ) {
						if ( basename( $relative ) !== basename( $outcome['target'] ) && $relative !== $attachment['relative'] ) {
							$renamedVariants[ basename( $relative ) ] = basename( $outcome['target'] );
						}
					}

					if ( $dryRun ) {
						++$report['attachments_migrated'];
						$report['by_site'][ $blogId ]['attachments'] = ( $report['by_site'][ $blogId ]['attachments'] ?? 0 ) + 1;
						continue;
					}

					// Copy files first: an orphan file on disk is harmless,
					// a post referencing a missing file is broken.
					$copyFailed = false;
					foreach ( $filesByAttachment[ $attachmentIndex ] ?? array() as $file ) {
						if ( $file['outcome']['action'] !== 'copy' ) {
							continue;
						}

						$destPath = $destBase . '/' . $file['outcome']['target'];
						if ( ! is_dir( dirname( $destPath ) ) && ! mkdir( dirname( $destPath ), 0755, true ) ) {
							$this->warn( $warnings, $logger, sprintf( 'Cannot create destination directory %s', dirname( $destPath ) ) );
							$copyFailed = true;
							continue;
						}
						if ( ! copy( $file['path'], $destPath ) ) {
							$this->warn( $warnings, $logger, sprintf( 'Copy failed: %s => %s', $file['path'], $destPath ) );
							$copyFailed = true;
						}
					}

					if ( $copyFailed && $mainOutcome !== null && $mainOutcome['action'] === 'copy' ) {
						++$report['attachments_failed'];
						continue;
					}

					$newPostId = $this->insertAttachmentPost( $destination, $attachment, $newRelative, $config, $idMap );
					$this->insertAttachmentMeta( $destination, $attachment, $newPostId, $newRelative, $renamedVariants );

					$idMap->set( 'attachment', $blogId, $postId, $newPostId );
					$mapTable->record( $destination, 'attachment', $blogId, $postId, $newPostId );

					++$report['attachments_migrated'];
					$report['by_site'][ $blogId ]['attachments'] = ( $report['by_site'][ $blogId ]['attachments'] ?? 0 ) + 1;
				}

				if ( ! $dryRun ) {
					$destination->commit();
				}
			} catch ( \Throwable $e ) {
				if ( $destination->inTransaction() ) {
					$destination->rollBack();
				}
				$logger->error( sprintf( 'Media migration failed, rolled back: %s', $e->getMessage() ) );
				throw $e;
			}
		}

		$report['warnings'] = $warnings;

		$logger->info(
			sprintf(
				'Media migration%s: %d attachment(s) migrated, %d skipped (already mapped), %d failed, %d file(s) copied, %d reused (dedup), %d renamed, %d missing.',
				$dryRun ? ' (dry run)' : '',
				$report['attachments_migrated'],
				$report['attachments_skipped'],
				$report['attachments_failed'],
				$report['files_copied'],
				$report['files_reused'],
				$report['files_renamed'],
				$report['files_missing']
			)
		);

		return $report;
	}

	/**
	 * Basenames of generated image-size variants (and WP 5.3+'s
	 * `original_image` pre-scale file) named in a serialized
	 * `_wp_attachment_metadata` value. Returns [] for missing or
	 * unparseable metadata.
	 *
	 * @return string[]
	 */
	public function variantBasenames( string $serializedMetadata ): array {
		if ( ! $this->rewriter->isSerialized( $serializedMetadata ) ) {
			return array();
		}

		// Untrusted database input: no object instantiation.
		$metadata = @unserialize( $serializedMetadata, array( 'allowed_classes' => false ) );
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$names = array();
		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && $metadata['original_image'] !== '' ) {
			$names[] = $metadata['original_image'];
		}

		foreach ( $metadata['sizes'] ?? array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && $size['file'] !== '' ) {
				$names[] = $size['file'];
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Destination-relative target for every file, planned by
	 * MediaCollisionPlan (cross-site dedup + `_site{blog_id}` collision
	 * renames). Variant files additionally keep WordPress's `-{W}x{H}`
	 * size suffix AFTER the site marker so size filenames still line up
	 * with the renamed base.
	 *
	 * @param array<int, array{kind:string, relative:string, blog_id:int}> $files
	 * @param array<int, string>                                           $fingerprints Index-aligned with $files.
	 *
	 * @return array<int, array{target:string, renamed:bool}> Index-aligned with $files.
	 */
	public function planTargets( array $files, array $fingerprints ): array {
		$targets = $this->collisions->resolve( $files, $fingerprints );

		foreach ( $targets as $index => $plan ) {
			if ( ! $plan['renamed'] || $files[ $index ]['kind'] !== 'variant' ) {
				continue;
			}

			$dir = dirname( $files[ $index ]['relative'] );
			$targets[ $index ]['target'] = ( $dir === '.' ? '' : $dir . '/' )
				. MediaCollisionPlan::renamedVariantFilename( basename( $files[ $index ]['relative'] ), $files[ $index ]['blog_id'] );
		}

		return $targets;
	}

	/**
	 * Rewrites a serialized `_wp_attachment_metadata` value for the
	 * destination: the top-level `file` becomes the new destination
	 * path, each `sizes.{name}.file` basename is renamed per the
	 * collision plan, and `original_image` follows the same rename.
	 * Serialized string lengths are recalculated by SerializedDataRewriter.
	 *
	 * @param array<string, string> $renamedVariants old basename => new basename.
	 */
	public function rewriteAttachmentMetadata( string $serializedMetadata, string $newRelativeFile, array $renamedVariants ): string {
		return $this->rewriter->rewrite(
			$serializedMetadata,
			static function ( mixed $value, string|int $key, array $path ) use ( $newRelativeFile, $renamedVariants ): mixed {
				if ( ! is_string( $value ) ) {
					return $value;
				}

				// metadata['file']
				if ( $key === 'file' && $path === array( 'file' ) ) {
					return $newRelativeFile;
				}

				// metadata['sizes'][<size>]['file']
				if ( $key === 'file' && count( $path ) === 3 && $path[0] === 'sizes' ) {
					return $renamedVariants[ $value ] ?? $value;
				}

				// metadata['original_image']
				if ( $key === 'original_image' && $path === array( 'original_image' ) ) {
					return $renamedVariants[ $value ] ?? $value;
				}

				return $value;
			}
		);
	}

	/**
	 * Ordered alternative destination-relative names when a planned
	 * target is already occupied by different content: first the
	 * `_site{blog_id}` rename (variant-aware for `-{W}x{H}` files), then
	 * `..._site{blog_id}-2`, `-3`, ... inserted before any size suffix
	 * and extension. Deterministic and still traceable to the source
	 * site, unlike bare `-2` counters (PLAN.md §7.2).
	 *
	 * @return string[] Up to MAX_NAME_ALTERNATIVES candidates.
	 */
	public static function collisionAlternatives( string $relative, string $kind, int $blogId ): array {
		$dir = dirname( $relative );
		$prefix = $dir === '.' ? '' : $dir . '/';
		$basename = basename( $relative );

		// renamedRelativePath() takes the full relative path (it keeps
		// the directory itself); renamedVariantFilename() takes just the
		// basename and keeps the -{W}x{H} size suffix last. A name that
		// already carries the site marker (renamed by the collision plan
		// for a cross-site conflict) goes straight to numbered
		// alternatives rather than being suffixed twice.
		$alreadyRenamed = preg_match( '/_site\d+(-\d+x\d+)?(\.[^.]+)?$/', $basename ) === 1;

		$first = $alreadyRenamed
			? $relative
			: ( $kind === 'variant'
				? $prefix . MediaCollisionPlan::renamedVariantFilename( $basename, $blogId )
				: MediaCollisionPlan::renamedRelativePath( $relative, $blogId ) );

		$alternatives = array( $first );
		for ( $n = 2; $n <= self::MAX_NAME_ALTERNATIVES; ++$n ) {
			$alternatives[] = (string) preg_replace(
				'/_site(\d+)((?:-\d+x\d+)?)(\.[^.]+)?$/',
				'_site$1-' . $n . '$2$3',
				$first,
				1
			);
		}

		return $alternatives;
	}

	/**
	 * Decide a file's destination: 'copy' to a free target, 'reuse' when
	 * an identical file (size + SHA-256) already occupies the target
	 * (within this run or on the destination), or 'conflict' when every
	 * alternative name is taken by different content.
	 *
	 * @param array{blog_id:int, kind:string, relative:string} $file
	 * @param array<string, string>                            $claimed Destination-relative targets already
	 *                                                                    claimed this run => fingerprint.
	 *
	 * @return array{action:string, target:string}
	 */
	private function placeOnDestination( array $file, string $fingerprint, string $plannedTarget, string $destBase, array &$claimed ): array {
		if ( isset( $claimed[ $plannedTarget ] ) ) {
			// resolve() gives the same target only to identical files.
			return array(
				'action' => 'reuse',
				'target' => $plannedTarget,
			);
		}

		$candidates = array_merge(
			array( $plannedTarget ),
			self::collisionAlternatives( $plannedTarget, $file['kind'], $file['blog_id'] )
		);

		foreach ( $candidates as $candidate ) {
			if ( isset( $claimed[ $candidate ] ) ) {
				if ( $claimed[ $candidate ] === $fingerprint ) {
					return array(
						'action' => 'reuse',
						'target' => $candidate,
					);
				}
				continue;
			}

			$abs = $destBase . '/' . $candidate;
			if ( ! is_file( $abs ) ) {
				$claimed[ $candidate ] = $fingerprint;

				return array(
					'action' => 'copy',
					'target' => $candidate,
				);
			}

			if ( $this->hasher->fingerprint( $abs )->toKey() === $fingerprint ) {
				$claimed[ $candidate ] = $fingerprint;

				return array(
					'action' => 'reuse',
					'target' => $candidate,
				);
			}
		}

		return array(
			'action' => 'conflict',
			'target' => $plannedTarget,
		);
	}

	/**
	 * Every attachment post row for one site, keyed by ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchAttachments( Connection $source, int $blogId ): array {
		$postsTable = $source->siteTable( 'posts', $blogId );

		$params = array();
		$statusClause = PostQueryHelper::postStatusClause( array(), $params );

		$rows = $source->fetchAll(
			"SELECT * FROM {$postsTable} WHERE post_type = 'attachment' AND {$statusClause} ORDER BY ID",
			$params
		);

		$posts = array();
		foreach ( $rows as $row ) {
			$posts[ (int) $row['ID'] ] = $row;
		}

		return $posts;
	}

	/**
	 * Insert one attachment post row on the destination, remapping the
	 * author via the users IdMap (falling back to the configured
	 * destination admin) and regenerating the GUID.
	 *
	 * @param array{post: array<string, mixed>, site: Site} $attachment
	 */
	private function insertAttachmentPost( Connection $destination, array $attachment, string $newRelative, MergeConfig $config, IdMap $idMap ): int {
		$post = $attachment['post'];
		$postsTable = $destination->siteTable( 'posts', 1 );

		$author = $idMap->get( 'user', 0, (int) $post['post_author'] )
			?? $destination->config->adminUserId
			?? 0;

		$destination->execute(
			"INSERT INTO {$postsTable} (
                post_author, post_date, post_date_gmt, post_content, post_title,
                post_excerpt, post_status, comment_status, ping_status,
                post_password, post_name, to_ping, pinged,
                post_modified, post_modified_gmt, post_content_filtered,
                post_parent, guid, menu_order, post_type, post_mime_type,
                comment_count
            ) VALUES (
                :author, :date, :date_gmt, :content, :title,
                :excerpt, :status, :comment_status, :ping_status,
                :password, :name, :to_ping, :pinged,
                :modified, :modified_gmt, :content_filtered,
                0, :guid, :menu_order, 'attachment', :mime,
                :comment_count
            )",
			array(
				'author'           => $author,
				'date'             => (string) $post['post_date'],
				'date_gmt'         => (string) $post['post_date_gmt'],
				'content'          => (string) $post['post_content'],
				'title'            => (string) $post['post_title'],
				'excerpt'          => (string) $post['post_excerpt'],
				'status'           => (string) $post['post_status'],
				'comment_status'   => (string) $post['comment_status'],
				'ping_status'      => (string) $post['ping_status'],
				'password'         => (string) $post['post_password'],
				'name'             => (string) $post['post_name'],
				'to_ping'          => (string) $post['to_ping'],
				'pinged'           => (string) $post['pinged'],
				'modified'         => (string) $post['post_modified'],
				'modified_gmt'     => (string) $post['post_modified_gmt'],
				'content_filtered' => (string) $post['post_content_filtered'],
				'guid'             => GuidGenerator::forAttachment( $config->destinationUrl, $newRelative ),
				'menu_order'       => (int) $post['menu_order'],
				'mime'             => (string) $post['post_mime_type'],
				'comment_count'    => (int) $post['comment_count'],
			)
		);

		return (int) $destination->lastInsertId();
	}

	/**
	 * Copy all source postmeta onto the new attachment, rewriting
	 * `_wp_attached_file` to the destination-relative path and
	 * `_wp_attachment_metadata` via SerializedDataRewriter so renamed
	 * files and string lengths stay consistent. Everything else is
	 * copied verbatim.
	 *
	 * @param array{meta: array<string, string[]>} $attachment
	 * @param array<string, string>                $renamedVariants
	 */
	private function insertAttachmentMeta( Connection $destination, array $attachment, int $newPostId, string $newRelative, array $renamedVariants ): void {
		$metaTable = $destination->siteTable( 'postmeta', 1 );

		foreach ( $attachment['meta'] as $metaKey => $values ) {
			foreach ( $values as $value ) {
				if ( $metaKey === '_wp_attached_file' ) {
					$value = $newRelative;
				} elseif ( $metaKey === '_wp_attachment_metadata' ) {
					$value = $this->rewriteAttachmentMetadata( $value, $newRelative, $renamedVariants );
				}

				$destination->execute(
					"INSERT INTO {$metaTable} (post_id, meta_key, meta_value) VALUES (:post_id, :key, :value)",
					array(
						'post_id' => $newPostId,
						'key'     => $metaKey,
						'value'   => $value,
					)
				);
			}
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
