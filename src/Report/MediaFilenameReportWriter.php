<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use MergeMultisite\Audit\AuditFinding;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the media-filename findings (problematic characters +
 * suggested safe names + alt/caption/title/where-used context) as a
 * spreadsheet, reusing the integrity report's .xlsx workbook by
 * appending a "media-filenames" tab -- the same workbook that carries
 * the "divergent-options" tab -- so everything stays in one file.
 *
 * Falls back to a standalone CSV when ext-zip is missing or no xlsx
 * exists yet, so a reviewable table always lands.
 *
 * @package MergeMultisite
 */
final class MediaFilenameReportWriter {

	/**
	 * @param AuditFinding[] $findings media-filenames.* findings.
	 * @param string|null    $existingXlsx Absolute path of the integrity .xlsx to append a tab to (may be null).
	 *
	 * @return string Absolute path of the written file (.xlsx or .csv fallback).
	 */
	public function write( array $findings, string $outputDirectory, string $baseName, ?string $existingXlsx = null ): string {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		$rows = $this->toRows( $findings );

		if ( extension_loaded( 'zip' ) ) {
			$path = $existingXlsx ?? ( $outputDirectory . '/' . $baseName . '.xlsx' );
			$this->appendSheet( $rows, $path );

			return $path;
		}

		$csvPath = $outputDirectory . '/' . $baseName . '-media-filenames.csv';
		file_put_contents( $csvPath, $this->toCsv( $rows ) );

		return $csvPath;
	}

	/**
	 * Flatten findings into spreadsheet rows.
	 *
	 * @param AuditFinding[] $findings
	 *
	 * @return array<int, array<string, string>>
	 */
	public function toRows( array $findings ): array {
		$rows = array();
		foreach ( $findings as $finding ) {
			$context = $finding->context;

			$rows[] = array(
				'site_id'         => (string) ( $context['blog_id'] ?? '' ),
				'attachment_id'   => (string) ( $context['post_id'] ?? '' ),
				'file'            => (string) ( $context['file'] ?? '' ),
				'issues'          => implode(
					'; ',
					array_map(
						static fn ( array $issue ): string => $issue['detail'],
						(array) ( $context['issues'] ?? array() )
					)
				),
				'suggested_name'  => (string) ( $context['suggested_name'] ?? '' ),
				'suggested_from'  => (string) ( $context['suggested_from'] ?? '' ),
				'alt_text'        => (string) ( $context['alt_text'] ?? '' ),
				'caption'         => (string) ( $context['caption'] ?? '' ),
				'attachment_title' => (string) ( $context['title'] ?? '' ),
				'used_in'         => implode(
					'; ',
					array_map(
						static fn ( array $usage ): string => sprintf( '%s (%s, post %d)', $usage['post_title'], $usage['post_type'], $usage['post_id'] ),
						(array) ( $context['used_in'] ?? array() )
					)
				),
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, string>> $rows
	 */
	public function toCsv( array $rows ): string {
		$handle = fopen( 'php://temp', 'w+' );

		fputcsv( $handle, self::columns(), ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ), ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv === false ? '' : $csv;
	}

	/**
	 * Append the media-filenames sheet to an existing workbook, or
	 * create a new one when $path does not exist yet.
	 *
	 * @param array<int, array<string, string>> $rows
	 */
	public function appendSheet( array $rows, string $path ): void {
		$spreadsheet = is_file( $path )
			? IOFactory::load( $path )
			: new Spreadsheet();

		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle( 'media-filenames' );

		$widths = array(
			'site_id'          => 8,
			'attachment_id'    => 14,
			'file'             => 70,
			'issues'           => 50,
			'suggested_name'   => 60,
			'suggested_from'   => 14,
			'alt_text'         => 40,
			'caption'          => 40,
			'attachment_title' => 40,
			'used_in'          => 50,
		);

		$columns = self::columns();
		foreach ( $columns as $index => $key ) {
			$letter = Coordinate::stringFromColumnIndex( $index + 1 );
			$sheet->setCellValue( $letter . '1', $key );
			$sheet->getColumnDimension( $letter )->setWidth( $widths[ $key ] ?? 40 );
		}

		foreach ( $rows as $rowIndex => $row ) {
			foreach ( $columns as $index => $key ) {
				$letter = Coordinate::stringFromColumnIndex( $index + 1 );
				$sheet->setCellValue( $letter . ( $rowIndex + 2 ), $row[ $key ] ?? '' );
			}

			SpreadsheetRowHeight::apply( $sheet, $rowIndex + 2, $row, $columns, $widths );
		}

		$lastColumn = Coordinate::stringFromColumnIndex( count( $columns ) );
		$lastRow = count( $rows ) + 1;
		$wholeRange = 'A1:' . $lastColumn . $lastRow;

		$sheet->getStyle( 'A1:' . $lastColumn . '1' )->getFont()->setBold( true );
		$sheet->getStyle( $wholeRange )->getAlignment()
			->setWrapText( true )
			->setVertical( Alignment::VERTICAL_TOP );
		$sheet->freezePane( 'A2' );
		$sheet->setAutoFilter( $wholeRange );

		( new Xlsx( $spreadsheet ) )->save( $path );
		$spreadsheet->disconnectWorksheets();
	}

	/**
	 * The media-filenames columns, in order.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array(
			'site_id',
			'attachment_id',
			'file',
			'issues',
			'suggested_name',
			'suggested_from',
			'alt_text',
			'caption',
			'attachment_title',
			'used_in',
		);
	}
}
