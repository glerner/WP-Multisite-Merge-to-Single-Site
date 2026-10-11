<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\MediaFilenameSuggester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MediaFilenameSuggester: the DALL·E-style real-world case (date +
 * timestamp noise, unix-ms suffix, non-ASCII middot), generic-name
 * fallback to alt/caption/title, clean names left alone, and the
 * Windows/URL problem catalog.
 *
 * @package MergeMultisite
 */
#[CoversClass( MediaFilenameSuggester::class )]
final class MediaFilenameSuggesterTest extends TestCase {

	public function testDalleStyleFilenameGetsSafeShortName(): void {
		$filename = 'DALL·E-2024-12-19-22.39.42-A-futuristic-abstract-design-with-vibrant-high-tech-visuals-meant-for-a-WordPress-featured-image.-The-image-has-a-widescreen-aspect-ratio-about-4--e1734673835640.webp';

		$result = ( new MediaFilenameSuggester() )->suggest( $filename, '', '', '' );

		self::assertTrue( $result['changed'] );
		self::assertSame( 'filename', $result['from'] );
		// Date prefix and -e{unix} suffix stripped, middot replaced,
		// lowercased, capped at a word boundary.
		self::assertStringStartsWith( 'dall-e-a-futuristic-abstract-design-with-vibrant-high-tech-visuals', $result['basename'] );
		self::assertStringEndsWith( '.webp', $result['basename'] );
		self::assertLessThanOrEqual( 90, mb_strlen( pathinfo( $result['basename'], PATHINFO_FILENAME ) ) );
		self::assertDoesNotMatchRegularExpression( '/[^\x00-\x7F]/', $result['basename'] );
		self::assertStringNotContainsString( '2024-12-19', $result['basename'] );
		self::assertStringNotContainsString( 'e1734673835640', $result['basename'] );
	}

	public function testGenericNameFallsBackToAltText(): void {
		$result = ( new MediaFilenameSuggester() )->suggest(
			'image-2024.png',
			'Futuristic abstract design',
			'',
			''
		);

		self::assertSame( 'futuristic-abstract-design.png', $result['basename'] );
		self::assertSame( 'alt', $result['from'] );
	}

	public function testAltEmptyFallsBackToCaptionThenTitle(): void {
		$suggester = new MediaFilenameSuggester();

		self::assertSame(
			'caption-says-this.png',
			$suggester->suggest( 'img_1234.png', '', 'Caption says this', 'A title' )['basename']
		);
		self::assertSame(
			'title-says-this.png',
			$suggester->suggest( 'img_1234.png', '', '', 'Title says this' )['basename']
		);
	}

	public function testCleanNameIsUnchanged(): void {
		$result = ( new MediaFilenameSuggester() )->suggest( 'hello-world.png', '', '', '' );

		self::assertFalse( $result['changed'] );
		self::assertSame( 'hello-world.png', $result['basename'] );
	}

	public function testProblemsCataloguesFilenameIssues(): void {
		$suggester = new MediaFilenameSuggester();

		$types = array_map(
			static fn ( array $problem ): string => $problem['type'],
			$suggester->problems( 'DALL·E-a<b>c "d".webp' )
		);
		self::assertContains( 'non-ascii', $types );
		self::assertContains( 'windows-illegal', $types );

		self::assertSame(
			array( 'space' ),
			array_map( static fn ( array $p ): string => $p['type'], $suggester->problems( 'my file.png' ) )
		);
		self::assertSame(
			array( 'reserved-name' ),
			array_map( static fn ( array $p ): string => $p['type'], $suggester->problems( 'CON.png' ) )
		);
		self::assertSame(
			array( 'trailing-dot-space' ),
			array_map( static fn ( array $p ): string => $p['type'], $suggester->problems( 'photo..png' ) )
		);
		self::assertSame(
			array( 'too-long' ),
			array_map( static fn ( array $p ): string => $p['type'], $suggester->problems( str_repeat( 'a', 160 ) . '.png' ) )
		);
	}

	public function testCleanFilenameHasNoProblems(): void {
		self::assertSame( array(), ( new MediaFilenameSuggester() )->problems( '2024/12/hello-world-150x150.png' ) );
	}
}
