<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Admin_Page;
use WP_Knowledge_Chat\Analytics;
use WP_Knowledge_Chat\Rest_Controller;
use WP_Knowledge_Chat\Settings;

final class AnalyticsAndLeadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Analytics::METRICS_OPTION );
		delete_option( Analytics::LEADS_OPTION );
		Analytics::delete_leads();
		$this->clear_lead_limits();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	protected function tearDown(): void {
		delete_option( Analytics::METRICS_OPTION );
		delete_option( Analytics::LEADS_OPTION );
		Analytics::delete_leads();
		$this->clear_lead_limits();
		parent::tearDown();
	}

	private function clear_lead_limits(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wkc\\_lead%' OR option_name LIKE '\\_transient\\_timeout\\_wkc\\_lead%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
	}

	private function lead_request( array $params, string $origin = '' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/' . Rest_Controller::NAMESPACE . '/lead' );
		$request->set_header( 'origin', '' !== $origin ? $origin : home_url() );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function valid_lead( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'    => 'Jane Smith',
				'email'   => 'jane@company.com',
				'company' => 'Global Ventures',
				'message' => 'Need distributor sourcing ' . wp_rand(),
			),
			$overrides
		);
	}

	public function test_record_query_increments_metrics_and_stores_recent_snippets(): void {
		Analytics::record_query( 'What services do you offer?', 'en', true, 'answered' );
		Analytics::record_query( 'Berapa biaya konsultasi?', 'id', false, 'fallback' );

		$metrics = Analytics::get_metrics();
		$this->assertSame( 2, $metrics['total_queries'] );
		$this->assertSame( 1, $metrics['grounded_queries'] );
		$this->assertSame( 1, $metrics['fallback_queries'] );
		$this->assertSame( 1, $metrics['languages']['en'] );
		$this->assertSame( 1, $metrics['languages']['id'] );
		$this->assertCount( 2, $metrics['recent_queries'] );
		$this->assertSame( 'Berapa biaya konsultasi?', $metrics['recent_queries'][0]['question'] );
		$this->assertFalse( $metrics['recent_queries'][0]['grounded'] );
		$this->assertTrue( $metrics['recent_queries'][1]['grounded'] );
	}

	public function test_recent_question_snippets_are_redacted_of_contact_details(): void {
		Analytics::record_query( 'My email is jane.doe@corp.com, phone +62 812 3456 7890, see https://corp.example/x, ID 1234567890', 'en', false );
		$snippet = Analytics::get_metrics()['recent_queries'][0]['question'];
		$this->assertStringNotContainsString( 'jane.doe@corp.com', $snippet );
		$this->assertStringNotContainsString( '3456', $snippet );
		$this->assertStringNotContainsString( 'corp.example', $snippet );
		$this->assertStringContainsString( '[email]', $snippet );
		$this->assertStringContainsString( '[phone]', $snippet );
		$this->assertStringContainsString( '[link]', $snippet );
	}

	public function test_snippets_can_be_turned_off_keeping_counts(): void {
		$this->add_filter( 'wp_knowledge_chat_store_query_snippets', '__return_false' );
		Analytics::record_query( 'What services do you offer?', 'en', true );
		$metrics = Analytics::get_metrics();
		$this->assertSame( 1, $metrics['total_queries'] );
		$this->assertSame( array(), $metrics['recent_queries'] );
	}

	public function test_save_lead_validates_input_and_persists(): void {
		$this->assertFalse( Analytics::save_lead( array( 'name' => '', 'email' => 'bad-email' ) ) );
		$this->assertSame( array(), Analytics::get_leads() );

		$this->assertTrue(
			Analytics::save_lead(
				array(
					'name'    => 'John Doe',
					'email'   => 'john@example.com',
					'company' => 'Acme Corp',
					'message' => 'Interested in market entry to Indonesia.',
				)
			)
		);

		$leads = Analytics::get_leads();
		$this->assertCount( 1, $leads );
		$this->assertSame( 'John Doe', $leads[0]['name'] );
		$this->assertSame( 'john@example.com', $leads[0]['email'] );
		$this->assertSame( 'Acme Corp', $leads[0]['company'] );
		$this->assertSame( 'Interested in market entry to Indonesia.', $leads[0]['message'] );
		$this->assertSame( 'sent', $leads[0]['mail_status'] );
	}

	public function test_oversized_fields_are_rejected_not_truncated(): void {
		$this->assertFalse( Analytics::save_lead( $this->valid_lead( array( 'name' => str_repeat( 'N', 101 ) ) ) ) );
		$this->assertFalse( Analytics::save_lead( $this->valid_lead( array( 'message' => str_repeat( 'M', 2001 ) ) ) ) );
		$this->assertFalse( Analytics::save_lead( $this->valid_lead( array( 'email' => array( 'x@example.com' ) ) ) ) );
		$this->assertSame( 0, Analytics::count_leads() );
		$this->assertTrue( Analytics::save_lead( $this->valid_lead( array( 'message' => str_repeat( 'M', 2000 ) ) ) ) );
	}

	public function test_a_flood_of_submissions_never_evicts_earlier_leads(): void {
		Analytics::save_lead( $this->valid_lead( array( 'name' => 'Real Prospect', 'email' => 'real@example.com' ) ) );
		for ( $i = 0; $i < Analytics::MAX_LEADS + 5; $i++ ) {
			Analytics::save_lead( $this->valid_lead( array( 'name' => "Bot $i", 'email' => "bot$i@spam.test" ) ) );
		}
		$this->assertSame( Analytics::MAX_LEADS + 6, Analytics::count_leads() );
		$emails = array_column( Analytics::get_leads( 1000 ), 'email' );
		$this->assertContains( 'real@example.com', $emails, 'the 0.2.0 option kept only the newest 100 leads' );
	}

	public function test_identical_resubmission_is_stored_once(): void {
		$lead = $this->valid_lead( array( 'message' => 'Same text' ) );
		$this->assertTrue( Analytics::save_lead( $lead ) );
		$this->assertTrue( Analytics::save_lead( $lead ) );
		$this->assertSame( 1, Analytics::count_leads() );
		$this->assertTrue( Analytics::save_lead( array_merge( $lead, array( 'message' => 'Different text' ) ) ) );
		$this->assertSame( 2, Analytics::count_leads() );
	}

	public function test_mail_failure_keeps_the_lead_and_records_the_failure(): void {
		$this->add_filter( 'pre_wp_mail', '__return_false' );
		$reasons = array();
		$this->add_filter(
			'wp_knowledge_chat_lead_mail_failed',
			function ( $reason ) use ( &$reasons ) {
				$reasons[] = $reason;
			}
		);
		$this->assertTrue( Analytics::save_lead( $this->valid_lead() ) );
		$leads = Analytics::get_leads();
		$this->assertCount( 1, $leads );
		$this->assertSame( 'failed', $leads[0]['mail_status'] );
		$this->assertSame( array( 'mail_failed' ), $reasons, 'the action carries no personal data' );
	}

	public function test_legacy_option_leads_are_migrated_without_loss(): void {
		update_option(
			Analytics::LEADS_OPTION,
			array(
				array(
					'id'         => 'b',
					'created_at' => '2026-10-15 08:00:00',
					'name'       => 'Newer',
					'email'      => 'newer@example.com',
					'company'    => '',
					'message'    => '',
				),
				array(
					'id'         => 'a',
					'created_at' => '2026-10-14 08:00:00',
					'name'       => 'Older',
					'email'      => 'older@example.com',
					'company'    => 'Old Co',
					'message'    => 'Hi',
				),
			),
			false
		);
		Analytics::maybe_migrate_legacy_leads();
		$this->assertFalse( get_option( Analytics::LEADS_OPTION ) );
		$leads = Analytics::get_leads();
		$this->assertSame( array( 'Newer', 'Older' ), array_column( $leads, 'name' ) );
		$this->assertSame( '2026-10-14 08:00:00', $leads[1]['created_at'] );
		$this->assertSame( 'migrated', $leads[1]['mail_status'] );
	}

	public function test_retention_filter_purges_only_old_leads(): void {
		Analytics::save_lead( $this->valid_lead() );
		$this->assertSame( 0, Analytics::purge_expired_leads(), 'default keeps leads until deleted' );
		$old = Analytics::get_leads()[0]['id'];
		wp_update_post(
			array(
				'ID'            => (int) $old,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			)
		);
		Analytics::save_lead( $this->valid_lead( array( 'email' => 'new@example.com' ) ) );
		$this->add_filter( 'wp_knowledge_chat_lead_retention_days', fn() => 30 );
		$this->assertSame( 1, Analytics::purge_expired_leads() );
		$this->assertSame( array( 'new@example.com' ), array_column( Analytics::get_leads(), 'email' ) );
	}

	public function test_lead_rest_endpoint(): void {
		$response = ( new Rest_Controller() )->handle_lead( $this->lead_request( $this->valid_lead() ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'lead_sent', $data['code'] );
		$this->assertStringNotContainsString( 'jane@company.com', wp_json_encode( $data ), 'responses never echo submitted data' );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		$leads = Analytics::get_leads();
		$this->assertCount( 1, $leads );
		$this->assertSame( 'Jane Smith', $leads[0]['name'] );
	}

	public function test_lead_endpoint_reports_invalid_input_in_the_visitor_language(): void {
		$response = ( new Rest_Controller() )->handle_lead( $this->lead_request( $this->valid_lead( array( 'email' => 'nope', 'language' => 'ko' ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertStringContainsString( '이메일', $response->get_data()['message'] );
		$this->assertSame( 0, Analytics::count_leads() );
	}

	public function test_lead_route_schema_rejects_oversized_payloads(): void {
		$request = $this->lead_request( $this->valid_lead( array( 'message' => str_repeat( 'x', 5000 ) ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, Analytics::count_leads() );
	}

	public function test_honeypot_submissions_look_successful_but_are_not_stored(): void {
		$response = ( new Rest_Controller() )->handle_lead( $this->lead_request( $this->valid_lead( array( 'website' => 'http://spam.test' ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, Analytics::count_leads() );
	}

	public function test_lead_endpoint_rate_limits_per_visitor_and_site_wide(): void {
		$controller = new Rest_Controller();
		for ( $i = 0; $i < Rest_Controller::LEAD_LIMIT_PER_VISITOR; $i++ ) {
			$this->assertSame( 200, $controller->handle_lead( $this->lead_request( $this->valid_lead() ) )->get_status() );
		}
		$blocked = $controller->handle_lead( $this->lead_request( $this->valid_lead() ) );
		$this->assertSame( 429, $blocked->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $blocked->get_headers() );

		// Chat traffic is limited separately: the lead limit does not consume the chat bucket and vice versa.
		$this->clear_lead_limits();
		$this->add_filter( 'wp_knowledge_chat_lead_limit_per_hour', fn() => 2 );
		foreach ( array( '198.51.100.1', '198.51.100.2' ) as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			$this->assertSame( 200, $controller->handle_lead( $this->lead_request( $this->valid_lead() ) )->get_status() );
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
		$this->assertSame( 429, $controller->handle_lead( $this->lead_request( $this->valid_lead() ) )->get_status(), 'site-wide cap bounds notification e-mails' );
	}

	public function test_lead_endpoint_rejects_foreign_origins_and_disabled_widget(): void {
		$controller = new Rest_Controller();
		$foreign    = $controller->handle_lead( $this->lead_request( $this->valid_lead(), 'https://evil.example' ) );
		$this->assertInstanceOf( \WP_Error::class, $foreign );
		$this->assertSame( 403, $foreign->get_error_data()['status'] );

		update_option( Settings::OPTION, array_merge( Settings::get(), array( 'enabled' => false ) ) );
		$disabled = $controller->handle_lead( $this->lead_request( $this->valid_lead() ) );
		$this->assertInstanceOf( \WP_Error::class, $disabled );
		$this->assertSame( 503, $disabled->get_error_data()['status'] );
		$this->assertSame( 0, Analytics::count_leads() );
	}

	public function test_leads_are_not_reachable_through_public_or_rest_queries(): void {
		Analytics::save_lead( $this->valid_lead() );
		$type = get_post_type_object( Analytics::LEAD_POST_TYPE );
		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->can_export );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/' . Analytics::LEAD_POST_TYPE ) )->get_status() );
		// Even an editor cannot read or delete leads through core capabilities.
		$lead_id = (int) Analytics::get_leads()[0]['id'];
		wp_set_current_user( $this->user_id( 'editor' ) );
		$this->assertFalse( current_user_can( 'read_post', $lead_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $lead_id ) );
	}

	public function test_admin_lead_actions_require_capability_and_nonce(): void {
		Analytics::save_lead( $this->valid_lead() );
		$lead_id = (int) Analytics::get_leads()[0]['id'];
		$page    = new Admin_Page();
		$_POST   = array(
			'operation' => 'delete',
			'lead_id'   => (string) $lead_id,
		);

		wp_set_current_user( $this->user_id( 'editor' ) );
		try {
			$page->handle_leads_action();
			$this->fail( 'editors must not delete leads' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}

		wp_set_current_user( $this->user_id( 'admin' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::LEADS_ACTION . '_delete_all_0' );
		try {
			$page->handle_leads_action();
			$this->fail( 'a nonce for another operation must be rejected' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}
		$this->assertSame( 1, Analytics::count_leads() );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::LEADS_ACTION . '_delete_' . $lead_id );
		$this->add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new RedirectException( $location );
			}
		);
		try {
			$page->handle_leads_action();
			$this->fail( 'expected redirect' );
		} catch ( RedirectException $e ) {
			$this->assertStringContainsString( Admin_Page::LEADS_SLUG, $e->getMessage() );
		}
		$this->assertSame( 0, Analytics::count_leads() );
		$_POST    = array();
		$_REQUEST = array();
	}

	public function test_leads_page_escapes_stored_values_and_is_admin_only(): void {
		Analytics::save_lead( $this->valid_lead( array( 'name' => '<script>alert(1)</script>Eve', 'company' => '"><img src=x onerror=alert(1)>' ) ) );
		Analytics::record_query( '<img src=x onerror=alert(2)> services?', 'en', false );
		$page = new Admin_Page();

		wp_set_current_user( $this->user_id( 'editor' ) );
		try {
			$page->render_leads_page();
			$this->fail( 'editors must not see leads' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}

		wp_set_current_user( $this->user_id( 'admin' ) );
		ob_start();
		$page->render_leads_page();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'Eve', $html );
	}

	public function test_csv_export_neutralises_formulas(): void {
		Analytics::save_lead( $this->valid_lead( array( 'company' => '=HYPERLINK("http://evil.test")', 'message' => '+cmd' ) ) );
		$csv = Admin_Page::leads_csv();
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( "'+cmd", $csv );
		$this->assertStringStartsWith( 'created_at_utc,name,email', $csv );
	}

	public function test_privacy_exporter_and_eraser_cover_leads(): void {
		Analytics::save_lead( $this->valid_lead( array( 'email' => 'subject@example.com' ) ) );
		Analytics::save_lead( $this->valid_lead( array( 'email' => 'other@example.com' ) ) );

		$this->assertArrayHasKey( 'wp-knowledge-chat', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'wp-knowledge-chat', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		$export = Analytics::export_personal_data( 'Subject@Example.com' );
		$this->assertCount( 1, $export['data'] );

		$erase = Analytics::erase_personal_data( 'subject@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( array( 'other@example.com' ), array_column( Analytics::get_leads(), 'email' ) );
	}
}
