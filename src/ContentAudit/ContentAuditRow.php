<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit;

/**
 * One row of `site-audit.php` output: one post/page, with every
 * detector's findings attached under its category name.
 *
 * @package MergeMultisite
 */
final class ContentAuditRow {

	/**
	 * @param array<string, string[]> $categoryFindings Keyed by detector category, e.g. "form_plugin" => ["WPForms"].
	 */
	public function __construct(
		public readonly int $blogId,
		public readonly string $domain,
		public readonly int $postId,
		public readonly string $postType,
		public readonly string $postStatus,
		public readonly string $slug,
		public readonly array $categoryFindings,
	) {
	}

	/**
	 * @return array<string, string>
	 */
	public function toRow(): array {
		$row = array(
			'blog_id' => (string) $this->blogId,
			'domain' => $this->domain,
			'post_id' => (string) $this->postId,
			'post_type' => $this->postType,
			'post_status' => $this->postStatus,
			'slug' => $this->slug,
			'url' => 'https://' . $this->domain . '/' . trim( $this->slug, '/' ) . '/',
		);

		foreach ( $this->categoryFindings as $category => $labels ) {
			$row[ $category ] = implode( '; ', $labels );
		}

		return $row;
	}
}
