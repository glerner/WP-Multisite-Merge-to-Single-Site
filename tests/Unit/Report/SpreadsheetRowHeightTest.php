<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Report\SpreadsheetRowHeight;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The .xlsx row-height cap: rows whose wrapped cells would exceed
 * 4" get a fixed height; ordinary rows are left auto-sized.
 */
#[CoversClass( SpreadsheetRowHeight::class )]
final class SpreadsheetRowHeightTest extends TestCase {

	public function testShortRowKeepsAutoHeight(): void {
		$sheet = ( new Spreadsheet() )->getActiveSheet();

		SpreadsheetRowHeight::apply(
			$sheet,
			2,
			array( 'value' => 'short string' ),
			array( 'value' ),
			array( 'value' => 80 )
		);

		// PhpSpreadsheet leaves unset row heights at -1 (auto).
		self::assertEquals( -1, $sheet->getRowDimension( 2 )->getRowHeight() );
	}

	public function testLongCellIsCappedAtMaxHeight(): void {
		$sheet = ( new Spreadsheet() )->getActiveSheet();

		// ~40 lines of text in an 80-char column: well over 288pt.
		SpreadsheetRowHeight::apply(
			$sheet,
			3,
			array( 'value' => str_repeat( 'x', 80 * 40 ) ),
			array( 'value' ),
			array( 'value' => 80 )
		);

		self::assertEquals( SpreadsheetRowHeight::DEFAULT_MAX_PT, $sheet->getRowDimension( 3 )->getRowHeight() );
	}

	/**
	 * Embedded newlines count as wrapped lines too (needs-review
	 * raw_data dumps are multi-line).
	 */
	public function testEmbeddedNewlinesContributeToHeight(): void {
		$sheet = ( new Spreadsheet() )->getActiveSheet();

		SpreadsheetRowHeight::apply(
			$sheet,
			4,
			array( 'value' => implode( "\n", array_fill( 0, 30, 'line' ) ) ),
			array( 'value' ),
			array( 'value' => 80 )
		);

		self::assertEquals( SpreadsheetRowHeight::DEFAULT_MAX_PT, $sheet->getRowDimension( 4 )->getRowHeight() );
	}
}
