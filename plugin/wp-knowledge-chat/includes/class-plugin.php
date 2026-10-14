<?php
/**
 * Plugin bootstrap: lifecycle, hook registration and widget loading.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const HANDLE = 'wp-knowledge-chat';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', array( Settings::class, 'register' ) );
		// While the stored value equals the registered default, update_option() takes the add_option() path.
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'purge_page_cache' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'purge_page_cache' ) );
		add_action( 'rest_api_init', array( new Rest_Controller(), 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_KNOWLEDGE_CHAT_FILE ), array( $this, 'action_links' ) );

		if ( is_admin() ) {
			( new Admin_Page() )->hooks();
		}
	}

	public static function activate(): void {
		Settings::install();
	}

	/**
	 * Deactivation keeps settings (removed only on uninstall) so re-activating restores the configuration.
	 */
	public static function deactivate(): void {
		wp_cache_delete( Settings::OPTION, 'options' );
	}

	/**
	 * The greeting, language and contact URL are embedded in every page, and
	 * the enabled flag decides whether the widget is printed at all, so cached
	 * pages are stale after a settings change. Asks LiteSpeed Cache (when
	 * active) to purge; the action is a no-op otherwise.
	 */
	public static function purge_page_cache(): void {
		do_action( 'litespeed_purge_all' );
	}

	/**
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Admin_Page::SLUG ) ), esc_html__( 'Settings', 'wp-knowledge-chat' ) )
		);
		return $links;
	}

	public function should_load_widget(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_customize_preview() ) {
			return false;
		}
		// Never inject into the Elementor editor preview iframe.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check.
		if ( isset( $_GET['elementor-preview'] ) ) {
			return false;
		}
		$load = Settings::get()['enabled'] && Backend_Client::is_configured();

		/**
		 * Filters whether the chat widget loads on the current request.
		 * Use it to restrict the widget to specific pages, e.g. `is_front_page() || is_page( 'contact' )`.
		 *
		 * @param bool $load Whether to load the widget.
		 */
		return (bool) apply_filters( 'wp_knowledge_chat_should_load', $load );
	}

	public function enqueue_assets(): void {
		if ( ! $this->should_load_widget() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, WP_KNOWLEDGE_CHAT_URL . 'assets/css/chat-widget.css', array(), WP_KNOWLEDGE_CHAT_VERSION );
		wp_enqueue_script(
			self::HANDLE,
			WP_KNOWLEDGE_CHAT_URL . 'assets/js/chat-widget.js',
			array(),
			WP_KNOWLEDGE_CHAT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.wpKnowledgeChatConfig = ' . wp_json_encode( $this->frontend_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	/**
	 * Public configuration for the widget. Contains no secrets and no backend address.
	 */
	public function frontend_config(): array {
		$settings = Settings::get();
		return array(
			'restUrl'      => esc_url_raw( rest_url( Rest_Controller::NAMESPACE . '/chat' ) ),
			'greeting'     => $settings['greeting_message'],
			'language'     => $settings['language'],
			'contactUrl'   => $settings['contact_url'],
			'maxLength'    => Rest_Controller::MAX_MESSAGE_CHARS,
			'sourceHosts'  => Settings::approved_source_hosts(),
			'contactHosts' => Settings::approved_contact_hosts(),
			'suggestions'  => self::suggested_questions( $settings['language'] ),
		);
	}

	/**
	 * @return string[]
	 */
	public static function suggested_questions( string $language ): array {
		$defaults = array(
			'en' => array(
				'What services does Inpartner offer?',
				'How do you help companies enter the Indonesian market?',
				'What is Distribution Partner Sourcing Advisory?',
				'How can I contact Inpartner?',
			),
			'id' => array(
				'Layanan apa saja yang ditawarkan Inpartner?',
				'Bagaimana Inpartner membantu perusahaan masuk ke pasar Indonesia?',
				'Apa itu layanan Distribution Partner Sourcing Advisory?',
				'Bagaimana cara menghubungi Inpartner?',
			),
		);

		/**
		 * Filters the suggested questions shown in the widget.
		 *
		 * @param string[] $questions Questions for the language.
		 * @param string   $language  'en' or 'id'.
		 */
		$questions = apply_filters( 'wp_knowledge_chat_suggested_questions', $defaults[ $language ] ?? $defaults['en'], $language );
		if ( ! is_array( $questions ) ) {
			return array();
		}
		$clean = array();
		foreach ( $questions as $question ) {
			if ( ! is_string( $question ) ) {
				continue;
			}
			$question = trim( sanitize_text_field( $question ) );
			if ( '' !== $question && mb_strlen( $question ) <= 150 ) {
				$clean[] = $question;
			}
		}
		return array_slice( $clean, 0, 4 );
	}
}
