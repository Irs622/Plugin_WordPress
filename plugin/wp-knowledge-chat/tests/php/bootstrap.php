<?php
/**
 * Boots the disposable local WordPress created by tests/bin/setup-wp.sh so
 * tests run against real WordPress APIs (options, sanitizers, REST server,
 * capabilities, nonces). Never point WKC_WP_DIR at a production install.
 */

$wp_dir = getenv( 'WKC_WP_DIR' ) ?: dirname( __DIR__, 4 ) . '/.wp-test/wordpress';
if ( ! is_file( $wp_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress test install not found at {$wp_dir}. Run tests/bin/setup-wp.sh first.\n" );
	exit( 1 );
}
if ( ! is_file( $wp_dir . '/wp-content/plugins/wp-knowledge-chat/wp-knowledge-chat.php' ) ) {
	fwrite( STDERR, "Plugin is not linked into the test install.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_SERVER['HTTP_HOST']       = '127.0.0.1:8899';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'WP_USE_THEMES', false );
require_once $wp_dir . '/wp-load.php';

if ( ! class_exists( 'WP_Knowledge_Chat\\Plugin' ) ) {
	fwrite( STDERR, "WP Knowledge Chat is not active in the test install. Run: wp plugin activate wp-knowledge-chat\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once __DIR__ . '/TestCase.php';
