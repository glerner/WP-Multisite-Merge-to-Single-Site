<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\BlockAttributeRewriter;
use MergeMultisite\Migration\IdMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( BlockAttributeRewriter::class )]
final class BlockAttributeRewriterTest extends TestCase {

	public function testRewritesNavigationRefViaIdMap(): void {
		$idMap = new IdMap();
		$idMap->set( 'post', 3, 123, 456 );

		$content = '<!-- wp:navigation {"ref":123} /-->';

		self::assertSame(
			'<!-- wp:navigation {"ref":456} /-->',
			( new BlockAttributeRewriter() )->rewrite( $content, 3, $idMap )
		);
	}

	public function testRewritesReusableBlockRef(): void {
		$idMap = new IdMap();
		$idMap->set( 'post', 1, 10, 77 );

		$content = '<!-- wp:block {"ref":10} -->';

		self::assertSame(
			'<!-- wp:block {"ref":77} -->',
			( new BlockAttributeRewriter() )->rewrite( $content, 1, $idMap )
		);
	}

	public function testLeavesUnmappedRefsUntouched(): void {
		$idMap = new IdMap();

		$content = '<!-- wp:navigation {"ref":999} /-->';

		// No mapping recorded for ref 999 -- the attribute stays as-is
		// rather than being rewritten to something wrong.
		self::assertSame( $content, ( new BlockAttributeRewriter() )->rewrite( $content, 1, $idMap ) );
	}

	public function testLeavesNonRefAttributesAndPlainBlocksAlone(): void {
		$idMap = new IdMap();
		$idMap->set( 'post', 1, 123, 456 );

		$content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'
			. '<!-- wp:navigation {"ref":123,"overlayMenu":"mobile"} /-->';

		$rewritten = ( new BlockAttributeRewriter() )->rewrite( $content, 1, $idMap );

		self::assertStringContainsString( '<!-- wp:paragraph -->', $rewritten );
		self::assertStringContainsString( '{"ref":456,"overlayMenu":"mobile"}', $rewritten );
	}

	public function testUsesBlogIdScopedMappings(): void {
		// Site 2's post 123 is a different post than site 3's post 123.
		$idMap = new IdMap();
		$idMap->set( 'post', 2, 123, 200 );
		$idMap->set( 'post', 3, 123, 300 );

		$rewriter = new BlockAttributeRewriter();
		$content = '<!-- wp:navigation {"ref":123} /-->';

		self::assertStringContainsString( '"ref":200', $rewriter->rewrite( $content, 2, $idMap ) );
		self::assertStringContainsString( '"ref":300', $rewriter->rewrite( $content, 3, $idMap ) );
	}

	public function testAdditionalRefAttributesAreConfigurable(): void {
		$idMap = new IdMap();
		$idMap->set( 'term', 1, 5, 50 );

		$content = '<!-- wp:latest-posts {"ref":9,"categories":[{"id":5}]} -->';

		$rewritten = ( new BlockAttributeRewriter() )->rewrite( $content, 1, $idMap, array( 'categories' => 'term' ) );

		// Nested arrays of objects aren't ID lists -- only flat integer
		// values are remapped, so categories stays untouched.
		self::assertSame( $content, $rewritten );
	}

	public function testRemapsIdListAttributeValues(): void {
		$idMap = new IdMap();
		$idMap->set( 'attachment', 4, 501, 901 );
		$idMap->set( 'attachment', 4, 502, 902 );

		$content = '<!-- wp:gallery {"ids":[501,502]} /-->';

		self::assertSame(
			'<!-- wp:gallery {"ids":[901,902]} /-->',
			( new BlockAttributeRewriter() )->rewrite( $content, 4, $idMap, array( 'ids' => 'attachment' ) )
		);
	}

	public function testRemapsSingleIdAttribute(): void {
		$idMap = new IdMap();
		$idMap->set( 'attachment', 4, 501, 901 );

		$content = '<!-- wp:image {"id":501} /-->';

		self::assertSame(
			'<!-- wp:image {"id":901} /-->',
			( new BlockAttributeRewriter() )->rewrite( $content, 4, $idMap, array( 'id' => 'attachment' ) )
		);
	}

	public function testLeavesUnmappedListMembersAndNonNumericValuesAlone(): void {
		$idMap = new IdMap();
		$idMap->set( 'attachment', 4, 501, 901 );

		$content = '<!-- wp:gallery {"ids":[501,999],"linkTo":"media"} /-->';

		self::assertSame(
			'<!-- wp:gallery {"ids":[901,999],"linkTo":"media"} /-->',
			( new BlockAttributeRewriter() )->rewrite( $content, 4, $idMap, array( 'ids' => 'attachment' ) )
		);
	}
}
