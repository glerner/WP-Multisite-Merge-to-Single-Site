<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\FormPluginDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass( FormPluginDetector::class )]
final class FormPluginDetectorTest extends TestCase {

	#[DataProvider( 'signatureProvider' )]
	public function testDetectsKnownFormPluginSignatures( string $content, string $expectedLabel ): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost( 1, 1, 'page', 'publish', 'contact', 'Contact', $content, array() );

		self::assertSame( array( $expectedLabel ), $detector->detect( $post ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function signatureProvider(): array {
		return array(
			'Contact Form 7 shortcode' => array( '[contact-form-7 id="1" title="Contact"]', 'Contact Form 7' ),
			'WPForms shortcode'        => array( '[wpforms id="42"]', 'WPForms' ),
			'WPForms block'            => array( '<!-- wp:wpforms/form-selector {"formId":"42"} /-->', 'WPForms' ),
			'Ninja Forms shortcode'    => array( '[ninja_form id=3]', 'Ninja Forms' ),
			'Gravity Forms shortcode'  => array( '[gravityform id="1"]', 'Gravity Forms' ),
			'Formidable shortcode'     => array( '[formidable id=2]', 'Formidable Forms' ),
			'WS Form shortcode'        => array( '[ws_form id="4"]', 'WS Form' ),
			'SureForms block'          => array( '<!-- wp:sureforms/form-selector {"id":1} /-->', 'SureForms' ),
			'Divi contact form'       => array( '[et_pb_contact_form][/et_pb_contact_form]', 'Divi Contact Form' ),
			'Divi email optin'        => array( '[et_pb_signup][/et_pb_signup]', 'Divi Email Optin' ),
			'Kadence advanced form'   => array( '<!-- wp:kadence/advancedform {"formID":1} /-->', 'Kadence Advanced Form' ),
			'Brevo shortcode'         => array( '[sibwp_form id="1"]', 'Brevo (Sendinblue)' ),
		);
	}

	public function testDetectsUnidentifiedRawHtmlFormWhenNoKnownSignatureMatches(): void {
		$detector = new FormPluginDetector();

		// e.g. HTML embed code pasted directly from Brevo's site, with
		// no shortcode/block wrapper at all.
		$post = new ScannedPost( 1, 1, 'page', 'publish', 'contact', 'Contact', '<form action="https://example.com/submit"><input type="email"></form>', array() );

		self::assertSame( array( 'Unidentified HTML form (pasted embed code, e.g. Brevo)' ), $detector->detect( $post ) );
	}

	public function testDoesNotReportUnidentifiedFormWhenAKnownPluginAlreadyMatched(): void {
		$detector = new FormPluginDetector();

		// Contact Form 7 itself renders a <form> tag, but since the
		// shortcode already identifies it, the generic fallback must
		// not also fire and duplicate/obscure that.
		$post = new ScannedPost( 1, 1, 'page', 'publish', 'contact', 'Contact', '[contact-form-7 id="1"]<form></form>', array() );

		self::assertSame( array( 'Contact Form 7' ), $detector->detect( $post ) );
	}

	public function testDetectsElementorProFormViaElementorDataMeta(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost(
			1,
			1,
			'page',
			'publish',
			'contact',
			'Contact',
			'',
			array( '_elementor_data' => array( '[{"widgetType":"form","settings":{}}]' ) )
		);

		self::assertSame( array( 'Elementor Pro Form' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoFormFound(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost( 1, 1, 'page', 'publish', 'about', 'About', '<p>No forms here.</p>', array() );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testCanDetectMultipleFormPluginsOnOnePage(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost( 1, 1, 'page', 'publish', 'contact', 'Contact', '[contact-form-7 id="1"] [wpforms id="2"]', array() );

		self::assertSame( array( 'Contact Form 7', 'WPForms' ), $detector->detect( $post ) );
	}
}
