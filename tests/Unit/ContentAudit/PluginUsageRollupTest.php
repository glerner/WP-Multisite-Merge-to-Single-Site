<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\ContentAuditRow;
use MergeMultisite\ContentAudit\PluginUsageRollup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PluginUsageRollup::class )]
final class PluginUsageRollupTest extends TestCase {

	public function testMapsBlockNamespaceToInstalledPluginSlug(): void {
		$rollup = new PluginUsageRollup();

		$rows = array(
			$this->makeRow( 20, array( 'blocks' => array( 'ninja-forms/form' ) ) ),
			$this->makeRow( 33, array( 'blocks' => array( 'ninja-forms/submissions-table' ) ) ),
		);

		$result = $rollup->build( $rows, array( 'ninja-forms', 'akismet' ), array( 'ninja-forms' => array( 20, 33 ) ) );

		self::assertSame( 'ninja-forms', $result['used']['Ninja Forms']['slug'] );
		self::assertSame( array( 20, 33 ), $result['used']['Ninja Forms']['sites'] );
		self::assertCount( 2, $result['used']['Ninja Forms']['signals'] );
		self::assertArrayHasKey( 'akismet', $result['not_detected'] );
		self::assertArrayNotHasKey( 'ninja-forms', $result['not_detected'] );
	}

	public function testUnusedInstalledPluginLandsInNotDetectedWithActiveSites(): void {
		$rollup = new PluginUsageRollup();

		$result = $rollup->build(
			array( $this->makeRow( 20, array( 'blocks' => array( 'ninja-forms/form' ) ) ) ),
			array( 'ninja-forms', 'redirection' ),
			array( 'redirection' => array( 20, 49 ) )
		);

		self::assertSame( array( 20, 49 ), $result['not_detected']['redirection']['sites'] );
		self::assertFalse( $result['not_detected']['redirection']['has_data'] );
		self::assertNull( $result['not_detected']['redirection']['footprint'] );
		self::assertArrayNotHasKey( 'redirection', $result['used'] );
	}

	public function testFootprintDescriberMarksPluginsWithSavedData(): void {
		$rollup = new PluginUsageRollup();

		$result = $rollup->build(
			array( $this->makeRow( 20, array( 'blocks' => array( 'ninja-forms/form' ) ) ) ),
			array( 'ninja-forms', 'redirection', 'akismet' ),
			array(),
			static fn ( string $slug ): array => $slug === 'redirection'
				? array(
					'has_data' => true,
					'summary'  => 'tables: wp3_20_redirection_items',
				)
				: array(
					'has_data' => false,
					'summary'  => 'no data found',
				)
		);

		self::assertTrue( $result['not_detected']['redirection']['has_data'] );
		self::assertSame( 'tables: wp3_20_redirection_items', $result['not_detected']['redirection']['footprint'] );
		self::assertFalse( $result['not_detected']['akismet']['has_data'] );
	}

	public function testNavMenuLabelsAreNotTreatedAsPlugins(): void {
		$rollup = new PluginUsageRollup();

		$rows = array(
			$this->makeRow( 20, array( 'nav_menu' => array( 'page #123', 'custom: https://x.test/' ) ) ),
		);

		$result = $rollup->build( $rows, array(), array() );

		self::assertSame( array(), $result['not_installed'] );
	}

	public function testSignalsWithNoInstalledPluginAreGroupedByEntity(): void {
		$rollup = new PluginUsageRollup();

		$rows = array(
			$this->makeRow(
				20,
				array(
				'shortcodes' => array( 'et_pb_row', 'et_pb_section' ),
				'page_builder' => array( 'Divi' ),
				)
			),
		);

		// Divi is not installed -- it should surface once under "Divi", not per shortcode.
		$result = $rollup->build( $rows, array(), array() );

		self::assertArrayHasKey( 'Divi', $result['not_installed'] );
		self::assertCount( 1, $result['not_installed'] );
		self::assertCount( 3, $result['not_installed']['Divi']['signals'] );
		self::assertSame( array( 20 ), $result['not_installed']['Divi']['sites'] );
	}

	public function testCoreAndPlatformLabelsAreNotTreatedAsPlugins(): void {
		$rollup = new PluginUsageRollup();

		$rows = array(
			$this->makeRow(
				1,
				array(
				'blocks'      => array( 'core-embed/youtube' ),
				'shortcodes'  => array( 'gallery', 'caption' ),
				'video'       => array( 'YouTube' ),
				'form_plugin' => array( 'Unidentified HTML form (pasted embed code, e.g. Brevo)' ),
				)
			),
		);

		$result = $rollup->build( $rows, array(), array() );

		self::assertSame( array(), $result['used'] );
		self::assertSame( array(), $result['not_installed'] );
	}

	public function testSlugPrefixMatchWithoutAlias(): void {
		$rollup = new PluginUsageRollup();

		// "woocommercecart" has no alias entry matching... actually it does
		// via the "woocommerce" alias. Use a genuinely unaliased slug:
		// installed "relevanssi" + shortcode "relevanssi_live_search".
		$rows = array(
			$this->makeRow( 7, array( 'shortcodes' => array( 'relevanssi_live_search' ) ) ),
		);

		$result = $rollup->build( $rows, array( 'relevanssi' ), array() );

		self::assertArrayHasKey( 'relevanssi', $result['used'] );
		self::assertSame( 'relevanssi', $result['used']['relevanssi']['slug'] );
	}

	public function testSameRolePluginsCoActiveOnOneSiteAreAConflict(): void {
		$rollup = new PluginUsageRollup();

		$result = $rollup->build(
			array(),
			array( 'wp-mail-smtp', 'mailinblue', 'akismet' ),
			array(
				'wp-mail-smtp' => array( 20, 58 ),
				'mailinblue'   => array( 58, 33 ),
			)
		);

		$smtp = $result['conflicts']['Mail delivery / SMTP'];
		self::assertSame( array( 20, 58 ), $smtp['slugs']['wp-mail-smtp'] );
		self::assertSame( array( 33, 58 ), $smtp['slugs']['mailinblue'] );
		// Only site 58 has both co-active.
		self::assertSame( array( 58 ), $smtp['overlap'] );
		// Akismet alone in its role is not a conflict family at all.
		self::assertCount( 1, $result['conflicts'] );
	}

	public function testSingleActiveFamilyMemberIsNotAConflict(): void {
		$rollup = new PluginUsageRollup();

		// mailinblue is installed but never activated anywhere.
		$result = $rollup->build(
			array(),
			array( 'wp-mail-smtp', 'mailinblue' ),
			array( 'wp-mail-smtp' => array( 20 ) )
		);

		self::assertSame( array(), $result['conflicts'] );
	}

	/**
	 * @param array<string, string[]> $findings
	 */
	private function makeRow( int $blogId, array $findings ): ContentAuditRow {
		return new ContentAuditRow( $blogId, 'example.com', 1, 'page', 'publish', 'slug', 'My Post', $findings );
	}
}
