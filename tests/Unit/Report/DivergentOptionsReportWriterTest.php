<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Report\DivergentOptionsReportWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MergeMultisite\Report\DivergentOptionsReportWriter
 */
final class DivergentOptionsReportWriterTest extends TestCase {

	public function testToRowsWritesOneRowPerOptionValuePair(): void {
		$writer = new DivergentOptionsReportWriter();

		$rows = $writer->toRows(
			array(
				'aioseop_options' => array(
					'a:2:{long}'  => array( 35 ),
					'a:2:{other}' => array( 1, 7 ),
				),
				'comment_order' => array(
					'asc'  => array( 1 ),
					'desc' => array( 35 ),
				),
			)
		);

		self::assertCount( 4, $rows );
		self::assertSame(
			array(
				'option_name' => 'aioseop_options',
				'value'       => 'a:2:{long}',
				'site_ids'    => '35',
			),
			$rows[0]
		);
		self::assertSame( '1, 7', $rows[1]['site_ids'] );
		self::assertSame( 'comment_order', $rows[2]['option_name'] );
	}

	public function testToCsvSharesRowsAndQuotesOnlyWhenNeeded(): void {
		$writer = new DivergentOptionsReportWriter();

		$csv = $writer->toCsv(
			array(
				'some_option' => array(
					'serialized "value", with comma' => array( 2 ),
				),
			)
		);

		self::assertStringStartsWith( "option_name,value,site_ids\n", $csv );
		self::assertStringContainsString(
			'some_option,"serialized ""value"", with comma",2',
			$csv
		);
	}

	public function testWriteCreatesSpreadsheetFile(): void {
		$writer = new DivergentOptionsReportWriter();
		$dir    = sys_get_temp_dir() . '/divergent-report-' . uniqid( '', true );

		try {
			$path = $writer->write(
				array( 'opt' => array( 'v1' => array( 1 ) ) ),
				$dir,
				'out'
			);

			// .xlsx normally, .csv when ext-zip is missing.
			self::assertMatchesRegularExpression( '/\.(xlsx|csv)$/', $path );
			self::assertFileExists( $path );
		} finally {
			if ( isset( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
	}
}
