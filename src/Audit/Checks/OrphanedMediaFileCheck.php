<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use FilesystemIterator;
use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\UploadsPathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The reverse of MediaFileCheck: finds files that exist on disk under
 * a site's uploads directory but have NO matching attachment post in
 * the database at all.
 *
 * Common, mostly-benign causes:
 *  - Files FTP'd/copied directly into uploads without ever being
 *    imported into the Media Library (a well-known scenario -- some
 *    plugins exist specifically to "adopt" pre-existing files like
 *    this into proper attachment posts).
 *  - Old thumbnail sizes left behind after a theme/plugin's registered
 *    image sizes changed (regenerate-thumbnails-style situations).
 *  - Page-builder-generated cache files (e.g. Ultimate Addons for
 *    Gutenberg's per-post CSS/JS files, Elementor's CSS cache) that
 *    were never meant to be attachments in the first place.
 *  - Export/staging files from a migration plugin (e.g. Prime Mover)
 *    that happen to live under uploads/.
 *
 * Reported as informational, not an error -- unlike MediaFileCheck's
 * "missing file" (which means content will visibly break), an
 * orphaned file on disk with no attachment record is invisible to end
 * users. It's still useful to know about before migrating, since the
 * migration only ever copies files that have an attachment record; an
 * orphaned file is otherwise silently left behind.
 *
 * @package MergeMultisite
 */
final class OrphanedMediaFileCheck implements AuditCheckInterface {

	/**
	 * Cap on how many example paths are listed per site in the
	 * human-readable message before collapsing to "and N more" --
	 * the full list is always in the JSON report's context.
	 */
	private const MAX_EXAMPLES_PER_SITE = 20;

	public function name(): string {
		return 'orphaned-media-files';
	}

	public function description(): string {
		return 'Files on disk under uploads with no attachment record -- often harmless (old thumbnails, plugin caches, FTP\'d files); migration leaves them behind.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$resolver = new UploadsPathResolver( $config->source->uploadsPath );
		$findings = array();

		foreach ( $sites as $site ) {
			$rootPath = $resolver->siteRootPath( $site->blogId );

			if ( $rootPath === null ) {
				continue;
			}

			$referenced = $this->referencedRelativePaths( $source, $site->blogId );
			$onDisk = $this->relativePathsOnDisk( $rootPath );

			$orphaned = array_values( array_diff( $onDisk, $referenced ) );
			sort( $orphaned );

			if ( $orphaned === array() ) {
				continue;
			}

			$examples = array_slice( $orphaned, 0, self::MAX_EXAMPLES_PER_SITE );
			$summary = implode( ', ', $examples );
			if ( count( $orphaned ) > self::MAX_EXAMPLES_PER_SITE ) {
				$summary .= sprintf( ' (and %d more)', count( $orphaned ) - self::MAX_EXAMPLES_PER_SITE );
			}

			$findings[] = AuditFinding::info(
				$this->name(),
				sprintf(
					'Site %d: %d file(s) with no attachment record: %s. Full list in JSON.',
					$site->blogId,
					count( $orphaned ),
					$summary
				),
				array(
					'blog_id' => $site->blogId,
					'orphaned_files' => $orphaned,
				)
			);
		}

		return $findings;
	}

	/**
	 * Every relative path (e.g. "2024/12/photo.jpg") that the
	 * database considers a real attachment for this site: the main
	 * file from `_wp_attached_file`, plus every generated thumbnail
	 * size and the original (pre-scaling) file recorded in
	 * `_wp_attachment_metadata`.
	 *
	 * @return string[]
	 */
	private function referencedRelativePaths( Connection $source, int $blogId ): array {
		$postsTable = $source->siteTable( 'posts', $blogId );
		$postMetaTable = $source->siteTable( 'postmeta', $blogId );

		$rows = $source->fetchAll(
			"SELECT pm1.meta_value AS relative_file, pm2.meta_value AS metadata
             FROM {$postsTable} p
             INNER JOIN {$postMetaTable} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '_wp_attached_file'
             LEFT JOIN {$postMetaTable} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_wp_attachment_metadata'
             WHERE p.post_type = 'attachment'"
		);

		$referenced = array();
		foreach ( $rows as $row ) {
			$relativeFile = (string) $row['relative_file'];
			$referenced[] = $relativeFile;

			$directory = dirname( $relativeFile );
			$directory = $directory === '.' ? '' : $directory . '/';

			$metadata = is_string( $row['metadata'] ) ? @unserialize( $row['metadata'] ) : false;
			if ( ! is_array( $metadata ) ) {
				continue;
			}

			if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
				$referenced[] = $directory . $metadata['original_image'];
			}

			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size ) {
					if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
						$referenced[] = $directory . $size['file'];
					}
				}
			}
		}

		return array_values( array_unique( $referenced ) );
	}

	/**
	 * Every file actually present under a site's uploads root,
	 * relative to that root (e.g. "2024/12/photo.jpg").
	 *
	 * @return string[]
	 */
	private function relativePathsOnDisk( string $rootPath ): array {
		$paths = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $rootPath, FilesystemIterator::SKIP_DOTS )
		);

		$rootPath = rtrim( $rootPath, '/' );

		foreach ( $iterator as $fileInfo ) {
			if ( ! $fileInfo->isFile() ) {
				continue;
			}

			$paths[] = ltrim( substr( $fileInfo->getPathname(), strlen( $rootPath ) ), '/' );
		}

		return $paths;
	}
}
