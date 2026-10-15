<?php
/**
 * Content extractor and knowledge-base synchronizer for WordPress pages.
 *
 * Extracts published WordPress pages, cleans HTML/Gutenberg/Elementor markup,
 * maps them to approved knowledge-base categories, and pushes them to the AI backend.
 *
 * Approval model: a page enters the knowledge base only when an administrator
 * syncs it from WP Knowledge Chat → Knowledge Base. Those pages are recorded in
 * the SYNCED_OPTION map. Afterwards, edits to an approved page are pushed
 * automatically, and unpublishing, trashing, password-protecting or deleting
 * any page removes it. Publishing a new page never adds it by itself.
 *
 * Automatic updates are queued during the request and sent once at shutdown
 * (several save hooks fire for one edit), computed from the page's current
 * state, with a short timeout. A failed update is recorded in PENDING_OPTION,
 * shown on the Knowledge Base screen and retried by WP-Cron.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Content_Sync {

	public const CATEGORIES           = array( 'home', 'about', 'service', 'contact', 'faq', 'article' );
	public const MIN_DOCUMENT_CHARS   = 40;
	public const MAX_DOCUMENT_CHARS   = 100000;
	public const MAX_PAGES_PER_SYNC   = 200;
	public const SYNCED_OPTION        = 'wkc_synced_pages';
	public const PENDING_OPTION       = 'wkc_sync_pending';
	public const RETRY_HOOK           = 'wkc_retry_sync';
	public const RETRY_DELAY          = 5 * MINUTE_IN_SECONDS;
	public const MAX_ATTEMPTS         = 5;
	public const AUTO_SYNC_TIMEOUT    = 10;

	/** Lines that are page-builder boilerplate rather than content (compared case-insensitively). */
	public const BOILERPLATE_LINES = array( 'read more', 'learn more', 'lean more', 'get started', 'start a conversation', 'schedule a call', 'connect with us', 'get direction', 'view all insight', 'home', 'submit', 'send', 'no content is added yet.', 'no content is added yet' );

	/** @var array<int,true> Pages whose knowledge-base state must be re-checked at shutdown. */
	private static array $queue = array();

	/**
	 * Cleans page markup into clean, plain body text.
	 */
	public static function clean_html( string $content ): string {
		// Strip Gutenberg block comments <!-- wp:... -->.
		$text = preg_replace( '/<!-- \/?wp:[^>]*-->/', '', $content ) ?? $content;

		// Strip shortcodes.
		$text = strip_shortcodes( $text );

		// Strip script and style tags with their contents.
		$text = preg_replace( '/<(script|style)[^>]*?>.*?<\/\\1>/si', '', $text ) ?? $text;

		// Strip HTML tags and normalize whitespace.
		$text = wp_strip_all_tags( $text );

		// Decode HTML entities.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Strip control characters (except newline and tab).
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', $text ) ?? $text;

		// Normalize multiple newlines and spaces.
		$text = preg_replace( "/\r\n|\r/", "\n", $text ) ?? $text;
		$text = preg_replace( "/[ \t]+/", ' ', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;

		/**
		 * Filters lines dropped as page-builder boilerplate (buttons, menu items, placeholders).
		 *
		 * @param string[] $lines Lower-case lines.
		 */
		$boilerplate = (array) apply_filters( 'wp_knowledge_chat_boilerplate_lines', self::BOILERPLATE_LINES );
		$lines       = array_filter(
			explode( "\n", $text ),
			fn( string $line ) => ! in_array( mb_strtolower( trim( $line ) ), $boilerplate, true )
		);
		$text        = preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) ?? '';

		return trim( $text );
	}

	/**
	 * Infers a knowledge-base category from a page's slug, title, and homepage status.
	 */
	public static function detect_category( \WP_Post $post ): string {
		$front_page_id = (int) get_option( 'page_on_front', 0 );
		if ( $front_page_id > 0 && $post->ID === $front_page_id ) {
			return 'home';
		}

		$slug  = strtolower( $post->post_name );
		$title = strtolower( $post->post_title );
		$hay   = $slug . ' ' . $title;

		if ( preg_match( '/\b(about|tentang|profil|profile|who-we-are)\b/', $hay ) ) {
			return 'about';
		}
		if ( preg_match( '/\b(contact|kontak|hubungi|reach-us)\b/', $hay ) ) {
			return 'contact';
		}
		if ( preg_match( '/\b(faq|tanya-jawab|pertanyaan|help|qna)\b/', $hay ) ) {
			return 'faq';
		}
		if ( preg_match( '/\b(home|beranda|front)\b/', $hay ) ) {
			return 'home';
		}
		if ( preg_match( '/\b(article|artikel|blog|news|berita)\b/', $hay ) ) {
			return 'article';
		}

		return 'service';
	}

	/**
	 * Resolves raw or builder-rendered content for a post.
	 */
	public static function get_raw_post_content( \WP_Post $post ): string {
		// Render as an anonymous visitor: shortcodes and widgets that show members-only or
		// admin-only content to the logged-in editor must not leak it into the knowledge base.
		$user_id = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			return self::render_content( $post );
		} finally {
			wp_set_current_user( $user_id );
		}
	}

	private static function render_content( \WP_Post $post ): string {
		$content = $post->post_content;

		// Elementor builder compatibility: if built with Elementor, fetch rendered content.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->db ) && \Elementor\Plugin::$instance->db->is_built_with_elementor( $post->ID ) ) {
			if ( isset( \Elementor\Plugin::$instance->frontend ) && method_exists( \Elementor\Plugin::$instance->frontend, 'get_builder_content_for_display' ) ) {
				$builder_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID, false );
				if ( is_string( $builder_content ) && '' !== trim( $builder_content ) ) {
					$content = $builder_content;
				}
			}
		}

		// Expand shortcodes if present.
		if ( false !== strpos( $content, '[' ) && function_exists( 'do_shortcode' ) ) {
			$expanded = do_shortcode( $content );
			if ( is_string( $expanded ) && '' !== trim( $expanded ) ) {
				$content = $expanded;
			}
		}

		return $content;
	}

	/**
	 * Converts a single WordPress Post/Page into a KnowledgeDocument array.
	 * Returns null if the page is not published, has no body text, or is invalid.
	 *
	 * @return array{id:string,title:string,url:string,text:string,category:string,status:string,updated_at:string}|null
	 */
	public static function extract_document( \WP_Post $post, string $category = '' ): ?array {
		if ( ! self::is_indexable( $post ) ) {
			return null;
		}

		$text = self::clean_html( self::get_raw_post_content( $post ) );
		if ( mb_strlen( $text ) < self::MIN_DOCUMENT_CHARS ) {
			return null;
		}
		if ( mb_strlen( $text ) > self::MAX_DOCUMENT_CHARS ) {
			$text = mb_substr( $text, 0, self::MAX_DOCUMENT_CHARS );
		}

		$valid_category = in_array( $category, self::CATEGORIES, true ) ? $category : self::detect_category( $post );

		$title = wp_strip_all_tags( get_the_title( $post ) );
		if ( '' === trim( $title ) ) {
			$title = 'Page ' . $post->ID;
		}

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}

		$updated_at = ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt
			? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $post->post_modified_gmt ) )
			: gmdate( 'Y-m-d\TH:i:s\Z' );

		return array(
			'id'         => self::document_id( $post ),
			'title'      => mb_substr( $title, 0, 300 ),
			'url'        => $permalink,
			'text'       => $text,
			'category'   => $valid_category,
			'status'     => 'publish',
			'updated_at' => $updated_at,
		);
	}

	/**
	 * Only published, non-password-protected pages are public content. Password-protected
	 * pages have post_status "publish" too, but their body is not public.
	 */
	public static function is_indexable( \WP_Post $post ): bool {
		return 'page' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
	}

	/**
	 * Knowledge-base id of a page. Kept compatible with 0.2.0 ("page-{ID}-{slug}").
	 */
	public static function document_id( \WP_Post $post ): string {
		$title = wp_strip_all_tags( get_the_title( $post ) );
		// Trashing renames "about" to "about__trashed"; the document was indexed as "about".
		$name = preg_replace( '/__trashed(-\d+)?$/', '', $post->post_name ) ?? $post->post_name;
		$slug = sanitize_title( $name ?: $title );
		if ( '' === $slug ) {
			$slug = (string) $post->ID;
		}
		return 'page-' . $post->ID . '-' . $slug;
	}

	/**
	 * @return array<int,array{doc_id:string,synced_at:string}> Approved pages by post ID.
	 */
	public static function synced_pages(): array {
		$map = get_option( self::SYNCED_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * @return array<int,array{attempts:int,error:string,since:string}> Failed automatic updates by post ID.
	 */
	public static function pending(): array {
		$map = get_option( self::PENDING_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Lists all published WordPress pages with extraction status and metadata.
	 *
	 * @return array<int,array{id:int,title:string,url:string,slug:string,modified:string,char_count:int,category:string,eligible:bool,protected:bool,synced:bool}>
	 */
	public static function get_syncable_pages(): array {
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'number'      => self::MAX_PAGES_PER_SYNC,
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
			)
		);

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$result = array();
		foreach ( $pages as $page ) {
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			$protected  = '' !== $page->post_password;
			$clean_text = $protected ? '' : self::clean_html( self::get_raw_post_content( $page ) );
			$char_count = mb_strlen( $clean_text );
			$result[]   = array(
				'id'         => $page->ID,
				'title'      => get_the_title( $page ),
				'url'        => get_permalink( $page ),
				'slug'       => $page->post_name,
				'modified'   => $page->post_modified,
				'char_count' => $char_count,
				'category'   => self::detect_category( $page ),
				'eligible'   => ! $protected && $char_count >= self::MIN_DOCUMENT_CHARS,
				'protected'  => $protected,
				'synced'     => isset( self::synced_pages()[ $page->ID ] ),
			);
		}

		return $result;
	}

	/**
	 * Syncs selected published pages to the AI backend via Backend_Client::index.
	 * A successful sync is what approves the pages for automatic updates.
	 *
	 * @param array<int,int>           $page_ids       Array of post IDs to sync.
	 * @param array<int|string,string> $category_map   Map of post ID to selected category.
	 * @param bool                     $replace_all    Whether to replace the entire backend KB.
	 * @return array{success:bool, message:string, counts?:array{upserted:int,unchanged:int,total:int}}
	 */
	public static function sync_pages( array $page_ids, array $category_map = array(), bool $replace_all = false ): array {
		if ( empty( $page_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'No pages selected for synchronization.', 'wp-knowledge-chat' ),
			);
		}

		if ( ! Backend_Client::is_index_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'Index API Token is not configured. Please define WP_KNOWLEDGE_CHAT_INDEX_API_TOKEN in wp-config.php.', 'wp-knowledge-chat' ),
			);
		}

		$synced     = self::synced_pages();
		$documents  = array();
		$delete_ids = array();
		$approved   = array();
		foreach ( array_unique( array_map( 'intval', $page_ids ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$category = isset( $category_map[ $id ] ) && is_string( $category_map[ $id ] ) ? $category_map[ $id ] : '';
			$doc      = self::extract_document( $post, $category );
			if ( null === $doc ) {
				continue;
			}
			$documents[]     = $doc;
			$approved[ $id ] = $doc['id'];
			// The slug changed since the last sync: remove the old entry in the same request.
			if ( isset( $synced[ $id ]['doc_id'] ) && $synced[ $id ]['doc_id'] !== $doc['id'] && ! $replace_all ) {
				$delete_ids[] = $synced[ $id ]['doc_id'];
			}
		}

		if ( empty( $documents ) ) {
			return array(
				'success' => false,
				'message' => __( 'None of the selected pages contain sufficient public text content to be indexed (minimum 40 characters; password-protected pages are never indexed).', 'wp-knowledge-chat' ),
			);
		}

		$result = Backend_Client::index( $documents, $delete_ids, $replace_all );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Synchronization failed: %s', 'wp-knowledge-chat' ),
					$result->get_error_message()
				),
			);
		}

		$status = $result['status'];
		$body   = $result['body'];

		if ( 200 !== $status || ! is_array( $body ) ) {
			$err_msg = is_array( $body ) && isset( $body['error']['message'] ) && is_string( $body['error']['message'] )
				? $body['error']['message']
				: __( 'Unexpected response from AI backend.', 'wp-knowledge-chat' );
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: status code, 2: message */
					__( 'Backend rejected synchronization (HTTP %1$d): %2$s', 'wp-knowledge-chat' ),
					$status,
					$err_msg
				),
			);
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$next = $replace_all ? array() : $synced;
		foreach ( $documents as $doc ) {
			$id          = (int) array_search( $doc['id'], $approved, true );
			$next[ $id ] = array(
				'doc_id'    => $doc['id'],
				'synced_at' => $now,
			);
			// Automatic updates keep the category chosen here.
			update_post_meta( $id, '_wkc_category', $doc['category'] );
		}
		update_option( self::SYNCED_OPTION, $next, false );
		$pending = self::pending();
		foreach ( array_keys( $approved ) as $id ) {
			unset( $pending[ $id ] );
		}
		update_option( self::PENDING_OPTION, $pending, false );

		$upserted  = isset( $body['upserted'] ) ? (int) $body['upserted'] : count( $documents );
		$unchanged = isset( $body['unchanged'] ) ? (int) $body['unchanged'] : 0;
		$total     = isset( $body['total'] ) ? (int) $body['total'] : count( $documents );

		$message = sprintf(
			/* translators: 1: upserted count, 2: unchanged count, 3: total count */
			__( 'Knowledge Base synchronized successfully! %1$d document(s) updated, %2$d unchanged. Total documents in AI Backend: %3$d.', 'wp-knowledge-chat' ),
			$upserted,
			$unchanged,
			$total
		);
		if ( isset( $body['persisted'] ) && 'ephemeral' === $body['persisted'] ) {
			$message .= ' ' . __( 'Warning: the backend could not save the knowledge base to disk; the update will be lost when it restarts.', 'wp-knowledge-chat' );
		}

		return array(
			'success' => true,
			'message' => $message,
			'counts'  => array(
				'upserted'  => $upserted,
				'unchanged' => $unchanged,
				'total'     => $total,
			),
		);
	}

	/**
	 * Hooked to transition_post_status: queues the page for a check at shutdown.
	 */
	public static function on_post_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'page' !== $post->post_type || wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}
		// Only pages that are or were public can affect the knowledge base.
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		self::queue( $post->ID );
	}

	/**
	 * Hooked to wp_after_insert_post: runs after meta (e.g. Elementor data, password) is saved.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_after_insert_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && 'page' === $post->post_type && ! wp_is_post_revision( $post ) && ( isset( self::synced_pages()[ $post->ID ] ) || isset( self::$queue[ $post->ID ] ) ) ) {
			self::queue( $post->ID );
		}
	}

	/**
	 * Hooked to elementor/document/after_save: Elementor saves its data after the post itself.
	 *
	 * @param object $document Elementor document.
	 */
	public static function on_elementor_save( $document ): void {
		if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
			$post_id = (int) $document->get_main_id();
			if ( isset( self::synced_pages()[ $post_id ] ) ) {
				self::queue( $post_id );
			}
		}
	}

	/**
	 * Hooked to before_delete_post: a published page deleted without going through the trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_before_delete_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return;
		}
		$ids = self::stale_ids( $post );
		if ( 'publish' === $post->post_status || isset( self::synced_pages()[ $post->ID ] ) ) {
			// The post is about to disappear, so it cannot be re-read at shutdown: delete now.
			unset( self::$queue[ $post->ID ] );
			self::send_change( $post->ID, array(), $ids );
		}
	}

	private static function queue( int $post_id ): void {
		if ( ! Backend_Client::is_index_configured() ) {
			return;
		}
		if ( empty( self::$queue ) && ! has_action( 'shutdown', array( self::class, 'flush_queue' ) ) ) {
			add_action( 'shutdown', array( self::class, 'flush_queue' ) );
		}
		self::$queue[ $post_id ] = true;
	}

	/**
	 * Drops queued changes without sending them (tests, or a request that must not sync).
	 */
	public static function discard_queue(): void {
		self::$queue = array();
	}

	/**
	 * Sends the queued changes (once per page per request).
	 */
	public static function flush_queue(): void {
		$queue       = array_keys( self::$queue );
		self::$queue = array();
		foreach ( $queue as $post_id ) {
			self::apply_current_state( (int) $post_id );
		}
	}

	/**
	 * Brings the knowledge base in line with the page as it is now: an approved,
	 * indexable page is upserted; anything else is removed. Idempotent, so
	 * duplicate events and retries are harmless.
	 *
	 * @return bool True when the backend accepted the change (or none was needed).
	 */
	public static function apply_current_state( int $post_id ): bool {
		$post   = get_post( $post_id );
		$synced = self::synced_pages();

		if ( ! $post instanceof \WP_Post ) {
			$ids = isset( $synced[ $post_id ]['doc_id'] ) ? array( $synced[ $post_id ]['doc_id'] ) : array();
			return empty( $ids ) ? self::forget( $post_id ) : self::send_change( $post_id, array(), $ids );
		}

		$approved = isset( $synced[ $post_id ] );
		$doc      = $approved ? self::extract_document( $post, self::stored_category( $post ) ) : null;
		if ( null !== $doc ) {
			$delete = array_values( array_diff( self::stale_ids( $post ), array( $doc['id'] ) ) );
			return self::send_change( $post_id, array( $doc ), $delete );
		}
		if ( ! $approved && self::is_indexable( $post ) ) {
			return true; // Published but never approved: not ours to add.
		}
		return self::send_change( $post_id, array(), self::stale_ids( $post ) );
	}

	/**
	 * Keeps the category an administrator chose at sync time; otherwise detects it.
	 */
	private static function stored_category( \WP_Post $post ): string {
		$category = get_post_meta( $post->ID, '_wkc_category', true );
		return is_string( $category ) && in_array( $category, self::CATEGORIES, true ) ? $category : '';
	}

	/**
	 * Ids under which this page may exist in the knowledge base: the one recorded at
	 * the last sync and the one derived from its current slug (0.2.0 pushed pages
	 * automatically without recording them).
	 *
	 * @return string[]
	 */
	private static function stale_ids( \WP_Post $post ): array {
		$ids    = array( self::document_id( $post ) );
		$synced = self::synced_pages();
		if ( isset( $synced[ $post->ID ]['doc_id'] ) ) {
			$ids[] = $synced[ $post->ID ]['doc_id'];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<int,array<string,string>> $documents
	 * @param string[]                        $delete_ids
	 */
	private static function send_change( int $post_id, array $documents, array $delete_ids ): bool {
		if ( empty( $documents ) && empty( $delete_ids ) ) {
			return true;
		}
		$result = Backend_Client::index( $documents, $delete_ids, false, self::AUTO_SYNC_TIMEOUT );
		$ok     = ! is_wp_error( $result ) && 200 === $result['status'];
		if ( ! $ok ) {
			$reason = is_wp_error( $result ) ? 'unreachable' : 'http_' . (int) $result['status'];
			self::record_failure( $post_id, $reason );
			/**
			 * Fires when an automatic knowledge-base update failed (it will be retried).
			 *
			 * @param int    $post_id Page ID.
			 * @param string $reason  unreachable or http_<status>.
			 */
			do_action( 'wp_knowledge_chat_sync_failure', $post_id, $reason );
			return false;
		}

		$synced  = self::synced_pages();
		$pending = self::pending();
		unset( $pending[ $post_id ] );
		if ( empty( $documents ) ) {
			unset( $synced[ $post_id ] ); // Removed pages need a new approval to come back.
		} else {
			$synced[ $post_id ] = array(
				'doc_id'    => $documents[0]['id'],
				'synced_at' => gmdate( 'Y-m-d H:i:s' ),
			);
		}
		update_option( self::SYNCED_OPTION, $synced, false );
		update_option( self::PENDING_OPTION, $pending, false );
		return true;
	}

	private static function forget( int $post_id ): bool {
		$synced  = self::synced_pages();
		$pending = self::pending();
		unset( $synced[ $post_id ], $pending[ $post_id ] );
		update_option( self::SYNCED_OPTION, $synced, false );
		update_option( self::PENDING_OPTION, $pending, false );
		return true;
	}

	private static function record_failure( int $post_id, string $reason ): void {
		$pending             = self::pending();
		$attempts            = (int) ( $pending[ $post_id ]['attempts'] ?? 0 ) + 1;
		$pending[ $post_id ] = array(
			'attempts' => $attempts,
			'error'    => $reason,
			'since'    => $pending[ $post_id ]['since'] ?? gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::PENDING_OPTION, $pending, false );
		if ( $attempts < self::MAX_ATTEMPTS && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK );
		}
	}

	/**
	 * WP-Cron handler (and "Retry now" button): re-applies every pending page.
	 *
	 * @return int Pages still pending.
	 */
	public static function retry_pending(): int {
		foreach ( array_keys( self::pending() ) as $post_id ) {
			self::apply_current_state( (int) $post_id );
		}
		return count( self::pending() );
	}
}
