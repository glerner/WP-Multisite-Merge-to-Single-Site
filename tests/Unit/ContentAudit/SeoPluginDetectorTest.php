<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\SeoPluginDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SeoPluginDetector::class )]
final class SeoPluginDetectorTest extends TestCase {

	/**
	 * A built-in meta prefix maps to its plugin label.
	 */
	public function testDetectsBuiltInMetaPrefix(): void {
		$detector = new SeoPluginDetector();

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'about',
			postTitle: 'About',
			content: '',
			meta: array(
				'_yoast_wpseo_title' => array( 'About us' ),
			)
		);

		self::assertSame( array( 'Yoast SEO' ), $detector->detect( $post ) );
	}

	/**
	 * Detector_extras 'meta_prefixes' teaches the detector a plugin it
	 * doesn't know without a source edit.
	 */
	public function testExtrasMetaPrefixAddsNewPlugin(): void {
		$detector = new SeoPluginDetector(
			array( 'meta_prefixes' => array( '_myseo_' => 'My SEO Plugin' ) )
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'about',
			postTitle: 'About',
			content: '',
			meta: array(
				'_myseo_title' => array( 'About us' ),
			)
		);

		self::assertSame( array( 'My SEO Plugin' ), $detector->detect( $post ) );
	}

	/**
	 * An extras prefix colliding with a built-in relabels it rather
	 * than reporting both names.
	 */
	public function testExtrasPrefixRelabelsBuiltIn(): void {
		$detector = new SeoPluginDetector(
			array( 'meta_prefixes' => array( '_sq_' => 'Squirrly (legacy install)' ) )
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'about',
			postTitle: 'About',
			content: '',
			meta: array(
				'_sq_keyword' => array( 'k' ),
			)
		);

		self::assertSame( array( 'Squirrly (legacy install)' ), $detector->detect( $post ) );
	}

	/**
	 * No SEO meta keys means no findings.
	 */
	public function testReturnsEmptyWhenNoSeoMetaPresent(): void {
		$detector = new SeoPluginDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'about', postTitle: 'About', content: '', meta: array( 'other_key' => array( 'v' ) ) );

		self::assertSame( array(), $detector->detect( $post ) );
	}
}
