<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\PostMigrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure destination-slug logic of PostMigrator: contact-page
 * canonicalization and WP-style per-type uniqueness. The DB-backed
 * half lives in tests/Integration/PostMigratorTest.
 *
 * @package MergeMultisite
 */
#[CoversClass( PostMigrator::class )]
final class PostMigratorTest extends TestCase {

	private const CONTACT_PATHS = array( '/contact/', '/contact-me/' );

	/**
	 * @param array<string, array<string, int>> $slugUse
	 * @param array<string, mixed>              $report
	 */
	private function slug( string $sourceSlug, string $postType, array &$slugUse, array &$report ): string {
		$migrator = new PostMigrator();

		return $migrator->destinationSlug( $sourceSlug, self::CONTACT_PATHS, 7, 1, $postType, $slugUse, $report );
	}

	public function testUnchangedSlugPassesThrough(): void {
		$slugUse = array();
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( 'about', $this->slug( 'about', 'page', $slugUse, $report ) );
		self::assertSame( array(), $report['slug_renames'] );
		self::assertSame( array(), $report['contact_canonicalized'] );
	}

	public function testEmptySlugStaysEmptyAndIsNotDeduped(): void {
		$slugUse = array();
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( '', $this->slug( '', 'page', $slugUse, $report ) );
	}

	public function testContactVariantCanonicalizesToContact(): void {
		$slugUse = array();
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( 'contact', $this->slug( 'contact-me', 'page', $slugUse, $report ) );

		self::assertCount( 1, $report['contact_canonicalized'] );
		self::assertSame( 'contact-me', $report['contact_canonicalized'][0]['old_slug'] );
		self::assertSame( 'contact', $report['contact_canonicalized'][0]['new_slug'] );
	}

	public function testCanonicalizationRespectsConfiguredVariantList(): void {
		$slugUse = array();
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		// 'get-in-touch' is contact-like (contains "contact"? no — it
		// doesn't) but more importantly it is NOT in the configured
		// variant list, so it passes through untouched. Only slugs on
		// the confirmed list canonicalize.
		self::assertSame( 'get-in-touch', $this->slug( 'get-in-touch', 'page', $slugUse, $report ) );
		self::assertSame( array(), $report['contact_canonicalized'] );
	}

	public function testDuplicateSlugWithinPostTypeGetsNumberedSuffix(): void {
		$slugUse = array( 'page' => array( 'about' => 1 ) );
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( 'about-2', $this->slug( 'about', 'page', $slugUse, $report ) );

		self::assertCount( 1, $report['slug_renames'] );
		self::assertSame( 'about', $report['slug_renames'][0]['old_slug'] );
		self::assertSame( 'about-2', $report['slug_renames'][0]['new_slug'] );
	}

	public function testSameSlugInDifferentPostTypeDoesNotConflict(): void {
		$slugUse = array( 'page' => array( 'about' => 1 ) );
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( 'about', $this->slug( 'about', 'post', $slugUse, $report ) );
	}

	public function testCanonicalizedSlugStillDedupesAgainstExistingContact(): void {
		$slugUse = array( 'page' => array( 'contact' => 1 ) );
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( 'contact-2', $this->slug( 'contact-me', 'page', $slugUse, $report ) );

		// Still recorded as a canonicalization (the -2 is the dedupe,
		// not a plain rename).
		self::assertCount( 1, $report['contact_canonicalized'] );
		self::assertSame( 'contact-2', $report['contact_canonicalized'][0]['new_slug'] );
	}

	public function testSlugUseAccumulatesAcrossCalls(): void {
		$slugUse = array();
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		self::assertSame( 'news', $this->slug( 'news', 'post', $slugUse, $report ) );
		self::assertSame( 'news-2', $this->slug( 'news', 'post', $slugUse, $report ) );
		self::assertSame( 'news-3', $this->slug( 'news', 'post', $slugUse, $report ) );
	}

	public function testCanonicalSlugItselfIsANoOp(): void {
		$slugUse = array();
		$report = array(
		'slug_renames' => array(),
		'contact_canonicalized' => array(),
		);

		// 'contact' is in the configured variant list; canonicalizing it
		// yields 'contact' unchanged (no rename, no -2).
		self::assertSame( 'contact', $this->slug( 'contact', 'page', $slugUse, $report ) );
		self::assertCount( 1, $report['contact_canonicalized'] );
	}
}
