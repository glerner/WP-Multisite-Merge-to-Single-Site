<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\RedirectMapBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( RedirectMapBuilder::class )]
final class RedirectMapBuilderTest extends TestCase {

	/**
	 * Deliberately unsorted input — every formatter must emit the
	 * same sorted-by-old_url order so files are stable run-to-run.
	 *
	 * @return array<int, array{old_url:string, new_url:string, blog_id:int, post_id:int, status:int}>
	 */
	private function redirects(): array {
		return array(
			array(
				'old_url' => 'https://site-b.example.com/services/',
				'new_url' => 'https://merged.example.com/services/',
				'blog_id' => 7,
				'post_id' => 42,
				'status'  => 301,
			),
			array(
				'old_url' => 'https://site-a.example.com/about/team/',
				'new_url' => 'https://merged.example.com/about/team/',
				'blog_id' => 3,
				'post_id' => 12,
				'status'  => 301,
			),
			array(
				'old_url' => 'https://site-a.example.com/gone-page/',
				'new_url' => '',
				'blog_id' => 3,
				'post_id' => 99,
				'status'  => 410,
			),
		);
	}

	public function testToJsonWritesStructuredEntriesSortedByOldUrl(): void {
		$json = ( new RedirectMapBuilder() )->toJson( $this->redirects() );
		$decoded = json_decode( $json, true );

		self::assertIsArray( $decoded );
		self::assertCount( 3, $decoded );
		self::assertSame(
			'https://site-a.example.com/about/team/',
			$decoded[0]['old_url']
		);
		self::assertSame(
			array(
				'old_url' => 'https://site-a.example.com/gone-page/',
				'new_url' => '',
				'blog_id' => 3,
				'post_id' => 99,
				'status'  => 410,
			),
			$decoded[1]
		);
	}

	public function testToMarkdownProducesTable(): void {
		$md = ( new RedirectMapBuilder() )->toMarkdown( $this->redirects() );

		self::assertStringContainsString( '| Old URL | New URL | Site | Post ID | Status |', $md );
		self::assertStringContainsString(
			'| https://site-a.example.com/about/team/ | https://merged.example.com/about/team/ | 3 | 12 | 301 |',
			$md
		);
	}

	public function testToCsvUsesRedirectionPluginFormat(): void {
		$csv = ( new RedirectMapBuilder() )->toCsv( $this->redirects() );
		$lines = explode( "\n", trim( $csv ) );

		self::assertSame( 'source URL,target URL', $lines[0] );
		self::assertSame(
			'"https://site-a.example.com/about/team/","https://merged.example.com/about/team/"',
			$lines[1]
		);
		self::assertCount( 4, $lines );
	}

	public function testToYoastCsvUsesFourColumnFormat(): void {
		$csv = ( new RedirectMapBuilder() )->toYoastCsv( $this->redirects() );
		$lines = explode( "\n", trim( $csv ) );

		self::assertSame( '"Origin","Target","Type","Format"', $lines[0] );
		self::assertSame(
			'"https://site-a.example.com/about/team/","https://merged.example.com/about/team/","301","plain"',
			$lines[1]
		);
	}

	public function testToHtaccessEmitsRedirectAndGoneLines(): void {
		$htaccess = ( new RedirectMapBuilder() )->toHtaccess( $this->redirects() );

		self::assertStringContainsString( 'Redirect 301 "/about/team/" "https://merged.example.com/about/team/"', $htaccess );
		self::assertStringContainsString( 'Redirect gone "/gone-page/"', $htaccess );
		self::assertStringContainsString( '<IfModule mod_alias.c>', $htaccess );
	}

	public function testToNginxEmitsRewriteAndReturnBlocks(): void {
		$nginx = ( new RedirectMapBuilder() )->toNginx( $this->redirects() );

		self::assertStringContainsString( 'rewrite ^/about/team/$ https://merged.example.com/about/team/ permanent;', $nginx );
		self::assertStringContainsString( 'location = /gone-page/ { return 410; }', $nginx );
	}

	public function testWriteAllProducesSixFiles(): void {
		$directory = sys_get_temp_dir() . '/redirect-map-test-' . uniqid();
		$written = ( new RedirectMapBuilder() )->writeAll( $this->redirects(), $directory );

		self::assertSame(
			array(
				'redirects.json',
				'redirects.md',
				'redirects.csv',
				'redirects-yoast.csv',
				'redirects.htaccess',
				'redirects-nginx.conf',
			),
			$written
		);

		foreach ( $written as $name ) {
			self::assertFileExists( $directory . '/' . $name );
		}

		// Cleanup.
		foreach ( $written as $name ) {
			unlink( $directory . '/' . $name );
		}
		rmdir( $directory );
	}
}
