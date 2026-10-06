<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the complete divergent-options detail as a spreadsheet: one
 * row per (option name, distinct value) pair with the sites holding
 * that value. The Markdown report caps how many options are shown
 * (DivergentSiteOptionCheck::MAX_FINDINGS); this workbook carries the
 * uncapped list so large divergences can be sorted/filtered in a
 * spreadsheet instead of paging through hundreds of findings.
 *
 * Produces integrity-{ts}.xlsx with a "divergent-options" tab when
 * ext-zip is available, falling back to a CSV of the same basename
 * (a spreadsheet always lands).
 *
 * @package MergeMultisite
 */
final class DivergentOptionsReportWriter {

	/**
	 * @param array<string, array<string, int[]>> $divergent option name => raw value => blog_ids.
	 *
	 * @return string Absolute path of the written file (.xlsx or .csv fallback).
	 */
	public function write( array $divergent, string $outputDirectory, string $baseName ): string {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		// The .xlsx needs ext-zip (xlsx is a zip of XML parts); fall
		// back to CSV when the extension is missing.
		if ( extension_loaded( 'zip' ) ) {
			$xlsxPath = $outputDirectory . '/' . $baseName . '.xlsx';
			$this->toXlsx( $divergent, $xlsxPath );

			return $xlsxPath;
		}

		$csvPath = $outputDirectory . '/' . $baseName . '.csv';
		file_put_contents( $csvPath, $this->toCsv( $divergent ) );

		return $csvPath;
	}

	/**
	 * @param array<string, array<string, int[]>> $divergent option name => raw value => blog_ids.
	 */
	public function toCsv( array $divergent ): string {
		$handle = fopen( 'php://temp', 'w+' );

		fputcsv( $handle, self::columns(), ',', '"', '\\' );

		foreach ( $this->toRows( $divergent ) as $row ) {
			fputcsv( $handle, array_values( $row ), ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv === false ? '' : $csv;
	}

	/**
	 * The divergent-options rows (one per distinct value) -- shared by
	 * the CSV fallback and the .xlsx "divergent-options" tab so both
	 * outputs stay identical.
	 *
	 * @param array<string, array<string, int[]>> $divergent option name => raw value => blog_ids.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function toRows( array $divergent ): array {
		$rows = array();

		foreach ( $divergent as $optionName => $valueGroups ) {
			foreach ( $valueGroups as $value => $blogIds ) {
				sort( $blogIds );
				$rows[] = array(
					'option_name' => $optionName,
					'value'       => (string) $value,
					'site_ids'    => implode( ', ', $blogIds ),
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, array<string, int[]>> $divergent option name => raw value => blog_ids.
	 */
	public function toXlsx( array $divergent, string $path ): void {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->getDefaultStyle()->getFont()
			->setName( 'Consolas' )
			->setSize( 12 );
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'divergent-options' );

		$widths = array(
			'option_name' => 40,
			'value'       => 80,
			'site_ids'    => 24,
		);

		$columns = self::columns();
		foreach ( $columns as $index => $key ) {
			$letter = Coordinate::stringFromColumnIndex( $index + 1 );
			$sheet->setCellValue( $letter . '1', $key );
			$sheet->getColumnDimension( $letter )->setWidth( $widths[ $key ] ?? 40 );
		}

		foreach ( $this->toRows( $divergent ) as $rowIndex => $row ) {
			foreach ( $columns as $index => $key ) {
				$letter = Coordinate::stringFromColumnIndex( $index + 1 );
				$sheet->setCellValue( $letter . ( $rowIndex + 2 ), $row[ $key ] ?? '' );
			}
		}

		$lastColumn = Coordinate::stringFromColumnIndex( count( $columns ) );
		$lastRow    = count( $this->toRows( $divergent ) ) + 1;
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
	 * The divergent-options columns, in order.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array(
			'option_name',
			'value',
			'site_ids',
		);
	}
}
