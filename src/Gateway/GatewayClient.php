<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Gateway;

use WP_Error;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;

use function WordPress\CloudflareAiGateway\get_account_id;
use function WordPress\CloudflareAiGateway\get_api_key;
use function WordPress\CloudflareAiGateway\get_gateway_id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around Cloudflare's AI Gateway management REST API
 * (`/client/v4/accounts/{account_id}/ai-gateway/gateways`).
 *
 * All inference in this plugin is routed through a gateway managed here —
 * there is no direct (non-gateway) API path. See Models\CloudflareTextGenerationModel
 * and Provider\CloudflareAiGatewayProvider::baseUrl().
 *
 * @since 0.2.0
 *
 * @phpstan-type GatewayRecord array{id: string, created_on?: string, modified_on?: string, cache_ttl?: int|null}
 */
final class GatewayClient {

	/**
	 * ID used when auto-provisioning a gateway for this site. Cloudflare gateway
	 * IDs are unique per account, so a fresh site always gets this name unless
	 * one already exists (in which case it's reused, not recreated).
	 *
	 * @since 0.2.0
	 */
	public const DEFAULT_GATEWAY_ID = 'wordpress';

	/**
	 * Returns the gateway ID to use for inference.
	 *
	 * get_gateway_id() already resolves to Cloudflare's zero-config "default"
	 * gateway when nothing more specific is configured, so this never needs
	 * to call the gateway-management API (POST /ai-gateway/gateways) just to
	 * make inference work — that endpoint requires the separate "AI Gateway:
	 * Edit" permission, which a token scoped only for Workers AI won't have,
	 * and gateway routing via the `cf-aig-gateway-id` header doesn't need it.
	 * The management API (see createGateway() et al.) is only used when a
	 * user explicitly names/configures a gateway on the Gateway Config tab.
	 *
	 * @since 0.2.0
	 *
	 * @return string Gateway ID.
	 */
	public static function ensureGateway(): string {
		return get_gateway_id();
	}

	/**
	 * Guards against calling out to Cloudflare with an incomplete Account ID —
	 * for use at the top of any AiClient model's generate*Result() method. A
	 * gateway ID is always available (see ensureGateway()), so the only real
	 * prerequisite left to check here is the Account ID itself.
	 *
	 * @since 0.3.0
	 *
	 * @param ProviderMetadata $providerMetadata
	 * @return void
	 * @throws ResponseException If the Cloudflare Account ID is not configured.
	 */
	public static function ensureGatewayOrThrow( ProviderMetadata $providerMetadata ): void {
		if ( get_account_id() !== '' ) {
			return;
		}

		throw ResponseException::fromInvalidData( $providerMetadata->getName(), 'account_id', 'Cloudflare Account ID is not configured.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Lists every AI Gateway on the configured Cloudflare account.
	 *
	 * @since 0.2.0
	 *
	 * @return list<GatewayRecord>|WP_Error
	 */
	public static function listGateways() {
		$response = self::request( 'GET', '/gateways' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return is_array( $response['result'] ?? null ) ? $response['result'] : array();
	}

	/**
	 * Fetches a single gateway by ID, or null if it doesn't exist.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId
	 * @return GatewayRecord|null|WP_Error
	 */
	public static function getGateway( string $gatewayId ) {
		$response = self::request( 'GET', '/gateways/' . rawurlencode( $gatewayId ) );
		if ( is_wp_error( $response ) ) {
			if ( $response->get_error_data( 'status' ) === 404 ) {
				return null;
			}
			return $response;
		}

		return is_array( $response['result'] ?? null ) ? $response['result'] : null;
	}

	/**
	 * Creates a new gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $gatewayId
	 * @param array<string, mixed> $config Additional gateway config fields (caching, rate limiting, etc).
	 * @return GatewayRecord|WP_Error
	 */
	public static function createGateway( string $gatewayId, array $config = array() ) {
		$response = self::request( 'POST', '/gateways', array_merge( array( 'id' => $gatewayId ), $config ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['result'];
	}

	/**
	 * Updates an existing gateway's configuration.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $gatewayId
	 * @param array<string, mixed> $config
	 * @return GatewayRecord|WP_Error
	 */
	public static function updateGateway( string $gatewayId, array $config ) {
		$response = self::request( 'PUT', '/gateways/' . rawurlencode( $gatewayId ), $config );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['result'];
	}

	/**
	 * Lists recent requests logged by the gateway.
	 *
	 * @since 0.5.0
	 *
	 * @param string               $gatewayId
	 * @param array<string, mixed> $params Query params — Cloudflare documents: cached, success,
	 *                                     model, provider, start_date, end_date, min/max_tokens_in,
	 *                                     min/max_tokens_out, min/max_cost, order_by, search, per_page, page.
	 * @return array<string, mixed>|WP_Error Decoded response ({result, result_info} at minimum).
	 */
	public static function getLogs( string $gatewayId, array $params = array() ) {
		$query = http_build_query( $params );
		$path  = '/gateways/' . rawurlencode( $gatewayId ) . '/logs' . ( $query !== '' ? '?' . $query : '' );

		return self::request( 'GET', $path );
	}

	/**
	 * Computes simple aggregates (request count, cache-hit rate, total spend)
	 * from a page of log entries returned by getLogs() — shared by the REST
	 * logs endpoint and the get-gateway-logs ability, since Cloudflare doesn't
	 * expose a dedicated analytics/usage endpoint to get these from directly.
	 *
	 * @since 0.7.0
	 *
	 * @param list<array<string, mixed>> $logs
	 * @return array{requestCount: int, cacheHitRate: float, totalCost: float}
	 */
	public static function summarizeLogs( array $logs ): array {
		$requestCount = count( $logs );
		$cachedCount  = 0;
		$totalCost    = 0.0;

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}
			if ( ! empty( $log['cached'] ) ) {
				++$cachedCount;
			}
			$totalCost += isset( $log['cost'] ) && is_numeric( $log['cost'] ) ? (float) $log['cost'] : 0.0;
		}

		return array(
			'requestCount' => $requestCount,
			'cacheHitRate' => $requestCount > 0 ? round( ( $cachedCount / $requestCount ) * 100, 1 ) : 0.0,
			'totalCost'    => round( $totalCost, 6 ),
		);
	}

	/**
	 * Sends an authenticated request to the AI Gateway management API.
	 *
	 * @since 0.2.0
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path relative to `/ai-gateway`, including leading slash.
	 * @param array<string, mixed>|null $body   JSON body for POST/PUT requests.
	 * @return array<string, mixed>|WP_Error Decoded response body on success.
	 */
	private static function request( string $method, string $path, ?array $body = null ) {
		$accountId = get_account_id();
		$apiKey    = get_api_key();

		if ( $accountId === '' || $apiKey === '' ) {
			return new WP_Error(
				'cfaig_missing_credentials',
				__( 'Account ID and API token are required.', 'cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$url = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai-gateway%s',
			rawurlencode( $accountId ),
			$path
		);

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $apiKey,
				'Accept'        => 'application/json',
			),
		);

		if ( $body !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $decoded ) && ! empty( $decoded['errors'][0]['message'] )
				? (string) $decoded['errors'][0]['message']
				: sprintf( 'HTTP %d', $status );

			// A 401/403 here almost never means the token is entirely invalid —
			// Settings → Connectors already verified it via /user/tokens/verify
			// before it could be saved. It means the token is missing the
			// "AI Gateway - Edit" permission group, which is separate from
			// "Workers AI - Edit" and not implied by it.
			if ( $status === 401 || $status === 403 ) {
				$message .= ' ' . __( '(Your Cloudflare API token is missing the "AI Gateway - Edit" permission — "Workers AI - Edit" alone is not enough to create or manage a gateway.)', 'cloudflare-ai-gateway' );
			}

			return new WP_Error( 'cfaig_gateway_api_error', $message, array( 'status' => $status ) );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'cfaig_gateway_api_error', __( 'Unexpected response from Cloudflare.', 'cloudflare-ai-gateway' ) );
		}

		return $decoded;
	}
}
