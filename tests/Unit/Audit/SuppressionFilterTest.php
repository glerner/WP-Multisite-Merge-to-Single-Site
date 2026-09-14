<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Audit;

use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Audit\SuppressionFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SuppressionFilter::class )]
final class SuppressionFilterTest extends TestCase {

	public function testEmptyRulesKeepEverything(): void {
		$findings = array( $this->finding( 'a.b' ), $this->finding( 'c.d' ) );

		self::assertSame( $findings, SuppressionFilter::apply( $findings, array() ) );
	}

	public function testExactCheckNameMatch(): void {
		$findings = array( $this->finding( 'a.b' ), $this->finding( 'a.bc' ) );

		$kept = SuppressionFilter::apply( $findings, array( array( 'check' => 'a.b' ) ) );

		self::assertCount( 2, $kept ); // kept finding + suppression summary
		self::assertSame( 'a.bc', $kept[0]->checkName );
	}

	public function testTrailingStarIsPrefixMatch(): void {
		$findings = array(
			$this->finding( 'plugin-data.no-rule' ),
			$this->finding( 'plugin-data.orphaned-data' ),
			$this->finding( 'other.check' ),
		);

		$kept = SuppressionFilter::apply( $findings, array( array( 'check' => 'plugin-data.*' ) ) );

		self::assertCount( 2, $kept );
		self::assertSame( 'other.check', $kept[0]->checkName );
	}

	public function testContextCriterionNarrowsTheMatch(): void {
		$findings = array(
			$this->finding( 'x.y', array( 'plugin' => 'woocommerce' ) ),
			$this->finding( 'x.y', array( 'plugin' => 'wordfence' ) ),
		);

		$kept = SuppressionFilter::apply(
			$findings,
			array(
				array(
					'check'  => 'x.y',
					'plugin' => 'woocommerce',
				),
			)
		);

		self::assertCount( 2, $kept );
		self::assertSame( 'wordfence', $kept[0]->context['plugin'] );
	}

	public function testContextCriterionMatchesInsideListValues(): void {
		$findings = array(
			$this->finding( 'x.y', array( 'installed_on' => array( 1, 20, 33 ) ) ),
			$this->finding( 'x.y', array( 'installed_on' => array( 5 ) ) ),
		);

		$kept = SuppressionFilter::apply(
			$findings,
			array(
				array(
					'check'        => 'x.y',
					'installed_on' => 20,
				),
			)
		);

		self::assertCount( 2, $kept );
		self::assertSame( array( 5 ), $kept[0]->context['installed_on'] );
	}

	public function testSummaryFindingRecordsTheSuppressedCount(): void {
		$findings = array( $this->finding( 'a.b' ), $this->finding( 'a.b' ), $this->finding( 'a.b' ) );

		$kept = SuppressionFilter::apply( $findings, array( array( 'check' => 'a.b' ) ) );

		self::assertCount( 1, $kept );
		self::assertSame( 'audit.suppressed', $kept[0]->checkName );
		self::assertSame( AuditFinding::SEVERITY_INFO, $kept[0]->severity );
		self::assertSame( 3, $kept[0]->context['suppressed_count'] );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function finding( string $checkName, array $context = array() ): AuditFinding {
		return AuditFinding::info( $checkName, 'message', $context );
	}
}
