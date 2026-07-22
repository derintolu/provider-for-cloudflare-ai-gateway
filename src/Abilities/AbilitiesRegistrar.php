<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Abilities;

use WP_Error;
use WordPress\CloudflareAiGateway\Gateway\GatewayClient;
use WordPress\CloudflareAiGateway\Gateway\InferenceClient;
use WordPress\CloudflareAiGateway\Gateway\ModelCatalog;

use function WordPress\CloudflareAiGateway\get_gateway_id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers this plugin's WordPress Abilities API surface — the same
 * capabilities exposed via REST for the admin UI (Model Catalog, Playground,
 * Gateway Config, Logs), also exposed as abilities so any Abilities-aware
 * consumer (the core Abilities Explorer, the MCP Adapter, other plugins) can
 * browse models, run inference, and read/update the gateway configuration
 * without going through wp-admin.
 *
 * Every ability shares the REST controllers' cap requirement (`manage_options`)
 * and business logic (Gateway\GatewayClient, Gateway\ModelCatalog,
 * Gateway\InferenceClient) — this class only adds the Abilities-specific
 * registration, schemas, and input/output shaping.
 *
 * @since 0.7.0
 */
final class AbilitiesRegistrar {

	/**
	 * Ability category slug abilities in this file are grouped under.
	 *
	 * @since 0.7.0
	 */
	public const CATEGORY = 'cloudflare-ai-gateway';

	/**
	 * Registers the ability category. Must run on the `wp_abilities_api_categories_init`
	 * hook, before registerAbilities() runs on `wp_abilities_api_init`.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public static function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Cloudflare AI Gateway', 'cloudflare-ai-gateway' ),
				'description' => __( 'Browse Cloudflare\'s AI model catalog, run inference, and manage the AI Gateway configuration.', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
			)
		);
	}

	/**
	 * Registers every ability. Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public static function registerAbilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerListModels();
		self::registerRunInference();
		self::registerGetGatewaySettings();
		self::registerUpdateGatewaySettings();
		self::registerGetGatewayLogs();
	}

	/**
	 * Shared permission check — every ability here requires the same
	 * capability as this plugin's REST endpoints and admin screens.
	 *
	 * @since 0.7.0
	 *
	 * @return bool
	 */
	public static function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private static function registerListModels(): void {
		wp_register_ability(
			self::CATEGORY . '/list-models',
			array(
				'label'               => __( 'List Cloudflare AI models', 'cloudflare-ai-gateway' ),
				'description'         => __( 'Returns every model available on the connected Cloudflare account, across every modality (text generation, image generation, embeddings, ASR/TTS, etc.), optionally filtered by task or a search term.', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'task'   => array(
							'type'        => 'string',
							'description' => __( 'Filter to a specific task/modality, e.g. "Text Generation".', 'cloudflare-ai-gateway' ),
						),
						'search' => array(
							'type'        => 'string',
							'description' => __( 'Case-insensitive substring match on model ID.', 'cloudflare-ai-gateway' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'models' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'   => array( 'type' => 'string' ),
									'name' => array( 'type' => 'string' ),
									'task' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'executeListModels' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * @since 0.7.0
	 *
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function executeListModels( $input ) {
		$models = ModelCatalog::fetchAll();
		if ( is_wp_error( $models ) ) {
			return $models;
		}

		$task   = is_array( $input ) && isset( $input['task'] ) ? (string) $input['task'] : '';
		$search = is_array( $input ) && isset( $input['search'] ) ? (string) $input['search'] : '';

		$filtered = ModelCatalog::filter( $models, $task, $search );

		return array(
			'models' => array_map(
				static function ( array $model ): array {
					return array(
						'id'   => $model['id'],
						'name' => $model['name'],
						'task' => $model['task'],
					);
				},
				$filtered
			),
		);
	}

	/**
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private static function registerRunInference(): void {
		wp_register_ability(
			self::CATEGORY . '/run-inference',
			array(
				'label'               => __( 'Run Cloudflare AI inference', 'cloudflare-ai-gateway' ),
				'description'         => __( 'Sends a prompt to any Cloudflare-hosted model (or, with BYOK configured on the Cloudflare dashboard, a third-party provider) through AI Gateway and returns the reply. This makes a real API call and may incur cost on the connected Cloudflare account.', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'model'  => array(
							'type'        => 'string',
							'description' => __( 'A Workers AI ID (@cf/...) or {provider}/{model} for a BYOK provider.', 'cloudflare-ai-gateway' ),
						),
						'prompt' => array(
							'type'        => 'string',
							'description' => __( 'The prompt text to send.', 'cloudflare-ai-gateway' ),
						),
					),
					'required'   => array( 'model', 'prompt' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'reply'   => array( 'type' => 'string' ),
						'latency' => array( 'type' => 'number' ),
					),
				),
				'execute_callback'    => array( self::class, 'executeRunInference' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * @since 0.7.0
	 *
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function executeRunInference( $input ) {
		$model  = is_array( $input ) && isset( $input['model'] ) ? (string) $input['model'] : '';
		$prompt = is_array( $input ) && isset( $input['prompt'] ) ? (string) $input['prompt'] : '';

		if ( $model === '' || $prompt === '' ) {
			return new WP_Error( 'cfaig_missing_params', __( 'A model and a prompt are both required.', 'cloudflare-ai-gateway' ) );
		}

		return InferenceClient::run( $model, $prompt );
	}

	/**
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private static function registerGetGatewaySettings(): void {
		wp_register_ability(
			self::CATEGORY . '/get-gateway-settings',
			array(
				'label'               => __( 'Read Cloudflare AI Gateway settings', 'cloudflare-ai-gateway' ),
				'description'         => __( 'Returns the configured AI Gateway\'s current settings (caching, rate limiting, retries, logging, authentication, and whatever else Cloudflare returns for it).', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'gatewayId' => array( 'type' => 'string' ),
					),
					'additionalProperties' => true,
				),
				'execute_callback'    => array( self::class, 'executeGetGatewaySettings' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * @since 0.7.0
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function executeGetGatewaySettings() {
		$gatewayId = get_gateway_id();
		if ( $gatewayId === '' ) {
			return new WP_Error( 'cfaig_no_gateway', __( 'No gateway is configured yet.', 'cloudflare-ai-gateway' ) );
		}

		$gateway = GatewayClient::getGateway( $gatewayId );
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}
		if ( $gateway === null ) {
			return new WP_Error( 'cfaig_gateway_not_found', __( 'The configured gateway no longer exists.', 'cloudflare-ai-gateway' ) );
		}

		return array_merge( array( 'gatewayId' => $gatewayId ), $gateway );
	}

	/**
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private static function registerUpdateGatewaySettings(): void {
		wp_register_ability(
			self::CATEGORY . '/update-gateway-settings',
			array(
				'label'               => __( 'Update Cloudflare AI Gateway settings', 'cloudflare-ai-gateway' ),
				'description'         => __( 'Updates the configured AI Gateway\'s caching, rate limiting, retry, logging, and authentication settings. Fields omitted from the input are left unchanged.', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'cache_ttl'                  => array( 'type' => 'integer' ),
						'cache_invalidate_on_update' => array( 'type' => 'boolean' ),
						'rate_limiting_interval'     => array( 'type' => 'integer' ),
						'rate_limiting_limit'        => array( 'type' => 'integer' ),
						'rate_limiting_technique'    => array(
							'type' => 'string',
							'enum' => array( 'fixed', 'sliding' ),
						),
						'retry_max_attempts'         => array( 'type' => 'integer' ),
						'retry_delay'                => array( 'type' => 'integer' ),
						'retry_backoff'              => array(
							'type' => 'string',
							'enum' => array( 'constant', 'linear', 'exponential' ),
						),
						'collect_logs'               => array( 'type' => 'boolean' ),
						'authentication'             => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'gatewayId' => array( 'type' => 'string' ),
					),
					'additionalProperties' => true,
				),
				'execute_callback'    => array( self::class, 'executeUpdateGatewaySettings' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * @since 0.7.0
	 *
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function executeUpdateGatewaySettings( $input ) {
		$gatewayId = get_gateway_id();
		if ( $gatewayId === '' ) {
			return new WP_Error( 'cfaig_no_gateway', __( 'No gateway is configured yet.', 'cloudflare-ai-gateway' ) );
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$config = array();
		foreach (
			array(
				'cache_ttl',
				'cache_invalidate_on_update',
				'rate_limiting_interval',
				'rate_limiting_limit',
				'rate_limiting_technique',
				'retry_max_attempts',
				'retry_delay',
				'retry_backoff',
				'collect_logs',
				'authentication',
			) as $field
		) {
			if ( array_key_exists( $field, $input ) ) {
				$config[ $field ] = $input[ $field ];
			}
		}

		$updated = GatewayClient::updateGateway( $gatewayId, $config );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array_merge( array( 'gatewayId' => $gatewayId ), $updated );
	}

	/**
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private static function registerGetGatewayLogs(): void {
		wp_register_ability(
			self::CATEGORY . '/get-gateway-logs',
			array(
				'label'               => __( 'Read Cloudflare AI Gateway logs', 'cloudflare-ai-gateway' ),
				'description'         => __( 'Returns recent requests logged by the AI Gateway, plus aggregate stats (request count, cache-hit rate, total spend), optionally filtered by model, provider, success, or cache status.', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'model'    => array( 'type' => 'string' ),
						'provider' => array( 'type' => 'string' ),
						'success'  => array( 'type' => 'boolean' ),
						'cached'   => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'logs'    => array( 'type' => 'array' ),
						'summary' => array(
							'type'       => 'object',
							'properties' => array(
								'requestCount' => array( 'type' => 'integer' ),
								'cacheHitRate' => array( 'type' => 'number' ),
								'totalCost'    => array( 'type' => 'number' ),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'executeGetGatewayLogs' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * @since 0.7.0
	 *
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function executeGetGatewayLogs( $input ) {
		$gatewayId = get_gateway_id();
		if ( $gatewayId === '' ) {
			return new WP_Error( 'cfaig_no_gateway', __( 'No gateway is configured yet.', 'cloudflare-ai-gateway' ) );
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$params = array( 'per_page' => 50 );
		foreach ( array( 'model', 'provider', 'success', 'cached' ) as $field ) {
			if ( array_key_exists( $field, $input ) && $input[ $field ] !== null && $input[ $field ] !== '' ) {
				$params[ $field ] = $input[ $field ];
			}
		}

		$response = GatewayClient::getLogs( $gatewayId, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$logs = is_array( $response['result'] ?? null ) ? $response['result'] : array();

		return array(
			'logs'    => $logs,
			'summary' => GatewayClient::summarizeLogs( $logs ),
		);
	}
}
