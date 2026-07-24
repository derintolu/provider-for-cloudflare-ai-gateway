<?php

declare(strict_types=1);

namespace ProviderForCloudflareAiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level "Cloudflare AI Gateway" admin menu and mounts the
 * React admin app built from assets/src (see webpack.config.js).
 *
 * @since 0.1.0
 */
final class AdminMenu {

	/**
	 * Slug of the top-level admin menu page.
	 *
	 * @since 0.1.0
	 */
	public const PAGE_SLUG = 'provider-for-cloudflare-ai-gateway';

	/**
	 * Wires up admin hooks. Idempotent — safe to call multiple times.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'admin_menu', array( self::class, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
	}

	/**
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function registerMenu(): void {
		add_menu_page(
			__( 'Provider for Cloudflare AI Gateway', 'provider-for-cloudflare-ai-gateway' ),
			__( 'Cloudflare AI Gateway', 'provider-for-cloudflare-ai-gateway' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'renderPage' ),
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data: URI for the menu icon, not obfuscation.
			'data:image/svg+xml;base64,' . base64_encode(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset, not a remote URL.
				(string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/images/cloudflare.svg' )
			)
		);
	}

	/**
	 * @since 0.1.0
	 *
	 * @param string $hookSuffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueueAssets( string $hookSuffix ): void {
		if ( $hookSuffix !== 'toplevel_page_' . self::PAGE_SLUG ) {
			return;
		}

		$assetFile = dirname( __DIR__, 2 ) . '/assets/build/index.asset.php';
		if ( ! file_exists( $assetFile ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__(
							'Provider for Cloudflare AI Gateway: run "npm install && npm run build" in the plugin directory.',
							'provider-for-cloudflare-ai-gateway'
						)
					);
				}
			);
			return;
		}

		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset = require $assetFile;

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_script(
			'cloudflare-ai-gateway-admin',
			plugins_url( 'assets/build/index.js', \CFAIG_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( dirname( __DIR__, 2 ) . '/assets/build/style-index.css' ) ) {
			wp_enqueue_style(
				'cloudflare-ai-gateway-admin',
				plugins_url( 'assets/build/style-index.css', \CFAIG_FILE ),
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_set_script_translations( 'cloudflare-ai-gateway-admin', 'provider-for-cloudflare-ai-gateway' );

		wp_localize_script(
			'cloudflare-ai-gateway-admin',
			'cfaigAdmin',
			array(
				'connectorsUrl' => admin_url( 'options-connectors.php' ),
			)
		);
	}

	/**
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div id="cloudflare-ai-gateway-root" class="wrap"></div>';
	}
}
