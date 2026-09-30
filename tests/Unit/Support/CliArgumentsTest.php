<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Support;

use MergeMultisite\Support\CliArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( CliArguments::class )]
final class CliArgumentsTest extends TestCase {

	public function testParsesKeyEqualsValueForm(): void {
		$args = new CliArguments( array( 'script.php', '--site=5', '--name=test' ) );

		self::assertTrue( $args->has( 'site' ) );
		self::assertSame( '5', $args->get( 'site' ) );
		self::assertSame( 5, $args->getInt( 'site' ) );
		self::assertSame( 'test', $args->get( 'name' ) );
	}

	public function testParsesKeySpaceValueForm(): void {
		$args = new CliArguments( array( 'script.php', '--site', '12' ) );

		self::assertTrue( $args->has( 'site' ) );
		self::assertSame( '12', $args->get( 'site' ) );
		self::assertSame( 12, $args->getInt( 'site' ) );
	}

	public function testParsesStandaloneFlag(): void {
		$args = new CliArguments( array( 'script.php', '--dry-run' ) );

		self::assertTrue( $args->has( 'dry-run' ) );
		self::assertSame( '1', $args->get( 'dry-run' ) );
	}

	public function testBooleanFlagsDoNotSwallowNextPositionalArg(): void {
		$args = new CliArguments(
			array( 'script.php', '--dry-run', 'positional-file.txt' ),
			array( 'dry-run' )
		);

		self::assertTrue( $args->has( 'dry-run' ) );
		self::assertSame( array( 'positional-file.txt' ), $args->positional() );
	}

	public function testCollectsMultiplePositionalArguments(): void {
		$args = new CliArguments( array( 'script.php', 'cmd', '--site=2', 'arg2' ) );

		self::assertSame( array( 'cmd', 'arg2' ), $args->positional() );
		self::assertSame( '2', $args->get( 'site' ) );
	}

	public function testDefaultsWhenKeyNotFound(): void {
		$args = new CliArguments( array( 'script.php' ) );

		self::assertFalse( $args->has( 'missing' ) );
		self::assertNull( $args->get( 'missing' ) );
		self::assertSame( 'default', $args->get( 'missing', 'default' ) );
		self::assertSame( 42, $args->getInt( 'missing', 42 ) );
	}
}
