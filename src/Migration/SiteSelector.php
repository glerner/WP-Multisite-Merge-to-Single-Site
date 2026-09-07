<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Reads the source network's site registry (`wp_blogs`) and resolves
 * each row against this run's `sites.php` configuration.
 *
 * @package MergeMultisite
 */
final class SiteSelector {

	public function __construct( private readonly Connection $source ) {
	}

	/**
	 * All sites in the network, including deleted ones and excluded
	 * ones -- useful for reporting ("--list-sites").
	 *
	 * @return Site[]
	 */
	public function listAllSites( MergeConfig $config ): array {
		$rows = $this->source->fetchAll(
			sprintf( 'SELECT blog_id, domain, path, deleted FROM %s ORDER BY blog_id', $this->source->networkTable( 'blogs' ) )
		);

		$sites = array();
		foreach ( $rows as $row ) {
			$blogId = (int) $row['blog_id'];
			$title = $this->fetchBlogTitle( $blogId );
			$sites[] = Site::fromBlogRow( $row, $title, $config->siteConfigFor( $blogId ) );
		}

		return $sites;
	}

	/**
	 * Only the sites that are not deleted AND included for this run.
	 *
	 * @return Site[]
	 */
	public function listIncludedSites( MergeConfig $config ): array {
		return array_values(
			array_filter(
				$this->listAllSites( $config ),
				static fn ( Site $site ): bool => $site->included && ! $site->deleted
			)
		);
	}

	private function fetchBlogTitle( int $blogId ): string {
		$optionsTable = $this->source->siteTable( 'options', $blogId );

		$value = $this->source->fetchScalar(
			sprintf( 'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1', $optionsTable ),
			array( 'name' => 'blogname' )
		);

		return $value !== null ? (string) $value : sprintf( 'Site %d', $blogId );
	}
}
