<?php
/**
 * Analytics and lead storage for WP Knowledge Chat.
 *
 * Aggregate conversation metrics are kept in one option, with recent question
 * snippets redacted of e-mail addresses, phone numbers, links and long numbers.
 *
 * Leads (name, e-mail, optional company and message that a visitor submits
 * voluntarily) are stored as posts of a private, non-public post type so that
 * each lead is an independent row: concurrent submissions cannot overwrite
 * each other, a flood of submissions cannot push older leads out, and every
 * lead can be exported or erased through the WordPress privacy tools. Only
 * users with manage_options can see them (admin screen); the post type has no
 * UI, REST route, query var, search or export.
 *
 * Retention: leads are kept until an administrator deletes them, unless the
 * `wp_knowledge_chat_lead_retention_days` filter returns a positive number of
 * days. Uninstalling the plugin deletes all leads and metrics.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	public const METRICS_OPTION = 'wkc_metrics';
	/** Legacy (0.2.0) storage: an option holding up to 100 leads. Migrated to LEAD_POST_TYPE. */
	public const LEADS_OPTION   = 'wkc_leads';
	public const LEAD_POST_TYPE = 'wkc_lead';
	/** Upper bound for one admin screen; leads beyond it are kept, not dropped. */
	public const MAX_LEADS  = 100;
	public const MAX_RECENT = 25;

	/** Maximum characters per lead field. */
	public const FIELD_LIMITS = array(
		'name'    => 100,
		'email'   => 254,
		'company' => 150,
		'message' => 2000,
	);

	/** Identical e-mail + message within this window is treated as a resubmission. */
	public const DUPLICATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	private const META_PREFIX = '_wkc_';

	public static function register_post_type(): void {
		register_post_type(
			self::LEAD_POST_TYPE,
			array(
				'label'               => __( 'WP Knowledge Chat leads', 'wp-knowledge-chat' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array(),
				// Capabilities no role has: leads are read and deleted only through Analytics (manage_options screens).
				'capability_type'     => 'wkc_lead',
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Replaces personal identifiers that visitors sometimes type into a question.
	 */
	public static function redact( string $text ): string {
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text ) ?? '';
		$text = preg_replace( '#\b(?:https?://|www\.)\S+#i', '[link]', $text ) ?? '';
		$text = preg_replace( '/\+?\d[\d\s().-]{6,}\d/', '[phone]', $text ) ?? '';
		$text = preg_replace( '/\d{6,}/', '[number]', $text ) ?? '';
		return $text;
	}

	/**
	 * Records a chat query event for aggregate metrics and a redacted recent-question snippet.
	 */
	public static function record_query( string $question, string $language, bool $grounded, string $outcome = 'answered' ): void {
		$metrics = get_option( self::METRICS_OPTION, array() );
		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}

		$total          = (int) ( $metrics['total_queries'] ?? 0 ) + 1;
		$grounded_count = (int) ( $metrics['grounded_queries'] ?? 0 ) + ( $grounded ? 1 : 0 );
		$fallback_count = (int) ( $metrics['fallback_queries'] ?? 0 ) + ( $grounded ? 0 : 1 );

		$languages              = isset( $metrics['languages'] ) && is_array( $metrics['languages'] ) ? $metrics['languages'] : array();
		$languages[ $language ] = (int) ( $languages[ $language ] ?? 0 ) + 1;

		$recent = isset( $metrics['recent_queries'] ) && is_array( $metrics['recent_queries'] ) ? $metrics['recent_queries'] : array();

		/**
		 * Filters whether redacted question snippets are kept for the dashboard.
		 * Return false to keep only counts.
		 *
		 * @param bool $store Default true.
		 */
		if ( apply_filters( 'wp_knowledge_chat_store_query_snippets', true ) ) {
			array_unshift(
				$recent,
				array(
					'time'     => gmdate( 'Y-m-d H:i:s' ),
					'question' => mb_substr( self::redact( wp_strip_all_tags( $question ) ), 0, 120 ),
					'language' => $language,
					'grounded' => $grounded,
					'outcome'  => $outcome,
				)
			);
		}
		$recent = array_slice( $recent, 0, self::MAX_RECENT );

		$metrics['total_queries']    = $total;
		$metrics['grounded_queries'] = $grounded_count;
		$metrics['fallback_queries'] = $fallback_count;
		$metrics['languages']        = $languages;
		$metrics['recent_queries']   = $recent;

		update_option( self::METRICS_OPTION, $metrics, false );
	}

	/**
	 * Returns the aggregate metrics.
	 *
	 * @return array{total_queries:int,grounded_queries:int,fallback_queries:int,languages:array<string,int>,recent_queries:array<int,array{time:string,question:string,language:string,grounded:bool,outcome:string}>}
	 */
	public static function get_metrics(): array {
		$defaults = array(
			'total_queries'    => 0,
			'grounded_queries' => 0,
			'fallback_queries' => 0,
			'languages'        => array(
				'en' => 0,
				'id' => 0,
				'ko' => 0,
			),
			'recent_queries'   => array(),
		);
		$metrics  = get_option( self::METRICS_OPTION, array() );
		return is_array( $metrics ) ? array_merge( $defaults, $metrics ) : $defaults;
	}

	/**
	 * Validates and normalises a lead. Over-long fields are rejected, not cut,
	 * so a visitor never has part of their message silently dropped.
	 *
	 * @param array<string,mixed> $lead_data Raw fields.
	 * @return array{name:string,email:string,company:string,message:string}|null
	 */
	public static function clean_lead( array $lead_data ): ?array {
		$clean = array();
		foreach ( array_keys( self::FIELD_LIMITS ) as $field ) {
			$raw = $lead_data[ $field ] ?? '';
			if ( ! is_string( $raw ) ) {
				return null;
			}
			if ( mb_strlen( $raw ) > self::FIELD_LIMITS[ $field ] ) {
				return null;
			}
			$clean[ $field ] = trim( 'message' === $field ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
		}
		$clean['email'] = sanitize_email( $clean['email'] );
		if ( '' === $clean['name'] || ! is_email( $clean['email'] ) ) {
			return null;
		}
		return $clean;
	}

	private static function fingerprint( string $email, string $message ): string {
		return hash_hmac( 'sha256', strtolower( $email ) . '|' . $message, wp_salt( 'nonce' ) );
	}

	/**
	 * True when the same e-mail and message were stored within DUPLICATE_WINDOW.
	 */
	public static function is_duplicate( string $email, string $message ): bool {
		$ids = get_posts(
			array(
				'post_type'        => self::LEAD_POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by post type and date.
				'meta_query'       => array(
					array(
						'key'   => self::META_PREFIX . 'fingerprint',
						'value' => self::fingerprint( $email, $message ),
					),
				),
				'date_query'       => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', time() - self::DUPLICATE_WINDOW ),
						'column'    => 'post_date_gmt',
						'inclusive' => true,
					),
				),
			)
		);
		return ! empty( $ids );
	}

	/**
	 * Stores a lead, then notifies the site administrator. The lead is stored
	 * first, so a mail failure never loses it; the mail outcome is recorded on
	 * the lead and shown on the dashboard.
	 *
	 * @param array<string,mixed> $lead_data name, email, company?, message?, language?.
	 * @return bool True when the lead is stored (or was an identical recent resubmission).
	 */
	public static function save_lead( array $lead_data ): bool {
		$lead = self::clean_lead( $lead_data );
		if ( null === $lead ) {
			return false;
		}
		self::maybe_migrate_legacy_leads();

		if ( self::is_duplicate( $lead['email'], $lead['message'] ) ) {
			return true;
		}

		$language = isset( $lead_data['language'] ) && in_array( $lead_data['language'], Settings::LANGUAGES, true ) ? $lead_data['language'] : '';
		$id       = self::insert_lead( $lead, $language, gmdate( 'Y-m-d H:i:s' ) );
		if ( 0 === $id ) {
			return false;
		}

		update_post_meta( $id, self::META_PREFIX . 'mail_status', self::notify_admin( $lead ) ? 'sent' : 'failed' );
		return true;
	}

	/**
	 * @param array{name:string,email:string,company:string,message:string} $lead
	 * @return int Post ID, 0 on failure.
	 */
	private static function insert_lead( array $lead, string $language, string $created_gmt ): int {
		$id = wp_insert_post(
			array(
				'post_type'     => self::LEAD_POST_TYPE,
				'post_status'   => 'private',
				// No personal data in core post fields (titles show up in more places than meta).
				'post_title'    => 'Lead',
				'post_date_gmt' => $created_gmt,
				'post_date'     => get_date_from_gmt( $created_gmt ),
				'meta_input'    => array(
					self::META_PREFIX . 'name'        => $lead['name'],
					self::META_PREFIX . 'email'       => $lead['email'],
					self::META_PREFIX . 'company'     => $lead['company'],
					self::META_PREFIX . 'message'     => $lead['message'],
					self::META_PREFIX . 'language'    => $language,
					self::META_PREFIX . 'fingerprint' => self::fingerprint( $lead['email'], $lead['message'] ),
					self::META_PREFIX . 'mail_status' => 'pending',
				),
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * @param array{name:string,email:string,company:string,message:string} $lead
	 */
	private static function notify_admin( array $lead ): bool {
		/**
		 * Filters the address that receives new-lead notifications.
		 *
		 * @param string $email Default: the site's admin_email option.
		 */
		$to = apply_filters( 'wp_knowledge_chat_lead_notification_email', get_option( 'admin_email' ) );
		if ( ! is_string( $to ) || ! is_email( $to ) ) {
			return false;
		}
		// sanitize_text_field() removed line breaks from the name, so it cannot inject headers.
		$subject = sprintf( '[Inpartner AI Lead] New inquiry from %s', $lead['name'] );
		$body    = sprintf(
			"New consultation inquiry captured by WP Knowledge Chat:\n\nName: %s\nEmail: %s\nCompany: %s\nMessage:\n%s\n\nSubmitted at: %s UTC\n\nAll leads: %s",
			$lead['name'],
			$lead['email'],
			'' !== $lead['company'] ? $lead['company'] : '-',
			'' !== $lead['message'] ? $lead['message'] : '-',
			gmdate( 'Y-m-d H:i:s' ),
			admin_url( 'admin.php?page=' . Admin_Page::LEADS_SLUG )
		);
		$sent = (bool) wp_mail( $to, $subject, $body );
		if ( ! $sent ) {
			/**
			 * Fires when a lead was stored but the notification e-mail could not be sent.
			 * No personal data is passed.
			 *
			 * @param string $reason Always 'mail_failed'.
			 */
			do_action( 'wp_knowledge_chat_lead_mail_failed', 'mail_failed' );
		}
		return $sent;
	}

	/**
	 * Returns stored leads, newest first.
	 *
	 * @return array<int,array{id:string,created_at:string,name:string,email:string,company:string,message:string,language:string,mail_status:string}>
	 */
	public static function get_leads( int $limit = self::MAX_LEADS, int $offset = 0 ): array {
		$posts = get_posts(
			array(
				'post_type'        => self::LEAD_POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => max( 1, $limit ),
				'offset'           => max( 0, $offset ),
				'orderby'          => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$leads = array();
		foreach ( $posts as $post ) {
			$meta    = fn( string $key ) => (string) get_post_meta( $post->ID, self::META_PREFIX . $key, true );
			$leads[] = array(
				'id'          => (string) $post->ID,
				'created_at'  => $post->post_date_gmt,
				'name'        => $meta( 'name' ),
				'email'       => $meta( 'email' ),
				'company'     => $meta( 'company' ),
				'message'     => $meta( 'message' ),
				'language'    => $meta( 'language' ),
				'mail_status' => $meta( 'mail_status' ),
			);
		}
		return $leads;
	}

	public static function count_leads(): int {
		// Not wp_count_posts(): its cached counts are not invalidated when a post is deleted.
		$query = new \WP_Query(
			array(
				'post_type'        => self::LEAD_POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Permanently deletes one lead. Returns false when the id is not a lead.
	 */
	public static function delete_lead( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::LEAD_POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Permanently deletes leads, optionally only those created before a GMT timestamp.
	 *
	 * @return int Number of leads deleted.
	 */
	public static function delete_leads( ?int $before = null ): int {
		$args = array(
			'post_type'        => self::LEAD_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => 200,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);
		if ( null !== $before ) {
			$args['date_query'] = array(
				array(
					'before' => gmdate( 'Y-m-d H:i:s', $before ),
					'column' => 'post_date_gmt',
				),
			);
		}
		$deleted = 0;
		do {
			$ids = get_posts( $args );
			foreach ( $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					++$deleted;
				}
			}
		} while ( count( $ids ) === 200 );
		return $deleted;
	}

	/**
	 * Applies the optional retention period (filter, default 0 = keep until deleted).
	 *
	 * @return int Number of leads deleted.
	 */
	public static function purge_expired_leads(): int {
		/**
		 * Filters how many days leads are kept. 0 (default) keeps them until an administrator deletes them.
		 *
		 * @param int $days Retention in days.
		 */
		$days = (int) apply_filters( 'wp_knowledge_chat_lead_retention_days', 0 );
		return $days > 0 ? self::delete_leads( time() - $days * DAY_IN_SECONDS ) : 0;
	}

	/**
	 * Moves 0.2.0 leads from the wkc_leads option into the post type, keeping
	 * their submission time. The option is deleted only after every lead was
	 * stored, so a failed migration is retried and never loses an inquiry.
	 */
	public static function maybe_migrate_legacy_leads(): void {
		$legacy = get_option( self::LEADS_OPTION, null );
		if ( null === $legacy ) {
			return;
		}
		if ( ! is_array( $legacy ) ) {
			delete_option( self::LEADS_OPTION );
			return;
		}
		$failed = array();
		// Oldest first, so the dashboard order is preserved.
		foreach ( array_reverse( $legacy ) as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$lead = array(
				'name'    => sanitize_text_field( (string) ( $record['name'] ?? '' ) ),
				'email'   => sanitize_email( (string) ( $record['email'] ?? '' ) ),
				'company' => sanitize_text_field( (string) ( $record['company'] ?? '' ) ),
				'message' => sanitize_textarea_field( (string) ( $record['message'] ?? '' ) ),
			);
			if ( '' === $lead['name'] && '' === $lead['email'] ) {
				continue;
			}
			$created = isset( $record['created_at'] ) && is_string( $record['created_at'] ) && strtotime( $record['created_at'] . ' UTC' )
				? gmdate( 'Y-m-d H:i:s', (int) strtotime( $record['created_at'] . ' UTC' ) )
				: gmdate( 'Y-m-d H:i:s' );
			$id = self::insert_lead( $lead, '', $created );
			if ( 0 === $id ) {
				$failed[] = $record;
			} else {
				update_post_meta( $id, self::META_PREFIX . 'mail_status', 'migrated' );
			}
		}
		if ( empty( $failed ) ) {
			delete_option( self::LEADS_OPTION );
		} else {
			update_option( self::LEADS_OPTION, array_reverse( $failed ), false );
		}
	}

	/**
	 * Clears recorded metrics.
	 */
	public static function reset_metrics(): void {
		delete_option( self::METRICS_OPTION );
	}

	/**
	 * Personal data exporter (Tools → Export Personal Data).
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		$data = array();
		foreach ( self::leads_for_email( $email ) as $lead ) {
			$data[] = array(
				'group_id'    => 'wp-knowledge-chat-leads',
				'group_label' => __( 'Consultation requests (WP Knowledge Chat)', 'wp-knowledge-chat' ),
				'item_id'     => 'wkc-lead-' . $lead['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date (UTC)', 'wp-knowledge-chat' ),
						'value' => $lead['created_at'],
					),
					array(
						'name'  => __( 'Name', 'wp-knowledge-chat' ),
						'value' => $lead['name'],
					),
					array(
						'name'  => __( 'Email', 'wp-knowledge-chat' ),
						'value' => $lead['email'],
					),
					array(
						'name'  => __( 'Company', 'wp-knowledge-chat' ),
						'value' => $lead['company'],
					),
					array(
						'name'  => __( 'Inquiry Message', 'wp-knowledge-chat' ),
						'value' => $lead['message'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal data eraser (Tools → Erase Personal Data).
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		$removed = false;
		foreach ( self::leads_for_email( $email ) as $lead ) {
			$removed = self::delete_lead( (int) $lead['id'] ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * @return array<int,array{id:string,created_at:string,name:string,email:string,company:string,message:string,language:string,mail_status:string}>
	 */
	private static function leads_for_email( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		self::maybe_migrate_legacy_leads();
		return array_values(
			array_filter(
				self::get_leads( 10000 ),
				fn( array $lead ) => strtolower( $lead['email'] ) === $email
			)
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wp-knowledge-chat'] = array(
			'exporter_friendly_name' => __( 'WP Knowledge Chat leads', 'wp-knowledge-chat' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['wp-knowledge-chat'] = array(
			'eraser_friendly_name' => __( 'WP Knowledge Chat leads', 'wp-knowledge-chat' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}
}
