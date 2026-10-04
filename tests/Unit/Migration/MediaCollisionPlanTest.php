<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\MediaCollisionPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( MediaCollisionPlan::class )]
final class MediaCollisionPlanTest extends TestCase {

	public function testUnrelatedFilesKeepTheirPaths(): void {
		$plan = new MediaCollisionPlan();

		$targets = $plan->resolve(
			array(
				array(
			'blog_id' => 3,
			'relative' => '2024/01/logo.png',
			),
				array(
			'blog_id' => 7,
			'relative' => '2024/02/logo.png',
			),
			),
			array( 'aaa', 'bbb' )
		);

		// Same basename but different destination directories -- no
		// collision on the destination, so no rename.
		self::assertSame( '2024/01/logo.png', $targets[0]['target'] );
		self::assertFalse( $targets[0]['renamed'] );
		self::assertSame( '2024/02/logo.png', $targets[1]['target'] );
		self::assertFalse( $targets[1]['renamed'] );
	}

	public function testSamePathIdenticalContentDedupsToOneTarget(): void {
		$plan = new MediaCollisionPlan();

		$targets = $plan->resolve(
			array(
				array(
			'blog_id' => 3,
			'relative' => '2024/01/logo.png',
			),
				array(
			'blog_id' => 7,
			'relative' => '2024/01/logo.png',
			),
			),
			array( 'same-hash', 'same-hash' )
		);

		self::assertSame( '2024/01/logo.png', $targets[0]['target'] );
		self::assertFalse( $targets[0]['renamed'] );
		self::assertSame( '2024/01/logo.png', $targets[1]['target'] );
		self::assertFalse( $targets[1]['renamed'] );
	}

	public function testSamePathDifferentContentRenamesEveryFile(): void {
		$plan = new MediaCollisionPlan();

		$targets = $plan->resolve(
			array(
				array(
			'blog_id' => 3,
			'relative' => '2024/01/logo.png',
			),
				array(
			'blog_id' => 7,
			'relative' => '2024/01/logo.png',
			),
				array(
			'blog_id' => 9,
			'relative' => '2024/01/logo.png',
			),
			),
			array( 'hash-a', 'hash-b', 'hash-a' )
		);

		// Every file in a mixed-content group is renamed, even the two
		// that share content -- an arbitrary "winner" keeping the plain
		// name would make the result order-dependent.
		self::assertSame( '2024/01/logo_site3.png', $targets[0]['target'] );
		self::assertTrue( $targets[0]['renamed'] );
		self::assertSame( '2024/01/logo_site7.png', $targets[1]['target'] );
		self::assertTrue( $targets[1]['renamed'] );
		self::assertSame( '2024/01/logo_site9.png', $targets[2]['target'] );
		self::assertTrue( $targets[2]['renamed'] );
	}

	public function testRenamedRelativePathKeepsDirectoryAndExtension(): void {
		self::assertSame( 'logo_site3.png', MediaCollisionPlan::renamedRelativePath( 'logo.png', 3 ) );
		self::assertSame( '2024/01/photo_site7.jpg', MediaCollisionPlan::renamedRelativePath( '2024/01/photo.jpg', 7 ) );
		self::assertSame( 'README_site2', MediaCollisionPlan::renamedRelativePath( 'README', 2 ) );
	}

	public function testRenamedVariantFilenameKeepsSizeSuffixAfterSiteMarker(): void {
		self::assertSame( 'logo_site7-150x150.png', MediaCollisionPlan::renamedVariantFilename( 'logo-150x150.png', 7 ) );
		self::assertSame( 'img-2_site3-1024x768.jpg', MediaCollisionPlan::renamedVariantFilename( 'img-2-1024x768.jpg', 3 ) );
		self::assertSame( 'doc_site4.pdf', MediaCollisionPlan::renamedVariantFilename( 'doc.pdf', 4 ) );
	}

	public function testGroupsByBasenameIsSortedAndIndexKeyed(): void {
		$plan = new MediaCollisionPlan();

		$groups = $plan->groupsByBasename(
			array(
				5 => array( 'path' => '/uploads/sites/3/2024/01/zeta.png' ),
				9 => array( 'path' => '/uploads/sites/7/2024/02/alpha.png' ),
				11 => array( 'path' => '/uploads/sites/9/2024/03/zeta.png' ),
			)
		);

		self::assertSame( array( 'alpha.png', 'zeta.png' ), array_keys( $groups ) );
		self::assertSame( array( 5, 11 ), array_keys( $groups['zeta.png'] ) );
	}

	public function testGroupsByFingerprintGroupsRegardlessOfName(): void {
		$plan = new MediaCollisionPlan();

		$groups = $plan->groupsByFingerprint(
			array(
				array( 'path' => '/a/one.png' ),
				array( 'path' => '/b/two.png' ),
				array( 'path' => '/c/three.png' ),
			),
			array( 'hash-x', 'hash-y', 'hash-x' )
		);

		self::assertSame( array( 0, 2 ), array_keys( $groups['hash-x'] ) );
		self::assertSame( array( 1 ), array_keys( $groups['hash-y'] ) );
	}
}
