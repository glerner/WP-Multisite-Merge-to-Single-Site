<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\DetectorExtras;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the detector_extras merge semantics: extras augment the
 * built-in signature tables (append/extend) and can never remove or
 * silently corrupt one.
 */
#[CoversClass( DetectorExtras::class )]
final class DetectorExtrasTest extends TestCase {

	/**
	 * PatternMap appends patterns to a label that already exists in
	 * the built-in table (teaching a known plugin a new signature)
	 * rather than replacing its existing ones.
	 */
	public function testPatternMapAppendsToExistingLabel(): void {
		$merged = DetectorExtras::patternMap(
			array( 'My Plugin' => array( '\[existing\b' ) ),
			array( 'My Plugin' => array( 'wp:myplugin\/' ) )
		);

		self::assertSame( array( 'My Plugin' => array( '\[existing\b', 'wp:myplugin\/' ) ), $merged );
	}

	/**
	 * A label not in the built-in table is added as a new entry.
	 */
	public function testPatternMapAddsNewLabel(): void {
		$merged = DetectorExtras::patternMap(
			array( 'Built In' => array( 'x' ) ),
			array( 'New Plugin' => array( 'y' ) )
		);

		self::assertSame(
			array(
				'Built In'   => array( 'x' ),
				'New Plugin' => array( 'y' ),
			),
			$merged
		);
	}

	/**
	 * Garbage config (non-array extras, non-array pattern lists) is
	 * ignored rather than fataling or corrupting the built-ins.
	 */
	public function testPatternMapIgnoresMalformedExtras(): void {
		$builtin = array( 'Built In' => array( 'x' ) );

		self::assertSame( $builtin, DetectorExtras::patternMap( $builtin, 'not-an-array' ) );
		self::assertSame(
			$builtin,
			DetectorExtras::patternMap( $builtin, array( 'Bad' => 'not-a-list' ) )
		);
	}

	/**
	 * TupleMap replaces a known label's whole [key, needle] tuple --
	 * appending would corrupt the fixed two-element shape.
	 */
	public function testTupleMapReplacesExistingLabel(): void {
		$merged = DetectorExtras::tupleMap(
			array( 'My Plugin' => array( '_old_key', 'old-needle' ) ),
			array( 'My Plugin' => array( '_new_key', 'new-needle' ) )
		);

		self::assertSame( array( 'My Plugin' => array( '_new_key', 'new-needle' ) ), $merged );
	}

	/**
	 * TupleMap adds new labels and skips tuples shorter than two
	 * elements (a [key] alone has nothing to search for).
	 */
	public function testTupleMapAddsNewLabelAndSkipsShortTuples(): void {
		$merged = DetectorExtras::tupleMap(
			array( 'Built In' => array( '_k', 'n' ) ),
			array(
				'New Plugin' => array( '_mk', 'needle' ),
				'Bad'        => array( '_only_key' ),
			)
		);

		self::assertSame(
			array(
				'Built In'   => array( '_k', 'n' ),
				'New Plugin' => array( '_mk', 'needle' ),
			),
			$merged
		);
	}

	/**
	 * PrefixMap adds new prefixes and relabels a prefix already in the
	 * built-in table (e.g. reattributing a meta prefix's plugin).
	 */
	public function testPrefixMapAddsAndRelabels(): void {
		$merged = DetectorExtras::prefixMap(
			array( '_yoast_wpseo_' => 'Yoast SEO' ),
			array(
				'_yoast_wpseo_' => 'Yoast SEO (forked)',
				'_myseo_'      => 'My SEO',
			)
		);

		self::assertSame(
			array(
				'_yoast_wpseo_' => 'Yoast SEO (forked)',
				'_myseo_'      => 'My SEO',
			),
			$merged
		);
	}

	/**
	 * ForCategory returns only the matching detector's block, and an
	 * empty array for a category the config doesn't mention.
	 */
	public function testForCategorySelectsOneDetectorsBlock(): void {
		$extras = array( 'seo_plugin' => array( 'meta_prefixes' => array( '_x_' => 'X' ) ) );

		self::assertSame( array( 'meta_prefixes' => array( '_x_' => 'X' ) ), DetectorExtras::forCategory( $extras, 'seo_plugin' ) );
		self::assertSame( array(), DetectorExtras::forCategory( $extras, 'gallery' ) );
		self::assertSame( array(), DetectorExtras::forCategory( array( 'seo_plugin' => 'junk' ), 'seo_plugin' ) );
	}
}
