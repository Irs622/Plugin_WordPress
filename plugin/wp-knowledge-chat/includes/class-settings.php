<?php
/**
 * Settings storage, defaults and server-side validation.
 *
 * All settings live in a single option (wp_knowledge_chat_settings).
 * Reads always merge with defaults and re-validate each field, so missing,
 * partial or corrupted stored values degrade to safe defaults.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION             = 'wp_knowledge_chat_settings';
	public const GROUP              = 'wp_knowledge_chat';
	public const GREETING_MAX_CHARS = 500;
	public const LANGUAGES          = array( 'en', 'id', 'ko' );

	/** Greeting used before per-language greetings existed (0.1.x); see get(). */
	private const LEGACY_DEFAULT_GREETING = "Hello! How can I help you explore Inpartner's services?";

	/** Hosts approved for the contact link and for cited sources. Extend with the filters below. */
	private const DEFAULT_APPROVED_HOSTS = array( 'inpartnerglobal.com', 'www.inpartnerglobal.com' );

	/** @var array<string,bool> Error codes already reported during this request (WordPress may sanitize twice). */
	private static array $reported = array();

	public static function defaults(): array {
		return array(
			'enabled'                 => true,
			'greetings'               => self::default_greetings(),
			'language'                => 'en',
			'contact_url'             => 'https://inpartnerglobal.com',
			'language_switcher'       => true,
			'detect_browser_language' => true,
		);
	}

	/**
	 * Built-in greeting per language, used when the admin leaves one empty.
	 *
	 * @return array<string,string>
	 */
	public static function default_greetings(): array {
		return array(
			'en' => self::LEGACY_DEFAULT_GREETING,
			'id' => 'Halo! Ada yang bisa saya bantu terkait layanan Inpartner?',
			'ko' => '안녕하세요! Inpartner의 서비스에 대해 무엇을 도와드릴까요?',
		);
	}

	/**
	 * Current settings: stored values that pass validation, defaults for the rest.
	 */
	public static function get(): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = self::defaults();

		foreach ( array( 'enabled', 'language_switcher', 'detect_browser_language' ) as $key ) {
			if ( array_key_exists( $key, $stored ) && is_bool( $stored[ $key ] ) ) {
				$settings[ $key ] = $stored[ $key ];
			} elseif ( array_key_exists( $key, $stored ) && in_array( $stored[ $key ], array( 0, 1, '0', '1' ), true ) ) {
				$settings[ $key ] = (bool) (int) $stored[ $key ];
			}
		}

		if ( isset( $stored['language'] ) && in_array( $stored['language'], self::LANGUAGES, true ) ) {
			$settings['language'] = $stored['language'];
		}

		$greetings = isset( $stored['greetings'] ) && is_array( $stored['greetings'] ) ? $stored['greetings'] : array();
		// 0.1.x stored one greeting for the default language. A customised one is
		// kept for that language; the old built-in English text is not, so an
		// Indonesian site gets the Indonesian default instead.
		if ( ! $greetings && isset( $stored['greeting_message'] ) ) {
			$legacy = self::validate_greeting( $stored['greeting_message'] );
			if ( is_string( $legacy ) && self::LEGACY_DEFAULT_GREETING !== $legacy ) {
				$greetings = array( $settings['language'] => $legacy );
			}
		}
		foreach ( self::LANGUAGES as $lang ) {
			$greeting = isset( $greetings[ $lang ] ) ? self::validate_greeting( $greetings[ $lang ] ) : null;
			if ( is_string( $greeting ) ) {
				$settings['greetings'][ $lang ] = $greeting;
			}
		}

		$url = isset( $stored['contact_url'] ) ? self::validate_contact_url( $stored['contact_url'] ) : null;
		if ( is_string( $url ) ) {
			$settings['contact_url'] = $url;
		}

		return $settings;
	}

	/**
	 * Creates the option on first activation, or fills keys missing from an
	 * older/partial option without touching values the admin already set.
	 */
	public static function install(): void {
		$stored = get_option( self::OPTION, null );
		if ( null === $stored ) {
			add_option( self::OPTION, self::defaults(), '', true );
			return;
		}
		$merged = self::get();
		if ( $stored !== $merged ) {
			update_option( self::OPTION, $merged );
		}
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Settings API sanitize callback. Invalid fields are rejected with an
	 * admin error and keep their previous (valid) value.
	 *
	 * @param mixed $input Raw submitted value.
	 */
	public static function sanitize( $input ): array {
		$current = self::get();
		if ( ! is_array( $input ) ) {
			self::error( 'invalid_input', __( 'Settings were not saved: invalid input.', 'wp-knowledge-chat' ) );
			return $current;
		}

		$clean = $current;

		// A checkbox is absent when unticked. Canonical on/off values are accepted;
		// anything else is rejected and the saved value kept.
		$checkboxes = array(
			'enabled'                 => __( 'Enable Chatbot must be checked or unchecked.', 'wp-knowledge-chat' ),
			'language_switcher'       => __( 'Language Switcher must be checked or unchecked.', 'wp-knowledge-chat' ),
			'detect_browser_language' => __( 'Browser Language must be checked or unchecked.', 'wp-knowledge-chat' ),
		);
		foreach ( $checkboxes as $key => $message ) {
			$value = $input[ $key ] ?? false;
			if ( true === $value || in_array( $value, array( '1', 1, 'on', 'yes', 'true' ), true ) ) {
				$clean[ $key ] = true;
			} elseif ( false === $value || in_array( $value, array( '0', 0, '', 'off', 'no', 'false' ), true ) ) {
				$clean[ $key ] = false;
			} else {
				self::error( $key, $message );
			}
		}

		if ( array_key_exists( 'greetings', $input ) ) {
			if ( is_array( $input['greetings'] ) ) {
				$defaults = self::default_greetings();
				foreach ( self::LANGUAGES as $lang ) {
					if ( ! array_key_exists( $lang, $input['greetings'] ) ) {
						continue;
					}
					$raw = $input['greetings'][ $lang ];
					// An empty field restores the built-in greeting for that language.
					if ( is_string( $raw ) && '' === trim( $raw ) ) {
						$clean['greetings'][ $lang ] = $defaults[ $lang ];
						continue;
					}
					$greeting = self::validate_greeting( $raw );
					if ( is_string( $greeting ) ) {
						$clean['greetings'][ $lang ] = $greeting;
					} else {
						self::error( 'greetings_' . $lang, $greeting->get_error_message() );
					}
				}
			} else {
				self::error( 'greetings', __( 'Greeting message must be text.', 'wp-knowledge-chat' ) );
			}
		}

		if ( array_key_exists( 'language', $input ) ) {
			if ( is_string( $input['language'] ) && in_array( $input['language'], self::LANGUAGES, true ) ) {
				$clean['language'] = $input['language'];
			} else {
				self::error( 'language', __( 'Default language must be English (en), Indonesian (id) or Korean (ko).', 'wp-knowledge-chat' ) );
			}
		}

		if ( array_key_exists( 'contact_url', $input ) ) {
			$url = self::validate_contact_url( $input['contact_url'] );
			if ( is_string( $url ) ) {
				$clean['contact_url'] = $url;
			} else {
				self::error( 'contact_url', $url->get_error_message() );
			}
		}

		return $clean;
	}

	/**
	 * @param mixed $value Raw greeting.
	 * @return string|\WP_Error Plain-text greeting or error.
	 */
	public static function validate_greeting( $value ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'wkc_greeting', __( 'Greeting message must be text.', 'wp-knowledge-chat' ) );
		}
		// Stored and rendered as plain text: markup is removed, never executed.
		// No wp_unslash(): options.php already unslashes, and unslashing again
		// strips legitimate backslashes from the greeting.
		$text = trim( sanitize_textarea_field( $value ) );
		if ( '' === $text ) {
			return new \WP_Error( 'wkc_greeting', __( 'Greeting message is required.', 'wp-knowledge-chat' ) );
		}
		if ( mb_strlen( $text ) > self::GREETING_MAX_CHARS ) {
			/* translators: %d: maximum number of characters. */
			return new \WP_Error( 'wkc_greeting', sprintf( __( 'Greeting message must be %d characters or fewer.', 'wp-knowledge-chat' ), self::GREETING_MAX_CHARS ) );
		}
		return $text;
	}

	/**
	 * Validates the contact URL without fetching it.
	 *
	 * @param mixed $value Raw URL.
	 * @return string|\WP_Error Normalised URL or error.
	 */
	public static function validate_contact_url( $value ) {
		$url = self::validate_https_url( $value, self::approved_contact_hosts() );
		if ( null === $url ) {
			return new \WP_Error(
				'wkc_contact_url',
				sprintf(
					/* translators: %s: comma-separated list of approved hostnames. */
					__( 'Contact URL must be an absolute https:// URL on an approved domain (%s), without credentials or a custom port.', 'wp-knowledge-chat' ),
					implode( ', ', self::approved_contact_hosts() )
				)
			);
		}
		return $url;
	}

	/**
	 * Shared strict URL policy for contact links and cited sources.
	 *
	 * @param mixed    $value         Candidate URL.
	 * @param string[] $allowed_hosts Exact hostnames permitted.
	 */
	public static function validate_https_url( $value, array $allowed_hosts ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) {
			return null;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host ) ) {
			return null;
		}
		if ( ! in_array( $host, $allowed_hosts, true ) ) {
			return null;
		}
		$normalised = 'https://' . $host . ( $parts['path'] ?? '' );
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$normalised .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$normalised .= '#' . $parts['fragment'];
		}
		$escaped = esc_url_raw( $normalised, array( 'https' ) );
		return '' === $escaped ? null : $escaped;
	}

	/** @return string[] */
	public static function approved_contact_hosts(): array {
		/**
		 * Filters hostnames allowed for the contact URL.
		 *
		 * @param string[] $hosts Lower-case hostnames.
		 */
		return self::normalise_hosts( apply_filters( 'wp_knowledge_chat_allowed_contact_hosts', self::base_hosts() ) );
	}

	/** @return string[] */
	public static function approved_source_hosts(): array {
		/**
		 * Filters hostnames allowed for cited source links shown in the widget.
		 *
		 * @param string[] $hosts Lower-case hostnames.
		 */
		return self::normalise_hosts( apply_filters( 'wp_knowledge_chat_allowed_source_hosts', self::DEFAULT_APPROVED_HOSTS ) );
	}

	/** @return string[] */
	private static function base_hosts(): array {
		$hosts     = self::DEFAULT_APPROVED_HOSTS;
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $site_host ) && '' !== $site_host ) {
			$hosts[] = strtolower( $site_host );
		}
		return $hosts;
	}

	/** @param mixed $hosts */
	private static function normalise_hosts( $hosts ): array {
		if ( ! is_array( $hosts ) ) {
			return self::DEFAULT_APPROVED_HOSTS;
		}
		$hosts = array_filter( array_map( static fn( $h ) => is_string( $h ) ? strtolower( trim( $h ) ) : '', $hosts ) );
		return array_values( array_unique( $hosts ) );
	}

	private static function error( string $code, string $message ): void {
		if ( isset( self::$reported[ $code ] ) || ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		self::$reported[ $code ] = true;
		add_settings_error( self::OPTION, 'wkc_' . $code, $message, 'error' );
	}

	/** Test helper: resets per-request error de-duplication. */
	public static function reset_reported_errors(): void {
		self::$reported = array();
	}
}
