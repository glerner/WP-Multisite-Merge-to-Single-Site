<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\MediaCollisionPlan;
use MergeMultisite\Migration\MediaInventory;
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
 *    Media Library uploads; not auto-merged (each copy may carry
 *    different alt text, captions, and usage), low priority cleanup.
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

	public function description(): string {
		return 'Attachment files: missing on disk; same filename/different content (renamed during migration); same filename/identical content (dedupes); identical content/different names.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$inventory = new MediaInventory(
			new UploadsPathResolver( $config->source->uploadsPath ),
			$this->hasher
		);
		$plan = new MediaCollisionPlan();

		$collected = $inventory->collect( $source, $sites );
		$found = $collected['found'];

		// Fingerprint each file exactly once -- hashing is the dominant
		// cost of this check, and two consumers below need the same map.
		$fingerprints = $inventory->fingerprints( $found );

		$findings = $this->checkMissingFiles( $collected['missing'] );
		$findings = array( ...$findings, ...$this->checkFilenameCollisions( $found, $fingerprints, $plan ) );
		$findings = array( ...$findings, ...$this->checkDuplicateContentDifferentNames( $found, $fingerprints, $plan ) );

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
					'File "%s" missing on disk; referenced by %d attachment(s): %s.',
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
	 * @param array<int, string>                                                        $fingerprints Index-aligned with $found (computed once in run()).
	 * @param MediaCollisionPlan                                                        $plan         Shared basename grouping.
	 *
	 * @return AuditFinding[]
	 */
	private function checkFilenameCollisions( array $found, array $fingerprints, MediaCollisionPlan $plan ): array {
		$byBasename = $plan->groupsByBasename( $found );

		$errors = array();
		$identical = array();

		foreach ( $byBasename as $basename => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$uniqueFingerprints = array_unique(
				array_map( static fn ( int $index ): string => $fingerprints[ $index ], array_keys( $group ) )
			);

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
						'File "%s" on %d sites, DIFFERENT content -- renamed "_site{blogId}" during migration.',
						$basename,
						count( $group )
					),
					$context
				);
			} else {
				$identical[] = AuditFinding::info(
					$this->name() . '.filename-collision-identical-content',
					sprintf(
						'File "%s" on %d sites, identical content -- dedupes to one file.',
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
	 * @param array<int, string>                                                        $fingerprints Index-aligned with $found (computed once in run()).
	 * @param MediaCollisionPlan                                                        $plan         Shared fingerprint grouping.
	 *
	 * @return AuditFinding[]
	 */
	private function checkDuplicateContentDifferentNames( array $found, array $fingerprints, MediaCollisionPlan $plan ): array {
		$byFingerprint = $plan->groupsByFingerprint( $found, $fingerprints );

		$findings = array();
		foreach ( $byFingerprint as $group ) {
			$distinctNames = array_unique( array_map( static fn ( array $f ): string => basename( $f['path'] ), $group ) );
			if ( count( $distinctNames ) < 2 ) {
				continue; // Same name everywhere -- already covered above, not this case.
			}

			$findings[] = AuditFinding::info(
				$this->name() . '.duplicate-content-different-name',
				sprintf(
					'Identical content under %d filenames (%s) -- duplicate uploads; not auto-merged (each copy may carry different alt text/captions/usage).',
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
