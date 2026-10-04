<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\PostHierarchyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PostHierarchyResolver::class )]
final class PostHierarchyResolverTest extends TestCase {

	public function testNestedPagesProduceFullPath(): void {
		$resolver = PostHierarchyResolver::fromRows(
			array(
				array(
			'ID' => 10,
			'post_name' => 'about',
			'post_parent' => 0,
			'post_type' => 'page',
			),
				array(
			'ID' => 11,
			'post_name' => 'team',
			'post_parent' => 10,
			'post_type' => 'page',
			),
				array(
			'ID' => 12,
			'post_name' => 'alice',
			'post_parent' => 11,
			'post_type' => 'page',
			),
			)
		);

		self::assertSame( 'about/team/alice', $resolver->slugPath( 12 ) );
		self::assertSame( 'about/team', $resolver->slugPath( 11 ) );
		self::assertSame( 'about', $resolver->slugPath( 10 ) );
	}

	public function testDifferentTypeParentDoesNotNest(): void {
		// An attachment's post_parent is the post it was uploaded to --
		// different post type, so it must not join the URL path.
		$resolver = PostHierarchyResolver::fromRows(
			array(
				array(
			'ID' => 10,
			'post_name' => 'about',
			'post_parent' => 0,
			'post_type' => 'page',
			),
				array(
			'ID' => 20,
			'post_name' => 'photo',
			'post_parent' => 10,
			'post_type' => 'attachment',
			),
			)
		);

		self::assertSame( 'photo', $resolver->slugPath( 20 ) );
	}

	public function testMissingParentStopsAtKnownChain(): void {
		// Parent filtered out of the scanned set (e.g. excluded status):
		// the path includes whatever ancestors are known.
		$resolver = PostHierarchyResolver::fromRows(
			array(
				array(
			'ID' => 12,
			'post_name' => 'alice',
			'post_parent' => 11,
			'post_type' => 'page',
			),
			)
		);

		self::assertSame( 'alice', $resolver->slugPath( 12 ) );
	}

	public function testParentCycleTerminates(): void {
		$resolver = PostHierarchyResolver::fromRows(
			array(
				array(
			'ID' => 1,
			'post_name' => 'a',
			'post_parent' => 2,
			'post_type' => 'page',
			),
				array(
			'ID' => 2,
			'post_name' => 'b',
			'post_parent' => 1,
			'post_type' => 'page',
			),
			)
		);

		self::assertSame( 'b/a', $resolver->slugPath( 1 ) );
		self::assertSame( 'a/b', $resolver->slugPath( 2 ) );
	}

	public function testTopLevelAndUnknownPostsUseBareSlug(): void {
		$resolver = PostHierarchyResolver::fromRows(
			array(
				array(
			'ID' => 5,
			'post_name' => 'hello',
			'post_parent' => 0,
			'post_type' => 'post',
			),
			)
		);

		self::assertSame( 'hello', $resolver->slugPath( 5 ) );
		self::assertSame( '', $resolver->slugPath( 999 ) );
	}
}
