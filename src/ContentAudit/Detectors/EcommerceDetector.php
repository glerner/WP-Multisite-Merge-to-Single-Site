<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects ecommerce plugin usage: WooCommerce, SureCart, or Easy
 * Digital Downloads (via each plugin's own post type or
 * shortcodes/blocks).
 *
 * @package MergeMultisite
 */
final class EcommerceDetector implements ContentDetectorInterface {

	public function category(): string {
		return 'ecommerce';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		if (
			$post->postType === 'product'
			|| preg_match( '/\[add_to_cart\b|\[woocommerce_|wp:woocommerce\//i', $post->content )
		) {
			$found[] = 'WooCommerce';
		}

		if (
			$post->postType === 'sc_product'
			|| preg_match( '/\[sc_product\b|wp:surecart\//i', $post->content )
		) {
			$found[] = 'SureCart';
		}

		if (
			$post->postType === 'download'
			|| preg_match( '/\[downloads\b|\[purchase_link\b|wp:edd\//i', $post->content )
		) {
			$found[] = 'Easy Digital Downloads';
		}

		return $found;
	}
}
