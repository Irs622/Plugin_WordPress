<?php
/**
 * Admin screen: WP Knowledge Chat → Settings.
 *
 * The form posts to options.php, so WordPress enforces the settings nonce
 * and the manage_options capability; the sanitize callback in Settings
 * performs field validation.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	public const SLUG          = 'wp-knowledge-chat';
	public const CAPABILITY    = 'manage_options';
	public const HEALTH_ACTION = 'wkc_test_connection';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
		add_action( 'admin_post_' . self::HEALTH_ACTION, array( $this, 'handle_test_connection' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'WP Knowledge Chat', 'wp-knowledge-chat' ),
			__( 'WP Knowledge Chat', 'wp-knowledge-chat' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat',
			80
		);
		add_submenu_page(
			self::SLUG,
			__( 'WP Knowledge Chat Settings', 'wp-knowledge-chat' ),
			__( 'Settings', 'wp-knowledge-chat' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function add_fields(): void {
		add_settings_section( 'wkc_main', __( 'Chat widget', 'wp-knowledge-chat' ), '__return_false', self::SLUG );

		$fields = array(
			'enabled'                 => __( 'Enable Chatbot', 'wp-knowledge-chat' ),
			'greetings'               => __( 'Greeting Messages', 'wp-knowledge-chat' ),
			'language'                => __( 'Default Language', 'wp-knowledge-chat' ),
			'language_switcher'       => __( 'Language Switcher', 'wp-knowledge-chat' ),
			'detect_browser_language' => __( 'Browser Language', 'wp-knowledge-chat' ),
			'contact_url'             => __( 'Contact URL', 'wp-knowledge-chat' ),
		);
		foreach ( $fields as $key => $label ) {
			$args = array( 'key' => $key );
			// The greetings row holds one labelled textarea per language, so its heading labels none of them.
			if ( 'greetings' !== $key ) {
				$args['label_for'] = 'wkc-' . str_replace( '_', '-', $key );
			}
			add_settings_field( 'wkc_' . $key, $label, array( $this, 'render_field' ), self::SLUG, 'wkc_main', $args );
		}
	}

	/**
	 * Language names, each written in its own language (never translated).
	 *
	 * @return array<string,string>
	 */
	public static function language_names(): array {
		return array(
			'en' => 'English',
			'id' => 'Bahasa Indonesia',
			'ko' => '한국어',
		);
	}

	/**
	 * @param array{key:string,label_for?:string} $args Field arguments.
	 */
	public function render_field( array $args ): void {
		$settings = Settings::get();
		$name     = Settings::OPTION . '[' . $args['key'] . ']';
		$id       = $args['label_for'] ?? 'wkc-' . $args['key'];

		switch ( $args['key'] ) {
			case 'enabled':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> <span>%4$s</span>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( $settings['enabled'], true, false ),
					esc_html__( 'Show the chat widget on the public site.', 'wp-knowledge-chat' )
				);
				break;

			case 'greetings':
				printf( '<fieldset aria-describedby="%1$s-help"><legend class="screen-reader-text">%2$s</legend>', esc_attr( $id ), esc_html__( 'Greeting Messages', 'wp-knowledge-chat' ) );
				foreach ( self::language_names() as $lang => $label ) {
					printf(
						'<p><label for="%1$s-%2$s">%3$s</label><br /><textarea id="%1$s-%2$s" name="%4$s[%2$s]" rows="3" cols="60" maxlength="%5$d" lang="%2$s">%6$s</textarea></p>',
						esc_attr( $id ),
						esc_attr( $lang ),
						esc_html( $label ),
						esc_attr( $name ),
						(int) Settings::GREETING_MAX_CHARS,
						esc_textarea( $settings['greetings'][ $lang ] )
					);
				}
				printf(
					'</fieldset><p class="description" id="%1$s-help">%2$s</p>',
					esc_attr( $id ),
					esc_html(
						sprintf(
							/* translators: %d: maximum characters. */
							__( 'Shown when the chat opens, in the visitor\'s language. Plain text only (HTML is removed). Maximum %d characters. Leave a field empty to restore its default.', 'wp-knowledge-chat' ),
							Settings::GREETING_MAX_CHARS
						)
					)
				);
				break;

			case 'language':
				printf( '<select id="%1$s" name="%2$s" aria-describedby="%1$s-help">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( self::language_names() as $value => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $settings['language'], $value, false ), esc_html( $label ) );
				}
				printf( '</select><p class="description" id="%1$s-help">%2$s</p>', esc_attr( $id ), esc_html__( 'Interface language and preferred answer language when the visitor has not chosen one. Source content is not altered.', 'wp-knowledge-chat' ) );
				break;

			case 'language_switcher':
			case 'detect_browser_language':
				$texts = array(
					'language_switcher'       => __( 'Let visitors switch between English, Bahasa Indonesia and 한국어 in the chat header.', 'wp-knowledge-chat' ),
					'detect_browser_language' => __( 'Start in the visitor\'s browser language when it is supported; otherwise use the default language.', 'wp-knowledge-chat' ),
				);
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> <span>%4$s</span>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( $settings[ $args['key'] ], true, false ),
					esc_html( $texts[ $args['key'] ] )
				);
				break;

			case 'contact_url':
				printf(
					'<input type="url" class="regular-text" id="%1$s" name="%2$s" value="%3$s" required pattern="https://.*" aria-describedby="%1$s-help" /><p class="description" id="%1$s-help">%4$s</p>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $settings['contact_url'] ),
					esc_html(
						sprintf(
							/* translators: %s: approved hostnames. */
							__( 'Absolute https:// URL on an approved domain: %s.', 'wp-knowledge-chat' ),
							implode( ', ', Settings::approved_contact_hosts() )
						)
					)
				);
				break;
		}
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'wp-knowledge-chat' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Knowledge Chat Settings', 'wp-knowledge-chat' ); ?></h1>
			<?php settings_errors(); ?>
			<?php $this->render_backend_status(); ?>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( Settings::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	private function render_backend_status(): void {
		$configured = Backend_Client::is_configured();
		$health     = get_transient( 'wkc_health_' . get_current_user_id() );
		if ( false !== $health ) {
			delete_transient( 'wkc_health_' . get_current_user_id() );
		}
		?>
		<div class="card" style="max-width:720px">
			<h2><?php esc_html_e( 'AI backend', 'wp-knowledge-chat' ); ?></h2>
			<?php if ( $configured ) : ?>
				<p><strong><?php esc_html_e( 'Configured.', 'wp-knowledge-chat' ); ?></strong>
				<?php esc_html_e( 'The backend URL and shared secret are read from wp-config.php and are never shown here or sent to browsers.', 'wp-knowledge-chat' ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Not configured.', 'wp-knowledge-chat' ); ?></strong>
				<?php esc_html_e( 'The widget stays hidden until WP_KNOWLEDGE_CHAT_BACKEND_URL (https) and WP_KNOWLEDGE_CHAT_SHARED_SECRET (32+ characters) are defined in wp-config.php.', 'wp-knowledge-chat' ); ?></p>
			<?php endif; ?>
			<?php if ( is_array( $health ) ) : ?>
				<p role="status">
					<?php
					if ( $health['ok'] ) {
						esc_html_e( 'Connection test: backend is reachable, the shared secret is accepted and the knowledge base is ready.', 'wp-knowledge-chat' );
					} elseif ( 401 === (int) $health['status'] ) {
						esc_html_e( 'Connection test failed: the backend rejected the shared secret. Check that WP_KNOWLEDGE_CHAT_SHARED_SECRET matches CHAT_SHARED_SECRET and that the server clock is correct.', 'wp-knowledge-chat' );
					} elseif ( 503 === (int) $health['status'] ) {
						esc_html_e( 'Connection test failed: the backend is running but its knowledge base is empty.', 'wp-knowledge-chat' );
					} else {
						/* translators: %d: HTTP status code, 0 when unreachable. */
						echo esc_html( sprintf( __( 'Connection test failed (HTTP status %d). Check the backend URL and that the service is running.', 'wp-knowledge-chat' ), (int) $health['status'] ) );
					}
					?>
				</p>
			<?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::HEALTH_ACTION ); ?>" />
				<?php wp_nonce_field( self::HEALTH_ACTION ); ?>
				<?php submit_button( __( 'Test connection', 'wp-knowledge-chat' ), 'secondary', 'submit', false, $configured ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-knowledge-chat' ), 403 );
		}
		check_admin_referer( self::HEALTH_ACTION );
		set_transient( 'wkc_health_' . get_current_user_id(), Backend_Client::health(), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
