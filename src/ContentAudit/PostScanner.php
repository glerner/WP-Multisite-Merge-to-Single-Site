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
	 * @param string[] $postTypes Post types to include; empty array means "all".
	 *
	 * @return ContentAuditRow[]
	 */
	public function scan( Connection $connection, array $sites, array $postTypes = array() ): array {
		$rows = array();

		foreach ( $sites as $site ) {
			foreach ( $this->scanSite( $connection, $site, $postTypes ) as $row ) {
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
	 * @param string[] $postTypes Post types to include; empty array means "all".
	 *
	 * @return array<int, array{row: ContentAuditRow, post: ScannedPost}>
	 */
	public function scanWithDetails( Connection $connection, array $sites, array $postTypes = array() ): array {
		$results = array();

		foreach ( $sites as $site ) {
			foreach ( $this->scanSiteWithDetails( $connection, $site, $postTypes ) as $result ) {
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
	 * @param string[] $needles   Case-insensitive terms to search for
	 *                            in post_content / post_title.
	 * @param string[] $postTypes Post types to include; empty array means "all".
	 *
	 * @return array<int, array{blog_id:int, post_id:int, post_type:string, post_status:string, post_title:string, post_name:string, matched_needle:string}>
	 */
	public function searchContent( Connection $connection, array $sites, array $needles, array $postTypes = array() ): array {
		$hits = array();

		foreach ( $sites as $site ) {
			foreach ( $this->searchSite( $connection, $site, $needles, $postTypes ) as $hit ) {
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
	private function searchSite( Connection $connection, Site $site, array $needles, array $postTypes ): array {
		$postsTable = $connection->siteTable( 'posts', $site->blogId );

		$where = "post_status NOT IN ('trash', 'auto-draft')";
		$params = array();

		if ( $postTypes !== array() ) {
			$placeholders = array();
			foreach ( $postTypes as $index => $postType ) {
				$key = 'post_type_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $postType;
			}
			$where .= ' AND post_type IN (' . implode( ', ', $placeholders ) . ')';
		}

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
	 *
	 * @return ContentAuditRow[]
	 */
	private function scanSite( Connection $connection, Site $site, array $postTypes ): array {
		$rows = array();
		foreach ( $this->scanSiteWithDetails( $connection, $site, $postTypes ) as $result ) {
			$rows[] = $result['row'];
		}

		return $rows;
	}

	/**
	 * @param string[] $postTypes
	 *
	 * @return array<int, array{row: ContentAuditRow, post: ScannedPost}>
	 */
	private function scanSiteWithDetails( Connection $connection, Site $site, array $postTypes ): array {
		$postsTable = $connection->siteTable( 'posts', $site->blogId );
		$postMetaTable = $connection->siteTable( 'postmeta', $site->blogId );

		$where = "post_status NOT IN ('trash', 'auto-draft')";
		$params = array();

		if ( $postTypes !== array() ) {
			$placeholders = array();
			foreach ( $postTypes as $index => $postType ) {
				$key = 'post_type_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $postType;
			}
			$where .= ' AND post_type IN (' . implode( ', ', $placeholders ) . ')';
		}

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
					categoryFindings: $categoryFindings,
				),
				'post' => $scannedPost,
			);
		}

		return $results;
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
