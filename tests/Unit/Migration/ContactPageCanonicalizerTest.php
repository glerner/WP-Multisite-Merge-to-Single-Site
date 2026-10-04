<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\ContactPageCanonicalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ContactPageCanonicalizer::class )]
final class ContactPageCanonicalizerTest extends TestCase {

	public function testIsContactLikeMatchesSubstringsCaseInsensitively(): void {
		self::assertTrue( ContactPageCanonicalizer::isContactLike( 'contact' ) );
		self::assertTrue( ContactPageCanonicalizer::isContactLike( 'contact-me' ) );
		self::assertTrue( ContactPageCanonicalizer::isContactLike( 'Contact-Us' ) );
		self::assertFalse( ContactPageCanonicalizer::isContactLike( 'about' ) );
		self::assertFalse( ContactPageCanonicalizer::isContactLike( 'get-in-touch' ) );
	}

	public function testCandidateClauseProducesSqlLikeFragment(): void {
		self::assertSame(
			"post_name LIKE '%contact%'",
			ContactPageCanonicalizer::candidateClause()
		);
		self::assertSame(
			"guid LIKE '%contact%'",
			ContactPageCanonicalizer::candidateClause( 'guid' )
		);
	}

	public function testIsVariantMatchesConfiguredPathsAfterNormalization(): void {
		$variants = array( 'contact', '/contact-me/', 'contact-us' );

		self::assertTrue( ContactPageCanonicalizer::isVariant( 'contact', $variants ) );
		self::assertTrue( ContactPageCanonicalizer::isVariant( 'contact-me', $variants ) );
		self::assertTrue( ContactPageCanonicalizer::isVariant( '/contact-us/', $variants ) );
		self::assertFalse( ContactPageCanonicalizer::isVariant( 'get-in-touch', $variants ) );
		self::assertFalse( ContactPageCanonicalizer::isVariant( 'contact-form-v2', $variants ) );
	}

	public function testCanonicalPathOnlyForConfiguredVariants(): void {
		$variants = array( 'contact', 'contact-me' );

		self::assertSame( '/contact/', ContactPageCanonicalizer::canonicalPath( 'contact-me', $variants ) );
		self::assertSame( '/contact/', ContactPageCanonicalizer::canonicalPath( '/contact/', $variants ) );
		self::assertNull( ContactPageCanonicalizer::canonicalPath( 'contact-form', $variants ) );
	}
}
