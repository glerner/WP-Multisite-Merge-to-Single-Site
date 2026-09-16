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
	 * one) => [display name, candidate plugin directory slugs]. The
	 * first candidate present on disk wins; when none is installed the
	 * signal still groups under the display name in the
	 * "not installed" list.
	 *
	 * Keys are matched against the condensed label (lowercased, with
	 * dashes/underscores/spaces stripped); longest prefix wins, so
	 * "ninjaforms" beats "ninja".
	 */
	private const SIGNAL_MAP = array(
		'uagb'              => array( 'Spectra / Ultimate Addons for Gutenberg', array( 'ultimate-addons-for-gutenberg', 'spectra', 'uagb' ) ),
		'greenshift'        => array( 'Greenshift', array( 'greenshift-animation-and-page-builder-blocks', 'greenshift' ) ),
		'ninjaforms'        => array( 'Ninja Forms', array( 'ninja-forms' ) ),
		'ninja'             => array( 'Ninja Forms', array( 'ninja-forms' ) ),
		'wsform'            => array( 'WS Form', array( 'ws-form' ) ),
		'wsf'               => array( 'WS Form', array( 'ws-form' ) ),
		'wpforms'           => array( 'WPForms', array( 'wpforms', 'wpforms-lite' ) ),
		'contactform'       => array( 'Contact Form 7', array( 'contact-form-7' ) ),
		'wpcf7'             => array( 'Contact Form 7', array( 'contact-form-7' ) ),
		'gravityform'       => array( 'Gravity Forms', array( 'gravityforms', 'gravity-forms' ) ),
		'gravity'           => array( 'Gravity Forms', array( 'gravityforms', 'gravity-forms' ) ),
		'flamingo'          => array( 'Flamingo', array( 'flamingo' ) ),
		'surecart'          => array( 'SureCart', array( 'surecart' ) ),
		'woocommerce'       => array( 'WooCommerce', array( 'woocommerce' ) ),
		'pmpro'             => array( 'Paid Memberships Pro', array( 'paid-memberships-pro' ) ),
		'mla'               => array( 'Media Library Assistant', array( 'media-library-assistant' ) ),
		'amazonproduct'     => array( 'Amazon Simple Affiliate', array( 'amazon-simple-affiliate', 'amazon-affiliate' ) ),
		'asa'               => array( 'Amazon Simple Affiliate', array( 'amazon-simple-affiliate', 'asa' ) ),
		'adinserter'        => array( 'Ad Inserter', array( 'ad-inserter', 'adinserter' ) ),
		'cfce'              => array( 'wpwm-cfce-plugin', array( 'wpwm-cfce-plugin' ) ),
		'etpb'              => array( 'Divi', array( 'divi-builder', 'divi' ) ),
		'divi'              => array( 'Divi', array( 'divi-builder', 'divi' ) ),
		'yoast'             => array( 'Yoast SEO', array( 'wordpress-seo' ) ),
		'wordpressseo'      => array( 'Yoast SEO', array( 'wordpress-seo' ) ),
		'theseoframework'   => array( 'The SEO Framework', array( 'autodescription', 'the-seo-framework' ) ),
		'autodescription'   => array( 'The SEO Framework', array( 'autodescription', 'the-seo-framework' ) ),
		'syntaxhighlighter' => array( 'SyntaxHighlighter', array( 'syntaxhighlighter-evolved', 'syntaxhighlighter' ) ),
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
		'Contact forms'        => array( 'contact-form-7', 'wpforms', 'wpforms-lite', 'gravityforms', 'ninja-forms', 'ws-form', 'fluent-forms', 'formidable', 'forminator' ),
		'SEO'                  => array( 'wordpress-seo', 'all-in-one-seo-pack', 'seo-by-rank-math', 'autodescription', 'the-seo-framework', 'seopress', 'squirrly-seo' ),
		'Caching/performance'  => array( 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-rocket', 'cache-enabler', 'breeze', 'sg-cachepress', 'wp-fastest-cache', 'flying-press', 'nitropack' ),
		'Image optimization'   => array( 'ewww-image-optimizer', 'ewww-image-optimizer-cloud', 'imagify', 'wp-smushit', 'shortpixel-image-optimiser', 'robin-image-optimizer', 'tiny-compress-images', 'optimole-wp', 'webp-converter-for-media', 'webp-express', 'iio', 'resmushit-image-optimizer' ),
		'CDN/edge'             => array( 'cloudflare', 'cdn-enabler', 'jetpack-boost', 'bunnycdn' ),
		'Security'             => array( 'wordfence', 'sucuri-scanner', 'better-wp-security', 'solid-security', 'all-in-one-wp-security-and-firewall' ),
		'Backups'              => array( 'updraftplus', 'duplicator', 'backwpup', 'backup-backup', 'wpvivid-backuprestore' ),
		'Page builders'        => array( 'elementor', 'divi-builder', 'beaver-builder-lite-version', 'siteorigin-panels', 'visualcomposer', 'themify-builder' ),
	);

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

		foreach ( self::CONFLICT_FAMILIES as $family => $slugs ) {
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

		foreach ( self::NOT_A_PLUGIN as $notPlugin ) {
			if ( $condensed === $notPlugin || str_starts_with( $condensed, $notPlugin ) ) {
				return null;
			}
		}

		$bestPrefix = '';
		foreach ( self::SIGNAL_MAP as $prefix => $definition ) {
			if ( ( $condensed === $prefix || str_starts_with( $condensed, $prefix ) ) && strlen( $prefix ) > strlen( $bestPrefix ) ) {
				$bestPrefix = $prefix;
			}
		}

		if ( $bestPrefix !== '' ) {
			[ $name, $candidates ] = self::SIGNAL_MAP[ $bestPrefix ];

			return array( $name, $this->firstInstalled( $candidates, $installedSlugs ) );
		}

		// No alias: an installed slug whose condensed form equals or
		// prefixes the token (e.g. token "woocommercecart" matches slug
		// "woocommerce"). Longest match wins.
		$bestSlug = null;
		foreach ( $installedSlugs as $slug ) {
			$condensedSlug = self::condense( $slug );
			if ( ( $condensed === $condensedSlug || str_starts_with( $condensed, $condensedSlug ) )
				&& ( $bestSlug === null || strlen( $condensedSlug ) > strlen( self::condense( $bestSlug ) ) ) ) {
				$bestSlug = $slug;
			}
		}

		return array( $bestSlug ?? $token, $bestSlug );
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
