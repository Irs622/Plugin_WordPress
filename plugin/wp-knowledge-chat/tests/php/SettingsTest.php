<?php

namespace WP_Knowledge_Chat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WP_Knowledge_Chat\Plugin;
use WP_Knowledge_Chat\Settings;

final class SettingsTest extends TestCase {

	public function test_activation_creates_defaults(): void {
		delete_option( Settings::OPTION );
		Plugin::activate();
		$this->assertSame(
			array(
				'enabled'                 => true,
				'greetings'               => array(
					'en' => "Hello! How can I help you explore Inpartner's services?",
					'id' => 'Halo! Ada yang bisa saya bantu terkait layanan Inpartner?',
					'ko' => '안녕하세요! Inpartner의 서비스에 대해 무엇을 도와드릴까요?',
				),
				'language'                => 'en',
				'contact_url'             => 'https://inpartnerglobal.com',
				'language_switcher'       => true,
				'detect_browser_language' => true,
			),
			get_option( Settings::OPTION )
		);
	}

	public function test_activation_does_not_overwrite_existing_settings(): void {
		update_option( Settings::OPTION, array( 'greetings' => array( 'ko' => 'Custom' ), 'language' => 'id' ) + Settings::defaults() );
		update_option( Settings::OPTION, array( 'enabled' => false ) + get_option( Settings::OPTION ) );
		Plugin::activate();
		$this->assertSame( 'Custom', get_option( Settings::OPTION )['greetings']['ko'] );
		$this->assertFalse( get_option( Settings::OPTION )['enabled'] );
	}

	public function test_deactivation_keeps_settings(): void {
		update_option( Settings::OPTION, array( 'language' => 'id' ) + Settings::defaults() );
		Plugin::deactivate();
		$this->assertSame( 'id', Settings::get()['language'] );
	}

	public function test_missing_keys_are_migrated_without_losing_values(): void {
		// Simulates an older/partial option written directly to the database (bypassing sanitize).
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		update_option( Settings::OPTION, array( 'language' => 'id' ) );
		Settings::register();

		$this->assertSame( 'id', Settings::get()['language'], 'existing value is used' );
		$this->assertTrue( Settings::get()['enabled'], 'missing key falls back to default' );

		Settings::install();
		$stored = get_option( Settings::OPTION );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $stored ) );
		$this->assertSame( 'id', $stored['language'] );
	}

	public function test_legacy_single_greeting_is_migrated_to_the_default_language(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		update_option( Settings::OPTION, array( 'enabled' => true, 'greeting_message' => 'Selamat datang di Inpartner!', 'language' => 'id', 'contact_url' => 'https://inpartnerglobal.com' ) );
		Settings::install();
		$stored = get_option( Settings::OPTION );
		$this->assertArrayNotHasKey( 'greeting_message', $stored );
		$this->assertSame( 'Selamat datang di Inpartner!', $stored['greetings']['id'] );
		$this->assertSame( Settings::default_greetings()['en'], $stored['greetings']['en'] );
		$this->assertTrue( $stored['language_switcher'] );

		// The old built-in English greeting is not carried into the Indonesian slot.
		update_option( Settings::OPTION, array( 'greeting_message' => Settings::default_greetings()['en'], 'language' => 'id' ) );
		$this->assertSame( Settings::default_greetings(), Settings::get()['greetings'] );
		Settings::register();
	}

	public function test_corrupt_or_invalid_stored_values_fall_back_to_defaults(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		foreach ( array( 'garbage', 42, null ) as $corrupt ) {
			update_option( Settings::OPTION, $corrupt );
			$this->assertSame( Settings::defaults(), Settings::get() );
		}
		update_option( Settings::OPTION, array( 'enabled' => 'maybe', 'greetings' => array( 'en' => str_repeat( 'x', 900 ), 'ko' => array() ), 'language' => 'fr', 'contact_url' => 'javascript:alert(1)', 'language_switcher' => 'yes please' ) );
		$this->assertSame( Settings::defaults(), Settings::get() );
		Settings::register();
	}

	public function test_valid_settings_persist_through_the_settings_api(): void {
		update_option(
			Settings::OPTION,
			array(
				'enabled'                 => '1',
				'greetings'               => array(
					'en' => 'Welcome!',
					'id' => "  Selamat datang!\nAda yang bisa dibantu?  ",
					'ko' => '환영합니다!',
				),
				'language'                => 'ko',
				'contact_url'             => 'https://inpartnerglobal.com/contact/',
				'language_switcher'       => '1',
			)
		);
		wp_cache_flush();
		$this->assertSame(
			array(
				'enabled'                 => true,
				'greetings'               => array(
					'en' => 'Welcome!',
					'id' => "Selamat datang!\nAda yang bisa dibantu?",
					'ko' => '환영합니다!',
				),
				'language'                => 'ko',
				'contact_url'             => 'https://inpartnerglobal.com/contact/',
				'language_switcher'       => true,
				'detect_browser_language' => false,
			),
			get_option( Settings::OPTION )
		);
		$this->assertSame( array(), $this->settings_error_codes() );
	}

	public function test_unchecked_checkbox_disables_widget(): void {
		update_option( Settings::OPTION, array( 'greetings' => array( 'en' => 'Hi' ), 'language' => 'en', 'contact_url' => 'https://inpartnerglobal.com' ) );
		$this->assertFalse( Settings::get()['enabled'] );
		$this->assertFalse( Settings::get()['language_switcher'] );
		$this->assertFalse( Settings::get()['detect_browser_language'] );
		foreach ( array( '0', 'false', array( '1' ), 'off' ) as $not_on ) {
			$this->assertFalse( Settings::sanitize( array( 'enabled' => $not_on ) )['enabled'] );
			$this->assertFalse( Settings::sanitize( array( 'language_switcher' => $not_on ) )['language_switcher'] );
		}
	}

	public function test_malformed_enabled_value_is_rejected_and_saved_value_kept(): void {
		$this->assertTrue( Settings::get()['enabled'] );
		foreach ( array( array( '1' ), 'maybe', 2, 1.5 ) as $malformed ) {
			Settings::reset_reported_errors();
			$GLOBALS['wp_settings_errors'] = array();
			$this->assertTrue( Settings::sanitize( array( 'enabled' => $malformed ) + Settings::defaults() )['enabled'], var_export( $malformed, true ) );
			$this->assertContains( 'wkc_enabled', $this->settings_error_codes() );
		}
		foreach ( array( '0', 'false', 'off', '', false ) as $off ) {
			$this->assertFalse( Settings::sanitize( array( 'enabled' => $off ) )['enabled'], var_export( $off, true ) );
		}
	}

	public function test_greeting_backslashes_survive_saving(): void {
		// options.php has already unslashed $_POST when the sanitize callback runs.
		$greeting = 'Paths like C:\\Data or a \\ are kept.';
		$this->assertSame( $greeting, Settings::sanitize( array( 'enabled' => '1', 'greetings' => array( 'en' => $greeting ) ) )['greetings']['en'] );
		update_option( Settings::OPTION, array( 'greetings' => array( 'en' => $greeting ) ) + Settings::defaults() );
		wp_cache_flush();
		$this->assertSame( $greeting, Settings::get()['greetings']['en'] );
	}

	public static function invalid_greetings(): array {
		return array(
			'only markup'  => array( '<script>alert(1)</script>' ),
			'too long'     => array( str_repeat( 'a', 501 ) ),
			'not a string' => array( array( 'Hello' ) ),
		);
	}

	#[DataProvider( 'invalid_greetings' )]
	public function test_invalid_greeting_is_rejected_and_previous_value_kept( $greeting ): void {
		update_option( Settings::OPTION, array( 'greetings' => array( 'ko' => '안녕하세요' ) ) + Settings::defaults() );
		Settings::reset_reported_errors();
		$GLOBALS['wp_settings_errors'] = array();
		$result = Settings::sanitize( array( 'enabled' => '1', 'greetings' => array( 'ko' => $greeting, 'en' => 'Hi' ) ) );
		$this->assertSame( '안녕하세요', $result['greetings']['ko'], 'invalid entry keeps its saved value' );
		$this->assertSame( 'Hi', $result['greetings']['en'], 'valid entries in the same submission are saved' );
		$this->assertContains( 'wkc_greetings_ko', $this->settings_error_codes() );
	}

	public function test_empty_greeting_restores_the_language_default(): void {
		update_option( Settings::OPTION, array( 'greetings' => array( 'id' => 'Custom' ) ) + Settings::defaults() );
		foreach ( array( '', "   \n  " ) as $empty ) {
			$this->assertSame( Settings::default_greetings()['id'], Settings::sanitize( array( 'greetings' => array( 'id' => $empty ) ) )['greetings']['id'] );
		}
		$this->assertSame( array(), $this->settings_error_codes() );
	}

	public function test_non_array_greetings_are_rejected(): void {
		$this->assertSame( Settings::default_greetings(), Settings::sanitize( array( 'greetings' => 'Hello' ) )['greetings'] );
		$this->assertContains( 'wkc_greetings', $this->settings_error_codes() );
	}

	public function test_greeting_markup_is_stripped_not_executed(): void {
		$result = Settings::sanitize( array( 'greetings' => array( 'en' => 'Hi <img src=x onerror=alert(1)><b>there</b> <script>alert(2)</script>& welcome' ) ) );
		$this->assertSame( 'Hi there & welcome', $result['greetings']['en'] );
	}

	public function test_greeting_accepts_exactly_500_multibyte_characters(): void {
		foreach ( array( 'é', '한' ) as $char ) {
			$greeting = str_repeat( $char, 500 );
			$this->assertSame( $greeting, Settings::sanitize( array( 'greetings' => array( 'ko' => $greeting ) ) )['greetings']['ko'] );
		}
	}

	public function test_korean_is_a_valid_language(): void {
		$this->assertSame( array( 'en', 'id', 'ko' ), Settings::LANGUAGES );
		$this->assertSame( 'ko', Settings::sanitize( array( 'language' => 'ko' ) )['language'] );
		$this->assertSame( array(), $this->settings_error_codes() );
	}

	public function test_invalid_language_is_rejected(): void {
		foreach ( array( 'fr', 'EN', '', 'en ', 'kr', 'ko-KR', array( 'en' ), 1 ) as $language ) {
			Settings::reset_reported_errors();
			$GLOBALS['wp_settings_errors'] = array();
			$this->assertSame( 'en', Settings::sanitize( array( 'enabled' => '1', 'language' => $language ) )['language'] );
			$this->assertContains( 'wkc_language', $this->settings_error_codes() );
		}
	}

	public static function invalid_contact_urls(): array {
		return array(
			'http'                 => array( 'http://inpartnerglobal.com/contact/' ),
			'javascript'           => array( 'javascript:alert(1)' ),
			'data uri'             => array( 'data:text/html,<script>alert(1)</script>' ),
			'protocol relative'    => array( '//inpartnerglobal.com/contact/' ),
			'relative'             => array( '/contact/' ),
			'other domain'         => array( 'https://example.com/contact/' ),
			'lookalike suffix'     => array( 'https://inpartnerglobal.com.evil.com/' ),
			'lookalike prefix'     => array( 'https://evilinpartnerglobal.com/' ),
			'credentials'          => array( 'https://user:pass@inpartnerglobal.com/' ),
			'username only'        => array( 'https://evil.com@inpartnerglobal.com/' ),
			'backslash trick'      => array( 'https://evil.com\\@inpartnerglobal.com/' ),
			'custom port'          => array( 'https://inpartnerglobal.com:8443/' ),
			'embedded newline'     => array( "https://inpartnerglobal.com/\ncontact" ),
			'embedded space'       => array( 'https://inpartnerglobal.com/con tact' ),
			'empty'                => array( '' ),
			'array'                => array( array( 'https://inpartnerglobal.com' ) ),
			'ftp'                  => array( 'ftp://inpartnerglobal.com/' ),
		);
	}

	#[DataProvider( 'invalid_contact_urls' )]
	public function test_invalid_contact_url_is_rejected( $url ): void {
		$result = Settings::sanitize( array( 'enabled' => '1', 'contact_url' => $url ) );
		$this->assertSame( 'https://inpartnerglobal.com', $result['contact_url'] );
		$this->assertContains( 'wkc_contact_url', $this->settings_error_codes() );
	}

	public function test_valid_contact_urls_are_normalised(): void {
		$this->assertSame( 'https://inpartnerglobal.com/contact/', Settings::sanitize( array( 'contact_url' => ' https://InpartnerGlobal.com/contact/ ' ) )['contact_url'] );
		$this->assertSame( 'https://www.inpartnerglobal.com/contact/?ref=chat', Settings::sanitize( array( 'contact_url' => 'https://www.inpartnerglobal.com:443/contact/?ref=chat' ) )['contact_url'] );
	}

	public function test_approved_domains_are_filterable(): void {
		$this->add_filter( 'wp_knowledge_chat_allowed_contact_hosts', fn( $hosts ) => array_merge( $hosts, array( 'contact.example.org' ) ) );
		$this->assertSame( 'https://contact.example.org/', Settings::sanitize( array( 'contact_url' => 'https://contact.example.org/' ) )['contact_url'] );
	}

	public function test_non_array_input_is_rejected(): void {
		$this->assertSame( Settings::get(), Settings::sanitize( 'enabled=1' ) );
		$this->assertContains( 'wkc_invalid_input', $this->settings_error_codes() );
	}

	public function test_settings_are_stored_in_a_single_autoloaded_option_without_secrets(): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION ) );
		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( 'secret', strtolower( $row->option_value ) );
		$this->assertStringNotContainsString( (string) getenv( 'WKC_SHARED_SECRET' ), $row->option_value );
		$tables = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%knowledge%'" );
		$this->assertSame( array(), $tables, 'no custom tables are created' );
	}
}
