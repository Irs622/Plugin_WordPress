<?php
/**
 * Public chat endpoint: POST /wp-json/wp-knowledge-chat/v1/chat
 *
 * The browser talks only to this same-origin endpoint. It validates input,
 * applies a per-visitor rate limit, forwards a signed request to the AI
 * backend and re-validates the backend response before returning it.
 *
 * The endpoint is intentionally public (anonymous visitors use the chat).
 * A WordPress nonce is not required: it would not authorise anything for
 * anonymous users and breaks on full-page caches (LiteSpeed). Abuse is
 * limited by origin checks, size limits and rate limits here and in the
 * backend.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Rest_Controller {

	public const NAMESPACE         = 'wp-knowledge-chat/v1';
	public const MAX_MESSAGE_CHARS = 500;
	public const MAX_ANSWER_CHARS  = 2000;
	public const MAX_SOURCES       = 5;

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'language' => array(
						'type' => 'string',
						'enum' => Settings::LANGUAGES,
					),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_chat( \WP_REST_Request $request ) {
		$settings = Settings::get();
		$language = $settings['language'];

		if ( ! $settings['enabled'] || ! Backend_Client::is_configured() ) {
			return $this->error( 'wkc_unavailable', 'unavailable', $language, 503 );
		}

		if ( ! $this->origin_allowed( $request->get_header( 'origin' ) ) ) {
			return $this->error( 'wkc_forbidden_origin', 'forbidden', $language, 403 );
		}

		$requested_language = $request->get_param( 'language' );
		if ( is_string( $requested_language ) && in_array( $requested_language, Settings::LANGUAGES, true ) ) {
			$language = $requested_language;
		}

		$message = $request->get_param( 'message' );
		if ( ! is_string( $message ) ) {
			return $this->error( 'wkc_invalid_message', 'invalid', $language, 400 );
		}
		$message = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', wp_check_invalid_utf8( $message ) ) ?? '' );
		if ( '' === $message ) {
			return $this->error( 'wkc_invalid_message', 'invalid', $language, 400 );
		}
		if ( mb_strlen( $message ) > self::MAX_MESSAGE_CHARS ) {
			return $this->error( 'wkc_message_too_long', 'too_long', $language, 400 );
		}

		$ip = $this->client_ip();
		if ( ! $this->rate_limit_allows( $ip ) ) {
			$response = $this->error( 'wkc_rate_limited', 'rate_limited', $language, 429 );
			return $response;
		}

		// The backend receives an opaque, salted identifier, never the visitor's IP address.
		$client_id = substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$result    = Backend_Client::chat( $message, $language, $client_id );

		if ( is_wp_error( $result ) ) {
			return $this->error( 'wkc_unavailable', 'unavailable', $language, 503 );
		}
		if ( 429 === $result['status'] ) {
			return $this->error( 'wkc_rate_limited', 'rate_limited', $language, 429 );
		}
		if ( 200 !== $result['status'] ) {
			return $this->error( 'wkc_unavailable', 'unavailable', $language, 503 );
		}

		$clean = self::sanitize_backend_response( $result['body'] );
		if ( null === $clean ) {
			return $this->error( 'wkc_unavailable', 'unavailable', $language, 503 );
		}

		$response = new \WP_REST_Response( $clean, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Re-validates the backend payload. The backend is trusted for content
	 * but not for markup or link destinations.
	 *
	 * @param mixed $body Decoded backend JSON.
	 * @return array{answer:string, sources:array<int,array{title:string,url:string}>, grounded:bool}|null
	 */
	public static function sanitize_backend_response( $body ): ?array {
		if ( ! is_array( $body ) || ! isset( $body['answer'] ) || ! is_string( $body['answer'] ) ) {
			return null;
		}
		if ( ! isset( $body['sources'] ) || ! is_array( $body['sources'] ) || ! isset( $body['grounded'] ) || ! is_bool( $body['grounded'] ) ) {
			return null;
		}

		$answer = trim( sanitize_textarea_field( $body['answer'] ) );
		if ( '' === $answer ) {
			return null;
		}
		if ( mb_strlen( $answer ) > self::MAX_ANSWER_CHARS ) {
			$answer = mb_substr( $answer, 0, self::MAX_ANSWER_CHARS - 1 ) . '…';
		}

		$hosts   = Settings::approved_source_hosts();
		$sources = array();
		$seen    = array();
		foreach ( array_slice( $body['sources'], 0, self::MAX_SOURCES ) as $source ) {
			if ( ! is_array( $source ) || ! isset( $source['title'], $source['url'] ) || ! is_string( $source['title'] ) ) {
				continue;
			}
			$url   = Settings::validate_https_url( $source['url'], $hosts );
			$title = mb_substr( trim( sanitize_text_field( $source['title'] ) ), 0, 200 );
			if ( null === $url || '' === $title || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$sources[]    = array(
				'title' => $title,
				'url'   => $url,
			);
		}

		return array(
			'answer'   => $answer,
			'sources'  => $sources,
			// A "grounded" answer without any verifiable source is not presented as grounded.
			'grounded' => $body['grounded'] && count( $sources ) > 0,
		);
	}

	/**
	 * Browsers send Origin on cross-site POSTs. Reject other sites embedding
	 * this endpoint; requests without Origin (non-browser) remain rate-limited.
	 */
	private function origin_allowed( ?string $origin ): bool {
		if ( null === $origin || '' === $origin ) {
			return true;
		}
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$allowed     = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
		/**
		 * Filters hostnames allowed to call the chat endpoint from a browser.
		 *
		 * @param string[] $allowed Hostnames.
		 */
		$allowed = apply_filters( 'wp_knowledge_chat_allowed_origins', $allowed );
		return is_string( $origin_host ) && in_array( strtolower( $origin_host ), array_map( 'strtolower', (array) $allowed ), true );
	}

	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limiting. Only override this
		 * (e.g. to read a CDN header) when the header is set by a trusted proxy.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = apply_filters( 'wp_knowledge_chat_client_ip', $ip );
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Per-visitor limit using a sliding-window counter stored in transients:
	 * the previous minute's count is weighted by how much of it still overlaps
	 * the last 60 seconds, so bursts across a minute boundary cannot exceed
	 * the limit. Protects PHP workers on shared hosting; the backend applies
	 * its own limits.
	 */
	private function rate_limit_allows( string $ip ): bool {
		/**
		 * Filters the maximum chat requests per visitor per minute.
		 *
		 * @param int $limit Requests per minute.
		 */
		$limit   = max( 1, (int) apply_filters( 'wp_knowledge_chat_rate_limit_per_minute', 6 ) );
		$now     = time();
		$window  = intdiv( $now, MINUTE_IN_SECONDS );
		$elapsed = $now % MINUTE_IN_SECONDS;
		$key     = fn( int $w ) => 'wkc_rl_' . substr( hash_hmac( 'sha256', $ip . '|' . $w, wp_salt( 'nonce' ) ), 0, 32 );

		$current  = (int) get_transient( $key( $window ) );
		$previous = (int) get_transient( $key( $window - 1 ) );
		$weighted = (int) ceil( $previous * ( MINUTE_IN_SECONDS - $elapsed ) / MINUTE_IN_SECONDS );

		if ( $current + $weighted >= $limit ) {
			return false;
		}
		set_transient( $key( $window ), $current + 1, 2 * MINUTE_IN_SECONDS + 5 );
		return true;
	}

	private function error( string $code, string $kind, string $language, int $status ): \WP_Error {
		$messages = array(
			'en' => array(
				'unavailable'  => 'The assistant is unavailable right now. Please try again later or contact us directly.',
				'forbidden'    => 'This request is not allowed.',
				'invalid'      => 'Please enter a question.',
				'too_long'     => sprintf( 'Please keep your question under %d characters.', self::MAX_MESSAGE_CHARS ),
				'rate_limited' => 'You are sending messages too quickly. Please wait a minute and try again.',
			),
			'id' => array(
				'unavailable'  => 'Asisten sedang tidak tersedia. Silakan coba lagi nanti atau hubungi kami secara langsung.',
				'forbidden'    => 'Permintaan ini tidak diizinkan.',
				'invalid'      => 'Silakan tulis pertanyaan Anda.',
				'too_long'     => sprintf( 'Pertanyaan maksimal %d karakter.', self::MAX_MESSAGE_CHARS ),
				'rate_limited' => 'Anda mengirim pesan terlalu cepat. Silakan tunggu sebentar lalu coba lagi.',
			),
		);
		$lang = isset( $messages[ $language ] ) ? $language : 'en';
		return new \WP_Error( $code, $messages[ $lang ][ $kind ], array( 'status' => $status ) );
	}
}
