<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects every shortcode tag name used in a post's content, the same
 * way BlockDetector surfaces every non-core block type.
 *
 * This exists specifically to catch plugins we *haven't* written a
 * specific detector for yet: FormPluginDetector/GalleryDetector/etc.
 * only recognize signatures we already know about, but this detector
 * lists every `[shortcode_name ...]` tag found, known or not, so
 * running `site-audit.php` against real data will surface anything
 * unexpected rather than silently missing it. Overlap with the more
 * specific detectors (e.g. "gallery" also showing up here) is
 * expected and useful -- it lets you cross-check the specific
 * detectors' interpretation against the raw shortcode list.
 *
 * @package MergeMultisite
 */
final class ShortcodeDetector implements ContentDetectorInterface {

	/**
	 * Lowercase tags that survive the regex but are never real
	 * shortcodes: English function words bracketed by prose
	 * ("[the]", "[in]"), PHP/print_r dump keys that appear without a
	 * trailing "=>" ("[file]", "[last_error]"), and other observed
	 * non-shortcode noise. Reviewed against real audit output -- a
	 * genuine shortcode is normally a plugin-namespaced identifier,
	 * not a common word.
	 */
	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing -- a one-word-per-line list is unreadable here.
	private const IGNORED_TAGS = array(
		// English function words / prose noise.
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from', 'has', 'have', 'he', 'his',
		'i', 'if', 'in', 'is', 'it', 'its', 'me', 'my', 'no', 'not', 'of', 'on', 'or', 'our', 's', 'she',
		'so', 'that', 'the', 'these', 'they', 'this', 'to', 'up', 'us', 'was', 'we', 'what', 'when',
		'which', 'who', 'will', 'with', 'would', 'you', 'your', 'note', 'see', 'e.g', 'etc',
		// Pasted code/config noise seen in real posts (dump keys, placeholders, prose in brackets).
		'args', 'before', 'blog_id', 'class', 'domain', 'encrypted', 'end', 'error', 'excellent',
		'file', 'function', 'id', 'insert_id', 'instead', 'last_error', 'last_query', 'last_result',
		'line', 'null', 'num_queries', 'num_rows', 'object', 'path', 'rows_affected', 'show_errors',
		'site-list', 'siteid', 'standby', 'suppress_errors', 'tableprefix', 'text', 'time', 'version',
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing

	/** @var string[] */
	private readonly array $ignored;

	/**
	 * @param string[] $ignored Extra tags to never report, on top of
	 *                          IGNORED_TAGS (from config/shortcode-ignore.php).
	 */
	public function __construct( array $ignored = array() ) {
		$this->ignored = array_merge(
			self::IGNORED_TAGS,
			array_map( static fn ( string $tag ): string => strtolower( trim( $tag, " \t\n\r\0\x0B[]" ) ), $ignored )
		);
	}

	public function category(): string {
		return 'shortcodes';
	}

	public function detect( ScannedPost $post ): array {
		// Strip <pre>/<code> blocks first: tutorial posts often paste
		// .htaccess snippets and print_r() dumps there, whose "[NC]"
		// rewrite flags and "[key] =>" array syntax would otherwise
		// flood the report.
		$content = (string) preg_replace( '/<(pre|code)\b[^>]*>.*?<\/\1>/is', ' ', $post->content );

		// Matches the tag name right after "[", but only for opening/
		// self-closing tags (the next character is whitespace, "]", or
		// "/"). Closing tags like "[/gallery]" start with "/" right
		// after "[", so they never match the leading [a-z] and are
		// correctly excluded without special-casing them.
		//
		// The name must start lowercase (the WP shortcode convention) --
		// this alone filters rewrite flags like "[NC]"/"[L]" and
		// capitalized prose like "[See]" left outside <pre>/<code>.
		// A name closed by "] =>" is a PHP array-dump key ("[file] =>"),
		// not a shortcode, so it is excluded too.
		if ( ! preg_match_all( '/\[([a-z][a-zA-Z0-9_-]*)(?:[\s\/]|\](?!\s*=>))/', $content, $matches ) ) {
			return array();
		}

		$shortcodes = array_values(
			array_diff( array_unique( $matches[1] ), $this->ignored )
		);
		sort( $shortcodes );

		return $shortcodes;
	}
}
