<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\ContentDetectorInterface;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\Site;

/**
 * Scans every post/page (optionally filtered by post type) on a set of
 * sites, running every configured ContentDetectorInterface against
 * each one, and produces one ContentAuditRow per post.
 *
 * @package MergeMultisite
 */
final class PostScanner {

	/**
	 * @param ContentDetectorInterface[] $detectors
	 */
	public function __construct( private readonly array $detectors ) {
	}

	/**
	 * @param Site[]   $sites
	 * @param string[] $postTypes         Post types to include; empty array means "all".
	 * @param string[] $excludedPostTypes Post types to skip when $postTypes is empty
	 *                                    (an explicit include list wins over exclusions).
	 *
	 * @return ContentAuditRow[]
	 */
	public function scan( Connection $connection, array $sites, array $postTypes = array(), array $excludedPostTypes = array() ): array {
		$rows = array();

		foreach ( $sites as $site ) {
			foreach ( $this->scanSite( $connection, $site, $postTypes, $excludedPostTypes ) as $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Like scan(), but also returns the underlying ScannedPost for
	 * every row, so report writers that need raw content/meta (e.g.
	 * the "needs review" CSV with raw page-builder data dumps) can
	 * access it without a second database pass.
	 *
	 * @param Site[]   $sites
	 * @param string[] $postTypes         Post types to include; empty array means "all".
	 * @param string[] $excludedPostTypes Post types to skip when $postTypes is empty
	 *                                    (an explicit include list wins over exclusions).
	 *
	 * @return array<int, array{row: ContentAuditRow, post: ScannedPost}>
	 */
	public function scanWithDetails( Connection $connection, array $sites, array $postTypes = array(), array $excludedPostTypes = array() ): array {
		$results = array();

		foreach ( $sites as $site ) {
			foreach ( $this->scanSiteWithDetails( $connection, $site, $postTypes, $excludedPostTypes ) as $result ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * Ad-hoc full-content search across all given sites (e.g. finding
	 * every post containing a spam keyword like "cialis"), used by
	 * `site-audit.php --search=...`. Unlike scan(), this is a plain
	 * find-anything search rather than a detector pass.
	 *
	 * @param Site[]   $sites
	 * @param string[] $needles           Case-insensitive terms to search for
	 *                                    in post_content / post_title.
	 * @param string[] $postTypes         Post types to include; empty array means "all".
	 * @param string[] $excludedPostTypes Post types to skip when $postTypes is empty
	 *                                    (an explicit include list wins over exclusions).
	 *
	 * @return array<int, array{blog_id:int, post_id:int, post_type:string, post_status:string, post_title:string, post_name:string, matched_needle:string}>
	 */
	public function searchContent( Connection $connection, array $sites, array $needles, array $postTypes = array(), array $excludedPostTypes = array() ): array {
		$hits = array();

		foreach ( $sites as $site ) {
			foreach ( $this->searchSite( $connection, $site, $needles, $postTypes, $excludedPostTypes ) as $hit ) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}

	/**
	 * @param string[] $needles
	 * @param string[] $postTypes
	 *
	 * @return array<int, array{blog_id:int, post_id:int, post_type:string, post_status:string, post_title:string, post_name:string, matched_needle:string}>
	 */
	private function searchSite( Connection $connection, Site $site, array $needles, array $postTypes, array $excludedPostTypes = array() ): array {
		$postsTable = $connection->siteTable( 'posts', $site->blogId );

		$params = array();
		$where = "post_status NOT IN ('trash', 'auto-draft')" . $this->postTypeClause( $postTypes, $excludedPostTypes, $params );

		$posts = $connection->fetchAll(
			"SELECT ID, post_type, post_status, post_title, post_name, post_content FROM {$postsTable} WHERE {$where}",
			$params
		);

		$hits = array();
		foreach ( $posts as $post ) {
			$haystack = (string) $post['post_title'] . "\n" . (string) $post['post_content'];

			foreach ( $needles as $needle ) {
				// Word-boundary match, not a bare substring check --
				// "cialis" is literally a substring of "specialist",
				// so a plain str_contains() would false-positive on
				// any post mentioning e.g. a "Certified Nutrition
				// Specialist". This still isn't a thorough spam/
				// malware scan (see the disclaimer printed by the
				// --search CLI option) -- just a keyword sweep with
				// that specific class of false positive fixed.
				$pattern = '/\b' . preg_quote( $needle, '/' ) . '\b/i';
				if ( preg_match( $pattern, $haystack ) === 1 ) {
					$hits[] = array(
						'blog_id' => $site->blogId,
						'post_id' => (int) $post['ID'],
						'post_type' => (string) $post['post_type'],
						'post_status' => (string) $post['post_status'],
						'post_title' => (string) $post['post_title'],
						'post_name' => (string) $post['post_name'],
						'matched_needle' => $needle,
					);
					break;
				}
			}
		}

		return $hits;
	}

	/**
	 * @param string[] $postTypes
	 * @param string[] $excludedPostTypes
	 *
	 * @return ContentAuditRow[]
	 */
	private function scanSite( Connection $connection, Site $site, array $postTypes, array $excludedPostTypes = array() ): array {
		$rows = array();
		foreach ( $this->scanSiteWithDetails( $connection, $site, $postTypes, $excludedPostTypes ) as $result ) {
			$rows[] = $result['row'];
		}

		return $rows;
	}

	/**
	 * @param string[] $postTypes
	 * @param string[] $excludedPostTypes
	 *
	 * @return array<int, array{row: ContentAuditRow, post: ScannedPost}>
	 */
	private function scanSiteWithDetails( Connection $connection, Site $site, array $postTypes, array $excludedPostTypes = array() ): array {
		$postsTable = $connection->siteTable( 'posts', $site->blogId );
		$postMetaTable = $connection->siteTable( 'postmeta', $site->blogId );

		$params = array();
		$where = "post_status NOT IN ('trash', 'auto-draft')" . $this->postTypeClause( $postTypes, $excludedPostTypes, $params );

		$posts = $connection->fetchAll(
			"SELECT ID, post_type, post_status, post_name, post_title, post_content FROM {$postsTable} WHERE {$where}",
			$params
		);

		if ( $posts === array() ) {
			return array();
		}

		$metaByPost = $this->fetchMetaForPosts( $connection, $postMetaTable, array_column( $posts, 'ID' ) );

		$results = array();
		foreach ( $posts as $post ) {
			$postId = (int) $post['ID'];

			$scannedPost = new ScannedPost(
				blogId: $site->blogId,
				postId: $postId,
				postType: (string) $post['post_type'],
				postStatus: (string) $post['post_status'],
				slug: (string) $post['post_name'],
				postTitle: (string) $post['post_title'],
				content: (string) $post['post_content'],
				meta: $metaByPost[ $postId ] ?? array(),
			);

			$categoryFindings = array();
			foreach ( $this->detectors as $detector ) {
				$findings = $detector->detect( $scannedPost );
				if ( $findings !== array() ) {
					$categoryFindings[ $detector->category() ] = $findings;
				}
			}

			$results[] = array(
				'row' => new ContentAuditRow(
					blogId: $site->blogId,
					domain: $site->domain,
					postId: $postId,
					postType: $scannedPost->postType,
					postStatus: $scannedPost->postStatus,
					slug: $scannedPost->slug,
					postTitle: $scannedPost->postTitle,
					categoryFindings: $categoryFindings,
				),
				'post' => $scannedPost,
			);
		}

		return $results;
	}

	/**
	 * Builds the post_type WHERE fragment: an IN() allow-list when
	 * $postTypes is given, otherwise a NOT IN() exclusion list (so an
	 * explicit --post-types selection can still audit an excluded type
	 * like "revision" on purpose).
	 *
	 * @param string[]              $postTypes
	 * @param string[]              $excludedPostTypes
	 * @param array<string, string> $params Bound parameters, appended.
	 */
	private function postTypeClause( array $postTypes, array $excludedPostTypes, array &$params ): string {
		$types = $postTypes !== array() ? $postTypes : null;
		$list = $types ?? $excludedPostTypes;

		if ( $list === array() ) {
			return '';
		}

		$placeholders = array();
		foreach ( $list as $index => $postType ) {
			$key = 'post_type_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = (string) $postType;
		}

		return sprintf( ' AND post_type %s (%s)', $types === null ? 'NOT IN' : 'IN', implode( ', ', $placeholders ) );
	}

	/**
	 * @param int[] $postIds
	 *
	 * @return array<int, array<string, string[]>>
	 */
	private function fetchMetaForPosts( Connection $connection, string $postMetaTable, array $postIds ): array {
		if ( $postIds === array() ) {
			return array();
		}

		$placeholders = array();
		$params = array();
		foreach ( $postIds as $index => $postId ) {
			$key = 'post_id_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $postId;
		}

		$rows = $connection->fetchAll(
			"SELECT post_id, meta_key, meta_value FROM {$postMetaTable} WHERE post_id IN (" . implode( ', ', $placeholders ) . ')',
			$params
		);

		$metaByPost = array();
		foreach ( $rows as $row ) {
			$metaByPost[ (int) $row['post_id'] ][ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
		}

		return $metaByPost;
	}
}
