<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\CloudflareAiGateway\Gateway\GatewayClient;

use function WordPress\CloudflareAiGateway\get_gateway_id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for `cloudflare-ai-gateway/v1/logs` — backs the Logs admin
 * tab. Wraps GatewayClient::getLogs() and computes simple aggregates
 * (request count, cache-hit rate, total spend) from the returned page, since
 * Cloudflare doesn't expose a dedicated analytics/usage endpoint.
 *
 * @since 0.5.0
 */
final class LogsController {

	/**
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getLogs' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'model'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'provider' => array(
						'type'     => 'string',
						'required' => false,
					),
					'success'  => array(
						'type'     => 'string',
						'required' => false,
					),
					'cached'   => array(
						'type'     => 'string',
						'required' => false,
					),
					'page'     => array(
						'type'     => 'integer',
						'required' => false,
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
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function getLogs( WP_REST_Request $request ) {
		$gatewayId = get_gateway_id();
		if ( $gatewayId === '' ) {
			return new WP_Error(
				'cfaig_no_gateway',
				__( 'No gateway is configured yet — save your credentials on the Credentials tab first.', 'cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$params = array( 'per_page' => 50 );
		foreach ( array( 'model', 'provider', 'success', 'cached', 'page' ) as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null && $value !== '' ) {
				$params[ $field ] = $value;
			}
		}

		$response = GatewayClient::getLogs( $gatewayId, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$logs = is_array( $response['result'] ?? null ) ? $response['result'] : array();

		return new WP_REST_Response(
			array(
				'logs'       => $logs,
				'summary'    => GatewayClient::summarizeLogs( $logs ),
				'resultInfo' => $response['result_info'] ?? null,
			)
		);
	}
}
