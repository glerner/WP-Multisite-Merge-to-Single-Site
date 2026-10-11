<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Audit\Checks\MediaFilenameCheck;
use MergeMultisite\Migration\Site;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * MediaFilenameCheck against the SQLite fixture: a DALL·E-style
 * attachment (middot + timestamp noise) is flagged with a suggested
 * safe name and the alt/caption/title/where-used context; a clean
 * filename produces no finding.
 *
 * @package MergeMultisite
 */
#[CoversClass( MediaFilenameCheck::class )]
final class MediaFilenameCheckTest extends SqliteTestCase {

	public function testFlagsProblematicFilenameWithContext(): void {
		$source = $this->sqliteConnection( 'wp_', array( 1 ) );

		$attachmentId = WpTestSchema::insertPost(
			$source->pdo(),
			$source->siteTable( 'posts', 1 ),
			array(
				'post_title' => 'DALL·E futuristic design',
				'post_content' => 'A vibrant high-tech abstract.',
				'post_type' => 'attachment',
			)
		);
		WpTestSchema::insertPostMeta(
			$source->pdo(),
			$source->siteTable( 'postmeta', 1 ),
			array(
				'post_id' => $attachmentId,
				'meta_key' => '_wp_attached_file',
				'meta_value' => '2024/12/DALL·E-2024-12-19-22.39.42-A-futuristic-abstract-design--e1734673835640.webp',
			)
		);
		WpTestSchema::insertPostMeta(
			$source->pdo(),
			$source->siteTable( 'postmeta', 1 ),
			array(
				'post_id' => $attachmentId,
				'meta_key' => '_wp_attachment_image_alt',
				'meta_value' => 'Futuristic abstract design',
			)
		);
		$pageId = WpTestSchema::insertPost(
			$source->pdo(),
			$source->siteTable( 'posts', 1 ),
			array(
				'post_title' => 'Uses the image',
				'post_type' => 'page',
			)
		);
		WpTestSchema::insertPostMeta(
			$source->pdo(),
			$source->siteTable( 'postmeta', 1 ),
			array(
				'post_id' => $pageId,
				'meta_key' => '_thumbnail_id',
				'meta_value' => (string) $attachmentId,
			)
		);

		$findings = ( new MediaFilenameCheck() )->run(
			$source,
			$this->testConfig(),
			array( new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ) )
		);

		self::assertCount( 1, $findings );
		$finding = $findings[0];

		self::assertSame( 'media-filenames.problematic-filename', $finding->checkName );
		self::assertSame( 'warning', $finding->severity );
		self::assertSame( 1, $finding->context['blog_id'] );
		self::assertSame( $attachmentId, $finding->context['post_id'] );
		self::assertSame( 'Futuristic abstract design', $finding->context['alt_text'] );
		self::assertSame( 'A vibrant high-tech abstract.', $finding->context['caption'] );
		self::assertSame( 'DALL·E futuristic design', $finding->context['title'] );
		self::assertCount( 1, $finding->context['used_in'] );
		self::assertSame( 'Uses the image', $finding->context['used_in'][0]['post_title'] );
		self::assertSame( 'page', $finding->context['used_in'][0]['post_type'] );

		// The filename's descriptive core ("A-futuristic-abstract-design")
		// survives noise stripping, so the suggestion is filename-derived
		// even though alt text exists.
		self::assertSame( 'filename', $finding->context['suggested_from'] );
		self::assertStringStartsWith( 'dall-e-a-futuristic-abstract-design', (string) $finding->context['suggested_name'] );
		self::assertStringEndsWith( '.webp', (string) $finding->context['suggested_name'] );
	}

	public function testCleanFilenamesProduceNoFindings(): void {
		$source = $this->sqliteConnection( 'wp_', array( 1 ) );

		$attachmentId = WpTestSchema::insertPost(
			$source->pdo(),
			$source->siteTable( 'posts', 1 ),
			array( 'post_type' => 'attachment' )
		);
		WpTestSchema::insertPostMeta(
			$source->pdo(),
			$source->siteTable( 'postmeta', 1 ),
			array(
				'post_id' => $attachmentId,
				'meta_key' => '_wp_attached_file',
				'meta_value' => '2024/12/hello-world-150x150.png',
			)
		);

		$findings = ( new MediaFilenameCheck() )->run(
			$source,
			$this->testConfig(),
			array( new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ) )
		);

		self::assertSame( array(), $findings );
	}
}
