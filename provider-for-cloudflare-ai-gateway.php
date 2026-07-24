<?php

/**
 * Plugin Name: Provider for Cloudflare AI Gateway
 * Plugin URI: https://github.com/derintolu/provider-for-cloudflare-ai-gateway
 * Description: Run any Cloudflare-hosted AI model from your WordPress site, routed entirely through Cloudflare AI Gateway (caching, rate limiting, guardrails, logging) — with an admin UI that mirrors the Cloudflare dashboard and full WordPress Abilities API integration. Forked from AI Provider for Cloudflare.
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Version: 0.1.0
 * Author: Derin Tolu
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: provider-for-cloudflare-ai-gateway
 *
 * @package ProviderForCloudflareAiGateway
 *
 * Forked from "AI Provider for Cloudflare" (aipcf-ai-provider-for-cloudflare) by Abhishek Deshpande
 * https://wordpress.org/plugins/aipcf-ai-provider-for-cloudflare/ — used and modified under GPL-2.0-or-later.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- WordPress plugin bootstrap files mix declarations and side effects by design.

namespace ProviderForCloudflareAiGateway;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use ProviderForCloudflareAiGateway\Abilities\AbilitiesRegistrar;
use ProviderForCloudflareAiGateway\Admin\AdminMenu;
use ProviderForCloudflareAiGateway\Provider\CloudflareAiGatewayProvider;
use ProviderForCloudflareAiGateway\Rest\RestApi;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

define( 'CFAIG_VERSION', '0.1.0' );
define( 'CFAIG_FILE', __FILE__ );
define( 'CFAIG_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Option key under which all plugin settings are stored as a single associative array.
 */
const CFAIG_SETTINGS_OPTION = 'cfaig_settings';

/**
 * Transient key used to cache the live Cloudflare Workers AI text-generation model list.
 */
const CFAIG_MODELS_TRANSIENT = 'cfaig_model_list';

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Provider for Cloudflare AI Gateway: run "composer install" in the plugin directory before activating.',
					'provider-for-cloudflare-ai-gateway'
				)
			);
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Returns the plugin settings array merged with defaults.
 *
 * @since 0.1.0
 *
 * @return array{api_key: string, account_id: string, gateway_id: string, preferred_model: string}
 */
function get_plugin_settings(): array {
	$defaults = array(
		'api_key'         => '',
		'account_id'      => '',
		'gateway_id'      => '',
		'preferred_model' => '',
	);

	$stored = get_option( CFAIG_SETTINGS_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return array_merge( $defaults, $stored );
}

/**
 * Returns the configured Cloudflare API Token.
 *
 * Resolution order (first non-empty value wins):
 *  1. Plugin's own settings option.
 *  2. CLOUDFLARE_AI_GATEWAY_API_TOKEN PHP constant from wp-config.php.
 *  3. CLOUDFLARE_AI_GATEWAY_API_TOKEN environment variable.
 *  4. AI Client registry — covers keys set via Settings → Connectors
 *     without reading the underlying option directly.
 *
 * @since 0.1.0
 *
 * @return string
 */
function get_api_key(): string {
	$settings = get_plugin_settings();
	if ( ! empty( $settings['api_key'] ) ) {
		return (string) $settings['api_key'];
	}

	if ( defined( 'CLOUDFLARE_AI_GATEWAY_API_TOKEN' ) && is_string( \CLOUDFLARE_AI_GATEWAY_API_TOKEN ) ) {
		return \CLOUDFLARE_AI_GATEWAY_API_TOKEN;
	}

	$envKey = getenv( 'CLOUDFLARE_AI_GATEWAY_API_TOKEN' );
	if ( is_string( $envKey ) && $envKey !== '' ) {
		return $envKey;
	}

	// Fall back to the AI Client registry so keys set via the WordPress
	// Connectors screen are honoured without reading the option directly.
	if ( class_exists( AiClient::class ) ) {
		try {
			$auth = AiClient::defaultRegistry()
				->getProviderRequestAuthentication( CloudflareAiGatewayProvider::class );
			if (
				$auth instanceof ApiKeyRequestAuthentication
				&& method_exists( $auth, 'getApiKey' )
			) {
				$key = (string) $auth->getApiKey();
				if ( $key !== '' ) {
					return $key;
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Registry unavailable or method absent — return empty.
		}
	}

	return '';
}

/**
 * Returns the configured Cloudflare Account ID.
 *
 * Resolution order (first non-empty value wins):
 *  1. Plugin's own settings option.
 *  2. CLOUDFLARE_AI_GATEWAY_ACCOUNT_ID PHP constant from wp-config.php.
 *  3. CLOUDFLARE_AI_GATEWAY_ACCOUNT_ID environment variable.
 *
 * @since 0.1.0
 *
 * @return string
 */
function get_account_id(): string {
	$settings = get_plugin_settings();
	if ( ! empty( $settings['account_id'] ) ) {
		return (string) $settings['account_id'];
	}

	if ( defined( 'CLOUDFLARE_AI_GATEWAY_ACCOUNT_ID' ) && is_string( \CLOUDFLARE_AI_GATEWAY_ACCOUNT_ID ) ) {
		return \CLOUDFLARE_AI_GATEWAY_ACCOUNT_ID;
	}

	$envId = getenv( 'CLOUDFLARE_AI_GATEWAY_ACCOUNT_ID' );
	if ( is_string( $envId ) && $envId !== '' ) {
		return $envId;
	}

	return '';
}

/**
 * Returns the configured Cloudflare AI Gateway ID.
 *
 * All inference requests are routed through this gateway — there is no direct
 * (non-gateway) API fallback. Resolution order (first non-empty value wins):
 *  1. Plugin's own settings option (a named gateway explicitly created via
 *     the Gateway Config tab, for accounts whose API token has "AI Gateway:
 *     Edit" and wants per-gateway config/logging).
 *  2. CLOUDFLARE_AI_GATEWAY_ID PHP constant from wp-config.php.
 *  3. CLOUDFLARE_AI_GATEWAY_ID environment variable.
 *  4. Cloudflare's zero-config "default" gateway — every account has one
 *     automatically, and routing through it via the `cf-aig-gateway-id`
 *     header needs only Workers AI permissions, not "AI Gateway: Edit". This
 *     is why get_gateway_id() never returns an empty string: a gateway is
 *     always available without any management-API call.
 *
 * @since 0.2.0
 *
 * @return string
 */
function get_gateway_id(): string {
	$settings = get_plugin_settings();
	if ( ! empty( $settings['gateway_id'] ) ) {
		return (string) $settings['gateway_id'];
	}

	if ( defined( 'CLOUDFLARE_AI_GATEWAY_ID' ) && is_string( \CLOUDFLARE_AI_GATEWAY_ID ) ) {
		return \CLOUDFLARE_AI_GATEWAY_ID;
	}

	$envId = getenv( 'CLOUDFLARE_AI_GATEWAY_ID' );
	if ( is_string( $envId ) && $envId !== '' ) {
		return $envId;
	}

	return 'default';
}

/**
 * Returns the model ID that should be used as the default for auto-discovery.
 *
 * Resolution order (first non-empty value wins):
 *  1. "Default model" setting saved on the plugin's Credentials page.
 *  2. CLOUDFLARE_AI_GATEWAY_DEFAULT_MODEL PHP constant from wp-config.php.
 *  3. CLOUDFLARE_AI_GATEWAY_DEFAULT_MODEL environment variable.
 *  4. Built-in default: @cf/meta/llama-4-scout-17b-16e-instruct.
 *
 * @since 0.1.0
 *
 * @return string
 */
function get_default_model_id(): string {
	$settings = get_plugin_settings();
	if ( ! empty( $settings['preferred_model'] ) ) {
		return (string) $settings['preferred_model'];
	}

	if ( defined( 'CLOUDFLARE_AI_GATEWAY_DEFAULT_MODEL' ) && is_string( \CLOUDFLARE_AI_GATEWAY_DEFAULT_MODEL ) ) {
		return \CLOUDFLARE_AI_GATEWAY_DEFAULT_MODEL;
	}

	$envModel = getenv( 'CLOUDFLARE_AI_GATEWAY_DEFAULT_MODEL' );
	if ( is_string( $envModel ) && $envModel !== '' ) {
		return $envModel;
	}

	return '@cf/meta/llama-4-scout-17b-16e-instruct';
}

/**
 * Fetches the list of available Cloudflare Workers AI text generation models.
 *
 * Results are cached in a transient for 12 hours. Returns an empty array when
 * credentials are not configured or the API call fails — callers should fall
 * back to a static list in that case.
 *
 * @since 0.1.0
 *
 * @return list<array{id: string, name: string}>
 */
function fetch_available_models(): array {
	$cached = get_transient( CFAIG_MODELS_TRANSIENT );
	if ( is_array( $cached ) ) {
		/** @var list<array{id: string, name: string}> $cached */
		return $cached;
	}

	$accountId = get_account_id();
	$apiKey    = get_api_key();

	if ( $accountId === '' || $apiKey === '' ) {
		return array();
	}

	$url = sprintf(
		'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search?per_page=100',
		rawurlencode( $accountId )
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $apiKey,
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return array();
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || ! isset( $body['result'] ) || ! is_array( $body['result'] ) ) {
		return array();
	}

	$models = array();
	foreach ( $body['result'] as $item ) {
		if ( ! is_array( $item ) || empty( $item['name'] ) || ! is_string( $item['name'] ) ) {
			continue;
		}

		// The API returns a UUID in `id` and the @cf/... path identifier in `name`.
		// We need the path identifier for API calls, not the UUID.
		$modelId = $item['name'];

		// Skip non-text-generation models (embeddings, image gen, TTS, ASR, etc.)
		// so this dropdown only shows models that can handle chat/completion tasks.
		// The full multi-modality catalog lives in the Model Catalog admin page.
		$taskName = isset( $item['task']['name'] ) && is_string( $item['task']['name'] )
			? $item['task']['name']
			: '';
		if ( $taskName !== 'Text Generation' ) {
			continue;
		}

		$models[] = array(
			'id'   => $modelId,
			'name' => $modelId,
		);
	}

	if ( ! empty( $models ) ) {
		set_transient( CFAIG_MODELS_TRANSIENT, $models, 12 * HOUR_IN_SECONDS );
	}

	return $models;
}

/**
 * Returns whether this plugin has been approved to use the Cloudflare AI Gateway connector.
 *
 * The "AI" plugin can gate AI connector usage behind per-plugin administrator
 * approval via its Connector_Approval experiment. That experiment's classes
 * are Composer-autoloaded whenever the "AI" plugin is active regardless of
 * whether the experiment itself is turned on, so class_exists() alone can't
 * tell us whether the gate is actually enforcing anything — it's an opt-in
 * experiment, off by default. We check the same two options the "AI" plugin's
 * own Abstract_Feature::is_enabled() checks (global + per-feature toggle)
 * before treating "no recorded approval" as "blocked".
 *
 * @since 0.1.0
 *
 * @return bool
 */
function is_connector_approved(): bool {
	if ( ! class_exists( '\WordPress\AI\Connector_Approval\Approvals_Store' ) ) {
		return true;
	}

	if (
		! get_option( 'wpai_features_enabled', false )
		|| ! get_option( 'wpai_feature_connector-approval_enabled', false )
	) {
		return true;
	}

	if ( ! defined( 'CFAIG_FILE' ) ) {
		return false;
	}

	$approvals = get_option( 'wpai_connector_approvals', array() );
	if ( ! is_array( $approvals ) ) {
		return false;
	}

	$basename = plugin_basename( \CFAIG_FILE );
	return ! empty( $approvals[ $basename ][ CloudflareAiGatewayProvider::ID ] );
}

/**
 * Whether the WordPress core "Connectors" admin screen is managing this provider's API key.
 *
 * When true, the plugin's own settings page should defer the API key field to that screen
 * to avoid a confusing duplicate input.
 *
 * @since 0.1.0
 *
 * @return bool
 */
function core_connector_is_available(): bool {
	return function_exists( 'wp_is_connector_registered' )
		&& wp_is_connector_registered( CloudflareAiGatewayProvider::ID );
}

/**
 * Registers the Cloudflare AI Gateway provider with the WordPress AI Client.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( ! $registry->hasProvider( CloudflareAiGatewayProvider::class ) ) {
		$registry->registerProvider( CloudflareAiGatewayProvider::class );
	}

	$apiKey = get_api_key();
	if ( $apiKey !== '' ) {
		$registry->setProviderRequestAuthentication(
			CloudflareAiGatewayProvider::class,
			new ApiKeyRequestAuthentication( $apiKey )
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

/**
 * Boots the admin UI in WP Admin only.
 *
 * @since 0.1.0
 *
 * @return void
 */
function boot_admin(): void {
	if ( ! is_admin() ) {
		return;
	}

	AdminMenu::boot();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot_admin' );

/**
 * Registers the plugin's REST API routes. The React admin app is the only
 * consumer of these routes — Cloudflare credentials never reach the browser.
 *
 * @since 0.1.0
 *
 * @return void
 */
function boot_rest(): void {
	RestApi::registerRoutes();
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\boot_rest' );

/**
 * Registers this plugin's WordPress Abilities API category and abilities —
 * a no-op on any site without the Abilities API available (AbilitiesRegistrar
 * checks for wp_register_ability()/wp_register_ability_category() itself).
 *
 * @since 0.7.0
 *
 * @return void
 */
function boot_abilities_category(): void {
	AbilitiesRegistrar::registerCategory();
}
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\boot_abilities_category' );

/**
 * @since 0.7.0
 *
 * @return void
 */
function boot_abilities(): void {
	AbilitiesRegistrar::registerAbilities();
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\boot_abilities' );

/**
 * Prepends the configured Cloudflare model to the WordPress AI plugin's
 * text-model preference list.
 *
 * The `wpai_preferred_text_models` filter is provided by the WordPress "AI"
 * plugin and consumed by every ability that calls
 * `get_preferred_models_for_text_generation()` (excerpt generation, comment
 * moderation, etc.). Each entry is a `[provider_id, model_id]` tuple tried in
 * order; the first available provider wins. Prepending ensures Cloudflare is
 * attempted before the built-in Anthropic / Google / OpenAI fallbacks, so a
 * site that only has Cloudflare credentials still gets AI features.
 *
 * We skip the prepend when credentials are not configured so as not to queue
 * a provider that would fail immediately and degrade the fallback chain.
 *
 * @since 0.1.0
 *
 * @param mixed $models Existing preference list (expected list<array{0:string,1:string}>).
 * @return array<int, array{0: string, 1: string}>
 */
function filter_preferred_text_models( $models ): array {
	if ( ! is_array( $models ) ) {
		$models = array();
	}

	if ( get_api_key() === '' || get_account_id() === '' ) {
		return $models;
	}

	$entry = array( CloudflareAiGatewayProvider::ID, get_default_model_id() );

	foreach ( $models as $candidate ) {
		if (
			is_array( $candidate )
			&& isset( $candidate[0], $candidate[1] )
			&& $candidate[0] === $entry[0]
			&& $candidate[1] === $entry[1]
		) {
			return $models;
		}
	}

	array_unshift( $models, $entry );

	return $models;
}
add_filter( 'wpai_preferred_text_models', __NAMESPACE__ . '\\filter_preferred_text_models', 5 );

/**
 * Returns the vision-capable model ID to advertise to the WordPress AI plugin.
 *
 * Resolution order (first non-empty value wins):
 *  1. CLOUDFLARE_AI_GATEWAY_VISION_MODEL PHP constant from wp-config.php.
 *  2. CLOUDFLARE_AI_GATEWAY_VISION_MODEL environment variable.
 *  3. Built-in default: @cf/meta/llama-4-scout-17b-16e-instruct (natively
 *     multimodal, and already this plugin's default text model besides).
 *
 * @since 0.3.0
 *
 * @return string
 */
function get_default_vision_model_id(): string {
	if ( defined( 'CLOUDFLARE_AI_GATEWAY_VISION_MODEL' ) && is_string( \CLOUDFLARE_AI_GATEWAY_VISION_MODEL ) ) {
		return \CLOUDFLARE_AI_GATEWAY_VISION_MODEL;
	}

	$envModel = getenv( 'CLOUDFLARE_AI_GATEWAY_VISION_MODEL' );
	if ( is_string( $envModel ) && $envModel !== '' ) {
		return $envModel;
	}

	return '@cf/meta/llama-4-scout-17b-16e-instruct';
}

/**
 * Prepends a Cloudflare vision model to the WordPress AI plugin's vision-model
 * preference list, consumed by its Alt Text Generation feature.
 *
 * @since 0.3.0
 *
 * @param mixed $models Existing preference list (expected list<array{0:string,1:string}>).
 * @return array<int, array{0: string, 1: string}>
 */
function filter_preferred_vision_models( $models ): array {
	if ( ! is_array( $models ) ) {
		$models = array();
	}

	if ( get_api_key() === '' || get_account_id() === '' ) {
		return $models;
	}

	$entry = array( CloudflareAiGatewayProvider::ID, get_default_vision_model_id() );

	foreach ( $models as $candidate ) {
		if (
			is_array( $candidate )
			&& isset( $candidate[0], $candidate[1] )
			&& $candidate[0] === $entry[0]
			&& $candidate[1] === $entry[1]
		) {
			return $models;
		}
	}

	array_unshift( $models, $entry );

	return $models;
}
add_filter( 'wpai_preferred_vision_models', __NAMESPACE__ . '\\filter_preferred_vision_models', 5 );

/**
 * Returns the image generation model ID to advertise to the WordPress AI plugin.
 *
 * Resolution order (first non-empty value wins):
 *  1. CLOUDFLARE_AI_GATEWAY_IMAGE_MODEL PHP constant from wp-config.php.
 *  2. CLOUDFLARE_AI_GATEWAY_IMAGE_MODEL environment variable.
 *  3. Built-in default: @cf/black-forest-labs/flux-1-schnell (fast, and its
 *     JSON-base64 response is the simplest of this plugin's two supported
 *     image-model response shapes — see Models\CloudflareImageGenerationModel).
 *
 * @since 0.3.0
 *
 * @return string
 */
function get_default_image_model_id(): string {
	if ( defined( 'CLOUDFLARE_AI_GATEWAY_IMAGE_MODEL' ) && is_string( \CLOUDFLARE_AI_GATEWAY_IMAGE_MODEL ) ) {
		return \CLOUDFLARE_AI_GATEWAY_IMAGE_MODEL;
	}

	$envModel = getenv( 'CLOUDFLARE_AI_GATEWAY_IMAGE_MODEL' );
	if ( is_string( $envModel ) && $envModel !== '' ) {
		return $envModel;
	}

	return '@cf/black-forest-labs/flux-1-schnell';
}

/**
 * Prepends a Cloudflare image model to the WordPress AI plugin's image-model
 * preference list, consumed by its Image Generation feature.
 *
 * @since 0.3.0
 *
 * @param mixed $models Existing preference list (expected list<array{0:string,1:string}>).
 * @return array<int, array{0: string, 1: string}>
 */
function filter_preferred_image_models( $models ): array {
	if ( ! is_array( $models ) ) {
		$models = array();
	}

	if ( get_api_key() === '' || get_account_id() === '' ) {
		return $models;
	}

	$entry = array( CloudflareAiGatewayProvider::ID, get_default_image_model_id() );

	foreach ( $models as $candidate ) {
		if (
			is_array( $candidate )
			&& isset( $candidate[0], $candidate[1] )
			&& $candidate[0] === $entry[0]
			&& $candidate[1] === $entry[1]
		) {
			return $models;
		}
	}

	array_unshift( $models, $entry );

	return $models;
}
add_filter( 'wpai_preferred_image_models', __NAMESPACE__ . '\\filter_preferred_image_models', 5 );

/**
 * Declares image-generation support for this connector to the WordPress AI
 * plugin without it having to probe our models live — mirrors what
 * has_image_generation_support() in the AI plugin's own helpers.php does by
 * default, just without the round-trip through the model metadata directory.
 *
 * @since 0.3.0
 *
 * @param mixed $hasSupport Whether image generation is already known to be supported.
 * @param mixed $connectors Connector IDs being checked.
 * @return bool
 */
function filter_has_image_generation_support( $hasSupport, $connectors ): bool {
	if ( $hasSupport === true ) {
		return true;
	}

	if ( ! is_array( $connectors ) || ! in_array( CloudflareAiGatewayProvider::ID, $connectors, true ) ) {
		return (bool) $hasSupport;
	}

	return get_api_key() !== '' && get_account_id() !== '';
}
add_filter( 'wpai_has_image_generation_support', __NAMESPACE__ . '\\filter_has_image_generation_support', 10, 2 );
