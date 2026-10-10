<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Row-height cap for wrapped .xlsx cells. WordPress data includes
 * cells that are many printed inches tall (a 36-key serialized
 * option, a plugin's raw data dump, a long block list); with
 * wrap-text on, Excel/Calc auto-sizes the row to show it all and the
 * sheet becomes unusable. apply() estimates the wrapped line count
 * per cell and fixes the row height at MAX_PT only when content
 * would exceed it, so tall cells clip cleanly instead.
 *
 * @package MergeMultisite
 */
final class SpreadsheetRowHeight {

	/**
	 * Default cap, in points: 4 inches at 72pt/inch -- tall enough to
	 * scan, short enough that a blob can't own the viewport.
	 */
	public const DEFAULT_MAX_PT = 288.0;

	/**
	 * Points per wrapped line at the 12pt report font (a little
	 * over 1em of slack).
	 */
	private const PT_PER_LINE = 14.0;

	/**
	 * Fix a data row's height at $maxPt when any of its cells would
	 * wrap past it. $row is keyed by column key; $columns lists the
	 * keys in sheet order; $widths is column key => character width
	 * (as set via ColumnDimension::setWidth).
	 *
	 * @param array<string, int|string> $row
	 * @param string[]                  $columns
	 * @param array<string, int|float>  $widths
	 */
	public static function apply( Worksheet $sheet, int $rowNumber, array $row, array $columns, array $widths, float $maxPt = self::DEFAULT_MAX_PT ): void {
		$estimatedLines = 1;
		foreach ( $columns as $key ) {
			$cell = (string) ( $row[ $key ] ?? '' );
			if ( $cell === '' ) {
				continue;
			}

			// Character-width columns hold roughly (width - padding)
			// chars per line; count each embedded newline too.
			$widthChars = max( 1, (int) floor( ( $widths[ $key ] ?? 40 ) - 2 ) );
			$lines      = 0;
			foreach ( explode( "\n", $cell ) as $line ) {
				$lines += max( 1, (int) ceil( strlen( $line ) / $widthChars ) );
			}
			$estimatedLines = max( $estimatedLines, $lines );
		}

		if ( $estimatedLines * self::PT_PER_LINE > $maxPt ) {
			$sheet->getRowDimension( $rowNumber )->setRowHeight( $maxPt );
		}
	}
}
