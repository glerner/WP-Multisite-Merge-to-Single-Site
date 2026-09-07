<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\TermMergeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( TermMergeResolver::class )]
final class TermMergeResolverTest extends TestCase {

	public function testMostUsedVariantWinsAsCanonicalLabel(): void {
		$resolver = new TermMergeResolver();

		$groups = $resolver->resolve(
			array(
				array(
					'taxonomy'    => 'category',
					'label'       => 'php',
					'usage_count' => 2,
					'site_id'     => 1,
				),
				array(
					'taxonomy'    => 'category',
					'label'       => 'PHP',
					'usage_count' => 10,
					'site_id'     => 2,
				),
			)
		);

		$group = $groups['category|php'];

		self::assertTrue( $group->hasCaseCollision() );
		self::assertSame( 'PHP', $group->canonicalLabel );
		self::assertSame( array( 'php', 'PHP' ), $group->variantLabels );
	}

	public function testTiesAreBrokenByLowestSiteId(): void {
		$resolver = new TermMergeResolver();

		$groups = $resolver->resolve(
			array(
				array(
					'taxonomy'    => 'post_tag',
					'label'       => 'MSR',
					'usage_count' => 5,
					'site_id'     => 3,
				),
				array(
					'taxonomy'    => 'post_tag',
					'label'       => 'msr',
					'usage_count' => 5,
					'site_id'     => 1,
				),
			)
		);

		$group = $groups['post_tag|msr'];

		self::assertSame( 'msr', $group->canonicalLabel );
	}

	public function testNoCollisionWhenOnlyOneVariantExists(): void {
		$resolver = new TermMergeResolver();

		$groups = $resolver->resolve(
			array(
				array(
					'taxonomy'    => 'category',
					'label'       => 'News',
					'usage_count' => 4,
					'site_id'     => 1,
				),
				array(
					'taxonomy'    => 'category',
					'label'       => 'News',
					'usage_count' => 1,
					'site_id'     => 2,
				),
			)
		);

		$group = $groups['category|news'];

		self::assertFalse( $group->hasCaseCollision() );
		self::assertSame( 'News', $group->canonicalLabel );
	}

	public function testDifferentTaxonomiesAreNotMergedTogether(): void {
		$resolver = new TermMergeResolver();

		$groups = $resolver->resolve(
			array(
				array(
					'taxonomy'    => 'category',
					'label'       => 'News',
					'usage_count' => 4,
					'site_id'     => 1,
				),
				array(
					'taxonomy'    => 'post_tag',
					'label'       => 'News',
					'usage_count' => 1,
					'site_id'     => 2,
				),
			)
		);

		self::assertCount( 2, $groups );
		self::assertArrayHasKey( 'category|news', $groups );
		self::assertArrayHasKey( 'post_tag|news', $groups );
	}
}
