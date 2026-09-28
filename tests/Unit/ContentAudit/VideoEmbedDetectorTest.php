<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\VideoEmbedDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( VideoEmbedDetector::class )]
final class VideoEmbedDetectorTest extends TestCase {

	public function testDetectsYouTubeEmbeds(): void {
		$detector = new VideoEmbedDetector();

		$post1 = $this->makePost( '<!-- wp:embed {"url":"https://www.youtube.com/embed/dQw4w9WgXcQ"} -->' );
		self::assertSame( array( 'YouTube' ), $detector->detect( $post1 ) );

		$post2 = $this->makePost( '<!-- wp:core-embed/youtube {"url":"https://youtu.be/dQw4w9WgXcQ"} -->' );
		self::assertSame( array( 'YouTube' ), $detector->detect( $post2 ) );

		$post3 = $this->makePost( '<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>' );
		self::assertSame( array( 'YouTube' ), $detector->detect( $post3 ) );
	}

	public function testDetectsVimeoEmbeds(): void {
		$detector = new VideoEmbedDetector();

		$post1 = $this->makePost( '<!-- wp:embed {"url":"https://vimeo.com/12345678"} -->' );
		self::assertSame( array( 'Vimeo' ), $detector->detect( $post1 ) );

		$post2 = $this->makePost( '<iframe src="https://player.vimeo.com/video/12345678"></iframe>' );
		self::assertSame( array( 'Vimeo' ), $detector->detect( $post2 ) );
	}

	public function testDetectsSelfHostedVideo(): void {
		$detector = new VideoEmbedDetector();

		$post = $this->makePost( '<video controls src="video.mp4"></video>' );
		self::assertSame( array( 'Self-hosted <video>' ), $detector->detect( $post ) );
	}

	private function makePost( string $content ): ScannedPost {
		return new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'post',
			postStatus: 'publish',
			slug: 'test-post',
			postTitle: 'Test Post',
			content: $content,
			meta: array()
		);
	}
}
