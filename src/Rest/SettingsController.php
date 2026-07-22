<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\AiClient\AiClient;
use WordPress\CloudflareAiGateway\Gateway\GatewayClient;
use WordPress\CloudflareAiGateway\Gateway\ModelCatalog;
use WordPress\CloudflareAiGateway\Provider\CloudflareAiGatewayProvider;

use function WordPress\CloudflareAiGateway\core_connector_is_available;
use function WordPress\CloudflareAiGateway\fetch_available_models;
use function WordPress\CloudflareAiGateway\get_account_id;
use function WordPress\CloudflareAiGateway\get_api_key;
use function WordPress\CloudflareAiGateway\get_gateway_id;
use function WordPress\CloudflareAiGateway\get_plugin_settings;
use function WordPress\CloudflareAiGateway\is_connector_approved;

use const WordPress\CloudflareAiGateway\CFAIG_MODELS_TRANSIENT;
use const WordPress\CloudflareAiGateway\CFAIG_SETTINGS_OPTION;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for `cloudflare-ai-gateway/v1/settings` and `.../test-connection`.
 *
 * Backs the Credentials tab of the React admin app. The Cloudflare API token is
 * never returned in a GET response — only a boolean `hasApiKey` flag — so it
 * cannot leak into the browser after being saved.
 *
 * @since 0.1.0
 */
final class SettingsController {

	/**
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'updateSettings' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'account_id'      => array(
							'type'     => 'string',
							'required' => false,
						),
						'api_key'         => array(
							'type'     => 'string',
							'required' => false,
						),
						'gateway_id'      => array(
							'type'     => 'string',
							'required' => false,
						),
						'preferred_model' => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'testConnection' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'listTextModels' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/gateways',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'listGateways' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/test-inference',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'testInference' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);
	}

	/**
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function getSettings(): WP_REST_Response {
		$settings = get_plugin_settings();

		return new WP_REST_Response(
			array(
				'accountId'         => $settings['account_id'],
				'hasApiKey'         => get_api_key() !== '',
				'gatewayId'         => get_gateway_id(),
				'preferredModel'    => $settings['preferred_model'],
				'connectorManaged'  => core_connector_is_available(),
				'connectorApproved' => is_connector_approved(),
			)
		);
	}

	/**
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateSettings( WP_REST_Request $request ) {
		$current = get_plugin_settings();

		$accountIdParam = (string) $request->get_param( 'account_id' );
		$accountId      = sanitize_text_field( $accountIdParam !== '' ? $accountIdParam : $current['account_id'] );
		$preferredModel = sanitize_text_field( (string) $request->get_param( 'preferred_model' ) );

		$apiKeyParam = $request->get_param( 'api_key' );
		$apiKey      = $apiKeyParam !== null && $apiKeyParam !== ''
			? sanitize_text_field( (string) $apiKeyParam )
			: $current['api_key'];

		// An explicit gateway_id (picking an existing gateway from the dropdown)
		// always wins; otherwise keep whatever's already resolved so we don't
		// clobber an auto-provisioned gateway on an unrelated field save.
		$gatewayParam = $request->get_param( 'gateway_id' );
		$gatewayId    = $gatewayParam !== null && $gatewayParam !== ''
			? sanitize_text_field( (string) $gatewayParam )
			: $current['gateway_id'];

		$credentialsChanged = ( $accountId !== $current['account_id'] || $apiKey !== $current['api_key'] );

		// A gateway can only be resolved once we have full credentials; a
		// change to either invalidates whatever gateway was resolved before.
		if ( $credentialsChanged ) {
			$gatewayId = ( $gatewayParam !== null && $gatewayParam !== '' )
				? sanitize_text_field( (string) $gatewayParam )
				: '';
		}

		// Resolves to Cloudflare's zero-config "default" gateway when nothing
		// more specific is configured — see GatewayClient::ensureGateway().
		if ( $gatewayId === '' && $accountId !== '' && $apiKey !== '' ) {
			$gatewayId = GatewayClient::ensureGateway();
		}

		update_option(
			CFAIG_SETTINGS_OPTION,
			array(
				'api_key'         => $apiKey,
				'account_id'      => $accountId,
				'gateway_id'      => $gatewayId,
				'preferred_model' => $preferredModel,
			)
		);

		if ( $credentialsChanged ) {
			delete_transient( CFAIG_MODELS_TRANSIENT );
			ModelCatalog::clearCache();
		}

		return $this->getSettings();
	}

	/**
	 * @since 0.2.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function listGateways() {
		$gateways = GatewayClient::listGateways();
		if ( is_wp_error( $gateways ) ) {
			return $gateways;
		}

		return new WP_REST_Response( array( 'gateways' => $gateways ) );
	}

	/**
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function testConnection() {
		$accountId = get_account_id();
		$apiKey    = get_api_key();

		if ( $accountId === '' || $apiKey === '' ) {
			return new WP_Error(
				'cfaig_missing_credentials',
				__( 'Account ID and API token are both required before testing the connection.', 'cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_connector_approved() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'reason'  => 'pending_approval',
					'message' => __( 'Connector not yet approved — allow this plugin on the Connectors screen, then test again.', 'cloudflare-ai-gateway' ), // phpcs:ignore Generic.Files.LineLength.TooLong
				)
			);
		}

		$url      = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search?per_page=1',
			rawurlencode( $accountId )
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'reason'  => 'network_error',
					'message' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$body    = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			$message = is_array( $decoded ) && ! empty( $decoded['errors'][0]['message'] )
				? (string) $decoded['errors'][0]['message']
				: sprintf( 'HTTP %d', $status );

			return new WP_REST_Response(
				array(
					'success' => false,
					'reason'  => 'api_error',
					'message' => $message,
				)
			);
		}

		delete_transient( CFAIG_MODELS_TRANSIENT );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Connected ✓ — credentials are valid.', 'cloudflare-ai-gateway' ),
			)
		);
	}

	/**
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function listTextModels(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'models'   => fetch_available_models(),
				'isLive'   => get_account_id() !== '' && get_api_key() !== '',
				'approved' => is_connector_approved(),
			)
		);
	}

	/**
	 * Sends a small fixed prompt through the configured provider via the WordPress
	 * AI Client — the same code path every other AI Client feature uses — to
	 * confirm the AI Gateway route actually works end to end. Returns only
	 * success/failure and the model's reply; nothing credential-related.
	 *
	 * @since 0.2.0
	 *
	 * @return WP_REST_Response
	 */
	public function testInference(): WP_REST_Response {
		if ( ! class_exists( AiClient::class ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'The WordPress AI Client is not available on this site.', 'cloudflare-ai-gateway' ),
				)
			);
		}

		$startedAt = microtime( true );

		try {
			$result = AiClient::prompt( 'Reply with only the single word: OK' )
				->usingProvider( CloudflareAiGatewayProvider::ID )
				->generateTextResult();

			return new WP_REST_Response(
				array(
					'success' => true,
					'reply'   => $result->toText(),
					'model'   => $result->getModelMetadata()->getId(),
					'latency' => round( ( microtime( true ) - $startedAt ) * 1000 ),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				)
			);
		}
	}
}
