<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Locates where a plugin actually stores data, by probing the slug's
 * common spellings ("ai-engine" -> "ai_engine%", "aiengine%",
 * "_ai_engine%") across four storage locations:
 *
 *   - wp_options option names
 *   - postmeta meta keys (including "_"-prefixed hidden meta)
 *   - post types containing the slug ("flamingo" -> "flamingo_contact")
 *   - custom DB tables containing the slug
 *
 * Used by PluginDataCheck (option-keys.php decision output) and by
 * site-audit.php's plugin-usage rollup (annotating plugins that left
 * no content markers but may still hold data).
 *
 * @package MergeMultisite
 */
final class PluginFootprintDetector {

	/**
	 * @param int[] $blogIds Sites where the plugin is installed/known.
	 *
	 * @return array{options: string[], postmeta: string[], posts: string[], tables: string[]}
	 */
	public function detect( Connection $source, MergeConfig $config, string $slug, array $blogIds ): array {
		return array(
			'options'  => $this->detectOptionNames( $source, $slug, $blogIds ),
			'postmeta' => $this->detectMetaKeys( $source, $slug, $blogIds ),
			'posts'    => $this->detectPostTypes( $source, $slug, $blogIds ),
			'tables'   => $this->detectTables( $source, $config, $slug ),
		);
	}

	/**
	 * One-line rendering of a footprint for report lists, e.g.
	 * "wp_options: akismet_strictness (+3 more) | posts: flamingo_contact x56".
	 *
	 * @param array{options: string[], postmeta: string[], posts: string[], tables: string[]} $footprint
	 */
	public function describe( array $footprint ): string {
		$parts = array();
		foreach (
			array(
				'wp_options' => $footprint['options'],
				'postmeta'   => $footprint['postmeta'],
				'posts'      => $footprint['posts'],
				'tables'     => $footprint['tables'],
			) as $label => $items
		) {
			if ( $items !== array() ) {
				$parts[] = sprintf(
					'%s: %s%s',
					$label,
					implode( ', ', array_slice( $items, 0, 3 ) ),
					count( $items ) > 3 ? sprintf( ' (+%d more)', count( $items ) - 3 ) : ''
				);
			}
		}

		return $parts === array()
			? 'no data found in wp_options, posts, postmeta, or custom tables'
			: implode( ' | ', $parts );
	}

	/**
	 * Turns detected option names into an option_keys suggestion:
	 * one match stays exact, several collapse to their common prefix
	 * plus "*". Falls back to a "<prefix>*" placeholder.
	 *
	 * @param string[] $names
	 *
	 * @return string[]
	 */
	public function suggestedOptionKeys( array $names ): array {
		if ( $names === array() ) {
			return array( '<prefix>*' );
		}

		if ( count( $names ) === 1 ) {
			return array( $names[0] );
		}

		$lcp = $names[0];
		foreach ( $names as $name ) {
			while ( $lcp !== '' && ! str_starts_with( $name, $lcp ) ) {
				$lcp = substr( $lcp, 0, -1 );
			}
		}

		// Trim a trailing partial word so e.g. "ai_engin" becomes
		// "ai_engine" -- cleaner to read and no less correct.
		if ( $lcp !== '' && ! str_ends_with( $lcp, '_' ) && ! str_ends_with( $lcp, '-' ) ) {
			$lastSeparator = max( (int) strrpos( $lcp, '_' ), (int) strrpos( $lcp, '-' ) );
			if ( $lastSeparator > 0 ) {
				$lcp = substr( $lcp, 0, $lastSeparator + 1 );
			}
		}

		return array( $lcp === '' ? '<prefix>*' : $lcp . '*' );
	}

	/**
	 * @param int[] $blogIds
	 *
	 * @return string[] Distinct matching option names.
	 */
	private function detectOptionNames( Connection $source, string $slug, array $blogIds ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$names = array();
		foreach ( $blogIds as $blogId ) {
			$optionsTable = $source->siteTable( 'options', $blogId );
			$rows = $source->fetchAll(
				"SELECT DISTINCT option_name FROM {$optionsTable}
                 WHERE option_name LIKE :underscored OR option_name LIKE :condensed
                 LIMIT 100",
				array(
				'underscored' => $underscored . '%',
				'condensed' => $condensed . '%',
				)
			);

			foreach ( $rows as $row ) {
				$names[ (string) $row['option_name'] ] = true;
			}
		}

		$names = array_keys( $names );
		sort( $names );

		return $names;
	}

	/**
	 * Postmeta keys often start with "_" (hidden meta), so probe both
	 * bare and underscored variants of each spelling.
	 *
	 * @param int[] $blogIds
	 *
	 * @return string[] Distinct matching meta keys.
	 */
	private function detectMetaKeys( Connection $source, string $slug, array $blogIds ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$names = array();
		foreach ( $blogIds as $blogId ) {
			$postMetaTable = $source->siteTable( 'postmeta', $blogId );
			$rows = $source->fetchAll(
				"SELECT DISTINCT meta_key FROM {$postMetaTable}
                 WHERE meta_key LIKE :u1 OR meta_key LIKE :u2
                    OR meta_key LIKE :c1 OR meta_key LIKE :c2
                 LIMIT 100",
				array(
				'u1' => $underscored . '%',
				'u2' => '_' . $underscored . '%',
				'c1' => $condensed . '%',
				'c2' => '_' . $condensed . '%',
				)
			);

			foreach ( $rows as $row ) {
				$names[ (string) $row['meta_key'] ] = true;
			}
		}

		$names = array_keys( $names );
		sort( $names );

		return $names;
	}

	/**
	 * Custom post types embedding the slug ("flamingo" ->
	 * "flamingo_contact"), which is where form-submission and similar
	 * plugins keep their real data.
	 *
	 * @param int[] $blogIds
	 *
	 * @return string[] "post_type xN" entries.
	 */
	private function detectPostTypes( Connection $source, string $slug, array $blogIds ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$counts = array();
		foreach ( $blogIds as $blogId ) {
			$postsTable = $source->siteTable( 'posts', $blogId );
			$rows = $source->fetchAll(
				"SELECT post_type, COUNT(*) AS n FROM {$postsTable}
                 WHERE post_type LIKE :u OR post_type LIKE :c
                 GROUP BY post_type
                 LIMIT 50",
				array(
				'u' => '%' . $underscored . '%',
				'c' => '%' . $condensed . '%',
				)
			);

			foreach ( $rows as $row ) {
				$type = (string) $row['post_type'];
				$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + (int) $row['n'];
			}
		}

		ksort( $counts );

		return array_map(
			static fn ( string $type, int $count ): string => sprintf( '%s x%d', $type, $count ),
			array_keys( $counts ),
			array_values( $counts )
		);
	}

	/**
	 * Custom DB tables whose name contains the slug, e.g. a plugin's
	 * own wp3_maiengine_* tables. Table names are prefix-qualified, so
	 * this is a contains-match, not a prefix-match.
	 *
	 * @return string[]
	 */
	private function detectTables( Connection $source, MergeConfig $config, string $slug ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$rows = $source->fetchAll(
			'SELECT TABLE_NAME FROM information_schema.TABLES '
			. 'WHERE TABLE_SCHEMA = :schema AND (TABLE_NAME LIKE :u OR TABLE_NAME LIKE :c)',
			array(
			'schema' => $config->source->database,
			'u' => '%' . $underscored . '%',
			'c' => '%' . $condensed . '%',
			)
		);

		$names = array_map( static fn ( array $row ): string => (string) $row['TABLE_NAME'], $rows );
		sort( $names );

		return $names;
	}
}
