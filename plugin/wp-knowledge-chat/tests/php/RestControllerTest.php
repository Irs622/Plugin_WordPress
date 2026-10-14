<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Backend_Client;
use WP_Knowledge_Chat\Rest_Controller;
use WP_Knowledge_Chat\Settings;

final class RestControllerTest extends TestCase {

	/** @var array<int,array{url:string,args:array}> */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->calls = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wkc_rl_%' OR option_name LIKE '_transient_timeout_wkc_rl_%'" );
		wp_cache_flush();
	}

	/** Replaces the HTTP transport with a scripted backend response. */
	private function backend( $response ): void {
		$this->add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->calls[] = array( 'url' => $url, 'args' => $args );
				if ( $response instanceof \WP_Error ) {
					return $response;
				}
				[ $status, $body, $headers ] = $response + array( 2 => array() );
				return array(
					'response' => array( 'code' => $status, 'message' => '' ),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'headers'  => $headers,
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	private function chat( array $body, array $headers = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/wp-knowledge-chat/v1/chat' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private const GOOD = array(
		'answer'   => 'Inpartner offers Distribution Partner Sourcing Advisory.',
		'sources'  => array( array( 'title' => 'Distribution Partner Sourcing Advisory', 'url' => 'https://inpartnerglobal.com/distribution-partner-sourcing-advisory/' ) ),
		'grounded' => true,
	);

	public function test_route_is_registered_as_public_post_only(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/wp-knowledge-chat/v1/chat', $routes );
		$this->assertSame( array( 'POST' => true ), $routes['/wp-knowledge-chat/v1/chat'][0]['methods'] );
		$get = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp-knowledge-chat/v1/chat' ) );
		$this->assertSame( 404, $get->get_status() );
	}

	public function test_valid_question_is_forwarded_signed_and_answer_returned(): void {
		$this->backend( array( 200, self::GOOD ) );
		$response = $this->chat( array( 'message' => '  What do you offer?  ', 'language' => 'id' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::GOOD, $response->get_data() );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		$this->assertCount( 1, $this->calls );
		$call = $this->calls[0];
		$this->assertSame( 'http://127.0.0.1:8787/api/chat', $call['url'] );
		$this->assertSame( 0, $call['args']['redirection'] );
		$this->assertSame( Backend_Client::TIMEOUT_SECONDS, $call['args']['timeout'] );
		$this->assertSame( '{"message":"What do you offer?","language":"id"}', $call['args']['body'] );

		$h = $call['args']['headers'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $h['X-WKC-Client'] );
		$this->assertStringNotContainsString( $_SERVER['REMOTE_ADDR'], wp_json_encode( $call['args'] ), 'visitor IP is not sent to the backend' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $h['X-WKC-Nonce'] );
		$expected = hash_hmac( 'sha256', $h['X-WKC-Timestamp'] . '.' . $h['X-WKC-Nonce'] . '.' . $h['X-WKC-Client'] . '.' . $call['args']['body'], (string) getenv( 'WKC_SHARED_SECRET' ) );
		$this->assertSame( $expected, $h['X-WKC-Signature'] );
	}

	public function test_valid_question_with_history_is_sanitized_and_forwarded(): void {
		$this->backend( array( 200, self::GOOD ) );
		$response = $this->chat(
			array(
				'message'  => 'How does it work?',
				'language' => 'en',
				'history'  => array(
					array( 'role' => 'user', 'text' => 'What is advisory?' ),
					array( 'role' => 'model', 'text' => 'It is corporate advisory.' ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->calls );
		$body = json_decode( $this->calls[0]['args']['body'], true );
		$this->assertSame( 'How does it work?', $body['message'] );
		$this->assertSame( 'en', $body['language'] );
		$this->assertSame(
			array(
				array( 'role' => 'user', 'text' => 'What is advisory?' ),
				array( 'role' => 'model', 'text' => 'It is corporate advisory.' ),
			),
			$body['history']
		);
	}

	public function test_forwarded_for_is_ignored_unless_the_peer_is_a_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$this->add_filter( 'wp_knowledge_chat_rate_limit_per_minute', fn() => 1 );
		$this->backend( array( 200, self::GOOD ) );

		// Untrusted peer: a spoofed header cannot buy a fresh rate-limit bucket.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$this->assertSame( 200, $this->chat( array( 'message' => 'Hi' ) )->get_status() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
		$this->assertSame( 429, $this->chat( array( 'message' => 'Hi' ) )->get_status() );

		// Trusted proxy: each forwarded visitor gets their own bucket and client id.
		$this->add_filter( 'wp_knowledge_chat_trusted_proxies', fn() => array( '10.0.0.0/8' ) );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.3';
		$this->assertSame( 200, $this->chat( array( 'message' => 'Hi' ) )->get_status() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.4';
		$this->assertSame( 200, $this->chat( array( 'message' => 'Hi' ) )->get_status() );
		$this->assertNotSame( $this->calls[1]['args']['headers']['X-WKC-Client'], $this->calls[2]['args']['headers']['X-WKC-Client'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_forwarded_for_parsing_uses_the_rightmost_untrusted_hop(): void {
		$this->add_filter( 'wp_knowledge_chat_trusted_proxies', fn() => array( '10.0.0.0/8', '2001:db8::/32', '192.0.2.7' ) );
		$this->assertSame( '198.51.100.9', Rest_Controller::client_from_forwarded_for( '1.2.3.4, 198.51.100.9, 10.1.2.3' ), 'left-most entries are client-supplied' );
		$this->assertSame( '198.51.100.9', Rest_Controller::client_from_forwarded_for( '198.51.100.9,192.0.2.7,2001:db8::1' ) );
		$this->assertNull( Rest_Controller::client_from_forwarded_for( 'garbage, 10.0.0.1' ) );
		$this->assertNull( Rest_Controller::client_from_forwarded_for( '10.0.0.1, 10.0.0.2' ), 'only proxies' );
		$this->assertTrue( Rest_Controller::is_trusted_proxy( '10.255.0.1' ) );
		$this->assertTrue( Rest_Controller::is_trusted_proxy( '2001:db8:abcd::1' ) );
		$this->assertFalse( Rest_Controller::is_trusted_proxy( '11.0.0.1' ) );
		$this->assertFalse( Rest_Controller::is_trusted_proxy( '192.0.2.8' ) );
		$this->assertFalse( Rest_Controller::is_trusted_proxy( 'not-an-ip' ) );
	}

	public function test_rate_limited_responses_carry_retry_after(): void {
		$this->add_filter( 'wp_knowledge_chat_rate_limit_per_minute', fn() => 1 );
		$this->backend( array( 200, self::GOOD ) );
		$this->chat( array( 'message' => 'Hi' ) );
		$limited = $this->chat( array( 'message' => 'Hi' ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( '60', $limited->get_headers()['Retry-After'] );
		$this->assertSame( 'wkc_rate_limited', $limited->get_data()['code'] );
	}

	public function test_backend_429_retry_after_is_passed_through_and_bounded(): void {
		$this->backend( array( 429, array( 'error' => array( 'code' => 'daily_limit_reached' ) ), array( 'retry-after' => '3600' ) ) );
		$this->assertSame( '3600', $this->chat( array( 'message' => 'Hi' ) )->get_headers()['Retry-After'] );
	}

	public function test_backend_failures_fire_an_observability_action_without_content(): void {
		$seen = array();
		$this->add_filter(
			'wp_knowledge_chat_backend_failure',
			function ( $reason, $status ) use ( &$seen ) {
				$seen[] = array( $reason, $status );
			},
			10,
			2
		);
		$this->backend( array( 401, array( 'error' => array( 'code' => 'unauthorized' ) ) ) );
		$this->assertSame( 503, $this->chat( array( 'message' => 'Secret question text' ) )->get_status() );
		$this->assertSame( array( array( 'unauthorized', 401 ) ), $seen );
	}

	public function test_backend_url_with_query_or_fragment_is_rejected(): void {
		$this->assertSame( 'https://chat.example.com/base', Backend_Client::normalise_base_url( 'https://chat.example.com/base/' ) );
		$this->assertSame( 'http://127.0.0.1:8787', Backend_Client::normalise_base_url( 'http://127.0.0.1:8787' ) );
		foreach ( array( 'https://chat.example.com/?x=1', 'https://chat.example.com/#a', 'http://chat.example.com', 'https://user:pw@chat.example.com', '', null ) as $bad ) {
			$this->assertNull( Backend_Client::normalise_base_url( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_signature_matches_backend_reference_vector(): void {
		// Same vector as backend/tests: computeSignature('s'*40, '1790000000', nonce, 'abc', body).
		$this->assertSame(
			hash_hmac( 'sha256', '1790000000.0123456789abcdef0123456789abcdef.abc.{"message":"hi","language":"en"}', str_repeat( 's', 40 ) ),
			Backend_Client::sign( str_repeat( 's', 40 ), '1790000000', '0123456789abcdef0123456789abcdef', 'abc', '{"message":"hi","language":"en"}' )
		);
	}

	public function test_invalid_input_is_rejected_before_calling_backend(): void {
		$this->backend( array( 200, self::GOOD ) );
		$cases = array(
			array( array( 'language' => 'en' ), 400 ),
			array( array( 'message' => '', 'language' => 'en' ), 400 ),
			array( array( 'message' => "  \x01\x02 ", 'language' => 'en' ), 400 ),
			array( array( 'message' => str_repeat( 'a', 501 ), 'language' => 'en' ), 400 ),
			array( array( 'message' => 'hi', 'language' => 'fr' ), 400 ),
			array( array( 'message' => array( 'hi' ), 'language' => 'en' ), 400 ),
		);
		foreach ( $cases as [ $body, $status ] ) {
			$this->assertSame( $status, $this->chat( $body )->get_status(), wp_json_encode( $body ) );
		}
		$this->assertCount( 0, $this->calls );
		$this->assertSame( 200, $this->chat( array( 'message' => str_repeat( 'é', 500 ) ) )->get_status(), '500 multibyte characters are allowed' );
	}

	public function test_cross_site_origin_is_rejected(): void {
		$this->backend( array( 200, self::GOOD ) );
		$this->assertSame( 403, $this->chat( array( 'message' => 'hi' ), array( 'Origin' => 'https://evil.example' ) )->get_status() );
		$this->assertSame( 403, $this->chat( array( 'message' => 'hi' ), array( 'Origin' => 'null' ) )->get_status() );
		$this->assertSame( 200, $this->chat( array( 'message' => 'hi' ), array( 'Origin' => 'http://127.0.0.1:8899' ) )->get_status() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_disabled_chatbot_returns_503_without_calling_backend(): void {
		$this->backend( array( 200, self::GOOD ) );
		update_option( Settings::OPTION, array( 'enabled' => '' ) + Settings::defaults() );
		$response = $this->chat( array( 'message' => 'hi' ) );
		$this->assertSame( 503, $response->get_status() );
		$this->assertCount( 0, $this->calls );
	}

	public function test_rate_limit_per_visitor(): void {
		$this->backend( array( 200, self::GOOD ) );
		$this->add_filter( 'wp_knowledge_chat_rate_limit_per_minute', fn() => 2 );
		$this->assertSame( 200, $this->chat( array( 'message' => 'one' ) )->get_status() );
		$this->assertSame( 200, $this->chat( array( 'message' => 'two' ) )->get_status() );
		$this->assertSame( 429, $this->chat( array( 'message' => 'three' ) )->get_status() );
		$this->assertCount( 2, $this->calls );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 200, $this->chat( array( 'message' => 'other visitor' ) )->get_status() );
	}

	public function test_rate_limit_carries_over_the_minute_boundary(): void {
		$this->backend( array( 200, self::GOOD ) );
		$ip       = $_SERVER['REMOTE_ADDR'];
		$previous = 'wkc_rl_' . substr( hash_hmac( 'sha256', $ip . '|' . ( intdiv( time(), MINUTE_IN_SECONDS ) - 1 ), wp_salt( 'nonce' ) ), 0, 32 );
		// A burst in the previous minute still counts (weighted) at the start of the next one.
		set_transient( $previous, 6 * MINUTE_IN_SECONDS, 120 );
		$this->assertSame( 429, $this->chat( array( 'message' => 'burst' ) )->get_status() );
		$this->assertCount( 0, $this->calls );
	}

	public function test_backend_failures_become_friendly_errors_without_details(): void {
		$this->add_filter( 'wp_knowledge_chat_rate_limit_per_minute', fn() => 100 );
		$cases = array(
			array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20000 ms at 10.0.0.5' ), 503 ),
			array( array( 500, array( 'error' => array( 'code' => 'internal_error', 'message' => 'stack at /srv/app/dist/server.js:10' ) ) ), 503 ),
			array( array( 502, array( 'error' => array( 'code' => 'provider_unavailable' ) ) ), 503 ),
			array( array( 504, '' ), 503 ),
			array( array( 401, array( 'error' => array( 'code' => 'unauthorized' ) ) ), 503 ),
			array( array( 429, array( 'error' => array( 'code' => 'rate_limited' ) ), array( 'retry-after' => '30' ) ), 429 ),
			array( array( 200, '<html>not json</html>' ), 503 ),
			array( array( 200, array( 'answer' => 'x' ) ), 503 ),
			array( array( 200, array( 'answer' => 5, 'sources' => array(), 'grounded' => true ) ), 503 ),
			array( array( 200, array( 'answer' => 'x', 'sources' => array(), 'grounded' => 'yes' ) ), 503 ),
		);
		foreach ( $cases as [ $backend, $status ] ) {
			remove_all_filters( 'pre_http_request' );
			$this->calls = array();
			$this->backend( $backend );
			$response = $this->chat( array( 'message' => 'hello' ) );
			$this->assertSame( $status, $response->get_status() );
			$json = wp_json_encode( $response->get_data() );
			foreach ( array( 'cURL', '10.0.0.5', 'stack', 'server.js', 'provider', '8787', (string) getenv( 'WKC_SHARED_SECRET' ) ) as $leak ) {
				$this->assertStringNotContainsString( $leak, $json );
			}
		}
	}

	public function test_backend_response_is_sanitised(): void {
		$this->backend(
			array(
				200,
				array(
					'answer'   => 'Hello <script>alert(1)</script><img src=x onerror=alert(2)>world',
					'sources'  => array(
						array( 'title' => '<b>Good</b>', 'url' => 'https://inpartnerglobal.com/about-us/' ),
						array( 'title' => 'Dup', 'url' => 'https://inpartnerglobal.com/about-us/' ),
						array( 'title' => 'Evil', 'url' => 'https://evil.example/' ),
						array( 'title' => 'JS', 'url' => 'javascript:alert(1)' ),
						array( 'title' => 'HTTP', 'url' => 'http://inpartnerglobal.com/' ),
						array( 'title' => 'Creds', 'url' => 'https://a:b@inpartnerglobal.com/' ),
						array( 'title' => '', 'url' => 'https://inpartnerglobal.com/contact/' ),
						'not-an-array',
					),
					'grounded' => true,
				),
			)
		);
		$data = $this->chat( array( 'message' => 'hi' ) )->get_data();
		$this->assertSame( 'Hello world', $data['answer'] );
		$this->assertSame( array( array( 'title' => 'Good', 'url' => 'https://inpartnerglobal.com/about-us/' ) ), $data['sources'] );
		$this->assertTrue( $data['grounded'] );
	}

	public function test_grounded_without_valid_sources_is_downgraded(): void {
		$clean = Rest_Controller::sanitize_backend_response( array( 'answer' => 'Trust me', 'sources' => array( array( 'title' => 'x', 'url' => 'https://evil.example/' ) ), 'grounded' => true ) );
		$this->assertFalse( $clean['grounded'] );
		$this->assertSame( array(), $clean['sources'] );
	}

	public function test_long_answers_are_truncated(): void {
		$clean = Rest_Controller::sanitize_backend_response( array( 'answer' => str_repeat( 'a', 5000 ), 'sources' => array(), 'grounded' => false ) );
		$this->assertSame( Rest_Controller::MAX_ANSWER_CHARS, mb_strlen( $clean['answer'] ) );
	}

	public function test_error_messages_follow_requested_language(): void {
		$this->backend( new \WP_Error( 'http_request_failed', 'down' ) );
		$this->assertStringContainsString( 'Asisten sedang tidak tersedia', $this->chat( array( 'message' => 'halo', 'language' => 'id' ) )->get_data()['message'] );
		$this->assertStringContainsString( 'The assistant is unavailable', $this->chat( array( 'message' => 'hello', 'language' => 'en' ) )->get_data()['message'] );
	}

	public function test_zero_width_characters_are_stripped_and_empty_message_rejected(): void {
		// Only zero-width characters (e.g. U+200B) should be treated as empty.
		$response = $this->chat( array( 'message' => "\xE2\x80\x8B\xE2\x80\x8C\xEF\xBB\xBF" ) );
		$this->assertSame( 400, $response->get_status() );

		// Text mixed with zero-width characters should have them stripped.
		$this->backend( array( 200, self::GOOD ) );
		$response = $this->chat( array( 'message' => "Hello\xE2\x80\x8B World" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '{"message":"Hello World","language":"en"}', $this->calls[0]['args']['body'] );
	}

	public function test_client_ip_handles_remote_addr_with_port(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99:54321';
		$this->backend( array( 200, self::GOOD ) );
		$response = $this->chat( array( 'message' => 'What services do you offer?' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->calls[0]['args']['headers']['X-WKC-Client'] );
	}
}

