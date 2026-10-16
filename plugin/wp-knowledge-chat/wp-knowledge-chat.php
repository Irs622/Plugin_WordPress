<?php
/**
 * Plugin Name:       WP Knowledge Chat
 * Description:       Inpartner AI Business Consultation Assistant: a floating chat widget that answers visitor questions from approved website content via a separate AI backend.
 * Version:           0.2.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      7.1
 * Author:            Inpartner
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        false
 * Text Domain:       wp-knowledge-chat
 * Domain Path:       /languages
 *
 * @package WPKnowledgeChat
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_KNOWLEDGE_CHAT_VERSION', '0.2.2' );
define( 'WP_KNOWLEDGE_CHAT_FILE', __FILE__ );
define( 'WP_KNOWLEDGE_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_KNOWLEDGE_CHAT_URL', plugin_dir_url( __FILE__ ) );

require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-settings.php';
require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-backend-client.php';
require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-content-sync.php';
require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-analytics.php';
require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-rest-controller.php';
require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-admin-page.php';
require_once WP_KNOWLEDGE_CHAT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'WP_Knowledge_Chat\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Knowledge_Chat\\Plugin', 'deactivate' ) );

WP_Knowledge_Chat\Plugin::instance()->boot();
