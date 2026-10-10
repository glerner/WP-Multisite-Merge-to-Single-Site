<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Report\DivergentOptionsReportWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( DivergentOptionsReportWriter::class )]
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

		// 'a:2:{long}' etc. are not valid serialized data, so these
		// stay whole-value rows with an empty 'key' column.
		self::assertCount( 4, $rows );
		self::assertSame(
			array(
				'option_name' => 'aioseop_options',
				'key'         => '',
				'value'       => 'a:2:{long}',
				'site_ids'    => '35',
			),
			$rows[0]
		);
		self::assertSame( '1, 7', $rows[1]['site_ids'] );
		self::assertSame( 'comment_order', $rows[2]['option_name'] );
	}

	/**
	 * Options whose values are all serialized arrays explode into
	 * one row per DIVERGING top-level key -- keys identical on every
	 * site are skipped, and a key missing on some sites shows
	 * '(absent)' for them. This is what turns a 36-key settings blob
	 * into a reviewable "which setting actually differs" list.
	 */
	public function testSerializedArrayOptionsExplodePerDivergingKey(): void {
		$writer = new DivergentOptionsReportWriter();

		$siteA = serialize(
			array(
				'smtp_host' => 'mail-a.example.com',
				'smtp_port' => '25',
				'same_key'  => 'identical',
				'nested'    => array( 'x' => 1 ),
			)
		);
		$siteB = serialize(
			array(
				'smtp_host' => 'mail-b.example.com',
				'smtp_port' => '25',
				'same_key'  => 'identical',
				// 'nested' absent here.
			)
		);

		$rows = $writer->toRows(
			array(
				'MemberWingAdminOptions' => array(
					$siteA => array( 35 ),
					$siteB => array( 1, 7 ),
				),
			)
		);

		// smtp_port and same_key are identical across sites, so only
		// smtp_host (differs) and nested (absent on 1,7) emit rows.
		self::assertSame(
			array(
				array(
					'option_name' => 'MemberWingAdminOptions',
					'key'         => 'smtp_host',
					'value'       => 'mail-a.example.com',
					'site_ids'    => '35',
				),
				array(
					'option_name' => 'MemberWingAdminOptions',
					'key'         => 'smtp_host',
					'value'       => 'mail-b.example.com',
					'site_ids'    => '1, 7',
				),
				array(
					'option_name' => 'MemberWingAdminOptions',
					'key'         => 'nested',
					'value'       => '{"x":1}',
					'site_ids'    => '35',
				),
				array(
					'option_name' => 'MemberWingAdminOptions',
					'key'         => 'nested',
					'value'       => '(absent)',
					'site_ids'    => '1, 7',
				),
			),
			$rows
		);
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

		self::assertStringStartsWith( "option_name,key,value,site_ids\n", $csv );
		self::assertStringContainsString(
			'some_option,,"serialized ""value"", with comma",2',
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
