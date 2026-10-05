<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\SitesPhpExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SitesPhpExporter::class )]
final class SitesPhpExporterTest extends TestCase {

	public function testExportsOneEntryPerSite(): void {
		$exporter = new SitesPhpExporter();

		$php = $exporter->export(
			array(
			new Site( 1, 'lc.lndo.site', '/', 'WP Website Mastery', false, true, 'WP Website Mastery' ),
			new Site( 35, 'molten-salt-reactor.lc.lndo.site', '/', 'Molten Salt Reactors', false, false, 'Molten Salt Reactors' ),
			)
		);

		self::assertStringContainsString(
			"array( 'blog_id' => 1, 'domain' => 'lc.lndo.site', 'include' => true, 'category_name' => 'WP Website Mastery' ),",
			$php
		);
		self::assertStringContainsString(
			"array( 'blog_id' => 35, 'domain' => 'molten-salt-reactor.lc.lndo.site', 'include' => false, 'category_name' => 'Molten Salt Reactors' ),",
			$php
		);
	}

	public function testMarksDeletedSitesExcludedButPresent(): void {
		$exporter = new SitesPhpExporter();

		$php = $exporter->export(
			array(
			new Site( 26, 'gljob.lernerconsulting.info', '/', '', true, false ),
			)
		);

		// Deleted sites stay visible for review: include=false plus a
		// 'deleted' documentation key SiteConfig ignores.
		self::assertStringContainsString(
			"array( 'blog_id' => 26, 'domain' => 'gljob.lernerconsulting.info', 'include' => false, 'deleted' => true, 'category_name' => NULL ),",
			$php
		);
	}

	public function testIncludesCategorySlugOnlyWhenSet(): void {
		$exporter = new SitesPhpExporter();

		$withSlug = $exporter->export(
			array(
			new Site( 1, 'lc.lndo.site', '/', 'WP Website Mastery', false, true, 'WP Website Mastery', 'wp-website-mastery' ),
			)
		);
		$withoutSlug = $exporter->export(
			array(
			new Site( 1, 'lc.lndo.site', '/', 'WP Website Mastery', false, true ),
			)
		);

		self::assertStringContainsString( "'category_slug' => 'wp-website-mastery'", $withSlug );
		self::assertStringNotContainsString( 'category_slug', $withoutSlug );
	}

	public function testEscapesQuotesInDomainAndTitle(): void {
		$exporter = new SitesPhpExporter();

		$php = $exporter->export(
			array(
			new Site( 1, 'site.example', '/', "George's Site", false, true, "George's Site" ),
			)
		);

		// var_export() writes single-quoted strings, escaping an
		// embedded apostrophe as \'.
		self::assertStringContainsString( "'George\\'s Site'", $php );
	}

	public function testExportEntriesReturnsPasteReadyLinesWithoutWrapper(): void {
		$exporter = new SitesPhpExporter();

		$entries = $exporter->exportEntries(
			array(
			new Site( 1, 'lc.lndo.site', '/', 'WP Website Mastery', false, true, 'WP Website Mastery' ),
			new Site( 56, 'hhtest.lernerconsulting.info', '/', 'HH Test', true, false ),
			)
		);

		// One array(...) line per site (deleted ones included, marked
		// include=false + deleted=true), no <?php header, no return
		// wrapper -- exactly what --list-sites prints.
		self::assertSame(
			array(
			"array( 'blog_id' => 1, 'domain' => 'lc.lndo.site', 'include' => true, 'category_name' => 'WP Website Mastery' )",
			"array( 'blog_id' => 56, 'domain' => 'hhtest.lernerconsulting.info', 'include' => false, 'deleted' => true, 'category_name' => NULL )",
			),
			$entries
		);
	}

	public function testProducesValidPhpThatReturnsAnArray(): void {
		$exporter = new SitesPhpExporter();

		$php = $exporter->export(
			array(
			new Site( 1, 'lc.lndo.site', '/', 'WP Website Mastery', false, true ),
			)
		);

		$tempFile = tempnam( sys_get_temp_dir(), 'sites-php-export-test-' );
		self::assertNotFalse( $tempFile );
		file_put_contents( $tempFile . '.php', $php );

		$result = require $tempFile . '.php';

		unlink( $tempFile . '.php' );
		unlink( $tempFile );

		self::assertIsArray( $result );
		self::assertCount( 1, $result );
		self::assertSame( 1, $result[0]['blog_id'] );
	}
}
