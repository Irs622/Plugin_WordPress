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
	public const SYNC_SLUG     = 'wp-knowledge-chat-sync';
	public const LEADS_SLUG    = 'wp-knowledge-chat-leads';
	public const CAPABILITY    = 'manage_options';
	public const HEALTH_ACTION = 'wkc_test_connection';
	public const SYNC_ACTION   = 'wkc_sync_kb';
	public const SYNC_NONCE    = 'wkc_sync_nonce';
	public const LEADS_ACTION  = 'wkc_leads_action';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
		add_action( 'admin_post_' . self::HEALTH_ACTION, array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync' ) );
		add_action( 'admin_post_' . self::LEADS_ACTION, array( $this, 'handle_leads_action' ) );
	}

	/**
	 * Loads the admin script and stylesheet on this plugin's screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screens = array(
			'toplevel_page_' . self::SLUG,
			self::SLUG . '_page_' . self::SYNC_SLUG,
			self::SLUG . '_page_' . self::LEADS_SLUG,
		);
		if ( ! in_array( $hook_suffix, $screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'wkc-admin', WP_KNOWLEDGE_CHAT_URL . 'assets/css/admin.css', array(), WP_KNOWLEDGE_CHAT_VERSION );
		wp_enqueue_script( 'wkc-admin', WP_KNOWLEDGE_CHAT_URL . 'assets/js/admin.js', array(), WP_KNOWLEDGE_CHAT_VERSION, array( 'in_footer' => true ) );
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
		add_submenu_page(
			self::SLUG,
			__( 'Knowledge Base Sync', 'wp-knowledge-chat' ),
			__( 'Knowledge Base', 'wp-knowledge-chat' ),
			self::CAPABILITY,
			self::SYNC_SLUG,
			array( $this, 'render_sync_page' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Analytics & Consultation Leads', 'wp-knowledge-chat' ),
			__( 'Analytics & Leads', 'wp-knowledge-chat' ),
			self::CAPABILITY,
			self::LEADS_SLUG,
			array( $this, 'render_leads_page' )
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
		<div class="card wkc-card-status">
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
			<?php if ( Backend_Client::is_index_configured() ) : ?>
				<p class="wkc-mt"><strong><?php esc_html_e( 'Index API token configured.', 'wp-knowledge-chat' ); ?></strong>
				<?php esc_html_e( 'Knowledge base synchronization from WordPress pages is enabled.', 'wp-knowledge-chat' ); ?></p>
			<?php else : ?>
				<p class="wkc-mt"><em><?php esc_html_e( 'Note: To enable page synchronization to the AI backend, define WP_KNOWLEDGE_CHAT_INDEX_API_TOKEN in wp-config.php.', 'wp-knowledge-chat' ); ?></em></p>
			<?php endif; ?>
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

	public function render_sync_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'wp-knowledge-chat' ), 403 );
		}

		$sync_result = get_transient( 'wkc_sync_result_' . get_current_user_id() );
		if ( false !== $sync_result ) {
			delete_transient( 'wkc_sync_result_' . get_current_user_id() );
		}

		$index_configured = Backend_Client::is_index_configured();
		$pages            = Content_Sync::get_syncable_pages();
		$categories       = Content_Sync::CATEGORIES;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Knowledge Base Synchronization', 'wp-knowledge-chat' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Sync published WordPress pages directly into the AI knowledge base. Content is cleaned (markup, Gutenberg blocks, and scripts removed) and vectorized by the AI backend.', 'wp-knowledge-chat' ); ?>
			</p>

			<?php if ( is_array( $sync_result ) && ! empty( $sync_result['message'] ) ) : ?>
				<div class="notice <?php echo ! empty( $sync_result['success'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
					<p><?php echo esc_html( $sync_result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			$pending = Content_Sync::pending();
			if ( ! empty( $pending ) ) :
				?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Automatic updates failed:', 'wp-knowledge-chat' ); ?></strong>
						<?php esc_html_e( 'the AI backend still has an outdated copy of these pages (an unpublished page may still be answered from). They are retried every few minutes.', 'wp-knowledge-chat' ); ?>
					</p>
					<ul class="wkc-list">
						<?php foreach ( $pending as $pending_id => $info ) : ?>
							<li>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: page title or ID, 2: error code, 3: attempts, 4: date. */
										__( '%1$s — %2$s, %3$d attempt(s) since %4$s UTC', 'wp-knowledge-chat' ),
										get_the_title( (int) $pending_id ) ?: '#' . (int) $pending_id,
										(string) ( $info['error'] ?? '' ),
										(int) ( $info['attempts'] ?? 0 ),
										(string) ( $info['since'] ?? '' )
									)
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wkc-mb">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SYNC_ACTION ); ?>" />
						<?php wp_nonce_field( self::SYNC_ACTION, self::SYNC_NONCE ); ?>
						<button type="submit" name="sync_mode" value="retry" class="button"><?php esc_html_e( 'Retry now', 'wp-knowledge-chat' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Only pages you sync here enter the knowledge base. After that, edits are sent automatically; unpublishing, trashing, password-protecting or deleting a page removes it. New pages are never added automatically, and password-protected pages are never indexed.', 'wp-knowledge-chat' ); ?>
			</p>

			<?php if ( ! $index_configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Sync not ready:', 'wp-knowledge-chat' ); ?></strong>
						<?php esc_html_e( 'Define WP_KNOWLEDGE_CHAT_INDEX_API_TOKEN in wp-config.php to enable synchronizing pages with the AI backend.', 'wp-knowledge-chat' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SYNC_ACTION ); ?>" />
				<?php wp_nonce_field( self::SYNC_ACTION, self::SYNC_NONCE ); ?>

				<div class="tablenav top wkc-tablenav">
					<button type="submit" name="sync_mode" value="selected" class="button button-primary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync Selected Pages', 'wp-knowledge-chat' ); ?>
					</button>
					<button type="submit" name="sync_mode" value="all" class="button button-secondary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync All Eligible Pages', 'wp-knowledge-chat' ); ?>
					</button>
					<label class="wkc-ml-auto">
						<input type="checkbox" name="replace_all" value="1" />
						<?php esc_html_e( 'Replace entire backend knowledge base (prune unselected)', 'wp-knowledge-chat' ); ?>
					</label>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-cb check-column wkc-w-cb">
								<input type="checkbox" id="wkc-select-all" />
							</th>
							<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Page Title', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column wkc-w-140"><?php esc_html_e( 'Category', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column wkc-w-160"><?php esc_html_e( 'Content Size', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column wkc-w-160"><?php esc_html_e( 'Last Modified', 'wp-knowledge-chat' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $pages ) ) : ?>
							<tr>
								<td colspan="5"><?php esc_html_e( 'No published pages found.', 'wp-knowledge-chat' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $pages as $page ) : ?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" name="page_ids[]" value="<?php echo esc_attr( (string) $page['id'] ); ?>" class="wkc-page-checkbox" <?php disabled( ! $page['eligible'] ); ?> <?php checked( $page['eligible'] ); ?> />
									</th>
									<td class="column-primary">
										<strong>
											<a href="<?php echo esc_url( get_edit_post_link( $page['id'] ) ?: $page['url'] ); ?>">
												<?php echo esc_html( $page['title'] ); ?>
											</a>
										</strong>
										<div class="row-actions">
											<span><a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'wp-knowledge-chat' ); ?></a></span>
										</div>
									</td>
									<td>
										<select name="categories[<?php echo esc_attr( (string) $page['id'] ); ?>]">
											<?php foreach ( $categories as $cat ) : ?>
												<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $page['category'], $cat ); ?>>
													<?php echo esc_html( self::category_label( $cat ) ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<?php if ( ! empty( $page['protected'] ) ) : ?>
											<span class="wkc-bad"><?php esc_html_e( 'Password protected (never indexed)', 'wp-knowledge-chat' ); ?></span>
										<?php elseif ( $page['eligible'] ) : ?>
											<span class="wkc-good">
												<?php
												printf(
													/* translators: %d: character count */
													esc_html__( '%d chars (Ready)', 'wp-knowledge-chat' ),
													(int) $page['char_count']
												);
												?>
											</span>
										<?php else : ?>
											<span class="wkc-bad">
												<?php
												printf(
													/* translators: %d: character count */
													esc_html__( '%d chars (Too short)', 'wp-knowledge-chat' ),
													(int) $page['char_count']
												);
												?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php echo esc_html( $page['modified'] ); ?>
										<?php if ( ! empty( $page['synced'] ) ) : ?>
											<br /><span class="description"><?php esc_html_e( 'In knowledge base', 'wp-knowledge-chat' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<th scope="col" class="manage-column column-cb check-column">
								<input type="checkbox" id="wkc-select-all-bottom" />
							</th>
							<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Page Title', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column"><?php esc_html_e( 'Category', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column"><?php esc_html_e( 'Content Size', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column"><?php esc_html_e( 'Last Modified', 'wp-knowledge-chat' ); ?></th>
						</tr>
					</tfoot>
				</table>

				<div class="tablenav bottom wkc-tablenav">
					<button type="submit" name="sync_mode" value="selected" class="button button-primary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync Selected Pages', 'wp-knowledge-chat' ); ?>
					</button>
					<button type="submit" name="sync_mode" value="all" class="button button-secondary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync All Eligible Pages', 'wp-knowledge-chat' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_sync(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-knowledge-chat' ), 403 );
		}
		check_admin_referer( self::SYNC_ACTION, self::SYNC_NONCE );

		$sync_mode   = isset( $_POST['sync_mode'] ) ? sanitize_key( wp_unslash( $_POST['sync_mode'] ) ) : 'selected';
		$replace_all = ! empty( $_POST['replace_all'] );

		if ( 'retry' === $sync_mode ) {
			$left = Content_Sync::retry_pending();
			set_transient(
				'wkc_sync_result_' . get_current_user_id(),
				array(
					'success' => 0 === $left,
					'message' => 0 === $left
						? __( 'All pending knowledge-base updates were applied.', 'wp-knowledge-chat' )
						/* translators: %d: number of pages. */
						: sprintf( __( '%d page update(s) still failing. Check the backend and try again.', 'wp-knowledge-chat' ), $left ),
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SYNC_SLUG ) );
			exit;
		}

		$category_map = array();
		if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- elements sanitized in loop.
			$raw_categories = (array) wp_unslash( $_POST['categories'] );
			foreach ( $raw_categories as $page_id => $cat ) {
				$category_map[ (int) $page_id ] = sanitize_key( (string) $cat );
			}
		}

		$page_ids = array();
		if ( 'all' === $sync_mode ) {
			$all_pages = Content_Sync::get_syncable_pages();
			foreach ( $all_pages as $p ) {
				if ( $p['eligible'] ) {
					$page_ids[] = (int) $p['id'];
				}
			}
		} elseif ( isset( $_POST['page_ids'] ) && is_array( $_POST['page_ids'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- elements cast to (int) in loop.
			$raw_page_ids = (array) wp_unslash( $_POST['page_ids'] );
			foreach ( $raw_page_ids as $id ) {
				$page_ids[] = (int) $id;
			}
		}

		$result = Content_Sync::sync_pages( $page_ids, $category_map, $replace_all );
		set_transient( 'wkc_sync_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SYNC_SLUG ) );
		exit;
	}

	/**
	 * Renders the Analytics & Leads dashboard.
	 */
	public function render_leads_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-knowledge-chat' ), 403 );
		}

		Analytics::maybe_migrate_legacy_leads();
		Analytics::purge_expired_leads();
		$notice = get_transient( 'wkc_leads_notice_' . get_current_user_id() );
		if ( false !== $notice ) {
			delete_transient( 'wkc_leads_notice_' . get_current_user_id() );
		}
		$metrics      = Analytics::get_metrics();
		$leads        = Analytics::get_leads();
		$lead_count   = Analytics::count_leads();
		$total        = $metrics['total_queries'];
		$grounded     = $metrics['grounded_queries'];
		$fallback     = $metrics['fallback_queries'];
		$answered_pct = $total > 0 ? (int) round( ( $grounded / $total ) * 100 ) : 0;
		$fallback_pct = $total > 0 ? (int) round( ( $fallback / $total ) * 100 ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Analytics & Consultation Leads', 'wp-knowledge-chat' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Monitor chatbot engagement, question trends, and visitor consultation requests. Visitor IP addresses are never stored. Question snippets have e-mail addresses, phone numbers and links removed, but may still contain personal details a visitor typed. Leads contain personal data: keep them only as long as needed.', 'wp-knowledge-chat' ); ?>
			</p>
			<?php if ( is_string( $notice ) && '' !== $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="wkc-stats">
				<div class="card wkc-stat">
					<h3 class="wkc-stat-title"><?php esc_html_e( 'Total Conversations', 'wp-knowledge-chat' ); ?></h3>
					<div class="wkc-stat-value"><?php echo (int) $total; ?></div>
					<p class="wkc-stat-note"><?php esc_html_e( 'Total questions asked by visitors', 'wp-knowledge-chat' ); ?></p>
				</div>
				<div class="card wkc-stat">
					<h3 class="wkc-stat-title wkc-good"><?php esc_html_e( 'Answered from Knowledge', 'wp-knowledge-chat' ); ?></h3>
					<div class="wkc-stat-value wkc-good"><?php echo (int) $grounded; ?> <span class="wkc-stat-pct">(<?php echo (int) $answered_pct; ?>%)</span></div>
					<p class="wkc-stat-note"><?php esc_html_e( 'Supported by official website sources', 'wp-knowledge-chat' ); ?></p>
				</div>
				<div class="card wkc-stat">
					<h3 class="wkc-stat-title wkc-bad"><?php esc_html_e( 'Consultation Fallbacks', 'wp-knowledge-chat' ); ?></h3>
					<div class="wkc-stat-value wkc-bad"><?php echo (int) $fallback; ?> <span class="wkc-stat-pct">(<?php echo (int) $fallback_pct; ?>%)</span></div>
					<p class="wkc-stat-note"><?php esc_html_e( 'Directed to consultation / contact link', 'wp-knowledge-chat' ); ?></p>
				</div>
				<div class="card wkc-stat">
					<h3 class="wkc-stat-title wkc-link"><?php esc_html_e( 'Captured Leads', 'wp-knowledge-chat' ); ?></h3>
					<div class="wkc-stat-value wkc-link"><?php echo (int) $lead_count; ?></div>
					<p class="wkc-stat-note"><?php esc_html_e( 'Prospective clients requesting consultation', 'wp-knowledge-chat' ); ?></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Captured Consultation Leads', 'wp-knowledge-chat' ); ?></h2>
			<?php if ( $lead_count > count( $leads ) ) : ?>
				<p class="description">
					<?php
					/* translators: 1: leads shown, 2: total leads. */
					echo esc_html( sprintf( __( 'Showing the newest %1$d of %2$d leads. Export CSV includes all of them.', 'wp-knowledge-chat' ), count( $leads ), $lead_count ) );
					?>
				</p>
			<?php endif; ?>
			<div class="wkc-actions">
				<?php $this->leads_button( 'export', __( 'Export CSV', 'wp-knowledge-chat' ), 'button' ); ?>
				<?php $this->leads_button( 'delete_all', __( 'Delete all leads', 'wp-knowledge-chat' ), 'button button-link-delete', __( 'Permanently delete all leads? This cannot be undone.', 'wp-knowledge-chat' ) ); ?>
				<?php $this->leads_button( 'reset_metrics', __( 'Reset metrics', 'wp-knowledge-chat' ), 'button', __( 'Reset all conversation counts and recent questions?', 'wp-knowledge-chat' ) ); ?>
			</div>
			<table class="wp-list-table widefat fixed striped wkc-mb-lg">
				<thead>
					<tr>
						<th scope="col" class="wkc-w-150"><?php esc_html_e( 'Date (UTC)', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-150"><?php esc_html_e( 'Name', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-190"><?php esc_html_e( 'Email', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-150"><?php esc_html_e( 'Company', 'wp-knowledge-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Inquiry Message', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-110"><?php esc_html_e( 'Notification', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-90"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wp-knowledge-chat' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $leads ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No leads captured yet. Prospective clients who request consultation via the chat widget will appear here.', 'wp-knowledge-chat' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $leads as $lead ) : ?>
							<tr>
								<td><?php echo esc_html( $lead['created_at'] ); ?></td>
								<td><strong><?php echo esc_html( $lead['name'] ); ?></strong></td>
								<td><a href="<?php echo esc_url( 'mailto:' . $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a></td>
								<td><?php echo esc_html( $lead['company'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $lead['message'] ?: '—' ); ?></td>
								<td><?php echo esc_html( self::mail_status_label( $lead['mail_status'] ) ); ?></td>
								<td><?php $this->leads_button( 'delete', __( 'Delete', 'wp-knowledge-chat' ), 'button-link button-link-delete', __( 'Permanently delete this lead?', 'wp-knowledge-chat' ), (int) $lead['id'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent Visitor Questions (Privacy-Safe Snippets)', 'wp-knowledge-chat' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="wkc-w-160"><?php esc_html_e( 'Date (UTC)', 'wp-knowledge-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Question', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-100"><?php esc_html_e( 'Language', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" class="wkc-w-140"><?php esc_html_e( 'Outcome', 'wp-knowledge-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $metrics['recent_queries'] ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No questions recorded yet.', 'wp-knowledge-chat' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $metrics['recent_queries'] as $q ) : ?>
							<tr>
								<td><?php echo esc_html( $q['time'] ); ?></td>
								<td><?php echo esc_html( $q['question'] ); ?></td>
								<td><span class="wkc-badge"><?php echo esc_html( strtoupper( $q['language'] ) ); ?></span></td>
								<td>
									<?php if ( ! empty( $q['grounded'] ) ) : ?>
										<span class="wkc-good wkc-strong"><?php esc_html_e( 'Grounded', 'wp-knowledge-chat' ); ?></span>
									<?php else : ?>
										<span class="wkc-bad wkc-strong"><?php esc_html_e( 'Fallback', 'wp-knowledge-chat' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function category_label( string $category ): string {
		$labels = array(
			'service' => __( 'Service', 'wp-knowledge-chat' ),
			'about'   => __( 'About', 'wp-knowledge-chat' ),
			'contact' => __( 'Contact', 'wp-knowledge-chat' ),
			'faq'     => __( 'FAQ', 'wp-knowledge-chat' ),
			'home'    => __( 'Home', 'wp-knowledge-chat' ),
			'article' => __( 'Article', 'wp-knowledge-chat' ),
		);
		return $labels[ $category ] ?? ucfirst( $category );
	}

	private static function mail_status_label( string $status ): string {
		$labels = array(
			'sent'     => __( 'Sent', 'wp-knowledge-chat' ),
			'failed'   => __( 'Failed', 'wp-knowledge-chat' ),
			'pending'  => __( 'Pending', 'wp-knowledge-chat' ),
			'migrated' => __( 'Before 0.2.1', 'wp-knowledge-chat' ),
		);
		return $labels[ $status ] ?? '—';
	}

	/**
	 * One-button form posting to admin-post.php with a nonce bound to the operation (and lead).
	 */
	private function leads_button( string $operation, string $label, string $class, string $confirm = '', int $lead_id = 0 ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wkc-inline-form"<?php echo '' !== $confirm ? ' data-wkc-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::LEADS_ACTION ); ?>" />
			<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>" />
			<?php if ( $lead_id > 0 ) : ?>
				<input type="hidden" name="lead_id" value="<?php echo esc_attr( (string) $lead_id ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( self::LEADS_ACTION . '_' . $operation . '_' . $lead_id ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public function handle_leads_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-knowledge-chat' ), 403 );
		}
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$lead_id   = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
		check_admin_referer( self::LEADS_ACTION . '_' . $operation . '_' . $lead_id );

		$notice = '';
		switch ( $operation ) {
			case 'delete':
				$notice = Analytics::delete_lead( $lead_id ) ? __( 'Lead deleted.', 'wp-knowledge-chat' ) : __( 'Lead not found.', 'wp-knowledge-chat' );
				break;
			case 'delete_all':
				/* translators: %d: number of leads. */
				$notice = sprintf( __( '%d lead(s) deleted.', 'wp-knowledge-chat' ), Analytics::delete_leads() );
				break;
			case 'reset_metrics':
				Analytics::reset_metrics();
				$notice = __( 'Metrics reset.', 'wp-knowledge-chat' );
				break;
			case 'export':
				$this->send_leads_csv();
				return;
			default:
				wp_die( esc_html__( 'Unknown action.', 'wp-knowledge-chat' ), 400 );
		}
		set_transient( 'wkc_leads_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::LEADS_SLUG ) );
		exit;
	}

	/**
	 * Neutralises spreadsheet formulas (CSV injection) in visitor-supplied cells.
	 */
	public static function csv_cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * @return string CSV of every lead (header row first).
	 */
	public static function leads_csv(): string {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $handle, array( 'created_at_utc', 'name', 'email', 'company', 'message', 'language', 'notification' ), ',', '"', '' );
		foreach ( Analytics::get_leads( 100000 ) as $lead ) {
			fputcsv(
				$handle,
				array_map( array( self::class, 'csv_cell' ), array( $lead['created_at'], $lead['name'], $lead['email'], $lead['company'], $lead['message'], $lead['language'], $lead['mail_status'] ) ),
				',',
				'"',
				''
			);
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	private function send_leads_csv(): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wkc-leads-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::leads_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells neutralised by csv_cell().
		exit;
	}
}
