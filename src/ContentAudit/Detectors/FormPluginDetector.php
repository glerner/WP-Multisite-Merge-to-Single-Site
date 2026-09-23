<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects which contact-form plugin(s) appear on a post, via their
 * shortcode or block signature. Used to decide which duplicate form
 * plugin to standardize on (PLAN.md §8) -- e.g. consolidating onto
 * SureForms.
 *
 * Besides dedicated form plugins, several page builders/block
 * libraries ship their own form element (Elementor Pro, Divi, Kadence
 * Blocks). Those are detected too, via their own content/postmeta
 * signatures. This list is a best-effort starting point based on
 * known plugin signatures, not an exhaustive survey -- running
 * `site-audit.php` against real data (and cross-checking against
 * ShortcodeDetector's raw shortcode list, which catches anything not
 * listed here) is expected to surface additional signatures worth
 * adding over time.
 *
 * @package MergeMultisite
 */
final class FormPluginDetector implements ContentDetectorInterface {

	/**
	 * Map of plugin label => list of regex patterns (already anchored
	 * with delimiters omitted) matched against `post_content`.
	 *
	 * @var array<string, string[]>
	 */
	private const CONTENT_SIGNATURES = array(
		'Contact Form 7' => array( '\[contact-form-7\b', 'wp:contact-form-7\/' ),
		'WPForms' => array( '\[wpforms\b', 'wp:wpforms\/' ),
		'Ninja Forms' => array( '\[ninja_form\b', 'wp:ninja-forms\/' ),
		'Gravity Forms' => array( '\[gravityform\b', 'wp:gravityforms\/' ),
		'Formidable Forms' => array( '\[formidable\b', 'wp:formidable\/' ),
		'WS Form' => array( '\[ws_form\b', 'wp:ws-form\/' ),
		'SureForms' => array( '\[sureforms\b', 'wp:sureforms\/' ),
		// Divi's own contact-form and email-optin (signup) modules.
		'Divi Contact Form' => array( '\[et_pb_contact_form\b' ),
		'Divi Email Optin' => array( '\[et_pb_signup\b' ),
		// Kadence Blocks' own Advanced Form block.
		'Kadence Advanced Form' => array( 'wp:kadence\/advancedform\b', 'wp:kadence\/form\b' ),
		// Brevo (formerly Sendinblue; plugin slug historically "mailin").
		// Signature is a best guess across plugin versions/renames --
		// confirm/correct once seen in real data (see README.md).
		'Brevo (Sendinblue)' => array( '\[sibwp_form\b', '\[sendinblue_form\b', '\[brevo_form\b', 'wp:sib-wordpress\/' ),
	);

	/**
	 * Page builders that store their layout as JSON in a single
	 * postmeta value rather than in `post_content`: map of plugin
	 * label => [meta key, needle to search for within it].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const META_SIGNATURES = array(
		'Elementor Pro Form' => array( '_elementor_data', '"widgetType":"form"' ),
	);

	/**
	 * Known non-WordPress form processors, recognized by their action
	 * URL: label => regex patterns matched against the action target.
	 * These are pasted/hand-coded embeds pointing at external scripts,
	 * so they only surface through the HTML-form fallback below. The
	 * label keeps the "HTML form" prefix on purpose -- the plugin-usage
	 * rollup buckets anything starting that way as "not a plugin".
	 *
	 * @var array<string, string[]>
	 */
	private const KNOWN_ACTION_SIGNATURES = array(
		// Standalone newsletter/mail-list CGI (pre-WordPress era);
		// embedded as <form action=".../infiniteresponder/s.php">.
		'Infinite Responder' => array( 'infiniteresponder' ),
	);

	/** @var array<string, string[]> */
	private readonly array $contentSignatures;

	/** @var array<string, array{0: string, 1: string}> */
	private readonly array $metaSignatures;

	/** @var array<string, string[]> */
	private readonly array $actionSignatures;

	/**
	 * @param array<string, mixed> $extras Optional 'detector_extras'
	 *        block for this category (plugin-roles.php):
	 *        'content_signatures' => label => patterns[] (appended),
	 *        'meta_signatures' => label => [meta_key, needle]
	 *        (replaced per label), 'action_signatures' => label =>
	 *        patterns[] matched against a raw HTML form's action URL.
	 */
	public function __construct( array $extras = array() ) {
		$this->contentSignatures = DetectorExtras::patternMap( self::CONTENT_SIGNATURES, $extras['content_signatures'] ?? null );
		$this->metaSignatures = DetectorExtras::tupleMap( self::META_SIGNATURES, $extras['meta_signatures'] ?? null );
		$this->actionSignatures = DetectorExtras::patternMap( self::KNOWN_ACTION_SIGNATURES, $extras['action_signatures'] ?? null );
	}

	public function category(): string {
		return 'form_plugin';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		foreach ( $this->contentSignatures as $label => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $post->content ) ) {
					$found[] = $label;
					break;
				}
			}
		}

		foreach ( $this->metaSignatures as $label => list( $metaKey, $needle ) ) {
			$value = $post->metaValue( $metaKey );
			if ( $value !== null && str_contains( $value, $needle ) ) {
				$found[] = $label;
			}
		}

		// Fallback: some services (Brevo among them) are commonly added
		// by pasting raw HTML embed code from the provider's site rather
		// than using a shortcode/block at all, and hand-coded forms do
		// the same. Surface the form's action target -- the host/path
		// usually identifies the actual processor (a pasted Brevo
		// endpoint, a mailto:, a self-processing PHP file, ...).
		if ( $found === array() ) {
			foreach ( $this->formActions( $post->content ) as $action ) {
				$known = $this->knownActionLabel( $action );
				$found[] = $known !== null
					? sprintf( 'HTML form (%s; action: %s)', $known, $action )
					: sprintf( 'HTML form (action: %s)', $action );
			}
		}

		return $found;
	}

	/**
	 * The KNOWN_ACTION_SIGNATURES label for a form action target, or
	 * null when it is not a recognized external processor.
	 */
	private function knownActionLabel( string $action ): ?string {
		foreach ( $this->actionSignatures as $label => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $action ) === 1 ) {
					return $label;
				}
			}
		}

		return null;
	}

	/**
	 * The core Search block (and get_search_form()) renders a
	 * <form role="search" ... class="...wp-block-search..."> -- it is
	 * WordPress's own markup, not a form plugin or a pasted embed, so
	 * it is excluded from the fallback.
	 */
	private function isCoreSearchForm( string $attrs ): bool {
		return preg_match( '/\brole\s*=\s*["\']search["\']/i', $attrs ) === 1
			|| preg_match( '/\bclass\s*=\s*["\'][^"\']*\b(?:wp-block-search|search-form)\b/i', $attrs ) === 1;
	}

	/**
	 * @return string[] Distinct form action targets, e.g. '"https://x.com/s"',
	 *                  '"(same page)"' for empty/missing/# actions.
	 */
	private function formActions( string $content ): array {
		preg_match_all( '/<form\b([^>]*)>/i', $content, $tags );

		$actions = array();
		foreach ( $tags[1] as $attrs ) {
			if ( $this->isCoreSearchForm( $attrs ) ) {
				continue;
			}
			if ( preg_match( '/\baction\s*=\s*(["\']?)([^\s"\'>]*)\1/i', $attrs, $attr ) !== 1 ) {
				$actions[] = '(no action attribute)';
				continue;
			}
			$actions[] = $attr[2] === '' || $attr[2] === '#' ? '(same page)' : '"' . $attr[2] . '"';
		}

		return array_values( array_unique( $actions ) );
	}
}
