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

	public function testInstalledPluginWhoseLabelStartsWithCoreTokenIsNotDropped(): void {
		$rollup = new PluginUsageRollup();

		// "Gallery Pro" condenses to "gallerypro", which starts with the
		// NOT_A_PLUGIN token "gallery" -- but the plugin IS installed, so
		// it must land in "used", not be silently discarded.
		$rows = array(
			$this->makeRow( 20, array( 'shortcodes' => array( 'gallery_pro_album' ) ) ),
		);

		$result = $rollup->build( $rows, array( 'gallery-pro' ), array( 'gallery-pro' => array( 20 ) ) );

		self::assertArrayHasKey( 'gallery-pro', $result['used'] );
		self::assertSame( array( 20 ), $result['used']['gallery-pro']['sites'] );
		self::assertArrayNotHasKey( 'gallery-pro', $result['not_detected'] );
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

	public function testConfigAddsNewConflictFamily(): void {
		$rollup = new PluginUsageRollup(
			array(
				'conflict_families' => array(
					'Membership' => array( 'memberpress', 'restrict-content' ),
				),
			)
		);

		$result = $rollup->build(
			array(),
			array( 'memberpress', 'restrict-content' ),
			array(
				'memberpress'      => array( 20, 58 ),
				'restrict-content' => array( 58 ),
			)
		);

		self::assertArrayHasKey( 'Membership', $result['conflicts'] );
		self::assertSame( array( 58 ), $result['conflicts']['Membership']['overlap'] );
	}

	public function testConfigAppendsAndRemovesFamilyMembers(): void {
		$rollup = new PluginUsageRollup(
			array(
				'conflict_families' => array(
					'Mail delivery / SMTP' => array( 'my-smtp-plugin', '-mailinblue' ),
				),
			)
		);

		// mailinblue was removed from the family; my-smtp-plugin joined it.
		$result = $rollup->build(
			array(),
			array( 'wp-mail-smtp', 'mailinblue', 'my-smtp-plugin' ),
			array(
				'wp-mail-smtp'   => array( 20 ),
				'mailinblue'     => array( 20 ),
				'my-smtp-plugin' => array( 20 ),
			)
		);

		$smtp = $result['conflicts']['Mail delivery / SMTP'];
		self::assertArrayNotHasKey( 'mailinblue', $smtp['slugs'] );
		self::assertArrayHasKey( 'my-smtp-plugin', $smtp['slugs'] );
		self::assertSame( array( 20 ), $smtp['overlap'] );
	}

	public function testConfigSignalMapAndNotAPluginOverrides(): void {
		$rollup = new PluginUsageRollup(
			array(
				'signal_map'   => array(
					'mywidget'   => array(
			'name' => 'My Widget',
			'slugs' => array( 'my-widget' ),
				),
					// Legacy positional form array(name, slugs) is also accepted.
					'legacytool' => array( 'Legacy Tool', array( 'legacy-tool' ) ),
				),
				'not_a_plugin' => array( 'mycorething' ),
			)
		);

		$rows = array(
			$this->makeRow(
				20,
				array(
				'blocks' => array( 'mywidget/block' ),
				'shortcodes' => array( 'mycorething' ),
				)
			),
			$this->makeRow( 33, array( 'blocks' => array( 'legacytool/gallery' ) ) ),
		);

		$result = $rollup->build( $rows, array( 'my-widget' ), array( 'my-widget' => array( 20 ) ) );

		self::assertArrayHasKey( 'My Widget', $result['used'] );
		self::assertSame( 'my-widget', $result['used']['My Widget']['slug'] );
		// Positional config resolved too; legacy-tool is not installed.
		self::assertArrayHasKey( 'Legacy Tool', $result['not_installed'] );
		// The extra not-a-plugin token suppresses the leftover signal.
		self::assertCount( 1, $result['not_installed'] );
	}

	/**
	 * Build a minimal audit row: a published page on blog $blogId whose
	 * only interesting data is the detector findings map.
	 *
	 * @param array<string, string[]> $findings Detector category => labels.
	 */
	private function makeRow( int $blogId, array $findings ): ContentAuditRow {
		return new ContentAuditRow(
			blogId: $blogId,
			domain: 'example.com',
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'slug',
			postTitle: 'My Post',
			categoryFindings: $findings,
		);
	}
}
