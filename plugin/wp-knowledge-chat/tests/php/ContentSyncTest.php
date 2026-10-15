<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Admin_Page;
use WP_Knowledge_Chat\Backend_Client;
use WP_Knowledge_Chat\Content_Sync;

final class ContentSyncTest extends TestCase {

	private array $created_posts = array();

	protected function tearDown(): void {
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();
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
}
