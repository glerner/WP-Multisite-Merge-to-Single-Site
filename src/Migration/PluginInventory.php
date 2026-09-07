<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Reads which plugins are installed (present on disk) and
 * active/network-active for a given site, from a source multisite.
 *
 * "Installed" is a filesystem concept (a directory or single file
 * under `wp-content/plugins/`) -- WordPress's database only ever
 * records which of the installed plugins are switched on -- so this
 * class needs read access to the source `wp-content/plugins`
 * directory, derived from the configured uploads path (its sibling
 * directory) unless a different plugins path is supplied.
 *
 * @package MergeMultisite
 */
final class PluginInventory {

	/**
	 * @param string $pluginsPath   Absolute path to the source's wp-content/plugins directory.
	 * @param string $muPluginsPath Absolute path to the source's wp-content/mu-plugins directory.
	 */
	public function __construct(
		private readonly string $pluginsPath,
		private readonly string $muPluginsPath = '',
	) {
	}

	/**
	 * Derive the plugins/mu-plugins directories from a wp-content/uploads
	 * path (both are its siblings), when no explicit paths are configured.
	 */
	public static function fromUploadsPath( string $uploadsPath ): self {
		$wpContent = rtrim( dirname( rtrim( $uploadsPath, '/' ) ), '/' );

		return new self( $wpContent . '/plugins', $wpContent . '/mu-plugins' );
	}

	/**
	 * Every plugin slug found on disk under wp-content/plugins,
	 * regardless of whether it's active. A "slug" is the plugin's
	 * directory name, or its filename (without .php) for single-file
	 * plugins living directly in the plugins directory.
	 *
	 * Not everything in wp-content/plugins is a plugin: it is common
	 * for that directory to also contain a repo's own dotfiles
	 * (.git/, .gitignore), Composer files (composer.json, vendor/),
	 * and documentation (docs/, README.md). Those are explicitly
	 * filtered out here -- inventing "plugins" from them produced
	 * bogus warnings (e.g. a "plugin vendor is installed" finding).
	 *
	 * @return string[]
	 */
	public function installedSlugs(): array {
		if ( ! is_dir( $this->pluginsPath ) ) {
			return array();
		}

		$slugs = array();
		$entries = scandir( $this->pluginsPath );
		$entries = $entries === false ? array() : $entries;

		foreach ( $entries as $entry ) {
			// Dotfiles/dot-directories are never plugin slugs.
			if ( $entry === '.' || $entry === '..' || str_starts_with( $entry, '.' ) ) {
				continue;
			}

			$fullPath = $this->pluginsPath . '/' . $entry;

			if ( is_dir( $fullPath ) ) {
				// A directory is only a plugin if one of its
				// TOP-LEVEL PHP files declares a "Plugin Name:"
				// header -- mirroring how WordPress's own
				// get_plugins() scans a plugin folder (only its
				// direct contents, not recursively). Merely
				// containing *some* PHP file isn't enough: vendor/
				// (Composer dependencies) and similar directories
				// have plenty of PHP files but no plugin header
				// anywhere in them.
				if ( $this->directoryHasTopLevelPluginHeader( $fullPath ) ) {
					$slugs[] = $entry;
				}
			} elseif ( str_ends_with( $entry, '.php' ) ) {
				// Single-file plugin: only if it actually declares a
				// plugin header -- excludes composer.json-styled
				// helper scripts that happen to be .php.
				if ( $this->fileHasPluginHeader( $fullPath ) ) {
					$slugs[] = substr( $entry, 0, -4 );
				}
			}
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * Must-use (MU) plugin slugs, from `wp-content/mu-plugins/`.
	 *
	 * MU-plugins are a fundamentally different mechanism from regular
	 * plugins: WordPress force-loads every PHP file directly inside
	 * this directory on EVERY request, for EVERY site, unconditionally
	 * -- there is no `active_plugins`/`active_sitewide_plugins` entry
	 * for them at all (they can't be deactivated short of removing the
	 * file). A slug here should always be treated as installed AND
	 * active everywhere, unlike installedSlugs()/activeSlugsForSite().
	 *
	 * Only top-level *.php files are considered (WordPress itself
	 * does not load a subdirectory's contents automatically unless an
	 * mu-plugin file explicitly requires them) -- e.g.
	 * "000-prime-mover-constants.php" -> "000-prime-mover-constants".
	 *
	 * @return string[]
	 */
	public function mustUseSlugs(): array {
		if ( $this->muPluginsPath === '' || ! is_dir( $this->muPluginsPath ) ) {
			return array();
		}

		$phpFiles = glob( $this->muPluginsPath . '/*.php' );
		if ( $phpFiles === false ) {
			return array();
		}

		$slugs = array_map(
			static fn ( string $path ): string => substr( basename( $path ), 0, -4 ),
			$phpFiles
		);

		sort( $slugs );

		return $slugs;
	}

	private function directoryHasTopLevelPluginHeader( string $directory ): bool {
		$phpFiles = glob( $directory . '/*.php' );
		if ( $phpFiles === false ) {
			return false;
		}

		foreach ( $phpFiles as $phpFile ) {
			if ( $this->fileHasPluginHeader( $phpFile ) ) {
				return true;
			}
		}

		return false;
	}

	private function fileHasPluginHeader( string $file ): bool {
		$head = file_get_contents( $file, false, null, 0, 8192 );

		return is_string( $head ) && preg_match( '/Plugin Name\s*:/i', $head ) === 1;
	}

	/**
	 * Plugin slugs active on one specific site (per-site
	 * `active_plugins` option), converted from `slug/file.php` (or
	 * `slug.php`) form to a bare slug.
	 *
	 * @return string[]
	 */
	public function activeSlugsForSite( Connection $source, int $blogId ): array {
		$optionsTable = $source->siteTable( 'options', $blogId );

		$value = $source->fetchScalar(
			"SELECT option_value FROM {$optionsTable} WHERE option_name = 'active_plugins' LIMIT 1"
		);

		return $this->slugsFromSerializedPluginList( is_string( $value ) ? $value : null );
	}

	/**
	 * Network-activated plugin slugs (`active_sitewide_plugins` in
	 * the network's `sitemeta` table), which apply to every site.
	 *
	 * @return string[]
	 */
	public function networkActiveSlugs( Connection $source ): array {
		$siteMetaTable = $source->networkTable( 'sitemeta' );

		if ( ! $source->tableExists( $siteMetaTable ) ) {
			return array();
		}

		$value = $source->fetchScalar(
			"SELECT meta_value FROM {$siteMetaTable} WHERE meta_key = 'active_sitewide_plugins' LIMIT 1"
		);

		if ( ! is_string( $value ) ) {
			return array();
		}

		$decoded = @unserialize( $value );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return $this->slugsFromPluginFileList( array_keys( $decoded ) );
	}

	private function slugsFromSerializedPluginList( ?string $serialized ): array {
		if ( $serialized === null ) {
			return array();
		}

		$decoded = @unserialize( $serialized );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return $this->slugsFromPluginFileList( $decoded );
	}

	/**
	 * @param array<int, mixed> $pluginFiles e.g. ["akismet/akismet.php", "hello.php"].
	 *
	 * @return string[]
	 */
	private function slugsFromPluginFileList( array $pluginFiles ): array {
		$slugs = array();
		foreach ( $pluginFiles as $pluginFile ) {
			if ( ! is_string( $pluginFile ) ) {
				continue;
			}

			$slugs[] = str_contains( $pluginFile, '/' )
				? strtok( $pluginFile, '/' )
				: preg_replace( '/\.php$/', '', $pluginFile );
		}

		return array_values( array_unique( array_filter( $slugs ) ) );
	}
}
