<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Destination;

use InvalidArgumentException;
use MergeMultisite\Destination\AdminIdRenumberer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( AdminIdRenumberer::class )]
final class AdminIdRenumbererTest extends TestCase {

	public function testPickRandomIdIsWithinDefaultRange(): void {
		$renumberer = new AdminIdRenumberer();

		for ( $i = 0; $i < 50; $i++ ) {
			$id = $renumberer->pickRandomId();
			self::assertGreaterThanOrEqual( 6, $id );
			self::assertLessThanOrEqual( 105, $id );
		}
	}

	public function testPickRandomIdNeverReturnsOldId(): void {
		$renumberer = new AdminIdRenumberer();

		// Force the range down to a single possible value so we can
		// prove the "never equal to oldId" retry loop actually works,
		// by making oldId the only value that range would ever produce.
		for ( $i = 0; $i < 20; $i++ ) {
			$id = $renumberer->pickRandomId( oldId: 6, rangeMin: 1, rangeMax: 2, offset: 5 );
			self::assertNotSame( 6, $id );
			self::assertSame( 7, $id );
		}
	}

	public function testPickRandomIdRejectsInvalidRange(): void {
		$renumberer = new AdminIdRenumberer();

		$this->expectException( InvalidArgumentException::class );

		$renumberer->pickRandomId( rangeMin: 100, rangeMax: 1 );
	}

	public function testBuildStatementsProducesExpectedUpdatesInOrder(): void {
		$renumberer = new AdminIdRenumberer();

		$statements = $renumberer->buildStatements( 1, 42, 'wp_' );

		self::assertSame(
			array(
				'UPDATE wp_users SET ID = 42 WHERE ID = 1',
				'UPDATE wp_usermeta SET user_id = 42 WHERE user_id = 1',
				'UPDATE wp_posts SET post_author = 42 WHERE post_author = 1',
				'UPDATE wp_comments SET user_id = 42 WHERE user_id = 1',
				'ALTER TABLE wp_users AUTO_INCREMENT = 43',
			),
			$statements
		);
	}

	public function testBuildStatementsUsesConfiguredTablePrefix(): void {
		$renumberer = new AdminIdRenumberer();

		$statements = $renumberer->buildStatements( 1, 42, 'wp3_' );

		self::assertStringStartsWith( 'UPDATE wp3_users', $statements[0] );
	}

	public function testBuildStatementsRejectsEqualOldAndNewId(): void {
		$renumberer = new AdminIdRenumberer();

		$this->expectException( InvalidArgumentException::class );

		$renumberer->buildStatements( 1, 1, 'wp_' );
	}
}
