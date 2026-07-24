<?php

declare(strict_types=1);

namespace ProviderForCloudflareAiGateway\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use ProviderForCloudflareAiGateway\Gateway\InferenceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for `cloudflare-ai-gateway/v1/playground` — runs an ad-hoc
 * prompt against any model (Workers AI or, with BYOK configured on the
 * Cloudflare dashboard, a third-party provider) via InferenceClient, backing
 * the Playground admin tab.
 *
 * @since 0.6.0
 */
final class PlaygroundController {

	/**
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/playground',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'model'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'prompt' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ) {
		$model  = sanitize_text_field( (string) $request->get_param( 'model' ) );
		$prompt = (string) $request->get_param( 'prompt' );

		if ( $model === '' || $prompt === '' ) {
			return new WP_Error(
				'cfaig_missing_params',
				__( 'A model and a prompt are both required.', 'provider-for-cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$result = InferenceClient::run( $model, $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result );
	}
}
