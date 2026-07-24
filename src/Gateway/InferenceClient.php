<?php

declare(strict_types=1);

namespace ProviderForCloudflareAiGateway\Gateway;

use WP_Error;

use function ProviderForCloudflareAiGateway\get_account_id;
use function ProviderForCloudflareAiGateway\get_api_key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs an ad-hoc chat completion against ANY model — Workers AI (`@cf/...`) or,
 * where the account has BYOK configured on the Cloudflare dashboard, a
 * third-party provider (`openai/gpt-4.1`, `anthropic/claude-*`, etc.) — via AI
 * Gateway's unified OpenAI-compatible endpoint. This is the one code path in
 * the plugin that can reach any provider, not just Workers AI; it backs the
 * Playground admin tab and (in a later milestone) the `run-inference` ability.
 *
 * Third-party models require BYOK to already be configured on the Cloudflare
 * dashboard — that binding has no REST API (see GatewayClient's class doc) so
 * this class can't set it up; it can only route through a gateway once BYOK
 * is in place.
 *
 * @since 0.6.0
 *
 * @phpstan-type InferenceResult array{
 *     success: true,
 *     reply: string,
 *     latency: float,
 *     raw: array<string, mixed>
 * }
 */
final class InferenceClient {

	/**
	 * Runs a single-turn chat completion.
	 *
	 * @since 0.6.0
	 *
	 * @param string $model  A Workers AI ID (`@cf/meta/llama-4-scout-17b-16e-instruct`)
	 *                       or `{provider}/{model}` for a BYOK third-party provider.
	 * @param string $prompt The user message text.
	 * @return InferenceResult|WP_Error
	 */
	public static function run( string $model, string $prompt ) {
		$accountId = get_account_id();
		$apiKey    = get_api_key();
		$gatewayId = GatewayClient::ensureGateway();

		if ( $accountId === '' || $apiKey === '' ) {
			return new WP_Error(
				'cfaig_missing_credentials',
				__( 'Account ID and API token are both required.', 'provider-for-cloudflare-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$url = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/v1/chat/completions',
			rawurlencode( $accountId )
		);

		$startedAt = microtime( true );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'     => 'Bearer ' . $apiKey,
					'Content-Type'      => 'application/json',
					'cf-aig-gateway-id' => $gatewayId,
				),
				'body'    => wp_json_encode(
					array(
						'model'    => $model,
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		$latency = round( ( microtime( true ) - $startedAt ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'HTTP %d: %s', $status, $body );
			return new WP_Error( 'cfaig_inference_error', $message, array( 'status' => $status ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cfaig_inference_error', __( 'Unexpected response from Cloudflare.', 'provider-for-cloudflare-ai-gateway' ) );
		}

		$reply = $data['choices'][0]['message']['content'] ?? null;

		return array(
			'success' => true,
			'reply'   => is_string( $reply ) ? $reply : wp_json_encode( $data ),
			'latency' => $latency,
			'raw'     => $data,
		);
	}
}
