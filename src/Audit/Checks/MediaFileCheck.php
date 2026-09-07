<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\UploadsPathResolver;
use MergeMultisite\Support\FileHasher;

/**
 * Audits attachment files across every included site (PLAN.md §7.2/§9):
 *
 *  - Missing files: the attachment post exists but no file is found on disk.
 *  - Same filename, different content ("errors"): will require the
 *    `_site{blog_id}` collision rename during migration.
 *  - Same filename, identical content ("informational"): will safely
 *    dedup to a single physical file.
 *  - Identical content, different filenames ("informational"): duplicate
 *    Media Library uploads; not auto-merged, low priority cleanup.
 *
 * @package MergeMultisite
 */
final class MediaFileCheck implements AuditCheckInterface {

	/**
	 * Cap on how many sites are listed for a single missing filename
	 * before the message collapses to "and N more site(s)" -- prevents
	 * a file shared by many sites (e.g. a WooCommerce placeholder
	 * missing from every student site) from flooding the report with
	 * identical repeated lines.
	 */
	private const MAX_MISSING_SITES_PER_FILE = 10;

	private readonly FileHasher $hasher;

	public function __construct( ?FileHasher $hasher = null ) {
		$this->hasher = $hasher ?? new FileHasher();
	}

	public function name(): string {
		return 'media-files';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$resolver = new UploadsPathResolver( $config->source->uploadsPath );

		/** @var array<int, array{blog_id:int, post_id:int, relative:string, path:string}> $found */
		$found = array();
		$missing = array();
		$findings = array();

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
				$absolute = $resolver->resolve( $site->blogId, $relative );

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

		$findings = array( ...$findings, ...$this->checkMissingFiles( $missing ) );
		$findings = array( ...$findings, ...$this->checkFilenameCollisions( $found ) );
		$findings = array( ...$findings, ...$this->checkDuplicateContentDifferentNames( $found ) );

		return $findings;
	}

	/**
	 * Missing files are aggregated BY FILENAME (sorted), listing at
	 * most MAX_MISSING_SITES_PER_FILE sites per filename before
	 * collapsing to "and N more" -- a missing file shared by dozens of
	 * sites (e.g. a WooCommerce placeholder) must not produce dozens of
	 * repeated findings.
	 *
	 * @param array<int, array{blog_id:int, post_id:int, relative:string}> $missing
	 *
	 * @return AuditFinding[]
	 */
	private function checkMissingFiles( array $missing ): array {
		$byFile = array();
		foreach ( $missing as $entry ) {
			$byFile[ $entry['relative'] ][] = $entry;
		}

		ksort( $byFile );

		$findings = array();
		foreach ( $byFile as $relativeFile => $entries ) {
			usort(
				$entries,
				static fn ( array $a, array $b ): int => $a['blog_id'] <=> $b['blog_id']
			);

			$siteList = array_map(
				static fn ( array $e ): string => sprintf( 'site %d (attachment %d)', $e['blog_id'], $e['post_id'] ),
				array_slice( $entries, 0, self::MAX_MISSING_SITES_PER_FILE )
			);

			$count = count( $entries );
			if ( $count > self::MAX_MISSING_SITES_PER_FILE ) {
				$siteList[] = sprintf( 'and %d more site(s)', $count - self::MAX_MISSING_SITES_PER_FILE );
			}

			$findings[] = AuditFinding::error(
				$this->name() . '.missing-file',
				sprintf(
					'File "%s" is missing on disk but referenced by %d attachment(s): %s.',
					$relativeFile,
					$count,
					implode( ', ', $siteList )
				),
				array(
					'relative_file' => $relativeFile,
					'attachments' => array_map(
						static fn ( array $e ): array => array(
							'blog_id' => $e['blog_id'],
							'post_id' => $e['post_id'],
						),
						$entries
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Groups files by basename; reports "different content" as errors
	 * and "identical content" as info, in two clearly separate lists.
	 *
	 * @param array<int, array{blog_id:int, post_id:int, relative:string, path:string}> $found
	 *
	 * @return AuditFinding[]
	 */
	private function checkFilenameCollisions( array $found ): array {
		$byBasename = array();
		foreach ( $found as $file ) {
			$byBasename[ basename( $file['path'] ) ][] = $file;
		}

		$errors = array();
		$identical = array();

		ksort( $byBasename );

		foreach ( $byBasename as $basename => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$fingerprints = array();
			foreach ( $group as $file ) {
				$fingerprints[] = $this->hasher->fingerprint( $file['path'] )->toKey();
			}

			$uniqueFingerprints = array_unique( $fingerprints );

			$context = array(
				'basename' => $basename,
				'files' => array_map(
					static fn ( array $f ): array => array(
					'blog_id' => $f['blog_id'],
					'path' => $f['path'],
					),
					$group
				),
			);

			if ( count( $uniqueFingerprints ) > 1 ) {
				$errors[] = AuditFinding::error(
					$this->name() . '.filename-collision-different-content',
					sprintf(
						'Filename "%s" appears on %d sites with DIFFERENT content -- will be renamed with a "_site{blogId}" suffix during migration.',
						$basename,
						count( $group )
					),
					$context
				);
			} else {
				$identical[] = AuditFinding::info(
					$this->name() . '.filename-collision-identical-content',
					sprintf(
						'Filename "%s" appears on %d sites with IDENTICAL content -- will safely de-duplicate to one file.',
						$basename,
						count( $group )
					),
					$context
				);
			}
		}

		// Errors first and most prominent, then the informational list.
		return array( ...$errors, ...$identical );
	}

	/**
	 * Groups files by content fingerprint (regardless of filename) to
	 * find duplicate Media Library uploads under different names.
	 * Purely informational -- not auto-merged (PLAN.md §7.2).
	 *
	 * @param array<int, array{blog_id:int, post_id:int, relative:string, path:string}> $found
	 *
	 * @return AuditFinding[]
	 */
	private function checkDuplicateContentDifferentNames( array $found ): array {
		$byFingerprint = array();
		foreach ( $found as $file ) {
			$key = $this->hasher->fingerprint( $file['path'] )->toKey();
			$byFingerprint[ $key ][] = $file;
		}

		$findings = array();
		foreach ( $byFingerprint as $group ) {
			$distinctNames = array_unique( array_map( static fn ( array $f ): string => basename( $f['path'] ), $group ) );
			if ( count( $distinctNames ) < 2 ) {
				continue; // Same name everywhere -- already covered above, not this case.
			}

			$findings[] = AuditFinding::info(
				$this->name() . '.duplicate-content-different-name',
				sprintf(
					'Identical file content found under %d different filenames (%s) -- likely the same asset uploaded to the Media Library more than once. Minimal impact unless the file is large; not auto-merged.',
					count( $distinctNames ),
					implode( ', ', $distinctNames )
				),
				array(
					'files' => array_map(
						static fn ( array $f ): array => array(
						'blog_id' => $f['blog_id'],
						'path' => $f['path'],
						),
						$group
					),
				)
			);
		}

		return $findings;
	}
}
