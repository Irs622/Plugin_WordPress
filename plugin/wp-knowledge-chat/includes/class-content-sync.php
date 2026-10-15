<?php
/**
 * Content extractor and knowledge-base synchronizer for WordPress pages.
 *
 * Extracts published WordPress pages, cleans HTML/Gutenberg/Elementor markup,
 * maps them to approved knowledge-base categories, and pushes them to the AI backend.
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
		if ( 'publish' !== $post->post_status ) {
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

		$slug = sanitize_title( $post->post_name ?: $title );
		if ( '' === $slug ) {
			$slug = (string) $post->ID;
		}

		$updated_at = ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt
			? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $post->post_modified_gmt ) )
			: gmdate( 'Y-m-d\TH:i:s\Z' );

		return array(
			'id'         => 'page-' . $post->ID . '-' . $slug,
			'title'      => mb_substr( $title, 0, 300 ),
			'url'        => $permalink,
			'text'       => $text,
			'category'   => $valid_category,
			'status'     => 'publish',
			'updated_at' => $updated_at,
		);
	}

	/**
	 * Lists all published WordPress pages with extraction status and metadata.
	 *
	 * @return array<int,array{id:int,title:string,url:string,slug:string,modified:string,char_count:int,category:string,eligible:bool}>
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
			$clean_text = self::clean_html( self::get_raw_post_content( $page ) );
			$char_count = mb_strlen( $clean_text );
			$result[]   = array(
				'id'         => $page->ID,
				'title'      => get_the_title( $page ),
				'url'        => get_permalink( $page ),
				'slug'       => $page->post_name,
				'modified'   => $page->post_modified,
				'char_count' => $char_count,
				'category'   => self::detect_category( $page ),
				'eligible'   => $char_count >= self::MIN_DOCUMENT_CHARS,
			);
		}

		return $result;
	}

	/**
	 * Syncs selected published pages to the AI backend via Backend_Client::index.
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

		$documents = array();
		foreach ( $page_ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$category = isset( $category_map[ $id ] ) && is_string( $category_map[ $id ] ) ? $category_map[ $id ] : '';
			$doc      = self::extract_document( $post, $category );
			if ( null !== $doc ) {
				$documents[] = $doc;
			}
		}

		if ( empty( $documents ) ) {
			return array(
				'success' => false,
				'message' => __( 'None of the selected pages contain sufficient text content to be indexed (minimum 40 characters).', 'wp-knowledge-chat' ),
			);
		}

		$result = Backend_Client::index( $documents, array(), $replace_all );
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
			$err_msg = isset( $body['error']['message'] ) && is_string( $body['error']['message'] )
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

		$upserted  = isset( $body['upserted'] ) ? (int) $body['upserted'] : count( $documents );
		$unchanged = isset( $body['unchanged'] ) ? (int) $body['unchanged'] : 0;
		$total     = isset( $body['total'] ) ? (int) $body['total'] : count( $documents );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: upserted count, 2: unchanged count, 3: total count */
				__( 'Knowledge Base synchronized successfully! %1$d document(s) updated, %2$d unchanged. Total documents in AI Backend: %3$d.', 'wp-knowledge-chat' ),
				$upserted,
				$unchanged,
				$total
			),
			'counts'  => array(
				'upserted'  => $upserted,
				'unchanged' => $unchanged,
				'total'     => $total,
			),
		);
	}

	/**
	 * Automatically synchronizes a page to the AI backend when published, updated, or unpublished.
	 */
	public static function on_post_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'page' !== $post->post_type || wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		if ( ! Backend_Client::is_index_configured() ) {
			return;
		}

		$title = wp_strip_all_tags( get_the_title( $post ) );
		$slug  = sanitize_title( $post->post_name ?: $title );
		if ( '' === $slug ) {
			$slug = (string) $post->ID;
		}
		$doc_id = 'page-' . $post->ID . '-' . $slug;

		if ( 'publish' === $new_status ) {
			$doc = self::extract_document( $post );
			if ( null !== $doc ) {
				Backend_Client::index( array( $doc ) );
			}
		} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
			Backend_Client::index( array(), array( $doc_id ) );
		}
	}
}

