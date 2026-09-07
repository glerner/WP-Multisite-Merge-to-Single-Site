<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\SitesPhpExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SitesPhpExporter::class )]
final class SitesPhpExporterTest extends TestCase {

	public function testExportsOneEntryPerNonDeletedSite(): void {
		$exporter = new SitesPhpExporter();

		$php = $exporter->export(
			array(
			new Site( 1, 'lc.lndo.site', '/', 'WP Website Mastery', false, true, 'WP Website Mastery' ),
			new Site( 35, 'molten-salt-reactor.lc.lndo.site', '/', 'Molten Salt Reactors', false, false, 'Molten Salt Reactors' ),
			)
		);

		self::assertStringContainsString(
			"['blog_id' => 1, 'domain' => 'lc.lndo.site', 'include' => true, 'category_name' => 'WP Website Mastery'],",
			$php
		);
		self::assertStringContainsString(
			"['blog_id' => 35, 'domain' => 'molten-salt-reactor.lc.lndo.site', 'include' => false, 'category_name' => 'Molten Salt Reactors'],",
			$php
		);
	}

	public function testSkipsDeletedSites(): void {
		$exporter = new SitesPhpExporter();

		$php = $exporter->export(
			array(
			new Site( 26, 'gljob.lernerconsulting.info', '/', '', true, false ),
			)
		);

		self::assertStringNotContainsString( 'gljob.lernerconsulting.info', $php );
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
