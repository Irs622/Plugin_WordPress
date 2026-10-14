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
					'history'  => array(
						'type'     => 'array',
						'required' => false,
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
		$message = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', wp_check_invalid_utf8( $message ) ) ?? '' );
		if ( '' === $message ) {
			return $this->error( 'wkc_invalid_message', 'invalid', $language, 400 );
		}
		if ( mb_strlen( $message ) > self::MAX_MESSAGE_CHARS ) {
			return $this->error( 'wkc_message_too_long', 'too_long', $language, 400 );
		}

		$history     = array();
		$raw_history = $request->get_param( 'history' );
		if ( is_array( $raw_history ) ) {
			foreach ( array_slice( $raw_history, -4 ) as $item ) {
				if ( is_array( $item ) && isset( $item['role'], $item['text'] ) && is_string( $item['role'] ) && is_string( $item['text'] ) ) {
					$role = $item['role'];
					if ( in_array( $role, array( 'user', 'model' ), true ) ) {
						$clean_text = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', wp_check_invalid_utf8( $item['text'] ) ) ?? '' );
						$max_chars  = 'model' === $role ? self::MAX_ANSWER_CHARS : self::MAX_MESSAGE_CHARS;
						if ( '' !== $clean_text && mb_strlen( $clean_text ) <= $max_chars ) {
							$history[] = array(
								'role' => $role,
								'text' => $clean_text,
							);
						}
					}
				}
			}
		}

		$ip = $this->client_ip();
		if ( ! $this->rate_limit_allows( $ip ) ) {
			return $this->rate_limited( $language, MINUTE_IN_SECONDS );
		}

		// The backend receives an opaque, salted identifier, never the visitor's IP address.
		$client_id = substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$result    = Backend_Client::chat( $message, $language, $client_id, $history );

		if ( is_wp_error( $result ) ) {
			self::report_backend_failure( 'unreachable', 0 );
			return $this->error( 'wkc_unavailable', 'unavailable', $language, 503 );
		}
		if ( 429 === $result['status'] ) {
			self::report_backend_failure( 'rate_limited', 429 );
			return $this->rate_limited( $language, $result['retry_after'] ?? MINUTE_IN_SECONDS );
		}
		if ( 200 !== $result['status'] ) {
			self::report_backend_failure( 401 === $result['status'] ? 'unauthorized' : 'http_error', $result['status'] );
			return $this->error( 'wkc_unavailable', 'unavailable', $language, 503 );
		}

		$clean = self::sanitize_backend_response( $result['body'] );
		if ( null === $clean ) {
			self::report_backend_failure( 'invalid_response', 200 );
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

	/**
	 * Lets operators observe backend failures (for example to log or alert)
	 * without the plugin writing logs itself. No message text or IP is passed.
	 */
	private static function report_backend_failure( string $reason, int $status ): void {
		/**
		 * Fires when a chat request could not be answered because of the backend.
		 *
		 * @param string $reason unreachable, unauthorized, rate_limited, http_error or invalid_response.
		 * @param int    $status Backend HTTP status, 0 when unreachable.
		 */
		do_action( 'wp_knowledge_chat_backend_failure', $reason, $status );
	}

	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $remote, $matches ) ) {
			$remote = $matches[1];
		} elseif ( preg_match( '/^\[([a-f0-9:]+)\]:\d+$/i', $remote, $matches ) ) {
			$remote = $matches[1];
		}
		$ip = $remote;
		// X-Forwarded-For is client-controlled. It is read only when the direct
		// peer is a proxy the site owner listed in WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES.
		if ( '' !== $remote && self::is_trusted_proxy( $remote ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip        = self::client_from_forwarded_for( $forwarded ) ?? $remote;
		}
		/**
		 * Filters the visitor IP used for rate limiting. Only override this
		 * (e.g. to read a CDN header) when the header is set by a trusted proxy.
		 *
		 * @param string $ip     Visitor IP (REMOTE_ADDR, or the forwarded client behind a trusted proxy).
		 * @param string $remote REMOTE_ADDR.
		 */
		$ip = apply_filters( 'wp_knowledge_chat_client_ip', $ip, $remote );
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * The client is the right-most X-Forwarded-For entry that is not itself a
	 * trusted proxy: entries further left were supplied by the client and can
	 * be forged.
	 */
	public static function client_from_forwarded_for( string $header ): ?string {
		$hops = array_reverse( array_map( 'trim', explode( ',', $header ) ) );
		foreach ( $hops as $hop ) {
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return null;
			}
			if ( ! self::is_trusted_proxy( $hop ) ) {
				return $hop;
			}
		}
		return null;
	}

	/**
	 * @return string[] IPs or CIDR ranges from WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES (comma-separated).
	 */
	private static function trusted_proxies(): array {
		$value = defined( 'WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES' ) ? constant( 'WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES' ) : '';
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		$proxies = is_string( $value ) ? array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) ) : array();
		/**
		 * Filters the reverse proxies (IPs or CIDR ranges) whose X-Forwarded-For header is trusted.
		 *
		 * @param string[] $proxies From WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES; empty by default.
		 */
		$proxies = apply_filters( 'wp_knowledge_chat_trusted_proxies', $proxies );
		return is_array( $proxies ) ? array_values( array_filter( $proxies, 'is_string' ) ) : array();
	}

	public static function is_trusted_proxy( string $ip ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		foreach ( self::trusted_proxies() as $range ) {
			[ $network, $bits ] = array_pad( explode( '/', $range, 2 ), 2, null );
			$net                = inet_pton( (string) $network );
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$max  = 8 * strlen( $net );
			$bits = null === $bits ? $max : (int) $bits;
			if ( $bits < 0 || $bits > $max ) {
				continue;
			}
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
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

	/**
	 * 429 response with a Retry-After header (bounded to one day).
	 */
	private function rate_limited( string $language, int $retry_after ): \WP_REST_Response {
		$response = rest_convert_error_to_response( $this->error( 'wkc_rate_limited', 'rate_limited', $language, 429 ) );
		$response->header( 'Retry-After', (string) max( 1, min( $retry_after, DAY_IN_SECONDS ) ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
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
			'ko' => array(
				'unavailable'  => '지금은 어시스턴트를 이용할 수 없습니다. 잠시 후 다시 시도하시거나 직접 문의해 주세요.',
				'forbidden'    => '허용되지 않은 요청입니다.',
				'invalid'      => '질문을 입력해 주세요.',
				'too_long'     => sprintf( '질문은 %d자 이내로 입력해 주세요.', self::MAX_MESSAGE_CHARS ),
				'rate_limited' => '메시지를 너무 빠르게 보내고 있습니다. 잠시 후 다시 시도해 주세요.',
			),
		);
		$lang = isset( $messages[ $language ] ) ? $language : 'en';
		return new \WP_Error( $code, $messages[ $lang ][ $kind ], array( 'status' => $status ) );
	}
}
