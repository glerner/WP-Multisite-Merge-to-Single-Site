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

	public function category(): string {
		return 'form_plugin';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		foreach ( self::CONTENT_SIGNATURES as $label => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $post->content ) ) {
					$found[] = $label;
					break;
				}
			}
		}

		foreach ( self::META_SIGNATURES as $label => list( $metaKey, $needle ) ) {
			$value = $post->metaValue( $metaKey );
			if ( $value !== null && str_contains( $value, $needle ) ) {
				$found[] = $label;
			}
		}

		// Fallback: some services (Brevo among them) are commonly added
		// by pasting raw HTML embed code from the provider's site rather
		// than using a shortcode/block at all. That HTML always includes
		// a <form> tag, so when nothing more specific matched, flag it
		// as "unidentified" rather than missing it entirely -- you'll
		// need to open the page to see which service it actually is.
		if ( $found === array() && preg_match( '/<form\b/i', $post->content ) ) {
			$found[] = 'Unidentified HTML form (pasted embed code, e.g. Brevo)';
		}

		return $found;
	}
}
