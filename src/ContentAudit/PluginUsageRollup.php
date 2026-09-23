<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit;

/**
 * Cross-references the detectors' per-label findings against the
 * plugins actually installed on the source, producing the summary's
 * "Plugin usage" section:
 *
 *   - used:          installed plugins whose output was detected in
 *                    page content (which signals, which sites)
 *   - not_installed: content signals matching no installed plugin --
 *                    leftovers from removed plugins, theme features,
 *                    or labels we haven't mapped yet
 *   - not_detected:  installed plugins that produced NO content signal
 *                    (review candidates for removal -- but note that
 *                    spam/redirect/performance plugins legitimately
 *                    leave no markup)
 *
 * @package MergeMultisite
 */
final class PluginUsageRollup {

	/**
	 * Content-signal token (a condensed detector label or prefix of
	 * one) => array with 'name' (display name for reports) and 'slugs'
	 * (candidate plugin directory names to look for; the first present
	 * on disk wins). When none is installed the signal still groups
	 * under 'name' in the "not installed" list.
	 *
	 * Keys are matched against the condensed label (lowercased, with
	 * dashes/underscores/spaces stripped); longest prefix wins, so
	 * "ninjaforms" beats "ninja".
	 */
	private const SIGNAL_MAP = array(
		'uagb'              => array(
	'name' => 'Spectra / Ultimate Addons for Gutenberg',
	'slugs' => array( 'ultimate-addons-for-gutenberg', 'spectra', 'uagb' ),
	),
		'greenshift'        => array(
	'name' => 'Greenshift',
	'slugs' => array( 'greenshift-animation-and-page-builder-blocks', 'greenshift' ),
	),
		'ninjaforms'        => array(
	'name' => 'Ninja Forms',
	'slugs' => array( 'ninja-forms' ),
	),
		'ninja'             => array(
	'name' => 'Ninja Forms',
	'slugs' => array( 'ninja-forms' ),
	),
		'wsform'            => array(
	'name' => 'WS Form',
	'slugs' => array( 'ws-form' ),
	),
		'wsf'               => array(
	'name' => 'WS Form',
	'slugs' => array( 'ws-form' ),
	),
		'wpforms'           => array(
	'name' => 'WPForms',
	'slugs' => array( 'wpforms', 'wpforms-lite' ),
	),
		'contactform'       => array(
	'name' => 'Contact Form 7',
	'slugs' => array( 'contact-form-7' ),
	),
		'wpcf7'             => array(
	'name' => 'Contact Form 7',
	'slugs' => array( 'contact-form-7' ),
	),
		'gravityform'       => array(
	'name' => 'Gravity Forms',
	'slugs' => array( 'gravityforms', 'gravity-forms' ),
	),
		'gravity'           => array(
	'name' => 'Gravity Forms',
	'slugs' => array( 'gravityforms', 'gravity-forms' ),
	),
		'flamingo'          => array(
	'name' => 'Flamingo',
	'slugs' => array( 'flamingo' ),
	),
		'sureforms'         => array(
	'name' => 'SureForms',
	'slugs' => array( 'sureforms' ),
	),
		'srfm'              => array(
	'name' => 'SureForms',
	'slugs' => array( 'sureforms' ),
	),
		'surecart'          => array(
	'name' => 'SureCart',
	'slugs' => array( 'surecart' ),
	),
		'woocommerce'       => array(
	'name' => 'WooCommerce',
	'slugs' => array( 'woocommerce' ),
	),
		'pmpro'             => array(
	'name' => 'Paid Memberships Pro',
	'slugs' => array( 'paid-memberships-pro' ),
	),
		'mla'               => array(
	'name' => 'Media Library Assistant',
	'slugs' => array( 'media-library-assistant' ),
	),
		'amazonproduct'     => array(
	'name' => 'Amazon Simple Affiliate',
	'slugs' => array( 'amazon-simple-affiliate', 'amazon-affiliate' ),
	),
		'asa'               => array(
	'name' => 'Amazon Simple Affiliate',
	'slugs' => array( 'amazon-simple-affiliate', 'asa' ),
	),
		'adinserter'        => array(
	'name' => 'Ad Inserter',
	'slugs' => array( 'ad-inserter', 'adinserter' ),
	),
		'cfce'              => array(
	'name' => 'wpwm-cfce-plugin',
	'slugs' => array( 'wpwm-cfce-plugin' ),
	),
		'etpb'              => array(
	'name' => 'Divi',
	'slugs' => array( 'divi-builder', 'divi' ),
	),
		'divi'              => array(
	'name' => 'Divi',
	'slugs' => array( 'divi-builder', 'divi' ),
	),
		'yoast'             => array(
	'name' => 'Yoast SEO',
	'slugs' => array( 'wordpress-seo' ),
	),
		'wordpressseo'      => array(
	'name' => 'Yoast SEO',
	'slugs' => array( 'wordpress-seo' ),
	),
		'allinoneseo'       => array(
	'name' => 'All in One SEO',
	'slugs' => array( 'all-in-one-seo-pack' ),
	),
		'rankmath'          => array(
	'name' => 'Rank Math',
	'slugs' => array( 'seo-by-rank-math' ),
	),
		'seopress'          => array(
	'name' => 'SEOPress',
	'slugs' => array( 'seopress', 'wp-seopress' ),
	),
		'squirrly'          => array(
	'name' => 'Squirrly SEO',
	'slugs' => array( 'squirrly-seo' ),
	),
		'akismet'           => array(
	'name' => 'Akismet',
	'slugs' => array( 'akismet' ),
	),
		'antispambee'       => array(
	'name' => 'Antispam Bee',
	'slugs' => array( 'antispam-bee' ),
	),
		'theseoframework'   => array(
	'name' => 'The SEO Framework',
	'slugs' => array( 'autodescription', 'the-seo-framework' ),
	),
		'autodescription'   => array(
	'name' => 'The SEO Framework',
	'slugs' => array( 'autodescription', 'the-seo-framework' ),
	),
		'syntaxhighlighter' => array(
	'name' => 'SyntaxHighlighter',
	'slugs' => array( 'syntaxhighlighter-evolved', 'syntaxhighlighter' ),
	),
	);

	/**
	 * Condensed tokens that are WordPress core or platform output, not
	 * a plugin (core embeds/shortcodes, video platforms, and the
	 * "unidentified form" catch-all which is a diagnosis, not a slug).
	 */
	private const NOT_A_PLUGIN = array(
		'core',
		'coreembed',
		'coregallery',
		'gallery',
		'caption',
		'youtube',
		'vimeo',
		'htmlform',          // "HTML form (action: ...)" -- hand-coded or pasted embed, not a plugin
		'unidentifiedhtmlform',
	);

	/**
	 * Plugin roles where two+ co-active plugins on the same site
	 * fight over the same hook (e.g. two SMTP plugins both override
	 * wp_mail()) or duplicate the same job. Slugs are wp.org plugin
	 * directory names. A family is reported only when two+ members
	 * are active somewhere; "overlap" is the sites where two+
	 * members are co-active, which is a real conflict rather than
	 * just a consolidation opportunity.
	 */
	private const CONFLICT_FAMILIES = array(
		'Mail delivery / SMTP' => array( 'wp-mail-smtp', 'post-smtp', 'easy-wp-smtp', 'fluent-smtp', 'gmail-smtp', 'mailinblue', 'brevo', 'sendgrid', 'mailgun', 'mailpoet', 'wp-mail-bank' ),
		'Contact forms'        => array( 'contact-form-7', 'wpforms', 'wpforms-lite', 'gravityforms', 'ninja-forms', 'ws-form', 'fluent-forms', 'formidable', 'forminator', 'sureforms' ),
		'SEO'                  => array( 'wordpress-seo', 'all-in-one-seo-pack', 'seo-by-rank-math', 'autodescription', 'the-seo-framework', 'seopress', 'squirrly-seo' ),
		'Comment spam'         => array( 'akismet', 'antispam-bee', 'cleantalk-spam-protect', 'stop-spammer-registrations-plugin', 'wp-spamshield', 'titan-anti-spam', 'zero-spam' ),
		'Caching/performance'  => array( 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-rocket', 'cache-enabler', 'breeze', 'sg-cachepress', 'wp-fastest-cache', 'flying-press', 'nitropack' ),
		'Image optimization'   => array( 'ewww-image-optimizer', 'ewww-image-optimizer-cloud', 'imagify', 'wp-smushit', 'shortpixel-image-optimiser', 'robin-image-optimizer', 'tiny-compress-images', 'optimole-wp', 'webp-converter-for-media', 'webp-express', 'iio', 'resmushit-image-optimizer' ),
		'CDN/edge'             => array( 'cloudflare', 'cdn-enabler', 'jetpack-boost', 'bunnycdn' ),
		'Security'             => array( 'wordfence', 'sucuri-scanner', 'better-wp-security', 'solid-security', 'all-in-one-wp-security-and-firewall' ),
		'Backups'              => array( 'updraftplus', 'duplicator', 'backwpup', 'backup-backup', 'wpvivid-backuprestore' ),
		'Page builders'        => array( 'elementor', 'divi-builder', 'beaver-builder-lite-version', 'siteorigin-panels', 'visualcomposer', 'themify-builder' ),
	);

	/**
	 * SIGNAL_MAP after config overrides.
	 *
	 * @var array<string, array{name: string, slugs: string[]}>
	 */
	private array $signalMap;

	/**
	 * NOT_A_PLUGIN after config overrides.
	 *
	 * @var string[]
	 */
	private array $notAPlugin;

	/**
	 * CONFLICT_FAMILIES after config overrides.
	 *
	 * @var array<string, string[]>
	 */
	private array $conflictFamilies;

	/**
	 * @param array<string, mixed> $overrides Optional user config
	 *        (config/plugin-roles.php) that AUGMENTS the built-in maps:
	 *        'signal_map' => token => array('name' => string,
	 *        'slugs' => string[]) added/overridden (a positional
	 *        array(name, slugs) is also accepted and normalized),
	 *        'not_a_plugin' => extra tokens treated as platform output,
	 *        'conflict_families' => family => slug[] merged into the
	 *        same-named family or added as a new one. Prefix a slug
	 *        with '-' to remove it from a built-in family.
	 */
	public function __construct( array $overrides = array() ) {
		$signalOverrides = is_array( $overrides['signal_map'] ?? null ) ? $overrides['signal_map'] : array();
		$this->signalMap = self::SIGNAL_MAP;
		foreach ( $signalOverrides as $token => $definition ) {
			$normalized = self::normalizeSignalDefinition( $definition );
			if ( $normalized !== null ) {
				$this->signalMap[ (string) $token ] = $normalized;
			}
		}

		$notPluginOverrides = is_array( $overrides['not_a_plugin'] ?? null ) ? $overrides['not_a_plugin'] : array();
		$this->notAPlugin = array_values(
			array_unique( array_merge( self::NOT_A_PLUGIN, array_map( 'strval', $notPluginOverrides ) ) )
		);

		$this->conflictFamilies = self::CONFLICT_FAMILIES;
		$familyOverrides = is_array( $overrides['conflict_families'] ?? null ) ? $overrides['conflict_families'] : array();
		foreach ( $familyOverrides as $family => $slugs ) {
			if ( ! is_array( $slugs ) ) {
				continue;
			}
			$merged = $this->conflictFamilies[ (string) $family ] ?? array();
			foreach ( array_map( 'strval', $slugs ) as $slug ) {
				if ( str_starts_with( $slug, '-' ) ) {
					$merged = array_values( array_diff( $merged, array( substr( $slug, 1 ) ) ) );
				} elseif ( ! in_array( $slug, $merged, true ) ) {
					$merged[] = $slug;
				}
			}
			$this->conflictFamilies[ (string) $family ] = $merged;
		}
	}

	/**
	 * Normalize a config-file signal_map entry into the canonical
	 * array('name' => string, 'slugs' => string[]) shape. Accepts the
	 * named form and the legacy positional form array(name, slugs);
	 * anything else returns null and is ignored.
	 *
	 * @param mixed $definition Raw config value.
	 *
	 * @return array{name: string, slugs: string[]}|null
	 */
	private static function normalizeSignalDefinition( mixed $definition ): ?array {
		if ( ! is_array( $definition ) ) {
			return null;
		}

		if ( isset( $definition['name'] ) ) {
			$slugs = $definition['slugs'] ?? array();
		} elseif ( isset( $definition[0] ) ) {
			$definition = array(
				'name'  => $definition[0],
				'slugs' => $definition[1] ?? array(),
			);
			$slugs = $definition['slugs'];
		} else {
			return null;
		}

		return array(
			'name'  => (string) $definition['name'],
			'slugs' => array_map( 'strval', is_array( $slugs ) ? $slugs : array( $slugs ) ),
		);
	}

	/**
	 * @param ContentAuditRow[]    $rows
	 * @param string[]             $installedSlugs     Plugin slugs present on disk.
	 * @param array<string, int[]> $activeSitesBySlug  Plugin slug => blog_ids where
	 *                                                 it is active (network-active
	 *                                                 plugins should list every site).
	 * @param callable|null        $footprintDescriber Optional fn(string $slug):
	 *                                                 array{has_data: bool, summary: string}
	 *                                                 describing the plugin's DB footprint
	 *                                                 (see PluginFootprintDetector) --
	 *                                                 annotates and splits the
	 *                                                 not_detected list.
	 *
	 * @return array{
	 *     used: array<string, array{slug: string, signals: string[], sites: int[]}>,
	 *     not_installed: array<string, array{signals: string[], sites: int[]}>,
	 *     not_detected: array<string, array{sites: int[], has_data: bool, footprint: string|null}>,
	 *     conflicts: array<string, array{slugs: array<string, int[]>, overlap: int[]}>
	 * }
	 */
	public function build( array $rows, array $installedSlugs, array $activeSitesBySlug, ?callable $footprintDescriber = null ): array {
		// Every label each detector emitted, with the sites using it.
		$labelSites = array();
		foreach ( $rows as $row ) {
			foreach ( $row->categoryFindings as $category => $findings ) {
				// needs_review is derived from the other detectors, and
				// nav_menu labels are link targets ("page #123"), not plugins.
				if ( $category === 'needs_review' || $category === 'nav_menu' ) {
					continue;
				}
				foreach ( $findings as $label ) {
					$labelSites[ $category ][ $label ][ $row->blogId ] = true;
				}
			}
		}

		$entities = array();
		foreach ( $labelSites as $category => $labels ) {
			foreach ( $labels as $label => $siteSet ) {
				$entity = $this->resolveEntity( $this->labelToken( $category, $label ), $installedSlugs );
				if ( $entity === null ) {
					continue; // Core/platform output -- never a plugin.
				}

				[ $name, $slug ] = $entity;
				$entities[ $name ]['slug'] = $slug;
				$entities[ $name ]['signals'][] = $category . '|' . $label;
				$entities[ $name ]['sites'] = ( $entities[ $name ]['sites'] ?? array() ) + $siteSet;
			}
		}

		$used = array();
		$notInstalled = array();
		foreach ( $entities as $name => $entity ) {
			$signals = $entity['signals'];
			sort( $signals );
			$sites = array_map( 'intval', array_keys( $entity['sites'] ) );
			sort( $sites );

			if ( $entity['slug'] !== null ) {
				$used[ $name ] = array(
				'slug' => $entity['slug'],
				'signals' => $signals,
				'sites' => $sites,
				);
			} else {
				$notInstalled[ $name ] = array(
				'signals' => $signals,
				'sites' => $sites,
				);
			}
		}

		$usedSlugs = array();
		foreach ( $used as $entity ) {
			$usedSlugs[ $entity['slug'] ] = true;
		}

		$notDetected = array();
		foreach ( $installedSlugs as $slug ) {
			if ( isset( $usedSlugs[ $slug ] ) ) {
				continue;
			}
			$active = array_values( array_unique( array_map( 'intval', $activeSitesBySlug[ $slug ] ?? array() ) ) );
			sort( $active );
			$footprint = $footprintDescriber === null ? null : $footprintDescriber( $slug );
			$notDetected[ $slug ] = array(
				'sites'     => $active,
				'has_data'  => (bool) ( $footprint['has_data'] ?? false ),
				'footprint' => $footprint === null ? null : (string) $footprint['summary'],
			);
		}

		ksort( $used );
		ksort( $notInstalled );
		ksort( $notDetected );

		return array(
			'used'          => $used,
			'not_installed' => $notInstalled,
			'not_detected'  => $notDetected,
			'conflicts'     => $this->roleConflicts( $installedSlugs, $activeSitesBySlug ),
		);
	}

	/**
	 * Finds role families (see CONFLICT_FAMILIES) where two+ member
	 * plugins are active, and the sites where two+ are co-active.
	 * Two SMTP plugins active on DIFFERENT sites is a consolidation
	 * decision; co-active on the SAME site is an actual conflict.
	 *
	 * @param string[]             $installedSlugs
	 * @param array<string, int[]> $activeSitesBySlug
	 *
	 * @return array<string, array{slugs: array<string, int[]>, overlap: int[]}>
	 */
	private function roleConflicts( array $installedSlugs, array $activeSitesBySlug ): array {
		$conflicts = array();

		foreach ( $this->conflictFamilies as $family => $slugs ) {
			$members = array();
			foreach ( array_intersect( $slugs, $installedSlugs ) as $slug ) {
				$active = array_values( array_unique( array_map( 'intval', $activeSitesBySlug[ $slug ] ?? array() ) ) );
				sort( $active );
				if ( $active !== array() ) {
					$members[ $slug ] = $active;
				}
			}

			if ( count( $members ) < 2 ) {
				continue;
			}

			// Sites with two+ family members co-active.
			$siteCounts = array();
			foreach ( $members as $sites ) {
				foreach ( $sites as $blogId ) {
					$siteCounts[ $blogId ] = ( $siteCounts[ $blogId ] ?? 0 ) + 1;
				}
			}
			$overlap = array_keys( array_filter( $siteCounts, static fn ( int $n ): bool => $n >= 2 ) );
			$overlap = array_map( 'intval', $overlap );
			sort( $overlap );

			$conflicts[ $family ] = array(
				'slugs'   => $members,
				'overlap' => $overlap,
			);
		}

		return $conflicts;
	}

	/**
	 * Reduces a detector label to the token that identifies its
	 * plugin: for blocks the namespace ("uagb/container" -> "uagb"),
	 * for everything else the label itself.
	 */
	private function labelToken( string $category, string $label ): string {
		if ( $category === 'blocks' && str_contains( $label, '/' ) ) {
			return (string) strtok( $label, '/' );
		}

		return $label;
	}

	/**
	 * @param string[] $installedSlugs
	 *
	 * @return array{string, string|null}|null [display name, installed slug or null]; null = not a plugin
	 */
	private function resolveEntity( string $token, array $installedSlugs ): ?array {
		$condensed = self::condense( $token );

		$bestPrefix = '';
		foreach ( $this->signalMap as $prefix => $definition ) {
			if ( ( $condensed === $prefix || str_starts_with( $condensed, $prefix ) ) && strlen( $prefix ) > strlen( $bestPrefix ) ) {
				$bestPrefix = $prefix;
			}
		}

		if ( $bestPrefix !== '' ) {
			$definition = $this->signalMap[ $bestPrefix ];

			return array( $definition['name'], $this->firstInstalled( $definition['slugs'], $installedSlugs ) );
		}

		// No alias: an installed slug whose condensed form equals or
		// prefixes the token (e.g. token "woocommercecart" matches slug
		// "woocommerce"). Longest match wins. This runs BEFORE the
		// NOT_A_PLUGIN check: a real installed plugin whose slug starts
		// with a core-looking token (e.g. "gallery-pro" starting with
		// "gallery") must not be discarded as platform output.
		$bestSlug = null;
		foreach ( $installedSlugs as $slug ) {
			$condensedSlug = self::condense( $slug );
			if ( ( $condensed === $condensedSlug || str_starts_with( $condensed, $condensedSlug ) )
				&& ( $bestSlug === null || strlen( $condensedSlug ) > strlen( self::condense( $bestSlug ) ) ) ) {
				$bestSlug = $slug;
			}
		}

		if ( $bestSlug !== null ) {
			return array( $bestSlug, $bestSlug );
		}

		foreach ( $this->notAPlugin as $notPlugin ) {
			if ( $condensed === $notPlugin || str_starts_with( $condensed, $notPlugin ) ) {
				return null;
			}
		}

		return array( $token, null );
	}

	/**
	 * @param string[] $candidates
	 * @param string[] $installedSlugs
	 */
	private function firstInstalled( array $candidates, array $installedSlugs ): ?string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $installedSlugs, true ) ) {
				return $candidate;
			}
		}

		return null;
	}

	private static function condense( string $value ): string {
		return strtolower( str_replace( array( '-', '_', ' ' ), '', $value ) );
	}
}
