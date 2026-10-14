=== WP Knowledge Chat ===
Contributors: inpartner
Tags: chatbot, faq, ai assistant
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inpartner AI Business Consultation Assistant: a floating chat widget that answers visitor questions from approved public website content.

== Description ==

WP Knowledge Chat adds an accessible floating chat widget. Questions are sent to this site's own REST endpoint, which forwards a signed request to a separately hosted AI backend. The AI provider key never reaches the browser or the WordPress database.

The widget stays hidden until the backend is configured in wp-config.php:

    define( 'WP_KNOWLEDGE_CHAT_BACKEND_URL', 'https://chat-api.example.com' );
    define( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET', 'same value as CHAT_SHARED_SECRET on the backend' );

Settings: WP Knowledge Chat → Settings (Enable Chatbot, Greeting Message, Default Language, Contact URL).

Developer filters: wp_knowledge_chat_should_load, wp_knowledge_chat_suggested_questions, wp_knowledge_chat_allowed_contact_hosts, wp_knowledge_chat_allowed_source_hosts, wp_knowledge_chat_allowed_origins, wp_knowledge_chat_rate_limit_per_minute, wp_knowledge_chat_client_ip.

== Privacy ==

The plugin stores no conversations. Visitor questions are sent to the configured AI backend, which forwards them to the AI provider (Google Gemini). The visitor IP address is not sent; a salted one-way identifier is used for rate limiting. Conversation history is kept only in the visitor's browser session storage.

== Changelog ==

= 0.1.0 =
* Initial MVP.
