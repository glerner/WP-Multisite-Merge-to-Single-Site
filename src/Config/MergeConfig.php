<?php

declare(strict_types=1);

namespace MergeMultisite\Config;

/**
 * Fully validated, aggregate configuration for a run of any of the
 * three merge-multisite CLI tools.
 *
 * @package MergeMultisite
 */
final class MergeConfig {

	/**
	 * @param DatabaseConfig                       $source                 Source multisite database connection settings.
	 * @param DatabaseConfig                       $destination            Destination single-site database connection settings.
	 * @param string                               $destinationUrl         Canonical production domain, e.g. "https://example.com".
	 * @param bool                                 $generateRedirectFiles  Whether to emit redirect map files.
	 * @param int                                  $batchSize              Number of rows processed per migration batch.
	 * @param string[]                             $excludedPostTypes      Post types always excluded from migration.
	 * @param string[]                             $excludedPostStatuses   Post statuses always excluded from migration.
	 * @param string                               $termMergeRule           Term case-merge rule identifier.
	 * @param string[]                             $contactPagePaths       Known contact-page path variants to canonicalize to /contact/.
	 * @param string                               $logLevel               Minimum log level to record.
	 * @param array<int, SiteConfig>               $sites                  Site-selection entries, keyed by blog_id.
	 * @param array<string, PluginOptionRule>      $pluginOptionRules Plugin option-migration rules, keyed by plugin slug.
	 * @param array<string, array<string, string>> $termOverrides Manual term-label overrides, by taxonomy.
	 * @param string|null                          $wpscanApiToken Optional API token for bin/run-wpscan.php.
	 * @param array<int, array<string, mixed>>     $suppressions    Finding-suppression rules applied by
	 *                                                              AuditRunner after all checks run:
	 *                                                              each rule is an array with a "check"
	 *                                                              key (finding name, or prefix when it
	 *                                                              ends with "*") plus optional context
	 *                                                              key/value criteria, e.g.
	 *                                                              ['check' => 'plugin-data.*', 'plugin' => 'x'].
	 * @param string[]                             $mediaSearchPaths Extra directories searched for files
	 *                                                              missing from uploads, so a copy
	 *                                                              script can be generated for them.
	 * @param string[]                             $ignoredShortcodes Extra shortcode tags the site-audit
	 *                                                              should not report (from
	 *                                                              shortcode-ignore.php).
	 * @param string                               $spreadsheetFormat  site-audit spreadsheet output:
	 *                                                              'xlsx' (default; falls back to CSV
	 *                                                              when ext-zip is missing), 'csv', or
	 *                                                              'both'.
	 * @param int|null                             $mainSite          blog_id of the "main" site whose
	 *                                                              variant wins conflicts (term-merge
	 *                                                              ties, duplicate template slugs,
	 *                                                              site-identity elements). Null means
	 *                                                              no main site: ties fall back to
	 *                                                              site-ID order.
	 * @param array<string, mixed>                 $pluginRoles       User overrides for
	 *                                                              PluginUsageRollup's plugin-role
	 *                                                              families, signal aliases, and
	 *                                                              not-a-plugin tokens (from
	 *                                                              plugin-roles.php); they augment the
	 *                                                              built-in lists.
	 */
	public function __construct(
		public readonly DatabaseConfig $source,
		public readonly DatabaseConfig $destination,
		public readonly string $destinationUrl,
		public readonly bool $generateRedirectFiles,
		public readonly int $batchSize,
		public readonly array $excludedPostTypes,
		public readonly array $excludedPostStatuses,
		public readonly string $termMergeRule,
		public readonly array $contactPagePaths,
		public readonly string $logLevel,
		public readonly array $sites,
		public readonly array $pluginOptionRules,
		public readonly array $termOverrides,
		public readonly ?string $wpscanApiToken = null,
		public readonly array $suppressions = array(),
		public readonly array $mediaSearchPaths = array(),
		public readonly array $ignoredShortcodes = array(),
		public readonly string $spreadsheetFormat = 'xlsx',
		public readonly ?int $mainSite = null,
		public readonly array $pluginRoles = array(),
	) {
	}

	/**
	 * Look up the site-selection entry for a given blog_id, if one was
	 * explicitly configured.
	 */
	public function siteConfigFor( int $blogId ): ?SiteConfig {
		return $this->sites[ $blogId ] ?? null;
	}

	/**
	 * Look up the plugin option-migration rule for a given plugin slug,
	 * if one was explicitly configured.
	 */
	public function pluginOptionRuleFor( string $pluginSlug ): ?PluginOptionRule {
		return $this->pluginOptionRules[ $pluginSlug ] ?? null;
	}
}
