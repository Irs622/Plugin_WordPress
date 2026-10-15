<?php
/**
 * Analytics and lead storage for WP Knowledge Chat.
 *
 * Records aggregate conversation metrics (non-PII) and optional visitor leads.
 *
 * @package WPKnowledgeChat
 */

namespace WP_Knowledge_Chat;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	public const METRICS_OPTION = 'wkc_metrics';
	public const LEADS_OPTION   = 'wkc_leads';
	public const MAX_LEADS      = 100;
	public const MAX_RECENT     = 25;

	/**
	 * Records a chat query event for aggregate metrics and privacy-safe recent queries.
	 */
	public static function record_query( string $question, string $language, bool $grounded, string $outcome = 'answered' ): void {
		$metrics = get_option( self::METRICS_OPTION, array() );
		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}

		$total          = (int) ( $metrics['total_queries'] ?? 0 ) + 1;
		$grounded_count = (int) ( $metrics['grounded_queries'] ?? 0 ) + ( $grounded ? 1 : 0 );
		$fallback_count = (int) ( $metrics['fallback_queries'] ?? 0 ) + ( $grounded ? 0 : 1 );

		$languages = isset( $metrics['languages'] ) && is_array( $metrics['languages'] ) ? $metrics['languages'] : array();
		$languages[ $language ] = (int) ( $languages[ $language ] ?? 0 ) + 1;

		$recent = isset( $metrics['recent_queries'] ) && is_array( $metrics['recent_queries'] ) ? $metrics['recent_queries'] : array();
		array_unshift(
			$recent,
			array(
				'time'     => gmdate( 'Y-m-d H:i:s' ),
				'question' => mb_substr( wp_strip_all_tags( $question ), 0, 120 ),
				'language' => $language,
				'grounded' => $grounded,
				'outcome'  => $outcome,
			)
		);
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
	 * Saves a captured lead voluntarily submitted by a visitor.
	 *
	 * @param array{name:string,email:string,company?:string,message?:string} $lead_data
	 */
	public static function save_lead( array $lead_data ): bool {
		$name  = sanitize_text_field( $lead_data['name'] ?? '' );
		$email = sanitize_email( $lead_data['email'] ?? '' );
		if ( '' === $name || ! is_email( $email ) ) {
			return false;
		}

		$leads = get_option( self::LEADS_OPTION, array() );
		if ( ! is_array( $leads ) ) {
			$leads = array();
		}

		$record = array(
			'id'         => bin2hex( random_bytes( 8 ) ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'name'       => $name,
			'email'      => $email,
			'company'    => sanitize_text_field( $lead_data['company'] ?? '' ),
			'message'    => sanitize_textarea_field( $lead_data['message'] ?? '' ),
		);

		array_unshift( $leads, $record );
		$leads = array_slice( $leads, 0, self::MAX_LEADS );
		$saved = update_option( self::LEADS_OPTION, $leads, false );

		$admin_email = get_option( 'admin_email' );
		if ( is_email( $admin_email ) ) {
			$subject = sprintf( '[Inpartner AI Lead] New inquiry from %s', $record['name'] );
			$body    = sprintf(
				"New consultation inquiry captured by WP Knowledge Chat:\n\nName: %s\nEmail: %s\nCompany: %s\nMessage:\n%s\n\nSubmitted at: %s UTC",
				$record['name'],
				$record['email'],
				$record['company'] ?: '-',
				$record['message'] ?: '-',
				$record['created_at']
			);
			wp_mail( $admin_email, $subject, $body );
		}

		return $saved;
	}

	/**
	 * Returns stored leads.
	 *
	 * @return array<int,array{id:string,created_at:string,name:string,email:string,company:string,message:string}>
	 */
	public static function get_leads(): array {
		$leads = get_option( self::LEADS_OPTION, array() );
		return is_array( $leads ) ? $leads : array();
	}

	/**
	 * Clears recorded metrics.
	 */
	public static function reset_metrics(): void {
		delete_option( self::METRICS_OPTION );
	}
}

