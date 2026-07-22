<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Gateway;

use WP_Error;

use function WordPress\CloudflareAiGateway\get_account_id;
use function WordPress\CloudflareAiGateway\get_api_key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps Cloudflare's model-search API (`/ai/models/search`) with no task filter,
 * so it surfaces every model across every modality — text generation, image
 * generation, embeddings, ASR/TTS, vision, reasoning, etc. — not just the
 * text-generation slice CloudflareModelMetadataDirectory feeds to the AI Client.
 *
 * Cloudflare's own REST API reference and generated SDK both type the response
 * as opaque (`array of unknown`) — there is no documented per-model schema. This
 * class normalises the two fields confirmed by the original aipcf plugin
 * (`name` as the `@cf/...` path identifier, `task.name` as the modality) and
 * passes every other field through verbatim under `raw` so the admin UI can
 * opportunistically display whatever else Cloudflare actually returns
 * (description, pricing, context window, capability tags) without this class
 * having to hard-code an unconfirmed schema.
 *
 * @since 0.4.0
 *
 * @phpstan-type CatalogModel array{id: string, name: string, task: string, raw: array<string, mixed>}
 */
final class ModelCatalog {

	/**
	 * Transient key the full catalog is cached under.
	 *
	 * @since 0.4.0
	 */
	private const TRANSIENT_KEY = 'cfaig_full_model_catalog';

	/**
	 * Safety cap on pagination — Cloudflare's entire catalog is ~100 models as of
	 * 2026-07, so this comfortably covers real growth without an unbounded loop.
	 *
	 * @since 0.4.0
	 */
	private const MAX_PAGES = 10;

	/**
	 * Fetches every model in the Cloudflare account's catalog, across every task
	 * type, cached for 12 hours.
	 *
	 * @since 0.4.0
	 *
	 * @return list<CatalogModel>|WP_Error
	 */
	public static function fetchAll() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$accountId = get_account_id();
		$apiKey    = get_api_key();
		if ( $accountId === '' || $apiKey === '' ) {
			return new WP_Error(
				'cfaig_missing_credentials',
				__( 'Account ID and API token are both required to browse the model catalog.', 'cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$models = array();
		$page   = 1;

		do {
			$response = wp_remote_get(
				sprintf(
					'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search?per_page=100&page=%d',
					rawurlencode( $accountId ),
					$page
				),
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $apiKey,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 ) {
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$message = is_array( $decoded ) && ! empty( $decoded['errors'][0]['message'] )
					? (string) $decoded['errors'][0]['message']
					: sprintf( 'HTTP %d', $status );
				return new WP_Error( 'cfaig_catalog_api_error', $message, array( 'status' => $status ) );
			}

			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$result  = is_array( $decoded ) && isset( $decoded['result'] ) && is_array( $decoded['result'] )
				? $decoded['result']
				: array();

			foreach ( $result as $item ) {
				if ( ! is_array( $item ) || empty( $item['name'] ) || ! is_string( $item['name'] ) ) {
					continue;
				}

				$task = isset( $item['task']['name'] ) && is_string( $item['task']['name'] )
					? $item['task']['name']
					: __( 'Unknown', 'cloudflare-ai-gateway' );

				$models[] = array(
					'id'   => $item['name'],
					'name' => $item['name'],
					'task' => $task,
					'raw'  => $item,
				);
			}

			$resultCount = count( $result );
			++$page;
		} while ( $resultCount === 100 && $page <= self::MAX_PAGES );

		set_transient( self::TRANSIENT_KEY, $models, 12 * HOUR_IN_SECONDS );

		return $models;
	}

	/**
	 * Clears the cached catalog — called whenever credentials change.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function clearCache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Filters a catalog list by task and/or a case-insensitive substring match
	 * on model ID — shared by the REST catalog endpoint and the list-models
	 * ability so both apply identical filtering semantics.
	 *
	 * @since 0.7.0
	 *
	 * @param list<CatalogModel> $models
	 * @param string             $task
	 * @param string             $search
	 * @return list<CatalogModel>
	 */
	public static function filter( array $models, string $task = '', string $search = '' ): array {
		if ( $task !== '' ) {
			$models = array_values(
				array_filter(
					$models,
					static function ( array $model ) use ( $task ): bool {
						return $model['task'] === $task;
					}
				)
			);
		}

		if ( $search !== '' ) {
			$needle = strtolower( $search );
			$models = array_values(
				array_filter(
					$models,
					static function ( array $model ) use ( $needle ): bool {
						return str_contains( strtolower( $model['id'] ), $needle );
					}
				)
			);
		}

		return $models;
	}
}
