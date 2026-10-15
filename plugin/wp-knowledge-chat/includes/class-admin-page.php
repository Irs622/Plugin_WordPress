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

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
		add_action( 'admin_post_' . self::HEALTH_ACTION, array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync' ) );
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
			<?php if ( Backend_Client::is_index_configured() ) : ?>
				<p style="margin-top:16px;"><strong><?php esc_html_e( 'Index API token configured.', 'wp-knowledge-chat' ); ?></strong>
				<?php esc_html_e( 'Knowledge base synchronization from WordPress pages is enabled.', 'wp-knowledge-chat' ); ?></p>
			<?php else : ?>
				<p style="margin-top:16px;"><em><?php esc_html_e( 'Note: To enable page synchronization to the AI backend, define WP_KNOWLEDGE_CHAT_INDEX_API_TOKEN in wp-config.php.', 'wp-knowledge-chat' ); ?></em></p>
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

				<div class="tablenav top" style="display:flex;align-items:center;gap:12px;margin:12px 0;">
					<button type="submit" name="sync_mode" value="selected" class="button button-primary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync Selected Pages', 'wp-knowledge-chat' ); ?>
					</button>
					<button type="submit" name="sync_mode" value="all" class="button button-secondary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync All Eligible Pages', 'wp-knowledge-chat' ); ?>
					</button>
					<label style="margin-left:auto;">
						<input type="checkbox" name="replace_all" value="1" />
						<?php esc_html_e( 'Replace entire backend knowledge base (prune unselected)', 'wp-knowledge-chat' ); ?>
					</label>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-cb check-column" style="width:3em;">
								<input type="checkbox" id="wkc-select-all" />
							</th>
							<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Page Title', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column" style="width:140px;"><?php esc_html_e( 'Category', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column" style="width:160px;"><?php esc_html_e( 'Content Size', 'wp-knowledge-chat' ); ?></th>
							<th scope="col" class="manage-column" style="width:160px;"><?php esc_html_e( 'Last Modified', 'wp-knowledge-chat' ); ?></th>
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
													<?php echo esc_html( ucfirst( $cat ) ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<?php if ( $page['eligible'] ) : ?>
											<span style="color:#007017;">
												<?php
												printf(
													/* translators: %d: character count */
													esc_html__( '%d chars (Ready)', 'wp-knowledge-chat' ),
													(int) $page['char_count']
												);
												?>
											</span>
										<?php else : ?>
											<span style="color:#b32d2e;">
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

				<div class="tablenav bottom" style="display:flex;align-items:center;gap:12px;margin:12px 0;">
					<button type="submit" name="sync_mode" value="selected" class="button button-primary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync Selected Pages', 'wp-knowledge-chat' ); ?>
					</button>
					<button type="submit" name="sync_mode" value="all" class="button button-secondary" <?php disabled( ! $index_configured || empty( $pages ) ); ?>>
						<?php esc_html_e( 'Sync All Eligible Pages', 'wp-knowledge-chat' ); ?>
					</button>
				</div>
			</form>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var topAll = document.getElementById('wkc-select-all');
			var botAll = document.getElementById('wkc-select-all-bottom');
			var boxes = document.querySelectorAll('.wkc-page-checkbox:not(:disabled)');
			function syncAll(checked) {
				boxes.forEach(function(b) { b.checked = checked; });
				if (topAll) topAll.checked = checked;
				if (botAll) botAll.checked = checked;
			}
			if (topAll) topAll.addEventListener('change', function() { syncAll(this.checked); });
			if (botAll) botAll.addEventListener('change', function() { syncAll(this.checked); });
		});
		</script>
		<?php
	}

	public function handle_sync(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-knowledge-chat' ), 403 );
		}
		check_admin_referer( self::SYNC_ACTION, self::SYNC_NONCE );

		$sync_mode   = isset( $_POST['sync_mode'] ) ? sanitize_key( wp_unslash( $_POST['sync_mode'] ) ) : 'selected';
		$replace_all = ! empty( $_POST['replace_all'] );

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

		$metrics      = Analytics::get_metrics();
		$leads        = Analytics::get_leads();
		$total        = $metrics['total_queries'];
		$grounded     = $metrics['grounded_queries'];
		$fallback     = $metrics['fallback_queries'];
		$answered_pct = $total > 0 ? (int) round( ( $grounded / $total ) * 100 ) : 0;
		$fallback_pct = $total > 0 ? (int) round( ( $fallback / $total ) * 100 ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Analytics & Consultation Leads', 'wp-knowledge-chat' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Monitor chatbot engagement, question trends, and visitor consultation requests. Zero personal data or visitor IPs are collected for queries.', 'wp-knowledge-chat' ); ?>
			</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:20px 0;">
				<div class="card" style="margin:0;padding:16px;">
					<h3 style="margin-top:0;color:#50575e;"><?php esc_html_e( 'Total Conversations', 'wp-knowledge-chat' ); ?></h3>
					<div style="font-size:28px;font-weight:700;color:#1d2327;"><?php echo (int) $total; ?></div>
					<p style="margin:6px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'Total questions asked by visitors', 'wp-knowledge-chat' ); ?></p>
				</div>
				<div class="card" style="margin:0;padding:16px;">
					<h3 style="margin-top:0;color:#007017;"><?php esc_html_e( 'Answered from Knowledge', 'wp-knowledge-chat' ); ?></h3>
					<div style="font-size:28px;font-weight:700;color:#007017;"><?php echo (int) $grounded; ?> <span style="font-size:16px;font-weight:400;">(<?php echo (int) $answered_pct; ?>%)</span></div>
					<p style="margin:6px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'Supported by official website sources', 'wp-knowledge-chat' ); ?></p>
				</div>
				<div class="card" style="margin:0;padding:16px;">
					<h3 style="margin-top:0;color:#b32d2e;"><?php esc_html_e( 'Consultation Fallbacks', 'wp-knowledge-chat' ); ?></h3>
					<div style="font-size:28px;font-weight:700;color:#b32d2e;"><?php echo (int) $fallback; ?> <span style="font-size:16px;font-weight:400;">(<?php echo (int) $fallback_pct; ?>%)</span></div>
					<p style="margin:6px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'Directed to consultation / contact link', 'wp-knowledge-chat' ); ?></p>
				</div>
				<div class="card" style="margin:0;padding:16px;">
					<h3 style="margin-top:0;color:#2271b1;"><?php esc_html_e( 'Captured Leads', 'wp-knowledge-chat' ); ?></h3>
					<div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo count( $leads ); ?></div>
					<p style="margin:6px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'Prospective clients requesting consultation', 'wp-knowledge-chat' ); ?></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Captured Consultation Leads', 'wp-knowledge-chat' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="margin-bottom:30px;">
				<thead>
					<tr>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Date (UTC)', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Name', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" style="width:200px;"><?php esc_html_e( 'Email', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" style="width:180px;"><?php esc_html_e( 'Company', 'wp-knowledge-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Inquiry Message', 'wp-knowledge-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $leads ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No leads captured yet. Prospective clients who request consultation via the chat widget will appear here.', 'wp-knowledge-chat' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $leads as $lead ) : ?>
							<tr>
								<td><?php echo esc_html( $lead['created_at'] ); ?></td>
								<td><strong><?php echo esc_html( $lead['name'] ); ?></strong></td>
								<td><a href="mailto:<?php echo esc_attr( $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a></td>
								<td><?php echo esc_html( $lead['company'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $lead['message'] ?: '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent Visitor Questions (Privacy-Safe Snippets)', 'wp-knowledge-chat' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Date (UTC)', 'wp-knowledge-chat' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Question', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" style="width:100px;"><?php esc_html_e( 'Language', 'wp-knowledge-chat' ); ?></th>
						<th scope="col" style="width:140px;"><?php esc_html_e( 'Outcome', 'wp-knowledge-chat' ); ?></th>
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
								<td><span class="badge" style="background:#f0f0f1;padding:2px 8px;border-radius:3px;font-size:12px;"><?php echo esc_html( strtoupper( $q['language'] ) ); ?></span></td>
								<td>
									<?php if ( ! empty( $q['grounded'] ) ) : ?>
										<span style="color:#007017;font-weight:600;"><?php esc_html_e( 'Grounded', 'wp-knowledge-chat' ); ?></span>
									<?php else : ?>
										<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'Fallback', 'wp-knowledge-chat' ); ?></span>
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
}
