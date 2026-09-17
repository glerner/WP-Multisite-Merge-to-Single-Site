<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use MergeMultisite\ContentAudit\ContentAuditRow;
use MergeMultisite\ContentAudit\RawContentExtractor;
use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Writes the "pages that need manual review after migration" CSV:
 * one row per page that carries a page-builder / slideshow /
 * complex-plugin footprint, with the original URL, a best-guess
 * destination URL (config.destination_url + post slug -- see
 * PLAN.md; the authoritative old->new mapping comes from the
 * redirect-map phase later), the detected plugin labels, and raw
 * data dumps per plugin so a reviewer can rebuild without opening
 * the original site's editor.
 *
 * @package MergeMultisite
 */
final class NeedsReviewReportWriter {

	private const MAX_RAW_ENTRIES = 8;

	/**
	 * @param array<int, array{row: ContentAuditRow, post: ScannedPost}> $details
	 *
	 * @return string Absolute path of the written CSV.
	 */
	public function write( array $details, string $destinationUrl, string $outputDirectory, string $baseName ): string {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		$csvPath = $outputDirectory . '/' . $baseName . '.csv';
		file_put_contents( $csvPath, $this->toCsv( $details, $destinationUrl ) );

		return $csvPath;
	}

	/**
	 * @param array<int, array{row: ContentAuditRow, post: ScannedPost}> $details
	 */
	public function toCsv( array $details, string $destinationUrl ): string {
		$handle = fopen( 'php://temp', 'w+' );

		fputcsv(
			$handle,
			array(
				'blog_id',
				'original_url',
				'destination_url (guess)',
				'post_id',
				'post_type',
				'post_status',
				'post_title',
				'plugins_that_need_checking',
				'raw_data',
			),
			',',
			'"',
			'\\'
		);

		$extractor = new RawContentExtractor();

		foreach ( $details as $entry ) {
			/** @var ContentAuditRow $row */
			$row = $entry['row'];
			/** @var ScannedPost $post */
			$post = $entry['post'];

			// Only pages flagged as needing review.
			$needsReview = $row->categoryFindings['needs_review'] ?? array();
			if ( $needsReview === array() ) {
				continue;
			}

			// Revisions are never migrated and would only duplicate the
			// live post's review entry -- skip them.
			if ( $row->postType === 'revision' ) {
				continue;
			}

			$originalUrl = 'https://' . $row->domain . '/' . trim( $row->slug, '/' ) . '/';
			$destinationGuess = rtrim( $destinationUrl, '/' ) . '/' . trim( $row->slug, '/' ) . '/';

			$rawEntries = $extractor->extract( $post, $needsReview );
			$rawLines = array();
			foreach ( array_slice( $rawEntries, 0, self::MAX_RAW_ENTRIES, true ) as $label => $raw ) {
				$rawLines[] = $label . ': ' . $raw;
			}

			fputcsv(
				$handle,
				array(
					(string) $row->blogId,
					$originalUrl,
					$destinationGuess,
					(string) $row->postId,
					$row->postType,
					$row->postStatus,
					$row->postTitle,
					implode( '; ', $needsReview ),
					implode( "\n", $rawLines ),
				),
				',',
				'"',
				'\\'
			);
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv === false ? '' : $csv;
	}
}
