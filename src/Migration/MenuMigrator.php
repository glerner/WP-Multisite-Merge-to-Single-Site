<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\Logger;

/**
 * Menus phase of the migration (PLAN.md §7.5).
 *
 * Copies every included site's nav menus as DATA:
 * `nav_menu` terms, `nav_menu_item` posts, and `wp_navigation` block
 * menus.
 * They are deliberately NOT assigned to any theme location
 * (inactive/orphaned), per the project owner's confirmation.
 * The merged destination theme's own menu locations are never
 * silently overwritten.
 *
 * What this phase does per site:
 *  - Creates each source `nav_menu` term + term_taxonomy row on the
 *    destination.
 *    Each site's menus stay separate.
 *    There is no cross-site name merging, matching §7.5.
 *    Records 'nav_menu' (term_id) and 'nav_menu_taxonomy'
 *    (term_taxonomy_id) IdMap entries.
 *  - Copies every `nav_menu_item` post with its full postmeta.
 *    `_menu_item_object_id` is rewritten by item type.
 *    'post_type' targets resolve through the posts IdMap, then the
 *    attachments IdMap.
 *    'taxonomy' targets resolve through the terms IdMap.
 *    The stored value for a taxonomy item is a term_id.
 *    'custom' and 'post_type_archive' targets stay verbatim.
 *    An unmapped post/taxonomy target keeps its old value.
 *    It is counted as item_targets_unmapped, never silent.
 *  - `_menu_item_menu_item_parent` is inserted as '0'.
 *    It is resolved in a per-site second pass through the 'menu_item'
 *    IdMap, like PostMigrator's post_parent and CommentMigrator's
 *    comment_parent fixups.
 *    An item whose parent was skipped is left top-level.
 *    It is counted as parents_unresolved.
 *  - Each item's nav_menu term_relationship is rewritten through
 *    'nav_menu_taxonomy'.
 *    Orphaned (unassigned) items migrate with no relationship,
 *    matching the source.
 *  - `wp_navigation` posts (block menus) migrate as posts.
 *    Their block attributes are rewritten.
 *    `ref` resolves through the 'wp_navigation' IdMap.
 *    `id` and `ids` resolve through the attachments IdMap.
 *    Block menus keep pointing at each other.
 *  - Destination nav_menu term counts are refreshed per site.
 *  - The report carries an `overview` tree: labels, targets, and
 *    nesting per site.
 *    bin/migrate.php renders it into menus-overview.md and
 *    menus-overview.json via MenuOverviewReportWriter.
 *    That is the manual-rebuild reference §7.5 calls for.
 *
 * Trash/auto-draft items and nav posts are discarded like PostMigrator
 * discards trashed posts.
 * They are counted as items_trash_skipped (or in the nav post total),
 * never silent.
 * Idempotent via 'nav_menu', 'nav_menu_taxonomy', 'menu_item', and
 * 'wp_navigation' entries in IdMap/MigrationTable.
 *
 * @phpstan-type MenuMigrationReport array{
 *     menus_migrated: int,
 *     menus_skipped: int,
 *     menu_items_migrated: int,
 *     menu_items_skipped: int,
 *     menu_items_failed: int,
 *     items_trash_skipped: int,
 *     item_targets_unmapped: int,
 *     nav_posts_migrated: int,
 *     nav_posts_skipped: int,
 *     nav_posts_failed: int,
 *     relationships_written: int,
 *     parents_fixed: int,
 *     parents_unresolved: int,
 *     authors_fallback: int,
 *     warnings: array<int, string>,
 *     by_site: array<int, array{menus: int, items: int}>,
 *     overview: array<int, array<string, mixed>>
 * }
 *
 * @package MergeMultisite
 */
final class MenuMigrator {

	/**
	 * Block-attribute remapping for wp_navigation post_content:
	 * `ref` is another wp_navigation post ID, `id`/`ids` are
	 * attachment IDs (same shape as PostMigrator's map).
	 *
	 * @var array<string, string>
	 */
	private const BLOCK_REF_ATTRIBUTES = array(
		'ref' => 'wp_navigation',
		'id' => 'attachment',
		'ids' => 'attachment',
	);

	/**
	 * Menu-item postmeta keys this phase rewrites (everything else
	 * copies verbatim).
	 */
	private const MENU_ITEM_TYPE_KEY = '_menu_item_type';
	private const MENU_ITEM_OBJECT_KEY = '_menu_item_object';
	private const MENU_ITEM_OBJECT_ID_KEY = '_menu_item_object_id';
	private const MENU_ITEM_URL_KEY = '_menu_item_url';
	private const MENU_ITEM_PARENT_KEY = '_menu_item_menu_item_parent';

	public function __construct(
		private readonly ?BlockAttributeRewriter $blockRewriter = null,
	) {
	}

	/**
	 * Runs the menu migration phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return MenuMigrationReport
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
		$blockRewriter = $this->blockRewriter ?? new BlockAttributeRewriter();
		$menuInventory = new MenuInventory();

		$destPostsTable = $destination->siteTable( 'posts', 1 );
		$destPostMetaTable = $destination->siteTable( 'postmeta', 1 );
		$destTaxonomyTable = $destination->siteTable( 'term_taxonomy', 1 );
		$destRelationshipsTable = $destination->siteTable( 'term_relationships', 1 );

		$report = array(
			'menus_migrated' => 0,
			'menus_skipped' => 0,
			'menu_items_migrated' => 0,
			'menu_items_skipped' => 0,
			'menu_items_failed' => 0,
			'items_trash_skipped' => 0,
			'item_targets_unmapped' => 0,
			'nav_posts_migrated' => 0,
			'nav_posts_skipped' => 0,
			'nav_posts_failed' => 0,
			'relationships_written' => 0,
			'parents_fixed' => 0,
			'parents_unresolved' => 0,
			'authors_fallback' => 0,
			'warnings' => array(),
			'by_site' => array(),
			'overview' => array(),
		);
		$warnings = array(); // collected separately so warn() never widens $report's shape

		foreach ( $sites as $site ) {
			$blogId = $site->blogId;
			$postsTable = $source->siteTable( 'posts', $blogId );
			$postMetaTable = $source->siteTable( 'postmeta', $blogId );
			$relationshipsTable = $source->siteTable( 'term_relationships', $blogId );
			$taxonomyTable = $source->siteTable( 'term_taxonomy', $blogId );

			$menus = $menuInventory->collect( $source, $blogId )['menus'];
			$itemRows = $this->fetchRowsByType( $source, $postsTable, 'nav_menu_item' );
			$navRows = $this->fetchNavigationRows( $source, $postsTable, $config );

			if ( $menus === array() && $itemRows === array() && $navRows === array() ) {
				continue;
			}

			// Menus are small; one transaction per site (TermMigrator
			// precedent) keeps items + fixups + counts atomic.
			if ( ! $dryRun ) {
				$destination->beginTransaction();
			}

			try {
				$menuNameByTt = $this->migrateMenus( $source, $destination, $site, $menus, $idMap, $mapTable, $dryRun, $report );
				$overviewItems = $this->migrateItems(
					$source,
					$destination,
					$config,
					$site,
					$itemRows,
					$postMetaTable,
					$relationshipsTable,
					$taxonomyTable,
					$idMap,
					$mapTable,
					$dryRun,
					$report,
					$warnings,
					$logger
				);
				$this->migrateNavigationPosts(
					$source,
					$destination,
					$config,
					$site,
					$navRows,
					$postMetaTable,
					$blockRewriter,
					$idMap,
					$mapTable,
					$dryRun,
					$report,
					$warnings,
					$logger
				);
				$this->refreshNavMenuCounts( $destination, $destTaxonomyTable, $destRelationshipsTable, $dryRun );

				if ( ! $dryRun ) {
					$destination->commit();
				}

				$report['overview'][ $blogId ] = $this->buildSiteOverview( $site, $overviewItems, $menuNameByTt );
			} catch ( \Throwable $e ) {
				if ( $destination->inTransaction() ) {
					$destination->rollBack();
				}
				$logger->error( sprintf( 'Menu migration failed, rolled back: %s', $e->getMessage() ) );
				throw $e;
			}
		}

		$report['warnings'] = $warnings;

		$logger->info(
			sprintf(
				'Menu migration%s: %d menu(s) migrated, %d menu item(s), %d nav post(s), %d relationship(s), %d parent fixup(s), %d unresolved parent(s), %d unmapped target(s), %d trash item(s) skipped.',
				$dryRun ? ' (dry run)' : '',
				$report['menus_migrated'],
				$report['menu_items_migrated'],
				$report['nav_posts_migrated'],
				$report['relationships_written'],
				$report['parents_fixed'],
				$report['parents_unresolved'],
				$report['item_targets_unmapped'],
				$report['items_trash_skipped']
			)
		);

		return $report;
	}

	/**
	 * Create each source nav_menu term + taxonomy row on the
	 * destination. Menus are NOT merged across sites by name (§7.5):
	 * every source menu gets its own destination term.
	 *
	 * @param array<int, array<string, string>> $menus
	 * @param array                             $report  Phase counters, mutated in place.
	 *
	 * @return array<int, string> source nav_menu term_taxonomy_id => menu name (for the overview).
	 */
	private function migrateMenus(
		Connection $source,
		Connection $destination,
		Site $site,
		array $menus,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		array &$report
	): array {
		$blogId = $site->blogId;
		$destTermsTable = $destination->siteTable( 'terms', 1 );
		$destTaxonomyTable = $destination->siteTable( 'term_taxonomy', 1 );

		$nameByTt = array();
		foreach ( $menus as $menu ) {
			$oldTermId = (int) $menu['term_id'];
			$oldTtId = (int) $menu['term_taxonomy_id'];
			$nameByTt[ $oldTtId ] = (string) $menu['name'];

			if ( $idMap->has( 'nav_menu', $blogId, $oldTermId ) ) {
				++$report['menus_skipped'];
				continue;
			}

			$newTermId = 0;
			$newTtId = 0;
			if ( ! $dryRun ) {
				$destination->execute(
					"INSERT INTO {$destTermsTable} (name, slug, term_group) VALUES (:name, :slug, 0)",
					array(
						'name' => (string) $menu['name'],
						'slug' => (string) $menu['slug'],
					)
				);
				$newTermId = $destination->lastInsertId();

				$destination->execute(
					"INSERT INTO {$destTaxonomyTable} (term_id, taxonomy, description, parent, count) VALUES (:term_id, 'nav_menu', :desc, 0, 0)",
					array(
						'term_id' => $newTermId,
						'desc' => sprintf( 'Migrated menu from %s', $site->domain ),
					)
				);
				$newTtId = $destination->lastInsertId();
			}

			$idMap->set( 'nav_menu', $blogId, $oldTermId, $newTermId );
			$idMap->set( 'nav_menu_taxonomy', $blogId, $oldTtId, $newTtId );
			if ( ! $dryRun ) {
				$mapTable->record( $destination, 'nav_menu', $blogId, $oldTermId, $newTermId );
				$mapTable->record( $destination, 'nav_menu_taxonomy', $blogId, $oldTtId, $newTtId );
			}

			++$report['menus_migrated'];
			$report['by_site'][ $blogId ]['menus'] = ( $report['by_site'][ $blogId ]['menus'] ?? 0 ) + 1;
		}

		return $nameByTt;
	}

	/**
	 * Copy every nav_menu_item post + meta for one site, remapping
	 * `_menu_item_object_id` by item type and deferring
	 * `_menu_item_menu_item_parent` to a second pass. Returns the
	 * site's flat overview items (label/target/nesting data per item)
	 * for buildSiteOverview().
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array                            $report  Phase counters, mutated in place.
	 * @param string[]                         $warnings
	 *
	 * @return array<int, array{item: array<string, mixed>, menu_tt: int|null}>
	 */
	private function migrateItems(
		Connection $source,
		Connection $destination,
		MergeConfig $config,
		Site $site,
		array $rows,
		string $postMetaTable,
		string $relationshipsTable,
		string $taxonomyTable,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		array &$report,
		array &$warnings,
		Logger $logger
	): array {
		$blogId = $site->blogId;
		$destPostsTable = $destination->siteTable( 'posts', 1 );
		$destPostMetaTable = $destination->siteTable( 'postmeta', 1 );
		$destRelationshipsTable = $destination->siteTable( 'term_relationships', 1 );

		$excludedStatuses = array_values( array_unique( array_merge( array( 'trash', 'auto-draft' ), $config->excludedPostStatuses ) ) );
		$parentFixups = array();
		$overviewItems = array();

		foreach ( array_chunk( $rows, max( 1, $config->batchSize ) ) as $batch ) {
			$ids = array_map( static fn ( array $row ): int => (int) $row['ID'], $batch );
			$metaByItem = PostQueryHelper::fetchMetaForPosts( $source, $postMetaTable, $ids );
			$menuTtByItem = $this->fetchMenuTtByItem( $source, $relationshipsTable, $taxonomyTable, $ids );

			foreach ( $batch as $row ) {
				$oldItemId = (int) $row['ID'];
				$status = (string) $row['post_status'];

				if ( $idMap->has( 'menu_item', $blogId, $oldItemId ) ) {
					++$report['menu_items_skipped'];
					continue;
				}
				if ( in_array( $status, $excludedStatuses, true ) ) {
					++$report['items_trash_skipped'];
					continue;
				}

				$meta = $metaByItem[ $oldItemId ] ?? array();
				$menuTtId = $menuTtByItem[ $oldItemId ] ?? null;
				$overviewItems[] = array(
					'item' => $this->overviewItem( $row, $meta ),
					'menu_tt' => $menuTtId,
				);

				if ( $dryRun ) {
					++$report['menu_items_migrated'];
					$report['by_site'][ $blogId ]['items'] = ( $report['by_site'][ $blogId ]['items'] ?? 0 ) + 1;
					continue;
				}

				try {
					$newItemId = $this->insertMenuPost( $destination, $destPostsTable, $row, (string) $row['post_content'], $config, $idMap, $report );
					$this->updateGuid( $destination, $destPostsTable, $newItemId, $config->destinationUrl );
					$this->insertItemMeta( $destination, $destPostMetaTable, $newItemId, $meta, $blogId, $idMap, $report );

					if ( $menuTtId !== null ) {
						$newTtId = $idMap->get( 'nav_menu_taxonomy', $blogId, $menuTtId );
						if ( $newTtId === null ) {
							$this->warn( $warnings, $logger, sprintf( 'Menu item %d (site %d): its nav_menu (tt %d) was not migrated; item left unassigned.', $oldItemId, $blogId, $menuTtId ) );
						} else {
							$this->insertRelationshipRow( $destination, $destRelationshipsTable, $newItemId, $newTtId );
							++$report['relationships_written'];
						}
					}
				} catch ( \Throwable $e ) {
					++$report['menu_items_failed'];
					$this->warn( $warnings, $logger, sprintf( 'Menu item %d (site %d) failed: %s', $oldItemId, $blogId, $e->getMessage() ) );
					continue;
				}

				$idMap->set( 'menu_item', $blogId, $oldItemId, $newItemId );
				$mapTable->record( $destination, 'menu_item', $blogId, $oldItemId, $newItemId );

				$oldParent = (int) ( $meta[ self::MENU_ITEM_PARENT_KEY ][0] ?? 0 );
				if ( $oldParent > 0 ) {
					$parentFixups[ $oldItemId ] = array(
						'new_id' => $newItemId,
						'old_parent' => $oldParent,
					);
				}

				++$report['menu_items_migrated'];
				$report['by_site'][ $blogId ]['items'] = ( $report['by_site'][ $blogId ]['items'] ?? 0 ) + 1;
			}
		}

		$this->fixItemParents( $destination, $destPostMetaTable, $blogId, $parentFixups, $idMap, $dryRun, $report, $warnings, $logger );

		return $overviewItems;
	}

	/**
	 * Copy wp_navigation posts (block menus) with block attributes
	 * rewritten so `ref` navigation links keep pointing at each other.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array                            $report  Phase counters, mutated in place.
	 * @param string[]                         $warnings
	 */
	private function migrateNavigationPosts(
		Connection $source,
		Connection $destination,
		MergeConfig $config,
		Site $site,
		array $rows,
		string $postMetaTable,
		BlockAttributeRewriter $blockRewriter,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		array &$report,
		array &$warnings,
		Logger $logger
	): void {
		$blogId = $site->blogId;
		$destPostsTable = $destination->siteTable( 'posts', 1 );
		$destPostMetaTable = $destination->siteTable( 'postmeta', 1 );

		foreach ( array_chunk( $rows, max( 1, $config->batchSize ) ) as $batch ) {
			$ids = array_map( static fn ( array $row ): int => (int) $row['ID'], $batch );
			$metaByPost = PostQueryHelper::fetchMetaForPosts( $source, $postMetaTable, $ids );

			foreach ( $batch as $row ) {
				$oldId = (int) $row['ID'];

				if ( $idMap->has( 'wp_navigation', $blogId, $oldId ) ) {
					++$report['nav_posts_skipped'];
					continue;
				}

				if ( $dryRun ) {
					++$report['nav_posts_migrated'];
					continue;
				}

				$content = (string) $row['post_content'];
				if ( $content !== '' ) {
					$content = $blockRewriter->rewrite( $content, $blogId, $idMap, self::BLOCK_REF_ATTRIBUTES );
				}

				try {
					$newId = $this->insertMenuPost( $destination, $destPostsTable, $row, $content, $config, $idMap, $report );
					$this->updateGuid( $destination, $destPostsTable, $newId, $config->destinationUrl );
					$this->insertItemMeta( $destination, $destPostMetaTable, $newId, $metaByPost[ $oldId ] ?? array(), $blogId, $idMap, $report );
				} catch ( \Throwable $e ) {
					++$report['nav_posts_failed'];
					$this->warn( $warnings, $logger, sprintf( 'wp_navigation post %d (site %d) failed: %s', $oldId, $blogId, $e->getMessage() ) );
					continue;
				}

				$idMap->set( 'wp_navigation', $blogId, $oldId, $newId );
				$mapTable->record( $destination, 'wp_navigation', $blogId, $oldId, $newId );

				++$report['nav_posts_migrated'];
			}
		}
	}

	/**
	 * Insert one menu/nav post row with post_parent = 0 and a blank
	 * GUID (set right after, since it embeds the new ID). post_author
	 * resolves through the users IdMap with the admin fallback, like
	 * PostMigrator. For wp_navigation posts, `$content` arrives
	 * already block-attribute-rewritten.
	 *
	 * @param array<string, mixed> $row
	 * @param array                $report  Phase counters, mutated in place.
	 */
	private function insertMenuPost(
		Connection $destination,
		string $postsTable,
		array $row,
		string $content,
		MergeConfig $config,
		IdMap $idMap,
		array &$report
	): int {
		$author = $idMap->get( 'user', 0, (int) $row['post_author'] );
		if ( $author === null ) {
			$author = $destination->config->adminUserId ?? 0;
			++$report['authors_fallback'];
		}

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
                0, '', :menu_order, :type, :mime,
                :comment_count
            )",
			array(
				'author'           => $author,
				'date'             => (string) $row['post_date'],
				'date_gmt'         => (string) $row['post_date_gmt'],
				'content'          => $content,
				'title'            => (string) $row['post_title'],
				'excerpt'          => (string) $row['post_excerpt'],
				'status'           => (string) $row['post_status'],
				'comment_status'   => (string) $row['comment_status'],
				'ping_status'      => (string) $row['ping_status'],
				'password'         => (string) $row['post_password'],
				'name'             => (string) $row['post_name'],
				'to_ping'          => (string) $row['to_ping'],
				'pinged'           => (string) $row['pinged'],
				'modified'         => (string) $row['post_modified'],
				'modified_gmt'     => (string) $row['post_modified_gmt'],
				'content_filtered' => (string) $row['post_content_filtered'],
				'menu_order'       => (int) $row['menu_order'],
				'type'             => (string) $row['post_type'],
				'mime'             => (string) $row['post_mime_type'],
				'comment_count'    => (int) $row['comment_count'],
			)
		);

		return $destination->lastInsertId();
	}

	/**
	 * Set the regenerated GUID (`?p={new_id}`) after the insert.
	 */
	private function updateGuid( Connection $destination, string $postsTable, int $newId, string $destinationUrl ): void {
		$destination->execute(
			"UPDATE {$postsTable} SET guid = :guid WHERE ID = :id",
			array(
				'guid' => GuidGenerator::forPost( $destinationUrl, $newId ),
				'id' => $newId,
			)
		);
	}

	/**
	 * Copy item/nav post meta onto the new post.
	 * `_menu_item_object_id` is remapped by item type.
	 * `_menu_item_menu_item_parent` is written as '0'.
	 * It is fixed in the second pass.
	 * Everything else copies verbatim.
	 *
	 * @param array<string, string[]> $meta
	 * @param array                   $report  Phase counters, mutated in place.
	 */
	private function insertItemMeta(
		Connection $destination,
		string $postMetaTable,
		int $newPostId,
		array $meta,
		int $blogId,
		IdMap $idMap,
		array &$report
	): void {
		foreach ( $meta as $metaKey => $values ) {
			foreach ( $values as $value ) {
				$newValue = $value;

				if ( $metaKey === self::MENU_ITEM_OBJECT_ID_KEY ) {
					$newValue = $this->remapObjectId( (string) $value, (string) ( $meta[ self::MENU_ITEM_TYPE_KEY ][0] ?? '' ), $blogId, $idMap, $report );
				} elseif ( $metaKey === self::MENU_ITEM_PARENT_KEY ) {
					$newValue = '0'; // resolved in the second pass.
				}

				$destination->execute(
					"INSERT INTO {$postMetaTable} (post_id, meta_key, meta_value) VALUES (:post_id, :key, :value)",
					array(
						'post_id' => $newPostId,
						'key'     => $metaKey,
						'value'   => $newValue,
					)
				);
			}
		}
	}

	/**
	 * Remap a `_menu_item_object_id` value by the item's type:
	 * 'post_type' through posts then attachments, 'taxonomy' through
	 * terms. 'custom'/'post_type_archive' and empty values stay
	 * verbatim. An unmapped post/taxonomy target keeps its old value
	 * (the item still shows, pointing at nothing) and is counted.
	 *
	 * @param array $report  Phase counters, mutated in place.
	 */
	private function remapObjectId( string $value, string $itemType, int $blogId, IdMap $idMap, array &$report ): string {
		if ( $value === '' || $value === '0' ) {
			return $value;
		}

		$newId = null;
		if ( $itemType === 'post_type' ) {
			$newId = $idMap->get( 'post', $blogId, (int) $value )
				?? $idMap->get( 'attachment', $blogId, (int) $value );
		} elseif ( $itemType === 'taxonomy' ) {
			$newId = $idMap->get( 'term', $blogId, (int) $value );
		}

		if ( $newId === null ) {
			if ( $itemType === 'post_type' || $itemType === 'taxonomy' ) {
				++$report['item_targets_unmapped'];
			}

			return $value;
		}

		return (string) $newId;
	}

	/**
	 * Fetch each item's nav_menu term_taxonomy_id, chunked. Items not
	 * assigned to any nav_menu term are absent from the map (orphans).
	 *
	 * @param int[] $itemIds
	 *
	 * @return array<int, int> item post ID => nav_menu term_taxonomy_id
	 */
	private function fetchMenuTtByItem( Connection $source, string $relationshipsTable, string $taxonomyTable, array $itemIds ): array {
		$byItem = array();

		foreach ( array_chunk( $itemIds, 5000 ) as $chunk ) {
			$placeholders = array();
			$params = array();
			foreach ( $chunk as $index => $itemId ) {
				$key = 'object_id_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $itemId;
			}

			$rows = $source->fetchAll(
				"SELECT tr.object_id, tt.term_taxonomy_id
                 FROM {$relationshipsTable} tr
                 INNER JOIN {$taxonomyTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.taxonomy = 'nav_menu' AND tr.object_id IN (" . implode( ', ', $placeholders ) . ')',
				$params
			);

			foreach ( $rows as $row ) {
				$byItem[ (int) $row['object_id'] ] = (int) $row['term_taxonomy_id'];
			}
		}

		return $byItem;
	}

	/**
	 * Second pass: resolve every `_menu_item_menu_item_parent` through
	 * the 'menu_item' IdMap once all of a site's items exist.
	 * An item whose parent was skipped (trash) or unmapped is left
	 * top-level ('0') and warned about.
	 *
	 * Runs inside the caller's per-site transaction.
	 * It opens no transaction of its own.
	 * The site's whole menu phase commits or rolls back together.
	 *
	 * @param array<int, array{new_id:int, old_parent:int}> $parentFixups
	 * @param array                                         $report  Phase counters, mutated in place.
	 * @param string[]                                      $warnings
	 */
	private function fixItemParents(
		Connection $destination,
		string $postMetaTable,
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
			$newParent = $idMap->get( 'menu_item', $blogId, $fixup['old_parent'] );
			if ( $newParent === null ) {
				++$report['parents_unresolved'];
				$this->warn( $warnings, $logger, sprintf( 'Menu item %d (site %d): parent item %d was not migrated; item left top-level.', $fixup['new_id'], $blogId, $fixup['old_parent'] ) );
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

		foreach ( $updates as $update ) {
			$destination->execute(
				"UPDATE {$postMetaTable} SET meta_value = :parent WHERE post_id = :id AND meta_key = '" . self::MENU_ITEM_PARENT_KEY . "'",
				array(
					'parent' => (string) $update['parent'],
					'id' => $update['id'],
				)
			);
			++$report['parents_fixed'];
		}
	}

	/**
	 * Recompute destination nav_menu term counts from the
	 * relationships actually written.
	 * TermMigrator inserts counts 0.
	 * PostMigrator refreshes category/tag counts.
	 * Menu counts are this phase's job.
	 */
	private function refreshNavMenuCounts( Connection $destination, string $taxonomyTable, string $relationshipsTable, bool $dryRun ): void {
		if ( $dryRun ) {
			return;
		}

		$destination->execute(
			"UPDATE {$taxonomyTable} SET count = (
                SELECT COUNT(*) FROM {$relationshipsTable}
                WHERE {$relationshipsTable}.term_taxonomy_id = {$taxonomyTable}.term_taxonomy_id
            ) WHERE {$taxonomyTable}.taxonomy = 'nav_menu'"
		);
	}

	/**
	 * One flat overview entry for a menu item (source IDs kept so the
	 * report cross-references the source site).
	 *
	 * @param array<string, mixed>    $row
	 * @param array<string, string[]> $meta
	 *
	 * @return array<string, mixed>
	 */
	private function overviewItem( array $row, array $meta ): array {
		return array(
			'id' => (int) $row['ID'],
			'label' => (string) $row['post_title'],
			'type' => (string) ( $meta[ self::MENU_ITEM_TYPE_KEY ][0] ?? '' ),
			'object' => (string) ( $meta[ self::MENU_ITEM_OBJECT_KEY ][0] ?? '' ),
			'target' => $this->overviewTarget( $meta ),
			'parent' => (int) ( $meta[ self::MENU_ITEM_PARENT_KEY ][0] ?? 0 ),
			'menu_order' => (int) $row['menu_order'],
		);
	}

	/**
	 * A human-readable target for the overview tree: the custom URL,
	 * or a source object reference ("post #12", "term #5",
	 * "archive: product").
	 *
	 * @param array<string, string[]> $meta
	 */
	private function overviewTarget( array $meta ): string {
		$type = (string) ( $meta[ self::MENU_ITEM_TYPE_KEY ][0] ?? '' );
		$object = (string) ( $meta[ self::MENU_ITEM_OBJECT_KEY ][0] ?? '' );
		$objectId = (string) ( $meta[ self::MENU_ITEM_OBJECT_ID_KEY ][0] ?? '' );

		if ( $type === 'custom' ) {
			return (string) ( $meta[ self::MENU_ITEM_URL_KEY ][0] ?? '' );
		}
		if ( $type === 'post_type_archive' ) {
			return 'archive: ' . $object;
		}
		if ( $type === 'post_type' ) {
			return 'post #' . $objectId;
		}
		if ( $type === 'taxonomy' ) {
			return 'term #' . $objectId;
		}

		return $type !== '' ? $type : '';
	}

	/**
	 * Group one site's flat overview items into per-menu lists.
	 * The orphaned items get their own list.
	 * The result feeds the report's `overview` tree.
	 * That tree is the manual-rebuild reference PLAN.md §7.5 calls
	 * for.
	 * Pure formatting, no DB.
	 *
	 * @param array<int, array{item: array<string, mixed>, menu_tt: int|null}> $overviewItems
	 * @param array<int, string>                                               $menuNameByTt
	 *
	 * @return array<string, mixed>
	 */
	private function buildSiteOverview( Site $site, array $overviewItems, array $menuNameByTt ): array {
		$menus = array();
		$orphaned = array();

		foreach ( $overviewItems as $entry ) {
			$menuTtId = $entry['menu_tt'];
			if ( $menuTtId === null ) {
				$orphaned[] = $entry['item'];
				continue;
			}

			if ( ! isset( $menus[ $menuTtId ] ) ) {
				$menus[ $menuTtId ] = array(
					'name' => $menuNameByTt[ $menuTtId ] ?? 'Unnamed menu',
					'items' => array(),
				);
			}
			$menus[ $menuTtId ]['items'][] = $entry['item'];
		}

		return array(
			'domain' => $site->domain,
			'menus' => array_values( $menus ),
			'orphaned_items' => $orphaned,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRowsByType( Connection $source, string $postsTable, string $postType ): array {
		return $source->fetchAll(
			"SELECT * FROM {$postsTable} WHERE post_type = :type ORDER BY ID",
			array( 'type' => $postType )
		);
	}

	/**
	 * The wp_navigation rows to copy, status-filtered like
	 * PostMigrator's posts (trash/auto-draft always excluded, plus
	 * configured statuses).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchNavigationRows( Connection $source, string $postsTable, MergeConfig $config ): array {
		$excludedStatuses = array_values( array_unique( array_merge( array( 'auto-draft', 'trash' ), $config->excludedPostStatuses ) ) );

		$params = array();
		$placeholders = array();
		foreach ( $excludedStatuses as $index => $status ) {
			$key = 'excluded_status_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $status;
		}

		return $source->fetchAll(
			"SELECT * FROM {$postsTable} WHERE post_type = 'wp_navigation' AND post_status NOT IN (" . implode( ', ', $placeholders ) . ') ORDER BY ID',
			$params
		);
	}

	private function insertRelationshipRow( Connection $destination, string $relationshipsTable, int $objectId, int $termTaxonomyId ): void {
		$destination->execute(
			"INSERT INTO {$relationshipsTable} (object_id, term_taxonomy_id, term_order) VALUES (:object_id, :tt_id, 0)",
			array(
				'object_id' => $objectId,
				'tt_id' => $termTaxonomyId,
			)
		);
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
