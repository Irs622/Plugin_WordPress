<?php

namespace WP_Knowledge_Chat\Tests;

use WP_Knowledge_Chat\Settings;

class WpDieException extends \RuntimeException {
	public function __construct( string $message, public readonly int $status ) {
		parent::__construct( $message );
	}
}

class RedirectException extends \RuntimeException {}

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** @var array<int,array{0:string,1:callable,2:int}> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		delete_option( Settings::OPTION );
		Settings::install();
		Settings::reset_reported_errors();
		$GLOBALS['wp_settings_errors'] = array();
		wp_set_current_user( 0 );
		$this->add_filter( 'wp_die_handler', fn() => function ( $message, $title = '', $args = array() ) {
			$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
			throw new WpDieException( is_string( $message ) ? $message : 'wp_die', $status );
		} );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		$this->filters = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	protected function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	protected function user_id( string $login ): int {
		$user = get_user_by( 'login', $login );
		$this->assertInstanceOf( \WP_User::class, $user, "Test user {$login} missing; re-run tests/bin/setup-wp.sh" );
		return $user->ID;
	}

	protected function settings_error_codes(): array {
		return array_column( get_settings_errors( Settings::OPTION ), 'code' );
	}
}
