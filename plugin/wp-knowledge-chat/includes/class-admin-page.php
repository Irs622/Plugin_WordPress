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
			'enabled'          => __( 'Enable Chatbot', 'wp-knowledge-chat' ),
			'greeting_message' => __( 'Greeting Message', 'wp-knowledge-chat' ),
			'language'         => __( 'Default Language', 'wp-knowledge-chat' ),
			'contact_url'      => __( 'Contact URL', 'wp-knowledge-chat' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'wkc_' . $key,
				$label,
				array( $this, 'render_field' ),
				self::SLUG,
				'wkc_main',
				array(
					'key'       => $key,
					'label_for' => 'enabled' === $key ? 'wkc-enabled' : 'wkc-' . str_replace( '_', '-', $key ),
				)
			);
		}
	}

	/**
	 * @param array{key:string,label_for:string} $args Field arguments.
	 */
	public function render_field( array $args ): void {
		$settings = Settings::get();
		$name     = Settings::OPTION . '[' . $args['key'] . ']';
		$id       = $args['label_for'];

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

			case 'greeting_message':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="3" cols="60" maxlength="%3$d" required aria-describedby="%1$s-help">%4$s</textarea><p class="description" id="%1$s-help">%5$s</p>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) Settings::GREETING_MAX_CHARS,
					esc_textarea( $settings['greeting_message'] ),
					esc_html(
						sprintf(
							/* translators: %d: maximum characters. */
							__( 'Required. Plain text only (HTML is removed). Maximum %d characters.', 'wp-knowledge-chat' ),
							Settings::GREETING_MAX_CHARS
						)
					)
				);
				break;

			case 'language':
				$options = array(
					'en' => __( 'English', 'wp-knowledge-chat' ),
					'id' => __( 'Bahasa Indonesia', 'wp-knowledge-chat' ),
				);
				printf( '<select id="%1$s" name="%2$s" aria-describedby="%1$s-help">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $options as $value => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $settings['language'], $value, false ), esc_html( $label ) );
				}
				printf( '</select><p class="description" id="%1$s-help">%2$s</p>', esc_attr( $id ), esc_html__( 'Interface language and preferred answer language. Source content is not altered.', 'wp-knowledge-chat' ) );
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
							__( 'Absolute https:// URL on an approved domain: %s. The official contact page is https://inpartnerglobal.com/contact/.', 'wp-knowledge-chat' ),
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
					echo $health['ok']
						? esc_html__( 'Connection test: backend is reachable and its knowledge base is ready.', 'wp-knowledge-chat' )
						/* translators: %d: HTTP status code, 0 when unreachable. */
						: esc_html( sprintf( __( 'Connection test failed (HTTP status %d). Check the backend URL and that the service is running.', 'wp-knowledge-chat' ), (int) $health['status'] ) );
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
