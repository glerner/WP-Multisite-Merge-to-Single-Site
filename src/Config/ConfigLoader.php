<?php

declare(strict_types=1);

namespace MergeMultisite\Config;

/**
 * Loads and validates `config.php`, `sites.php`, `option-keys.php`, and
 * `term-overrides.php` from the project's `config/` directory, producing
 * a single validated MergeConfig.
 *
 * Every config file is optional except `config.php`; the others fall
 * back to sensible empty defaults so the tools remain usable before
 * every file has been created.
 *
 * @package MergeMultisite
 */
final class ConfigLoader {

	/**
	 * @param string $configDir Absolute path to the project's config/ directory.
	 */
	public function __construct( private readonly string $configDir ) {
	}

	/**
	 * @throws ConfigException If config.php is missing or invalid.
	 */
	public function load(): MergeConfig {
		$config = $this->requireArrayFile( 'config.php' );

		foreach ( array( 'source', 'destination' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
				throw new ConfigException( sprintf( 'config.php is missing the "%s" section.', $key ) );
			}
		}

		if ( empty( $config['destination_url'] ) ) {
			throw new ConfigException( 'config.php is missing "destination_url".' );
		}

		$source = DatabaseConfig::fromArray( $config['source'], 'source' );
		$destination = DatabaseConfig::fromArray( $config['destination'], 'destination' );

		$sites = array();
		foreach ( $this->optionalArrayFile( 'sites.php' ) as $entry ) {
			$siteConfig = SiteConfig::fromArray( $entry );
			$sites[ $siteConfig->blogId ] = $siteConfig;
		}

		$pluginOptionRules = array();
		foreach ( $this->optionalArrayFile( 'option-keys.php' ) as $pluginSlug => $entry ) {
			$pluginOptionRules[ $pluginSlug ] = PluginOptionRule::fromArray( (string) $pluginSlug, $entry );
		}

		$termOverridesRaw = $this->optionalArrayFile( 'term-overrides.php' );
		$termOverrides = is_array( $termOverridesRaw['taxonomy'] ?? null ) ? $termOverridesRaw['taxonomy'] : array();

		return new MergeConfig(
			source: $source,
			destination: $destination,
			destinationUrl: rtrim( (string) $config['destination_url'], '/' ),
			generateRedirectFiles: (bool) ( $config['generate_redirect_files'] ?? true ),
			batchSize: (int) ( $config['batch_size'] ?? 200 ),
			excludedPostTypes: array_map( 'strval', $config['excluded_post_types'] ?? array( 'revision' ) ),
			excludedPostStatuses: array_map( 'strval', $config['excluded_post_statuses'] ?? array( 'auto-draft' ) ),
			termMergeRule: (string) ( $config['term_merge_rule'] ?? 'most-used' ),
			contactPagePaths: array_map( 'strval', $config['contact_page_paths'] ?? array( '/contact/' ) ),
			logLevel: (string) ( $config['log_level'] ?? 'info' ),
			sites: $sites,
			pluginOptionRules: $pluginOptionRules,
			termOverrides: $termOverrides,
			wpscanApiToken: isset( $config['wpscan_api_token'] ) ? (string) $config['wpscan_api_token'] : null,
		);
	}

	/**
	 * @return array<int|string, mixed>
	 *
	 * @throws ConfigException If the file does not exist or does not return an array.
	 */
	private function requireArrayFile( string $filename ): array {
		$path = $this->configDir . '/' . $filename;

		if ( ! is_file( $path ) ) {
			$sample = $this->configDir . '/' . preg_replace( '/\.php$/', '.sample.php', $filename );
			throw new ConfigException(
				sprintf(
					'Required config file "%s" not found. Copy "%s" to get started.',
					$path,
					$sample
				)
			);
		}

		$data = require $path;

		if ( ! is_array( $data ) ) {
			throw new ConfigException( sprintf( 'Config file "%s" must return an array.', $path ) );
		}

		return $data;
	}

	/**
	 * Same as requireArrayFile(), but returns an empty array instead of
	 * throwing when the file does not exist.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @throws ConfigException If the file exists but does not return an array.
	 */
	private function optionalArrayFile( string $filename ): array {
		$path = $this->configDir . '/' . $filename;

		if ( ! is_file( $path ) ) {
			return array();
		}

		return $this->requireArrayFile( $filename );
	}
}
