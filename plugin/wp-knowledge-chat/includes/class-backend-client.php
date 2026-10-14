<?php
/**
 * Server-to-server client for the AI backend.
 *
 * The backend URL and shared secret come from wp-config.php constants so
 * they never live in the options table, the admin UI or frontend output:
 *
 *   define( 'WP_KNOWLEDGE_CHAT_BACKEND_URL', 'https://chat-api.example.com' );
 *   define( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET', '...64 hex chars...' );
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Backend_Client {

	public const TIMEOUT_SECONDS    = 20;
	public const MAX_RESPONSE_BYTES = 65536;
	private const MIN_SECRET_LENGTH = 32;

	public static function base_url(): ?string {
		$url = defined( 'WP_KNOWLEDGE_CHAT_BACKEND_URL' ) ? constant( 'WP_KNOWLEDGE_CHAT_BACKEND_URL' ) : '';
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme   = $parts['scheme'] ?? '';
		$is_local = in_array( $parts['host'], array( 'localhost', '127.0.0.1' ), true );
		// Plain http is accepted only for a backend on the same machine (local development).
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $is_local ) ) {
			return null;
		}
		return untrailingslashit( $url );
	}

	private static function secret(): ?string {
		$secret = defined( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET' ) ? constant( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET' ) : '';
		return is_string( $secret ) && strlen( $secret ) >= self::MIN_SECRET_LENGTH ? $secret : null;
	}

	public static function is_configured(): bool {
		return null !== self::base_url() && null !== self::secret();
	}

	/**
	 * Signature format shared with backend/src/lib/auth.ts.
	 */
	public static function sign( string $secret, string $timestamp, string $nonce, string $client_id, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $client_id . '.' . $body, $secret );
	}

	/**
	 * @return array{status:int, body:mixed, retry_after:?int}|\WP_Error
	 */
	public static function chat( string $message, string $language, string $client_id ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'wkc_not_configured', 'Backend is not configured.' );
		}

		$body      = wp_json_encode( array( 'message' => $message, 'language' => $language ) );
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );

		$response = wp_remote_post(
			self::base_url() . '/api/chat',
			array(
				'timeout'             => self::TIMEOUT_SECONDS,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => array(
					'Content-Type'    => 'application/json',
					'Accept'          => 'application/json',
					'X-WKC-Timestamp' => $timestamp,
					'X-WKC-Nonce'     => $nonce,
					'X-WKC-Client'    => $client_id,
					'X-WKC-Signature' => self::sign( self::secret(), $timestamp, $nonce, $client_id, $body ),
				),
				'body'                => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => json_decode( wp_remote_retrieve_body( $response ), true ),
			'retry_after' => is_numeric( $retry_after ) ? (int) $retry_after : null,
		);
	}

	/**
	 * Lightweight connectivity check used by the admin "Test connection" action.
	 *
	 * @return array{ok:bool, status:int}
	 */
	public static function health(): array {
		if ( null === self::base_url() ) {
			return array( 'ok' => false, 'status' => 0 );
		}
		$response = wp_remote_get(
			self::base_url() . '/api/health',
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 4096,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0 );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return array( 'ok' => 200 === $status, 'status' => $status );
	}
}
