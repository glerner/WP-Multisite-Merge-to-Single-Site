<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\TermMigrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( TermMigrator::class )]
final class TermMigratorTest extends TestCase {

	public function testSlugifyConvertsTitleToCleanSlug(): void {
		self::assertSame( 'website-tech', TermMigrator::slugify( 'Website Tech' ) );
		self::assertSame( 'wp-news-updates', TermMigrator::slugify( 'WP News & Updates' ) );
		self::assertSame( 'hello-world', TermMigrator::slugify( '  Hello, World!  ' ) );
		self::assertSame( 'category-123', TermMigrator::slugify( 'Category 123' ) );
		self::assertSame( 'mixed-casing-test', TermMigrator::slugify( 'MiXeD-CaSiNg_TeSt' ) );
	}

	public function testSlugifyHandlesMultipleHyphensAndUnderscores(): void {
		self::assertSame( 'test-slug', TermMigrator::slugify( 'test---slug' ) );
		self::assertSame( 'test-slug', TermMigrator::slugify( 'test_slug' ) );
	}
}
