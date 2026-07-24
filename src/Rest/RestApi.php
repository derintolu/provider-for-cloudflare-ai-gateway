<?php

/**
 * Registers every REST controller for the plugin's `cloudflare-ai-gateway/v1` namespace.
 *
 * @since 0.1.0
 *
 * @package ProviderForCloudflareAiGateway
 */

declare(strict_types=1);

namespace ProviderForCloudflareAiGateway\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The React admin app is the only intended consumer of these routes — Cloudflare
 * credentials are resolved server-side and never sent to the browser.
 *
 * @since 0.1.0
 */
final class RestApi {

	/**
	 * REST namespace shared by every controller in this plugin.
	 *
	 * @since 0.1.0
	 */
	public const NAMESPACE = 'cloudflare-ai-gateway/v1';

	/**
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function registerRoutes(): void {
		( new SettingsController() )->registerRoutes();
		( new CatalogController() )->registerRoutes();
		( new GatewayConfigController() )->registerRoutes();
		( new LogsController() )->registerRoutes();
		( new PlaygroundController() )->registerRoutes();
	}
}
