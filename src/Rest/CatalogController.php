<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\CloudflareAiGateway\Gateway\ModelCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for `cloudflare-ai-gateway/v1/catalog` — the full, unfiltered
 * multi-modal model catalog backing the Model Catalog admin tab.
 *
 * @since 0.4.0
 */
final class CatalogController {

	/**
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getCatalog' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'task'   => array(
						'type'     => 'string',
						'required' => false,
					),
					'search' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 0.4.0
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function getCatalog( WP_REST_Request $request ) {
		$allModels = ModelCatalog::fetchAll();
		if ( is_wp_error( $allModels ) ) {
			return $allModels;
		}

		$tasks = array_values( array_unique( array_column( $allModels, 'task' ) ) );
		sort( $tasks );

		$models = ModelCatalog::filter(
			$allModels,
			(string) $request->get_param( 'task' ),
			(string) $request->get_param( 'search' )
		);

		return new WP_REST_Response(
			array(
				'models' => $models,
				'tasks'  => $tasks,
			)
		);
	}
}
