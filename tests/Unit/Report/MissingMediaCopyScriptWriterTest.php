<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Migration\UploadsPathResolver;
use MergeMultisite\Report\MissingMediaCopyScriptWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( MissingMediaCopyScriptWriter::class )]
final class MissingMediaCopyScriptWriterTest extends TestCase {

	private string $tempDir;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/merge-multisite-media-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tempDir . '/search/blogs.dir/60/files/2013/08', 0775, true );
		mkdir( $this->tempDir . '/search/other/2013/08', 0775, true );
		mkdir( $this->tempDir . '/uploads', 0775, true );
	}

	protected function tearDown(): void {
		exec( sprintf( 'rm -rf %s', escapeshellarg( $this->tempDir ) ) );
	}

	public function testGeneratesInstallCommandForFoundFile(): void {
		$found = $this->tempDir . '/search/blogs.dir/60/files/2013/08/pic.png';
		touch( $found );

		$script = ( new MissingMediaCopyScriptWriter() )->generate(
			// blogId 60 = source subsite ID (e.g. sites/60/ or blogs.dir/60/), postId 100 = attachment post ID.
			array( $this->missingFinding( relative: '2013/08/pic.png', blogId: 60, postId: 100 ) ),
			array( $this->tempDir . '/search' ),
			new UploadsPathResolver( $this->tempDir . '/uploads' )
		);

		self::assertNotNull( $script );
		self::assertStringContainsString( 'install -D -m 0644', $script );
		self::assertStringContainsString( "'" . $found . "'", $script );
		// Copied into the source uploads tree where the migrator looks.
		self::assertStringContainsString(
			"'" . $this->tempDir . "/uploads/sites/60/2013/08/pic.png'",
			$script
		);
	}

	public function testPrefersCandidateWhosePathEndsWithRelativePath(): void {
		touch( $this->tempDir . '/search/other/pic.png' ); // same basename, wrong folder
		$right = $this->tempDir . '/search/blogs.dir/60/files/2013/08/pic.png';
		touch( $right );

		$script = ( new MissingMediaCopyScriptWriter() )->generate(
			array( $this->missingFinding( relative: '2013/08/pic.png', blogId: 60, postId: 100 ) ),
			array( $this->tempDir . '/search' ),
			new UploadsPathResolver( $this->tempDir . '/uploads' )
		);

		self::assertNotNull( $script );
		self::assertStringContainsString( "'" . $right . "'", $script );
	}

	public function testMissingEverywhereGetsNotFoundComment(): void {
		$script = ( new MissingMediaCopyScriptWriter() )->generate(
			array( $this->missingFinding( relative: '2013/08/nope.png', blogId: 60, postId: 100 ) ),
			array( $this->tempDir . '/search' ),
			new UploadsPathResolver( $this->tempDir . '/uploads' )
		);

		self::assertNotNull( $script );
		self::assertStringContainsString( '# NOT FOUND: site 60, attachment 100: 2013/08/nope.png', $script );
	}

	public function testReturnsNullWhenNoMissingFindings(): void {
		$script = ( new MissingMediaCopyScriptWriter() )->generate(
			array( AuditFinding::info( 'other.check', 'unrelated' ) ),
			array( $this->tempDir . '/search' ),
			new UploadsPathResolver( $this->tempDir . '/uploads' )
		);

		self::assertNull( $script );
	}

	public function testReturnsNullWhenNoSearchPathsConfigured(): void {
		$script = ( new MissingMediaCopyScriptWriter() )->generate(
			array( $this->missingFinding( relative: '2013/08/pic.png', blogId: 60, postId: 100 ) ),
			array(),
			new UploadsPathResolver( $this->tempDir . '/uploads' )
		);

		self::assertNull( $script );
	}

	public function testAmbiguousCandidatesEmitComment(): void {
		// Two different folders, neither matches 2013/08/pic.png relative path.
		touch( $this->tempDir . '/search/other/pic.png' );
		mkdir( $this->tempDir . '/search/extra', 0775, true );
		touch( $this->tempDir . '/search/extra/pic.png' );

		$script = ( new MissingMediaCopyScriptWriter() )->generate(
			array( $this->missingFinding( relative: '2013/08/pic.png', blogId: 60, postId: 100 ) ),
			array( $this->tempDir . '/search' ),
			new UploadsPathResolver( $this->tempDir . '/uploads' )
		);

		self::assertNotNull( $script );
		self::assertStringContainsString( '# AMBIGUOUS:', $script );
		self::assertStringContainsString( 'multiple candidates', $script );
	}

	/**
	 * Helper to create a missing-file finding fixture.
	 *
	 * @param string $relative Path stored in _wp_attached_file (e.g. 2013/08/pic.png).
	 * @param int    $blogId   Multisite subsite ID (e.g. 60 -> uploads/sites/60/).
	 * @param int    $postId   Attachment post ID in wp_{blogId}_posts.
	 */
	private function missingFinding( string $relative, int $blogId, int $postId ): AuditFinding {
		return AuditFinding::error(
			'media-files.missing-file',
			'missing',
			array(
				'relative_file' => $relative,
				'attachments'   => array(
					array(
						'blog_id' => $blogId,
						'post_id' => $postId,
					),
				),
			)
		);
	}
}
