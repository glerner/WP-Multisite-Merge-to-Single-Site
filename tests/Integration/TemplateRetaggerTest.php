<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\TemplateRetagger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * TemplateRetagger against the SQLite fixture: plan() finds stale
 * theme-owned template rows, apply() retags them to the active
 * stylesheet inside one transaction.
 *
 * All fixture theme/post names are invented placeholders.
 *
 * @package MergeMultisite
 */
#[CoversClass( TemplateRetagger::class )]
final class TemplateRetaggerTest extends SqliteTestCase {

	private const BLOG_ID = 20;

	/**
	 * Seed blog 20: stylesheet "active-theme", one stale template, one
	 * stale part, one already-active row, dormant global styles.
	 *
	 * @return array{0: Connection, 1: array{staleTemplateId: int, stalePartId: int, staleTtId: int}}
	 */
	private function seed(): array {
		$pdo    = $this->newSqlitePdo( 'wp_', array( self::BLOG_ID ) );
		$source = $this->sqliteConnection( 'wp_', array( self::BLOG_ID ), '/tmp/uploads', null, $pdo );

		$posts    = 'wp_20_posts';
		$postMeta = 'wp_20_postmeta';
		$rels     = 'wp_20_term_relationships';
		$options  = 'wp_20_options';

		WpTestSchema::insertOption(
			$pdo,
			$options,
			array(
				'option_name'  => 'stylesheet',
				'option_value' => 'active-theme',
			)
		);

		$staleTermId = WpTestSchema::insertTerm(
			$pdo,
			'wp_20_terms',
			array(
				'name' => 'old-theme',
				'slug' => 'old-theme',
			)
		);
		$staleTtId   = WpTestSchema::insertTermTaxonomy(
			$pdo,
			'wp_20_term_taxonomy',
			array(
				'term_id'  => $staleTermId,
				'taxonomy' => 'wp_theme',
			)
		);

		$staleTemplateId = WpTestSchema::insertPost(
			$pdo,
			$posts,
			array(
				'post_type' => 'wp_template',
				'post_name' => 'old-theme//page',
			)
		);
		WpTestSchema::insertTermRelationship(
			$pdo,
			$rels,
			array(
				'object_id'        => $staleTemplateId,
				'term_taxonomy_id' => $staleTtId,
			)
		);

		$stalePartId = WpTestSchema::insertPost(
			$pdo,
			$posts,
			array(
				'post_type' => 'wp_template_part',
				'post_name' => 'old-theme//header',
			)
		);
		WpTestSchema::insertTermRelationship(
			$pdo,
			$rels,
			array(
				'object_id'        => $stalePartId,
				'term_taxonomy_id' => $staleTtId,
			)
		);

		// An active-theme "page" already exists, so retagging the stale
		// one would produce a duplicate slug -> CONFIRMED PRESENT.
		WpTestSchema::insertPost(
			$pdo,
			$posts,
			array(
				'post_type' => 'wp_template',
				'post_name' => 'active-theme//page',
			)
		);

		// A page that explicitly picked the stale template.
		$pageId = WpTestSchema::insertPost(
			$pdo,
			$posts,
			array( 'post_type' => 'page' )
		);
		WpTestSchema::insertPostMeta(
			$pdo,
			$postMeta,
			array(
				'post_id'    => $pageId,
				'meta_key'   => '_wp_page_template',
				'meta_value' => 'old-theme//page',
			)
		);

		// Dormant + active global styles.
		WpTestSchema::insertPost(
			$pdo,
			$posts,
			array(
				'post_type' => 'wp_global_styles',
				'post_name' => 'wp-global-styles-old-theme',
			)
		);
		WpTestSchema::insertPost(
			$pdo,
			$posts,
			array(
				'post_type' => 'wp_global_styles',
				'post_name' => 'wp-global-styles-active-theme',
			)
		);

		return array(
			$source,
			array(
				'staleTemplateId' => $staleTemplateId,
				'stalePartId'     => $stalePartId,
				'staleTtId'       => $staleTtId,
			),
		);
	}

	public function testPlanFindsStaleRowsAndDormantGlobalStyles(): void {
		[ $source, $ids ] = $this->seed();
		$plan             = ( new TemplateRetagger( $source, self::BLOG_ID ) )->plan();

		self::assertSame( 'active-theme', $plan['active'] );
		self::assertCount( 2, $plan['operations'] );

		$byName = array_column( $plan['operations'], null, 'oldName' );

		$pageOp = $byName['old-theme//page'];
		self::assertSame( $ids['staleTemplateId'], $pageOp['id'] );
		self::assertSame( 'page', $pageOp['slug'] );
		self::assertSame( 'active-theme//page', $pageOp['newName'] );
		self::assertTrue( $pageOp['activeExists'] );
		self::assertSame( 1, $pageOp['metaRefs'] );

		$headerOp = $byName['old-theme//header'];
		self::assertSame( $ids['stalePartId'], $headerOp['id'] );
		self::assertSame( 'active-theme//header', $headerOp['newName'] );
		self::assertFalse( $headerOp['activeExists'] );
		self::assertSame( 0, $headerOp['metaRefs'] );

		self::assertCount( 1, $plan['dormantGlobalStyles'] );
		self::assertSame( 'wp-global-styles-old-theme', $plan['dormantGlobalStyles'][0]['name'] );
	}

	public function testApplyRenamesRetagsAndRewritesMeta(): void {
		[ $source, $ids ] = $this->seed();
		$retagger         = new TemplateRetagger( $source, self::BLOG_ID );

		$retagger->apply( $retagger->plan()['operations'] );

		$renamed = $source->fetchAll(
			"SELECT ID, post_name FROM wp_20_posts WHERE post_type IN ('wp_template','wp_template_part') ORDER BY ID"
		);
		self::assertSame(
			array( 'active-theme//page', 'active-theme//header', 'active-theme//page' ),
			array_column( $renamed, 'post_name' )
		);

		// The active wp_theme term was created on demand and is now the
		// only theme relationship on both retagged rows.
		$activeTtId = (int) $source->fetchScalar(
			"SELECT tt.term_taxonomy_id FROM wp_20_term_taxonomy tt JOIN wp_20_terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'wp_theme' AND t.name = 'active-theme'"
		);
		self::assertGreaterThan( 0, $activeTtId );

		$rels = $source->fetchAll(
			'SELECT object_id, term_taxonomy_id FROM wp_20_term_relationships ORDER BY object_id'
		);
		self::assertSame(
			array(
				array(
					'object_id'        => $ids['staleTemplateId'],
					'term_taxonomy_id' => $activeTtId,
				),
				array(
					'object_id'        => $ids['stalePartId'],
					'term_taxonomy_id' => $activeTtId,
				),
			),
			$rels
		);

		$meta = $source->fetchScalar(
			"SELECT meta_value FROM wp_20_postmeta WHERE meta_key = '_wp_page_template'"
		);
		self::assertSame( 'active-theme//page', $meta );

		// Dormant global styles are reported but never retagged.
		$dormant = $source->fetchScalar(
			"SELECT post_name FROM wp_20_posts WHERE post_type = 'wp_global_styles' AND post_name LIKE '%old-theme%'"
		);
		self::assertSame( 'wp-global-styles-old-theme', $dormant );
	}
}
