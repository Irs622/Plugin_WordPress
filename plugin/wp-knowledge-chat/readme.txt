=== WP Knowledge Chat ===
Contributors: inpartner
Tags: chatbot, faq, ai assistant, knowledge base, gemini
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inpartner AI Business Consultation Assistant: a floating chat widget that answers visitor questions from approved public website content, with source links.

== Description ==

WP Knowledge Chat adds an accessible floating chat assistant to the Inpartner website. Visitors ask questions about Inpartner's services. The assistant answers only from approved, published website pages, shows the pages it used as sources, and points visitors to the official contact page when it does not have enough information.

The plugin is one half of a two-part system:

* **This plugin** (PHP) loads the widget, provides the admin settings, and offers a same-origin REST endpoint. That endpoint validates and rate-limits visitor questions and forwards them, signed, to the AI backend.
* **The AI backend** (Node.js, hosted separately) retrieves relevant content, calls Google Gemini, and validates every answer and source.

The AI provider key never reaches the browser or the WordPress database.

= Features =

* Floating chat button and panel. Responsive, with a full-width bottom sheet on mobile.
* Greeting message, suggested questions, loading indicator, friendly error states with retry, and a fallback with a contact link.
* Source links for factual answers. Only https links to approved domains are shown.
* English and Bahasa Indonesia interface.
* Keyboard accessible: Tab, Enter and Escape, visible focus, screen-reader announcements. Checked with axe-core (WCAG 2.1 A/AA).
* Conversation kept for the browser session only; nothing is stored on the server.
* Styles scoped to the widget, so they do not affect Elementor layouts or the site navigation.
* Server-side validation of all settings, per-visitor rate limiting, and no secrets in the page source.

= What the assistant will not do =

* Invent services, prices, clients, results or other claims not on the website.
* Give personalised investment, legal, tax or financial advice.
* Present itself as a human consultant.

== Installation ==

The AI backend must be deployed first (see `docs/deployment.md` in the project repository). Then:

1. Upload `wp-knowledge-chat-<version>.zip` via **Plugins → Add New → Upload Plugin**, or copy the `wp-knowledge-chat` folder to `wp-content/plugins/`.
2. Add the backend connection to `wp-config.php`, above the line "That's all, stop editing":

    define( 'WP_KNOWLEDGE_CHAT_BACKEND_URL', 'https://chat-api.example.com' );
    define( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET', 'same value as CHAT_SHARED_SECRET on the backend' );

   The URL must use https. The secret must be at least 32 characters.
3. Activate the plugin.
4. Go to **WP Knowledge Chat → Settings** and set the Contact URL, for example `https://inpartnerglobal.com/contact/`.
5. Click **Test connection**. It should report that the backend is reachable and its knowledge base is ready.
6. If a page cache is active (for example LiteSpeed Cache), purge it.

The widget stays hidden until both constants are defined and valid.

== Configuration ==

**WP Knowledge Chat → Settings** (administrators only, `manage_options`):

* **Enable Chatbot**: show or hide the widget on the public site. When it is off, the chat endpoint also refuses requests.
* **Greeting Message**: required, plain text, maximum 500 characters. Any HTML is removed.
* **Default Language**: English (`en`) or Bahasa Indonesia (`id`). Controls the interface language and the preferred answer language.
* **Contact URL**: an absolute `https://` URL on an approved domain (inpartnerglobal.com, www.inpartnerglobal.com, or this site's own domain), without a username/password or a custom port. The URL is never fetched by the server.

All settings are stored in the single option `wp_knowledge_chat_settings`. Invalid values are rejected with an error message, and the previous value is kept.

== Frequently Asked Questions ==

= The widget does not appear. =

Check, in order:

1. **Enable Chatbot** is ticked.
2. The settings page shows "AI backend: Configured". Both `wp-config.php` constants must be present, the URL must use https, and the secret must be at least 32 characters.
3. No code restricts the widget through the `wp_knowledge_chat_should_load` filter.
4. You are viewing the public site. The widget is never loaded in wp-admin or the Elementor editor preview.
5. The page cache has been purged.

= Every question returns "The assistant is unavailable right now". =

The backend cannot be reached or rejected the request. Use **Test connection** on the settings page.

* If the test fails, check the backend URL and that the service is running, and that the hosting allows outbound HTTPS.
* If the test passes but chat still fails, the shared secret probably differs between `wp-config.php` and the backend, or the server clocks differ by more than 5 minutes.

= Does the plugin store visitor conversations? =

No. Conversation history stays in the visitor's browser (sessionStorage) and is cleared when the tab is closed. WordPress stores only short-lived rate-limit counters keyed by a one-way hash of the IP address.

= Where does the assistant get its answers? =

From a reviewed snapshot of approved, published pages of inpartnerglobal.com held by the backend. Draft and private content cannot be indexed. To change which pages are used, see `docs/knowledge-base.md`.

= Can I show the widget only on some pages? =

Yes. Add to a small mu-plugin or the child theme's `functions.php`:

    add_filter( 'wp_knowledge_chat_should_load', function ( $load ) {
        return $load && ( is_front_page() || is_page( array( 'about-us', 'contact' ) ) );
    } );

= Visitors behind a proxy or CDN all get rate-limited together. =

By default the plugin uses `REMOTE_ADDR`. Behind a trusted proxy, return the real visitor IP with the `wp_knowledge_chat_client_ip` filter. Only read a header that your proxy sets and overwrites, because visitors can forge others.

= Is a WordPress nonce used for the chat requests? =

No, by design. The chat endpoint is public, and a nonce authorises nothing for anonymous visitors. Nonces also break on cached pages. Abuse is limited by origin checks, size limits and rate limits, and requests to the backend are HMAC-signed. Admin actions (saving settings, Test connection) do use nonces.

== Developer filters ==

* `wp_knowledge_chat_should_load` (bool): whether to load the widget on the current request.
* `wp_knowledge_chat_suggested_questions` (string[], $language): suggested questions. Maximum 4, each at most 150 characters.
* `wp_knowledge_chat_allowed_contact_hosts` (string[]): domains allowed for the Contact URL.
* `wp_knowledge_chat_allowed_source_hosts` (string[]): domains allowed for source links.
* `wp_knowledge_chat_allowed_origins` (string[]): hostnames allowed to call the chat endpoint from a browser.
* `wp_knowledge_chat_rate_limit_per_minute` (int, default 6): chat requests per visitor per minute.
* `wp_knowledge_chat_client_ip` (string): the IP address used for rate limiting.

REST endpoint: `POST /wp-json/wp-knowledge-chat/v1/chat` with `{"message": "...", "language": "en"}`. The full contract is in `docs/api-contract.md`.

== Screenshots ==

1. Closed widget on an Elementor page (desktop).
2. Open panel with greeting and suggested questions.
3. Answer with source link.
4. Fallback answer with contact link.
5. Error state with "Try again".
6. Mobile bottom sheet.

== Privacy ==

* Visitor questions are sent to the configured AI backend, which forwards them, together with excerpts of public website pages, to Google Gemini to generate an answer.
* The visitor's IP address, cookies and identity are not sent. A salted one-way identifier is used for rate limiting.
* The backend logs operational events (outcome, language, message length, latency) but not message text.
* Site owners should mention the AI assistant and Google Gemini in their privacy policy.

== Uninstall ==

Deactivating keeps the settings. Deleting the plugin from the Plugins screen runs `uninstall.php`, which removes the `wp_knowledge_chat_settings` option and the plugin's transients. Remove the two `wp-config.php` constants manually.

== Changelog ==

= 0.1.0 =
* Initial MVP: chat widget, four admin settings, signed REST proxy to the AI backend, rate limiting, source links, fallback, English and Indonesian interface.

== Upgrade Notice ==

= 0.1.0 =
First release. Requires the WP Knowledge Chat AI backend and two wp-config.php constants.
