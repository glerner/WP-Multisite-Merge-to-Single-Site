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
	/**
	 * @param string $template       The template this post renders through
	 *                               (see PageTemplateResolver), '' when n/a.
	 * @param string $templateStatus Why the template needs review
	 *                               ('customized', 'stale-customization', ...),
	 *                               '' when nothing per-page is needed.
	 */
	public function __construct(
		public readonly int $blogId,
		public readonly string $domain,
		public readonly int $postId,
		public readonly string $postType,
		public readonly string $postStatus,
		public readonly string $slug,
		public readonly string $postTitle,
		public readonly array $categoryFindings,
		public readonly string $template = '',
		public readonly string $templateStatus = '',
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
			'post_title' => $this->postTitle,
			'slug' => $this->slug,
			'original_url' => 'https://' . $this->domain . '/' . trim( $this->slug, '/' ) . '/',
			'template' => $this->template,
			'template_status' => $this->templateStatus,
		);

		foreach ( $this->categoryFindings as $category => $labels ) {
			$row[ $category ] = implode( '; ', $labels );
		}

		return $row;
	}
}
