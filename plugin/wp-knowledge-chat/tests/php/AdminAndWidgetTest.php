<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Admin_Page;
use WP_Knowledge_Chat\Backend_Client;
use WP_Knowledge_Chat\Plugin;
use WP_Knowledge_Chat\Settings;

final class AdminAndWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		unset( $_GET['elementor-preview'], $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
	}

	private function enqueue(): bool {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		Plugin::instance()->enqueue_assets();
		return wp_script_is( Plugin::HANDLE, 'enqueued' ) && wp_style_is( Plugin::HANDLE, 'enqueued' );
	}

	/* ---------- Capabilities and nonces ---------- */

	public function test_only_administrators_can_manage_settings(): void {
		foreach ( array( 'subscriber' => false, 'editor' => false, 'admin' => true ) as $login => $allowed ) {
			wp_set_current_user( $this->user_id( $login ) );
			$this->assertSame( $allowed, current_user_can( Admin_Page::CAPABILITY ), $login );
			// options.php checks this capability for the settings group.
			$this->assertSame( $allowed, current_user_can( apply_filters( 'option_page_capability_' . Settings::GROUP, 'manage_options' ) ), $login );
		}
	}

	public function test_settings_page_refuses_non_admins(): void {
		wp_set_current_user( $this->user_id( 'editor' ) );
		try {
			( new Admin_Page() )->render();
			$this->fail( 'Expected wp_die' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}
	}

	public function test_test_connection_requires_capability_and_nonce(): void {
		$page = new Admin_Page();

		wp_set_current_user( $this->user_id( 'subscriber' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::HEALTH_ACTION );
		try {
			$page->handle_test_connection();
			$this->fail( 'Subscriber must be refused' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}

		wp_set_current_user( $this->user_id( 'admin' ) );
		foreach ( array( '', 'invalid-nonce', wp_create_nonce( 'some_other_action' ) ) as $nonce ) {
			$_REQUEST['_wpnonce'] = $nonce;
			try {
				$page->handle_test_connection();
				$this->fail( 'Invalid nonce must be refused' );
			} catch ( WpDieException $e ) {
				$this->assertSame( 403, $e->status );
			}
		}

		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::HEALTH_ACTION );
		$this->add_filter( 'pre_http_request', fn() => array( 'response' => array( 'code' => 200 ), 'body' => '{"status":"ok"}', 'headers' => array(), 'cookies' => array() ) );
		$this->add_filter( 'wp_redirect', function ( $location ) {
			throw new RedirectException( $location );
		} );
		try {
			$page->handle_test_connection();
			$this->fail( 'Expected redirect' );
		} catch ( RedirectException $e ) {
			$this->assertStringContainsString( 'page=wp-knowledge-chat', $e->getMessage() );
		}
		$this->assertSame( array( 'ok' => true, 'status' => 200 ), get_transient( 'wkc_health_' . $this->user_id( 'admin' ) ) );
	}

	public function test_connection_test_is_signed_and_reports_a_rejected_secret(): void {
		wp_set_current_user( $this->user_id( 'admin' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::HEALTH_ACTION );
		$seen                 = array();
		$this->add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$seen ) {
				$seen = array( 'url' => $url, 'headers' => $args['headers'] );
				return array( 'response' => array( 'code' => 401 ), 'body' => '{"error":{"code":"unauthorized"}}', 'headers' => array(), 'cookies' => array() );
			},
			10,
			3
		);
		$this->add_filter( 'wp_redirect', function ( $location ) {
			throw new RedirectException( $location );
		} );
		try {
			( new Admin_Page() )->handle_test_connection();
		} catch ( RedirectException $e ) {
			// Expected.
		}
		$this->assertSame( 'http://127.0.0.1:8787/api/health', $seen['url'] );
		$h = $seen['headers'];
		$this->assertSame(
			Backend_Client::sign( (string) getenv( 'WKC_SHARED_SECRET' ), $h['X-WKC-Timestamp'], $h['X-WKC-Nonce'], $h['X-WKC-Client'], '' ),
			$h['X-WKC-Signature']
		);
		$this->assertSame( array( 'ok' => false, 'status' => 401 ), get_transient( 'wkc_health_' . $this->user_id( 'admin' ) ) );

		ob_start();
		( new Admin_Page() )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'rejected the shared secret', $html );
		$this->assertStringNotContainsString( (string) getenv( 'WKC_SHARED_SECRET' ), $html );
	}

	public function test_contact_url_help_does_not_assert_an_unapproved_contact_page(): void {
		wp_set_current_user( $this->user_id( 'admin' ) );
		ob_start();
		( new Admin_Page() )->render_field( array( 'key' => 'contact_url', 'label_for' => 'wkc-contact-url' ) );
		$this->assertStringNotContainsString( '/contact/', (string) ob_get_clean() );
		$this->assertSame( 'https://inpartnerglobal.com', Settings::defaults()['contact_url'] );
	}

	public function test_settings_changes_purge_litespeed_page_cache(): void {
		$purges = 0;
		$this->add_filter(
			'litespeed_purge_all',
			function () use ( &$purges ) {
				++$purges;
			}
		);
		// First save after activation: the stored value still equals the registered default.
		update_option( Settings::OPTION, array( 'greeting_message' => 'Changed greeting' ) + Settings::defaults() );
		$this->assertSame( 1, $purges );
		update_option( Settings::OPTION, array( 'greeting_message' => 'Changed greeting' ) + Settings::defaults() );
		$this->assertSame( 1, $purges, 'unchanged settings do not purge' );
		update_option( Settings::OPTION, array( 'enabled' => false ) + Settings::defaults() );
		$this->assertSame( 2, $purges, 'disabling the widget purges' );
	}

	public function test_admin_menu_requires_manage_options(): void {
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

		wp_set_current_user( $this->user_id( 'admin' ) );
		( new Admin_Page() )->add_menu();
		$this->assertSame( 'WP Knowledge Chat', $menu[80][0] ?? null );
		$this->assertSame( 'Settings', $submenu[ Admin_Page::SLUG ][0][0] ?? null );

		// WordPress hides menu items by this capability when the menu is rendered.
		$this->assertSame( 'manage_options', $menu[80][1] );
		$this->assertSame( 'manage_options', $submenu[ Admin_Page::SLUG ][0][1] );
	}

	/* ---------- Output escaping ---------- */

	public function test_admin_fields_escape_output(): void {
		wp_set_current_user( $this->user_id( 'admin' ) );
		update_option( Settings::OPTION, array( 'greeting_message' => 'Say "hi" & <wave> \'now\'', 'contact_url' => 'https://inpartnerglobal.com/contact/?a=1&b="2"' ) + Settings::defaults() );

		ob_start();
		$page = new Admin_Page();
		foreach ( array( 'enabled', 'greeting_message', 'language', 'contact_url' ) as $key ) {
			$page->render_field( array( 'key' => $key, 'label_for' => 'wkc-' . $key ) );
		}
		$html = ob_get_clean();

		$this->assertStringContainsString( '&quot;hi&quot; &amp;', $html );
		$this->assertStringNotContainsString( '"hi"', $html );
		$this->assertStringNotContainsString( '<wave>', $html );
		// esc_url_raw() drops the quotes entirely; the attribute value is entity-encoded.
		$this->assertStringContainsString( 'value="https://inpartnerglobal.com/contact/?a=1&amp;b=2"', $html );
		$this->assertStringContainsString( 'name="wp_knowledge_chat_settings[enabled]"', $html );
		$this->assertStringContainsString( 'maxlength="500"', $html );
	}

	public function test_settings_page_contains_settings_nonce_and_no_secret(): void {
		wp_set_current_user( $this->user_id( 'admin' ) );
		( new Admin_Page() )->add_fields();
		ob_start();
		( new Admin_Page() )->render();
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="[a-f0-9]{10}"/', $html );
		$this->assertMatchesRegularExpression( '/name=[\'"]option_page[\'"] value=[\'"]wp_knowledge_chat[\'"]/', $html );
		$this->assertStringContainsString( 'name="action" value="' . Admin_Page::HEALTH_ACTION . '"', $html );
		$this->assertStringNotContainsString( (string) getenv( 'WKC_SHARED_SECRET' ), $html );
		$this->assertStringNotContainsString( (string) getenv( 'WKC_BACKEND_URL' ), $html );
	}

	/* ---------- Widget loading ---------- */

	public function test_widget_loads_when_enabled_and_configured(): void {
		$this->assertTrue( $this->enqueue() );
		$this->assertSame( 'defer', wp_scripts()->get_data( Plugin::HANDLE, 'strategy' ) );
		$this->assertTrue( (bool) wp_scripts()->get_data( Plugin::HANDLE, 'group' ), 'script is in the footer' );
	}

	public function test_widget_does_not_load_when_disabled(): void {
		update_option( Settings::OPTION, array( 'enabled' => '' ) + Settings::defaults() );
		$this->assertFalse( Settings::get()['enabled'] );
		$this->assertFalse( $this->enqueue() );
	}

	public function test_widget_respects_page_targeting_filter(): void {
		$this->add_filter( 'wp_knowledge_chat_should_load', '__return_false' );
		$this->assertFalse( $this->enqueue() );
	}

	public function test_widget_does_not_load_in_elementor_preview(): void {
		$_GET['elementor-preview'] = '123';
		$this->assertFalse( $this->enqueue() );
		unset( $_GET['elementor-preview'] );
	}

	public function test_frontend_config_contains_no_secrets_and_is_script_safe(): void {
		update_option( Settings::OPTION, array( 'greeting_message' => 'Hi </script> "x" & \'y\' a < b' ) + Settings::defaults() );
		$this->assertTrue( $this->enqueue() );

		$inline = implode( "\n", (array) wp_scripts()->get_data( Plugin::HANDLE, 'before' ) );
		$this->assertStringStartsWith( 'window.wpKnowledgeChatConfig = ', trim( $inline ) );
		$this->assertStringNotContainsString( '</script', $inline );
		$this->assertStringNotContainsString( '<', substr( $inline, 30 ) );
		$this->assertStringNotContainsString( (string) getenv( 'WKC_SHARED_SECRET' ), $inline );
		$this->assertStringNotContainsString( '127.0.0.1:8787', $inline, 'backend address is not exposed' );
		$this->assertStringNotContainsString( 'AIza', $inline );

		$config = Plugin::instance()->frontend_config();
		$this->assertSame( array( 'restUrl', 'greeting', 'language', 'contactUrl', 'maxLength', 'sourceHosts', 'contactHosts', 'suggestions' ), array_keys( $config ) );
		$this->assertStringEndsWith( '/wp-knowledge-chat/v1/chat', $config['restUrl'] );
		$this->assertSame( 500, $config['maxLength'] );
	}

	public function test_suggested_questions_follow_language_and_are_sanitised(): void {
		$this->assertSame( 'Layanan apa saja yang ditawarkan Inpartner?', Plugin::suggested_questions( 'id' )[0] );
		$this->add_filter(
			'wp_knowledge_chat_suggested_questions',
			fn() => array( '<b>Bold?</b>', str_repeat( 'x', 151 ), 42, 'A', 'B', 'C', 'D' )
		);
		$this->assertSame( array( 'Bold?', 'A', 'B', 'C' ), Plugin::suggested_questions( 'en' ) );
	}
}
