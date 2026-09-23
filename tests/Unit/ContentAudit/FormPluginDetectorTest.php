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

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'contact', postTitle: 'Contact', content: $content, meta: array() );

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
		// no shortcode/block wrapper at all. The action target is
		// surfaced -- it usually identifies the actual processor.
		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'contact', postTitle: 'Contact', content: '<form action="https://example.com/submit"><input type="email"></form>', meta: array() );

		self::assertSame( array( 'HTML form (action: "https://example.com/submit")' ), $detector->detect( $post ) );
	}

	public function testLabelsKnownExternalProcessorByFormAction(): void {
		$detector = new FormPluginDetector();

		// Infinite Responder: a standalone pre-WordPress newsletter CGI
		// embedded as a pasted <form action="...infiniteresponder/s.php">.
		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'contact',
			postTitle: 'Contact',
			content: '<form action="http://example.com/infiniteresponder/s.php"><input type="email"></form>',
			meta: array()
		);

		self::assertSame(
			array( 'HTML form (Infinite Responder; action: "http://example.com/infiniteresponder/s.php")' ),
			$detector->detect( $post )
		);
	}

	public function testReportsSamePageAndMissingFormActions(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'contact',
			postTitle: 'Contact',
			content: '<form action="#"><input></form> <form><input></form> <form action=""><input></form>',
			meta: array()
		);

		self::assertSame(
			array( 'HTML form (action: (same page))', 'HTML form (action: (no action attribute))' ),
			$detector->detect( $post )
		);
	}

	public function testCoreSearchBlockFormIsNotReported(): void {
		$detector = new FormPluginDetector();

		// The core Search block renders a <form role="search"> -- WP's
		// own markup, not a form plugin or a pasted embed.
		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'contact',
			postTitle: 'Contact',
			content: '<form role="search" method="get" action="https://example.com/" class="wp-block-search"><input type="search" name="s"></form>',
			meta: array()
		);

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testDoesNotReportUnidentifiedFormWhenAKnownPluginAlreadyMatched(): void {
		$detector = new FormPluginDetector();

		// Contact Form 7 itself renders a <form> tag, but since the
		// shortcode already identifies it, the generic fallback must
		// not also fire and duplicate/obscure that.
		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'contact', postTitle: 'Contact', content: '[contact-form-7 id="1"]<form></form>', meta: array() );

		self::assertSame( array( 'Contact Form 7' ), $detector->detect( $post ) );
	}

	public function testDetectsElementorProFormViaElementorDataMeta(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'contact',
			postTitle: 'Contact',
			content: '',
			meta: array(
				'_elementor_data' => array( '[{"widgetType":"form","settings":{}}]' ),
			)
		);

		self::assertSame( array( 'Elementor Pro Form' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoFormFound(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'about', postTitle: 'About', content: '<p>No forms here.</p>', meta: array() );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testCanDetectMultipleFormPluginsOnOnePage(): void {
		$detector = new FormPluginDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'contact', postTitle: 'Contact', content: '[contact-form-7 id="1"] [wpforms id="2"]', meta: array() );

		self::assertSame( array( 'Contact Form 7', 'WPForms' ), $detector->detect( $post ) );
	}

	/**
	 * Detector_extras 'content_signatures' teaches the detector a form
	 * plugin it doesn't know, without a source edit.
	 */
	public function testExtrasContentSignatureAddsNewPlugin(): void {
		$detector = new FormPluginDetector(
			array( 'content_signatures' => array( 'My Form' => array( '\[myform\b' ) ) )
		);

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'contact', postTitle: 'Contact', content: '[myform id="9"]', meta: array() );

		self::assertSame( array( 'My Form' ), $detector->detect( $post ) );
	}

	/**
	 * Extra patterns under a built-in label APPEND: the plugin is
	 * still reported under its canonical name when only the extra
	 * signature matches.
	 */
	public function testExtrasPatternAppendsToBuiltInLabel(): void {
		$detector = new FormPluginDetector(
			array( 'content_signatures' => array( 'Contact Form 7' => array( 'wp:cf7fork\/' ) ) )
		);

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'contact', postTitle: 'Contact', content: '<!-- wp:cf7fork/form /-->', meta: array() );

		self::assertSame( array( 'Contact Form 7' ), $detector->detect( $post ) );
	}

	/**
	 * Detector_extras 'meta_signatures' adds a postmeta-based form
	 * signature (label => [meta_key, needle]).
	 */
	public function testExtrasMetaSignatureDetectsViaPostmeta(): void {
		$detector = new FormPluginDetector(
			array( 'meta_signatures' => array( 'My Builder Form' => array( '_my_builder', '"module":"form"' ) ) )
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'contact',
			postTitle: 'Contact',
			content: '',
			meta: array(
				'_my_builder' => array( '[{"module":"form"}]' ),
			)
		);

		self::assertSame( array( 'My Builder Form' ), $detector->detect( $post ) );
	}

	/**
	 * Detector_extras 'action_signatures' names a pasted-HTML form's
	 * external processor by its action URL.
	 */
	public function testExtrasActionSignatureLabelsExternalProcessor(): void {
		$detector = new FormPluginDetector(
			array( 'action_signatures' => array( 'My Mailer' => array( 'mymailer\.example\.com' ) ) )
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'contact',
			postTitle: 'Contact',
			content: '<form action="https://mymailer.example.com/sub"><input type="email"></form>',
			meta: array()
		);

		self::assertSame(
			array( 'HTML form (My Mailer; action: "https://mymailer.example.com/sub")' ),
			$detector->detect( $post )
		);
	}
}
