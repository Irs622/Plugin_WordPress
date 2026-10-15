<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Analytics;
use WP_Knowledge_Chat\Rest_Controller;

final class AnalyticsAndLeadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Analytics::METRICS_OPTION );
		delete_option( Analytics::LEADS_OPTION );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	protected function tearDown(): void {
		delete_option( Analytics::METRICS_OPTION );
		delete_option( Analytics::LEADS_OPTION );
		parent::tearDown();
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

	public function test_save_lead_validates_input_and_persists(): void {
		$invalid = Analytics::save_lead( array( 'name' => '', 'email' => 'bad-email' ) );
		$this->assertFalse( $invalid );
		$this->assertEmpty( Analytics::get_leads() );

		$valid = Analytics::save_lead(
			array(
				'name'    => 'John Doe',
				'email'   => 'john@example.com',
				'company' => 'Acme Corp',
				'message' => 'Interested in market entry to Indonesia.',
			)
		);
		$this->assertTrue( $valid );

		$leads = Analytics::get_leads();
		$this->assertCount( 1, $leads );
		$this->assertSame( 'John Doe', $leads[0]['name'] );
		$this->assertSame( 'john@example.com', $leads[0]['email'] );
		$this->assertSame( 'Acme Corp', $leads[0]['company'] );
		$this->assertSame( 'Interested in market entry to Indonesia.', $leads[0]['message'] );
	}

	public function test_lead_rest_endpoint(): void {
		$controller = new Rest_Controller();

		$request = new \WP_REST_Request( 'POST', '/' . Rest_Controller::NAMESPACE . '/lead' );
		$request->set_header( 'origin', home_url() );
		$request->set_param( 'name', 'Jane Smith' );
		$request->set_param( 'email', 'jane@company.com' );
		$request->set_param( 'company', 'Global Ventures' );
		$request->set_param( 'message', 'Need distributor sourcing.' );

		$response = $controller->handle_lead( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$leads = Analytics::get_leads();
		$this->assertCount( 1, $leads );
		$this->assertSame( 'Jane Smith', $leads[0]['name'] );
	}
}

