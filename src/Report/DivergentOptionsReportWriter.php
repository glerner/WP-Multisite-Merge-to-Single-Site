<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the complete divergent-options detail as a spreadsheet. The
 * Markdown report caps how many options are shown
 * (DivergentSiteOptionCheck::MAX_FINDINGS); this workbook carries the
 * uncapped list so large divergences can be sorted/filtered in a
 * spreadsheet instead of paging through hundreds of findings.
 *
 * Rows: scalar options emit one row per (option, distinct value).
 * Options whose values are all serialized arrays are exploded into
 * one row per DIVERGING sub-key -- a 36-key settings array that
 * differs only in `smtp_host` shows that key, not a 32-inch blob.
 * Rows are also height-capped via SpreadsheetRowHeight (4" default)
 * so a long value can't stretch a row beyond readability.
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
	 * The divergent-options rows -- shared by the CSV fallback and
	 * the .xlsx "divergent-options" tab so both outputs stay
	 * identical.
	 *
	 * @param array<string, array<string, int[]>> $divergent option name => raw value => blog_ids.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function toRows( array $divergent ): array {
		$rows = array();

		foreach ( $divergent as $optionName => $valueGroups ) {
			$decoded = array();
			$allArrays = $valueGroups !== array();
			foreach ( $valueGroups as $value => $blogIds ) {
				$decoded[ $value ] = self::maybeUnserialize( (string) $value );
				if ( ! is_array( $decoded[ $value ] ) ) {
					$allArrays = false;
				}
			}

			// Serialized-array options get the per-key view; scalar or
			// mixed options fall back to one row per whole value.
			if ( $allArrays ) {
				foreach ( $this->keyDivergenceRows( $optionName, $valueGroups, $decoded ) as $row ) {
					$rows[] = $row;
				}
				continue;
			}

			foreach ( $valueGroups as $value => $blogIds ) {
				sort( $blogIds );
				$rows[] = array(
					'option_name' => $optionName,
					'key'         => '',
					'value'       => (string) $value,
					'site_ids'    => implode( ', ', $blogIds ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Rows for one option whose distinct values are all serialized
	 * arrays: one row per top-level key whose value differs across
	 * sites (keys identical everywhere are skipped -- they are not
	 * why the option was flagged). A key missing on some sites shows
	 * '(absent)' for those sites.
	 *
	 * @param array<string, int[]>        $valueGroups raw value => blog_ids.
	 * @param array<string, array<mixed>> $decoded     raw value => unserialized array.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function keyDivergenceRows( string $optionName, array $valueGroups, array $decoded ): array {
		$keys = array();
		foreach ( $decoded as $subValues ) {
			foreach ( $subValues as $key => $unused ) {
				$keys[ (string) $key ] = true;
			}
		}

		$rows = array();
		foreach ( array_keys( $keys ) as $key ) {
			$byValue = array();
			foreach ( $valueGroups as $value => $blogIds ) {
				$rendered = array_key_exists( $key, $decoded[ $value ] )
					? self::renderValue( $decoded[ $value ][ $key ] )
					: '(absent)';
				foreach ( $blogIds as $blogId ) {
					$byValue[ $rendered ][] = $blogId;
				}
			}

			if ( count( $byValue ) < 2 ) {
				continue;
			}

			foreach ( $byValue as $rendered => $blogIds ) {
				$blogIds = array_values( array_unique( $blogIds ) );
				sort( $blogIds );
				$rows[] = array(
					'option_name' => $optionName,
					'key'         => $key,
					'value'       => $rendered,
					'site_ids'    => implode( ', ', $blogIds ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Unserializes for report display: returns the decoded value, or
	 * the original string when it is not serialized data. ('b:0;' is
	 * a legit serialized false, handled before unserialize's false
	 * return would misread it as "not serialized".)
	 */
	private static function maybeUnserialize( string $value ): mixed {
		if ( $value === 'b:0;' ) {
			return false;
		}
		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );

		return $decoded === false ? $value : $decoded;
	}

	/**
	 * One-line rendering for a sub-option value: scalars verbatim
	 * (bool as '0'/'1', matching wp_options storage), nested
	 * arrays/objects as compact JSON.
	 */
	private static function renderValue( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( $value === null ) {
			return '(null)';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return $json !== false ? $json : serialize( $value );
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
			'key'         => 30,
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

			SpreadsheetRowHeight::apply( $sheet, $rowIndex + 2, $row, $columns, $widths );
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
			'key',
			'value',
			'site_ids',
		);
	}
}
