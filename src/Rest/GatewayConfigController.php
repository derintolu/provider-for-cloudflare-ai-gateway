<?php

declare(strict_types=1);

namespace ProviderForCloudflareAiGateway\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use ProviderForCloudflareAiGateway\Gateway\GatewayClient;

use function ProviderForCloudflareAiGateway\get_gateway_id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for `cloudflare-ai-gateway/v1/gateway-config` — reads and
 * updates the configured gateway's settings for the Gateway Config admin tab.
 *
 * Only exposes write controls for fields whose request shape is confirmed by
 * Cloudflare's AI Gateway API documentation: caching, rate limiting, retries,
 * request logging, and the authenticated-gateway toggle. Guardrails, DLP and
 * spend limits are read-only here — their exact nested schemas aren't
 * confirmed with enough confidence to safely write, so getGatewayConfig()
 * reports whether each is configured and the UI links to the Cloudflare
 * dashboard for changing them, same as the confirmed dashboard-only features
 * (Dynamic Routing, BYOK, Custom Providers).
 *
 * @since 0.5.0
 */
final class GatewayConfigController {

	/**
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/gateway-config',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getGatewayConfig' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'updateGatewayConfig' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'cache_ttl'                  => array(
							'type'     => 'integer',
							'required' => false,
						),
						'cache_invalidate_on_update' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'rate_limiting_interval'     => array(
							'type'     => 'integer',
							'required' => false,
						),
						'rate_limiting_limit'        => array(
							'type'     => 'integer',
							'required' => false,
						),
						'rate_limiting_technique'    => array(
							'type'     => 'string',
							'required' => false,
						),
						'retry_max_attempts'         => array(
							'type'     => 'integer',
							'required' => false,
						),
						'retry_delay'                => array(
							'type'     => 'integer',
							'required' => false,
						),
						'retry_backoff'              => array(
							'type'     => 'string',
							'required' => false,
						),
						'collect_logs'               => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'authentication'             => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 0.5.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function getGatewayConfig() {
		$gatewayId = get_gateway_id();
		if ( $gatewayId === '' ) {
			return new WP_Error(
				'cfaig_no_gateway',
				__( 'No gateway is configured yet — save your credentials on the Credentials tab first.', 'provider-for-cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$gateway = GatewayClient::getGateway( $gatewayId );
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}
		if ( $gateway === null ) {
			return new WP_Error(
				'cfaig_gateway_not_found',
				__( 'The configured gateway no longer exists on your Cloudflare account.', 'provider-for-cloudflare-ai-gateway' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'gatewayId' => $gatewayId,
				'gateway'   => $gateway,
			)
		);
	}

	/**
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateGatewayConfig( WP_REST_Request $request ) {
		$gatewayId = get_gateway_id();
		if ( $gatewayId === '' ) {
			return new WP_Error(
				'cfaig_no_gateway',
				__( 'No gateway is configured yet — save your credentials on the Credentials tab first.', 'provider-for-cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$config = array();
		foreach (
			array(
				'cache_ttl'                  => 'integer',
				'cache_invalidate_on_update' => 'boolean',
				'rate_limiting_interval'     => 'integer',
				'rate_limiting_limit'        => 'integer',
				'rate_limiting_technique'    => 'string',
				'retry_max_attempts'         => 'integer',
				'retry_delay'                => 'integer',
				'retry_backoff'              => 'string',
				'collect_logs'               => 'boolean',
				'authentication'             => 'boolean',
			) as $field => $type
		) {
			$value = $request->get_param( $field );
			if ( $value === null ) {
				continue;
			}
			$config[ $field ] = $type === 'string' ? sanitize_text_field( (string) $value ) : $value;
		}

		$updated = GatewayClient::updateGateway( $gatewayId, $config );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return new WP_REST_Response(
			array(
				'gatewayId' => $gatewayId,
				'gateway'   => $updated,
			)
		);
	}
}
