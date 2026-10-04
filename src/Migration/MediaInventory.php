<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Support\FileHasher;

/**
 * Per-site inventory of attachment files: every `_wp_attached_file`
 * postmeta row joined to its attachment post, resolved to an absolute
 * path on disk via `UploadsPathResolver`.
 *
 * Shared by MediaFileCheck (which reports missing files and basename
 * collisions) and MediaMigrator (PLAN.md §7.2 — which copies/dedupes
 * the files and recreates attachment posts on the destination).
 *
 * @package MergeMultisite
 */
final class MediaInventory {

	private readonly FileHasher $hasher;

	public function __construct(
		private readonly UploadsPathResolver $resolver,
		?FileHasher $hasher = null,
	) {
		$this->hasher = $hasher ?? new FileHasher();
	}

	/**
	 * Fetches every attachment's `_wp_attached_file` per site and
	 * resolves the absolute path on disk.
	 *
	 * @param Site[] $sites
	 *
	 * @return array{found: array<int, array{blog_id:int, post_id:int, relative:string, path:string}>,
	 *               missing: array<int, array{blog_id:int, post_id:int, relative:string}>}
	 *         `found` entries resolved to a real file; `missing` entries
	 *         have no file under any candidate layout.
	 */
	public function collect( Connection $source, array $sites ): array {
		$found = array();
		$missing = array();

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );
			$postMetaTable = $source->siteTable( 'postmeta', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT p.ID, pm.meta_value AS relative_file
                 FROM {$postsTable} p
                 INNER JOIN {$postMetaTable} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                 WHERE p.post_type = 'attachment'"
			);

			foreach ( $rows as $row ) {
				$relative = (string) $row['relative_file'];
				$absolute = $this->resolver->resolve( $site->blogId, $relative );

				if ( $absolute === null ) {
					$missing[] = array(
						'blog_id' => $site->blogId,
						'post_id' => (int) $row['ID'],
						'relative' => $relative,
					);
					continue;
				}

				$found[] = array(
					'blog_id' => $site->blogId,
					'post_id' => (int) $row['ID'],
					'relative' => $relative,
					'path' => $absolute,
				);
			}
		}

		return array(
			'found' => $found,
			'missing' => $missing,
		);
	}

	/**
	 * Fingerprint every found file exactly once — hashing is the
	 * dominant cost of media auditing/migration, and both the
	 * collision report and the dedup plan need the same map.
	 *
	 * @param array<int, array{path:string}> $found
	 *
	 * @return array<int, string> Index-aligned with $found.
	 */
	public function fingerprints( array $found ): array {
		$fingerprints = array();
		foreach ( $found as $index => $file ) {
			$fingerprints[ $index ] = $this->hasher->fingerprint( $file['path'] )->toKey();
		}

		return $fingerprints;
	}
}
