<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Support;

use MergeMultisite\Support\FileHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( FileHasher::class )]
final class FileHasherTest extends TestCase {

	private string $tempDir;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/merge-multisite-hasher-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tempDir );
	}

	protected function tearDown(): void {
		$files = glob( $this->tempDir . '/*' );
		array_map( 'unlink', $files === false ? array() : $files );
		rmdir( $this->tempDir );
	}

	public function testIdenticalContentProducesEqualFingerprints(): void {
		$pathA = $this->tempDir . '/a.png';
		$pathB = $this->tempDir . '/b.png';
		file_put_contents( $pathA, 'identical-bytes' );
		file_put_contents( $pathB, 'identical-bytes' );

		$hasher = new FileHasher();

		self::assertTrue( $hasher->areIdentical( $pathA, $pathB ) );
	}

	public function testDifferentContentProducesDifferentFingerprints(): void {
		$pathA = $this->tempDir . '/a.png';
		$pathB = $this->tempDir . '/b.png';
		file_put_contents( $pathA, 'content-one' );
		file_put_contents( $pathB, 'content-two-different-length' );

		$hasher = new FileHasher();

		self::assertFalse( $hasher->areIdentical( $pathA, $pathB ) );
	}

	public function testSameSizeDifferentContentIsNotConfusedForIdentical(): void {
		$pathA = $this->tempDir . '/a.png';
		$pathB = $this->tempDir . '/b.png';
		file_put_contents( $pathA, 'aaaaaaaaaa' );
		file_put_contents( $pathB, 'bbbbbbbbbb' );

		$hasher = new FileHasher();

		$fingerprintA = $hasher->fingerprint( $pathA );
		$fingerprintB = $hasher->fingerprint( $pathB );

		self::assertSame( $fingerprintA->size, $fingerprintB->size );
		self::assertFalse( $fingerprintA->equals( $fingerprintB ) );
	}
}
