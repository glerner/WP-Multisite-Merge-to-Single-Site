<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\GuidGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( GuidGenerator::class )]
final class GuidGeneratorTest extends TestCase {

	public function testForPostUsesQueryFormWithNewId(): void {
		self::assertSame(
			'https://merged.example.com/?p=123',
			GuidGenerator::forPost( 'https://merged.example.com', 123 )
		);
		self::assertSame(
			'https://merged.example.com/?p=7',
			GuidGenerator::forPost( 'https://merged.example.com/', 7 )
		);
	}

	public function testForAttachmentUsesUploadsUrl(): void {
		self::assertSame(
			'https://merged.example.com/wp-content/uploads/2024/01/logo_site7.png',
			GuidGenerator::forAttachment( 'https://merged.example.com/', '2024/01/logo_site7.png' )
		);
	}
}
