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
	 * @param string                  $path           Hierarchical slug path (`parent/child`) for
	 *                                                building `original_url`; '' falls back to $slug.
	 * @param string                  $template       The template this post renders through
	 *                                                (see PageTemplateResolver), '' when n/a.
	 * @param string                  $templateStatus Why the template needs review
	 *                                                ('customized', 'stale-customization', ...),
	 *                                                '' when nothing per-page is needed.
	 * @param string                  $content        Raw post_content; populated only for
	 *                                                post types the reports quote verbatim
	 *                                                (currently custom_css), '' otherwise.
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
		public readonly string $path = '',
		public readonly string $template = '',
		public readonly string $templateStatus = '',
		public readonly string $content = '',
	) {
	}

	/**
	 * @return array<string, string>
	 */
	public function toRow(): array {
		// Drafts and some post types can have an empty post_name --
		// without a fallback that produces a bare "https://domain//"
		// URL. '?p={id}' is WordPress's own permalink form for
		// slugless/unpublished posts.
		$permalinkSlug = trim( $this->path !== '' ? $this->path : $this->slug, '/' );
		$originalUrl   = $permalinkSlug !== ''
			? 'https://' . $this->domain . '/' . $permalinkSlug . '/'
			: 'https://' . $this->domain . '/?p=' . $this->postId;

		$row = array(
			'blog_id' => (string) $this->blogId,
			'domain' => $this->domain,
			'post_id' => (string) $this->postId,
			'post_type' => $this->postType,
			'post_status' => $this->postStatus,
			'post_title' => $this->postTitle,
			'slug' => $this->slug,
			'original_url' => $originalUrl,
			'template' => $this->template,
			'template_status' => $this->templateStatus,
		);

		foreach ( $this->categoryFindings as $category => $labels ) {
			$row[ $category ] = implode( '; ', $labels );
		}

		return $row;
	}
}
