<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Admin_Page;
use WP_Knowledge_Chat\Backend_Client;
use WP_Knowledge_Chat\Content_Sync;

final class ContentSyncTest extends TestCase {

	private array $created_posts = array();

	/** @var array<int,array{documents:array,delete_ids:array,replace_all:bool,timeout:mixed}> */
	private array $index_calls = array();

	private int $backend_status = 200;

	protected function setUp(): void {
		parent::setUp();
		delete_option( Content_Sync::SYNCED_OPTION );
		delete_option( Content_Sync::PENDING_OPTION );
		wp_clear_scheduled_hook( Content_Sync::RETRY_HOOK );
		$this->index_calls    = array();
		$this->backend_status = 200;
		$this->add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== $preempt || ! str_ends_with( (string) $url, '/api/index' ) ) {
					return $preempt;
				}
				$payload             = json_decode( $args['body'], true );
				$payload['timeout']  = $args['timeout'];
				$this->index_calls[] = $payload;
				if ( 0 === $this->backend_status ) {
					return new \WP_Error( 'http_request_failed', 'down' );
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => $this->backend_status, 'message' => '' ),
					'body'     => wp_json_encode( 200 === $this->backend_status ? array( 'upserted' => count( $payload['documents'] ), 'unchanged' => 0, 'deleted' => count( $payload['delete_ids'] ), 'total' => 7, 'persisted' => 'file' ) : array( 'error' => array( 'code' => 'x', 'message' => 'Request is not authorised.' ) ) ),
				);
			},
			10,
			3
		);
	}

	protected function tearDown(): void {
		delete_option( Content_Sync::SYNCED_OPTION );
		delete_option( Content_Sync::PENDING_OPTION );
		wp_clear_scheduled_hook( Content_Sync::RETRY_HOOK );
		Content_Sync::discard_queue();
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();
		Content_Sync::discard_queue();
		delete_option( 'page_on_front' );
		parent::tearDown();
	}

	private function create_page( array $args = array() ): int {
		$defaults = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Test Advisory Service',
			'post_name'    => 'test-advisory-service',
			'post_content' => '<p>INPARTNER is a Business and Management Consulting firm providing strategic advisory, market entry, and transaction support across diverse industries.</p>',
		);
		$post_id  = wp_insert_post( array_merge( $defaults, $args ) );
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->created_posts[] = $post_id;
		return $post_id;
	}

	public function test_clean_html_strips_blocks_scripts_and_tags(): void {
		$raw = "<!-- wp:paragraph -->\n<p>Hello <script>alert('xss')</script>World!</p>\n<!-- /wp:paragraph -->"
			. "<style>.hide{display:none;}</style>\n[myshortcode]Some content[/myshortcode]";

		$cleaned = Content_Sync::clean_html( $raw );
		$this->assertStringNotContainsString( 'wp:paragraph', $cleaned );
		$this->assertStringNotContainsString( '<script>', $cleaned );
		$this->assertStringNotContainsString( 'alert', $cleaned );
		$this->assertStringNotContainsString( '<style>', $cleaned );
		$this->assertStringNotContainsString( '.hide', $cleaned );
		$this->assertStringNotContainsString( '<p>', $cleaned );
		$this->assertStringContainsString( 'Hello World!', $cleaned );
	}

	public function test_detect_category(): void {
		$home_id = $this->create_page( array( 'post_title' => 'Welcome to Inpartner', 'post_name' => 'home' ) );
		update_option( 'page_on_front', $home_id );
		$home_post = get_post( $home_id );
		$this->assertSame( 'home', Content_Sync::detect_category( $home_post ) );

		$about_id   = $this->create_page( array( 'post_title' => 'About Us & Company Profile', 'post_name' => 'about-us' ) );
		$contact_id = $this->create_page( array( 'post_title' => 'Hubungi Kami', 'post_name' => 'contact' ) );
		$faq_id     = $this->create_page( array( 'post_title' => 'Frequently Asked Questions', 'post_name' => 'faq' ) );
		$news_id    = $this->create_page( array( 'post_title' => 'Latest Industry News', 'post_name' => 'news-article' ) );
		$other_id   = $this->create_page( array( 'post_title' => 'Cross Border M&A Advisory', 'post_name' => 'cross-border-ma' ) );

		$this->assertSame( 'about', Content_Sync::detect_category( get_post( $about_id ) ) );
		$this->assertSame( 'contact', Content_Sync::detect_category( get_post( $contact_id ) ) );
		$this->assertSame( 'faq', Content_Sync::detect_category( get_post( $faq_id ) ) );
		$this->assertSame( 'article', Content_Sync::detect_category( get_post( $news_id ) ) );
		$this->assertSame( 'service', Content_Sync::detect_category( get_post( $other_id ) ) );
	}

	public function test_extract_document_rejects_drafts_and_short_content(): void {
		$draft_id = $this->create_page( array( 'post_status' => 'draft' ) );
		$this->assertNull( Content_Sync::extract_document( get_post( $draft_id ) ) );

		$short_id = $this->create_page( array( 'post_content' => '<p>Too short</p>' ) );
		$this->assertNull( Content_Sync::extract_document( get_post( $short_id ) ) );

		$valid_id = $this->create_page();
		$doc      = Content_Sync::extract_document( get_post( $valid_id ) );
		$this->assertIsArray( $doc );
		$this->assertStringStartsWith( 'page-' . $valid_id . '-', $doc['id'] );
		$this->assertSame( 'Test Advisory Service', $doc['title'] );
		$this->assertSame( 'service', $doc['category'] );
		$this->assertSame( 'publish', $doc['status'] );
		$this->assertNotEmpty( $doc['url'] );
		$this->assertNotEmpty( $doc['updated_at'] );
	}

	public function test_get_syncable_pages_lists_pages(): void {
		$page_id = $this->create_page();
		$pages   = Content_Sync::get_syncable_pages();

		$found = false;
		foreach ( $pages as $p ) {
			if ( $p['id'] === $page_id ) {
				$found = true;
				$this->assertTrue( $p['eligible'] );
				$this->assertGreaterThan( 40, $p['char_count'] );
				$this->assertSame( 'service', $p['category'] );
			}
		}
		$this->assertTrue( $found, 'Created page must appear in syncable pages list.' );
	}

	public function test_sync_admin_screen_permissions_and_nonce(): void {
		$admin_page = new Admin_Page();

		wp_set_current_user( $this->user_id( 'editor' ) );
		try {
			$admin_page->render_sync_page();
			$this->fail( 'Non-admin must not access sync page' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}

		try {
			$admin_page->handle_sync();
			$this->fail( 'Non-admin must not trigger sync' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}

		wp_set_current_user( $this->user_id( 'admin' ) );
		$_REQUEST[ Admin_Page::SYNC_NONCE ] = 'invalid-nonce';
		try {
			$admin_page->handle_sync();
			$this->fail( 'Invalid nonce must be rejected' );
		} catch ( WpDieException $e ) {
			$this->assertSame( 403, $e->status );
		}
	}

	public function test_sync_pages_with_mocked_backend(): void {
		$page_id = $this->create_page();

		$this->add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				if ( str_ends_with( $url, '/api/index' ) ) {
					return array(
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'body'     => json_encode( array(
							'status'    => 'ok',
							'upserted'  => 1,
							'unchanged' => 0,
							'total'     => 1,
						) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		// If WP_KNOWLEDGE_CHAT_INDEX_API_TOKEN is defined in wp-config, sync_pages can run
		if ( ! Backend_Client::is_index_configured() ) {
			// When not configured, returns clean error
			$res = Content_Sync::sync_pages( array( $page_id ) );
			$this->assertFalse( $res['success'] );
			$this->assertStringContainsString( 'Token is not configured', $res['message'] );
		}
	}

	private function approve( int $page_id ): array {
		$result = Content_Sync::sync_pages( array( $page_id ) );
		$this->assertTrue( $result['success'], $result['message'] );
		$this->index_calls = array();
		return $result;
	}

	public function test_password_protected_pages_are_never_indexed(): void {
		$page_id = $this->create_page( array( 'post_password' => 's3cret', 'post_content' => '<p>Confidential board minutes: the acquisition target and its valuation are listed here in detail.</p>' ) );
		$this->assertNull( Content_Sync::extract_document( get_post( $page_id ) ) );
		$listed = array_values( array_filter( Content_Sync::get_syncable_pages(), fn( $p ) => $p['id'] === $page_id ) );
		$this->assertFalse( $listed[0]['eligible'] );
		$this->assertTrue( $listed[0]['protected'] );
		$this->assertSame( 0, $listed[0]['char_count'], 'protected content is not even rendered' );
		$this->assertFalse( Content_Sync::sync_pages( array( $page_id ) )['success'] );
		$this->assertSame( array(), $this->index_calls );
	}

	public function test_only_pages_can_be_synced(): void {
		$post_id = $this->create_page( array( 'post_type' => 'post', 'post_name' => 'market-commentary' ) );
		$this->assertNull( Content_Sync::extract_document( get_post( $post_id ) ) );
		$this->assertFalse( Content_Sync::sync_pages( array( $post_id ) )['success'] );
	}

	public function test_manual_sync_approves_pages_and_keeps_the_chosen_category(): void {
		$page_id = $this->create_page();
		$result  = Content_Sync::sync_pages( array( $page_id ), array( $page_id => 'faq' ) );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->index_calls );
		$this->assertSame( 30, $this->index_calls[0]['timeout'] );
		$this->assertArrayHasKey( $page_id, Content_Sync::synced_pages() );
		$this->assertSame( 'faq', get_post_meta( $page_id, '_wkc_category', true ) );

		// A later automatic update keeps "faq" instead of re-detecting "service".
		$this->index_calls = array();
		wp_update_post( array( 'ID' => $page_id, 'post_content' => '<p>Updated: INPARTNER provides strategic advisory and market entry support for companies across Southeast Asia.</p>' ) );
		Content_Sync::flush_queue();
		$this->assertSame( 'faq', $this->index_calls[0]['documents'][0]['category'] );
	}

	public function test_failed_manual_sync_does_not_approve(): void {
		$this->backend_status = 401;
		$page_id              = $this->create_page();
		$result               = Content_Sync::sync_pages( array( $page_id ) );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'HTTP 401', $result['message'] );
		$this->assertSame( array(), Content_Sync::synced_pages() );
	}

	public function test_publishing_a_new_page_does_not_add_it_to_the_knowledge_base(): void {
		$this->create_page( array( 'post_title' => 'Thank You', 'post_name' => 'thank-you' ) );
		Content_Sync::flush_queue();
		$this->assertSame( array(), $this->index_calls, '0.2.0 pushed every published page' );
	}

	public function test_edits_to_an_approved_page_are_sent_once_with_a_short_timeout(): void {
		$page_id = $this->create_page();
		$this->approve( $page_id );
		wp_update_post( array( 'ID' => $page_id, 'post_title' => 'Test Advisory Service Updated' ) );
		Content_Sync::on_post_status_transition( 'publish', 'publish', get_post( $page_id ) ); // duplicate event
		Content_Sync::flush_queue();
		$this->assertCount( 1, $this->index_calls );
		$this->assertSame( Content_Sync::AUTO_SYNC_TIMEOUT, $this->index_calls[0]['timeout'] );
		$this->assertSame( 'Test Advisory Service Updated', $this->index_calls[0]['documents'][0]['title'] );
		$this->assertFalse( $this->index_calls[0]['replace_all'] );
	}

	public function test_slug_change_replaces_the_old_document_in_one_request(): void {
		$page_id = $this->create_page();
		$this->approve( $page_id );
		wp_update_post( array( 'ID' => $page_id, 'post_name' => 'renamed-service' ) );
		Content_Sync::flush_queue();
		$this->assertCount( 1, $this->index_calls );
		$this->assertSame( 'page-' . $page_id . '-renamed-service', $this->index_calls[0]['documents'][0]['id'] );
		$this->assertSame( array( 'page-' . $page_id . '-test-advisory-service' ), $this->index_calls[0]['delete_ids'] );
		$this->assertSame( 'page-' . $page_id . '-renamed-service', Content_Sync::synced_pages()[ $page_id ]['doc_id'] );
	}

	public function test_unpublishing_removes_the_page_and_republishing_needs_new_approval(): void {
		$page_id = $this->create_page();
		$this->approve( $page_id );
		wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ) );
		Content_Sync::flush_queue();
		$this->assertSame( array(), $this->index_calls[0]['documents'] );
		$this->assertContains( 'page-' . $page_id . '-test-advisory-service', $this->index_calls[0]['delete_ids'] );
		$this->assertArrayNotHasKey( $page_id, Content_Sync::synced_pages() );

		$this->index_calls = array();
		wp_update_post( array( 'ID' => $page_id, 'post_status' => 'publish' ) );
		Content_Sync::flush_queue();
		$this->assertSame( array(), $this->index_calls );
	}

	public function test_password_protecting_or_trashing_removes_the_page(): void {
		$protected = $this->create_page( array( 'post_name' => 'soon-protected' ) );
		$this->approve( $protected );
		wp_update_post( array( 'ID' => $protected, 'post_password' => 'secret' ) );
		Content_Sync::flush_queue();
		$this->assertSame( array( 'page-' . $protected . '-soon-protected' ), $this->index_calls[0]['delete_ids'] );

		// A page pushed by 0.2.0's automatic sync was never recorded; trashing it still removes it.
		$this->index_calls = array();
		$legacy            = $this->create_page( array( 'post_name' => 'legacy-page' ) );
		wp_trash_post( $legacy );
		Content_Sync::flush_queue();
		$this->assertSame( array( 'page-' . $legacy . '-legacy-page' ), $this->index_calls[0]['delete_ids'] );
	}

	public function test_permanent_deletion_of_a_published_page_removes_it_immediately(): void {
		$page_id = $this->create_page( array( 'post_name' => 'gone' ) );
		$this->approve( $page_id );
		wp_delete_post( $page_id, true );
		$this->assertCount( 1, $this->index_calls );
		$this->assertSame( array( 'page-' . $page_id . '-gone' ), $this->index_calls[0]['delete_ids'] );
		$this->assertArrayNotHasKey( $page_id, Content_Sync::synced_pages() );
	}

	public function test_failed_removal_is_recorded_and_retried(): void {
		$page_id = $this->create_page();
		$this->approve( $page_id );
		$this->backend_status = 0;
		$failures             = array();
		$this->add_filter(
			'wp_knowledge_chat_sync_failure',
			function ( $id, $reason ) use ( &$failures ) {
				$failures[] = array( $id, $reason );
			},
			10,
			2
		);
		wp_update_post( array( 'ID' => $page_id, 'post_status' => 'private' ) );
		Content_Sync::flush_queue();
		$this->assertSame( array( array( $page_id, 'unreachable' ) ), $failures );
		$this->assertSame( 1, Content_Sync::pending()[ $page_id ]['attempts'] );
		$this->assertNotFalse( wp_next_scheduled( Content_Sync::RETRY_HOOK ) );
		$this->assertArrayHasKey( $page_id, Content_Sync::synced_pages(), 'still listed as in the knowledge base until removal succeeds' );

		$this->backend_status = 200;
		$this->index_calls    = array();
		$this->assertSame( 0, Content_Sync::retry_pending() );
		$this->assertContains( 'page-' . $page_id . '-test-advisory-service', $this->index_calls[0]['delete_ids'] );
		$this->assertSame( array(), Content_Sync::pending() );
		$this->assertArrayNotHasKey( $page_id, Content_Sync::synced_pages() );
	}

	public function test_retries_stop_being_scheduled_after_max_attempts(): void {
		$page_id = $this->create_page();
		$this->approve( $page_id );
		$this->backend_status = 500;
		wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ) );
		Content_Sync::flush_queue();
		for ( $i = 1; $i < Content_Sync::MAX_ATTEMPTS; $i++ ) {
			wp_clear_scheduled_hook( Content_Sync::RETRY_HOOK );
			Content_Sync::retry_pending();
		}
		$this->assertSame( Content_Sync::MAX_ATTEMPTS, Content_Sync::pending()[ $page_id ]['attempts'] );
		$this->assertFalse( wp_next_scheduled( Content_Sync::RETRY_HOOK ) );
		$this->assertSame( 'http_500', Content_Sync::pending()[ $page_id ]['error'] );
	}

	public function test_replace_all_resets_the_approved_set(): void {
		$a = $this->create_page( array( 'post_name' => 'page-a' ) );
		$b = $this->create_page( array( 'post_name' => 'page-b' ) );
		$this->approve( $a );
		Content_Sync::sync_pages( array( $b ), array(), true );
		$this->assertSame( array( $b ), array_keys( Content_Sync::synced_pages() ) );
		$this->assertTrue( end( $this->index_calls )['replace_all'] );
	}

	public function test_content_is_rendered_as_an_anonymous_visitor(): void {
		add_shortcode( 'wkc_members_only', fn() => is_user_logged_in() ? 'MEMBERS-ONLY SECRET' : '' );
		try {
			wp_set_current_user( $this->user_id( 'admin' ) );
			$page_id = $this->create_page( array( 'post_content' => '<p>INPARTNER is a Business and Management Consulting firm providing strategic advisory services.</p>[wkc_members_only]' ) );
			$doc     = Content_Sync::extract_document( get_post( $page_id ) );
			$this->assertStringNotContainsString( 'MEMBERS-ONLY SECRET', $doc['text'] );
			$this->assertSame( $this->user_id( 'admin' ), get_current_user_id(), 'the editor is restored afterwards' );
		} finally {
			remove_shortcode( 'wkc_members_only' );
		}
	}

	public function test_page_builder_boilerplate_lines_are_dropped(): void {
		$text = Content_Sync::clean_html( "<p>Market Entry Advisory helps companies enter Indonesia.</p>\n<p>Read More</p>\n<p>Get Started</p>" );
		$this->assertStringContainsString( 'Market Entry Advisory', $text );
		$this->assertStringNotContainsString( 'Read More', $text );
		$this->assertStringNotContainsString( 'Get Started', $text );
	}

	public function test_backend_warning_when_the_update_is_not_durable(): void {
		$this->add_filter(
			'pre_http_request',
			fn( $preempt, $args, $url ) => str_ends_with( (string) $url, '/api/index' )
				? array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'upserted' => 1, 'unchanged' => 0, 'total' => 1, 'persisted' => 'ephemeral' ) ) )
				: $preempt,
			5,
			3
		);
		$result = Content_Sync::sync_pages( array( $this->create_page() ) );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'lost when it restarts', $result['message'] );
	}
}
