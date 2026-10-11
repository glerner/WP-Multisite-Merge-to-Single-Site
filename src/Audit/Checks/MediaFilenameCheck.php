<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\MediaFilenameSuggester;

/**
 * Flags attachment filenames whose characters are problematic -- in
 * URLs (spaces, non-ASCII like "DALL·E-...", long names), in browsers,
 * or on Windows filesystems (< > : " / \ | ? *, trailing dots/spaces,
 * reserved device names). Every finding carries a suggested safe
 * replacement name plus the attachment's alt text, caption, and title
 * and where it is used as a featured image, so the "media-filenames"
 * tab of the integrity .xlsx is a reviewable rename worksheet.
 *
 * Renames are never applied by the tool (a rename must also update
 * _wp_attached_file, _wp_attachment_metadata, and content references --
 * that is a media-rename plugin's job); this check only surfaces the
 * candidates (PLAN.md §7.2).
 *
 * Two-phase query: only the filenames are fetched and filtered first;
 * the post context (title/caption/alt) is loaded for flagged
 * attachments only, so clean media never drags its full text through
 * the report.
 *
 * @package MergeMultisite
 */
final class MediaFilenameCheck implements AuditCheckInterface {

	/**
	 * Cap on how many posts using an attachment are listed per row.
	 */
	private const MAX_USED_IN = 10;

	private readonly MediaFilenameSuggester $suggester;

	public function __construct( ?MediaFilenameSuggester $suggester = null ) {
		$this->suggester = $suggester ?? new MediaFilenameSuggester();
	}

	public function name(): string {
		return 'media-filenames';
	}

	public function description(): string {
		return 'Media filenames with characters that are problematic in URLs, browsers, or Windows filesystems; each finding suggests a safe replacement name (renaming itself is left to a media-rename plugin).';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );
			$postMetaTable = $source->siteTable( 'postmeta', $site->blogId );

			// Phase 1: fetch ONLY the filenames (post_id => relative
			// file) and filter for problems in PHP. Clean attachments --
			// normally the vast majority -- never pull their
			// post_title/post_content/alt rows.
			$rows = $source->fetchAll(
				"SELECT pm.post_id, pm.meta_value AS relative_file
                FROM {$postMetaTable} pm
                INNER JOIN {$postsTable} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                WHERE pm.meta_key = '_wp_attached_file'"
			);

			$flagged = array(); // post_id => relative file.
			foreach ( $rows as $row ) {
				$relativeFile = (string) $row['relative_file'];
				if ( $relativeFile === '' ) {
					continue;
				}
				if ( $this->suggester->problems( $relativeFile ) === array() ) {
					continue;
				}
				$flagged[ (int) $row['post_id'] ] = $relativeFile;
			}

			if ( $flagged === array() ) {
				continue;
			}

			// Phase 2: context (title/caption/alt) for the flagged
			// attachments only, chunked to stay under MySQL's 65535
			// parameter limit.
			$contextByPost = $this->fetchAttachmentContext( $source, $postsTable, $postMetaTable, array_keys( $flagged ) );

			foreach ( $flagged as $postId => $relativeFile ) {
				$context = $contextByPost[ $postId ] ?? array(
					'post_title' => '',
					'caption' => '',
					'alt_text' => '',
				);

				$problems = $this->suggester->problems( $relativeFile );
				$suggestion = $this->suggester->suggest( $relativeFile, $context['alt_text'], $context['caption'], $context['post_title'] );

				$findings[] = AuditFinding::warning(
					$this->name() . '.problematic-filename',
					sprintf(
						'Attachment %d (site %d): filename "%s" %s; suggested safe name: "%s"',
						$postId,
						$site->blogId,
						$relativeFile,
						implode( '; ', array_map( static fn ( array $p ): string => $p['detail'], $problems ) ),
						$suggestion['basename']
					),
					array(
						'blog_id' => $site->blogId,
						'post_id' => $postId,
						'file' => $relativeFile,
						'issues' => $problems,
						'suggested_name' => $suggestion['basename'],
						'suggested_from' => $suggestion['from'],
						'alt_text' => $context['alt_text'],
						'caption' => $context['caption'],
						'title' => $context['post_title'],
						'used_in' => $this->usedAsFeaturedImage( $source, $postsTable, $postMetaTable, $postId ),
					)
				);
			}
		}

		return $findings;
	}

	/**
	 * Title, caption, and alt text for a set of attachment IDs --
	 * the full text of every clean attachment is never loaded, only
	 * the flagged ones (Phase 2 of run()).
	 *
	 * @param int[] $postIds
	 *
	 * @return array<int, array{post_title: string, caption: string, alt_text: string}>
	 */
	private function fetchAttachmentContext( Connection $source, string $postsTable, string $postMetaTable, array $postIds ): array {
		$byPost = array();

		foreach ( array_chunk( $postIds, 5000 ) as $chunk ) {
			$placeholders = array();
			$params = array();
			foreach ( $chunk as $index => $postId ) {
				$key = 'post_id_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $postId;
			}

			$rows = $source->fetchAll(
				"SELECT p.ID, p.post_title, p.post_content AS caption,
                        alt.meta_value AS alt_text
                FROM {$postsTable} p
                LEFT JOIN {$postMetaTable} alt
                    ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
                WHERE p.ID IN (" . implode( ', ', $placeholders ) . ')',
				$params
			);

			foreach ( $rows as $row ) {
				$byPost[ (int) $row['ID'] ] = array(
					'post_title' => (string) $row['post_title'],
					'caption' => (string) $row['caption'],
					'alt_text' => (string) $row['alt_text'],
				);
			}
		}

		return $byPost;
	}

	/**
	 * Posts that use this attachment as their featured image
	 * (`_thumbnail_id`), newest first, capped for display.
	 *
	 * @return array<int, array{post_id: int, post_type: string, post_title: string}>
	 */
	private function usedAsFeaturedImage( Connection $source, string $postsTable, string $postMetaTable, int $attachmentId ): array {
		$rows = $source->fetchAll(
			"SELECT p.ID, p.post_type, p.post_title
             FROM {$postMetaTable} pm
             INNER JOIN {$postsTable} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = :attachment_id
             ORDER BY p.post_date DESC
             LIMIT " . self::MAX_USED_IN,
			array( 'attachment_id' => (string) $attachmentId )
		);

		return array_map(
			static fn ( array $row ): array => array(
				'post_id' => (int) $row['ID'],
				'post_type' => (string) $row['post_type'],
				'post_title' => (string) $row['post_title'],
			),
			$rows
		);
	}
}
