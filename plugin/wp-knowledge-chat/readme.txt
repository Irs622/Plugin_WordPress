=== WP Knowledge Chat ===
Contributors: inpartner
Tags: chatbot, faq, ai assistant, knowledge base, gemini
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A floating chat widget that answers visitor questions from approved public website content, with source links.

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
* English, Bahasa Indonesia and Korean (한국어) interface, with a language switcher and browser-language detection.
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
4. Go to **WP Knowledge Chat → Settings** and review the settings. The Contact URL defaults to the homepage (`https://inpartnerglobal.com`); change it to a dedicated contact page only once the site owner has approved that page.
5. Click **Test connection**. It should report that the backend is reachable, the shared secret is accepted and the knowledge base is ready.
6. If a page cache other than LiteSpeed Cache is active, purge it. LiteSpeed Cache is purged automatically when the settings change.

The widget stays hidden until both constants are defined and valid.

== Configuration ==

**WP Knowledge Chat → Settings** (administrators only, `manage_options`):

* **Enable Chatbot**: show or hide the widget on the public site. When it is off, the chat endpoint also refuses requests.
* **Greeting Messages**: one per language (English, Bahasa Indonesia, 한국어). Plain text, maximum 500 characters; any HTML is removed. Leave a field empty to restore that language's default.
* **Default Language**: English (`en`), Bahasa Indonesia (`id`) or Korean (`ko`). Used when the visitor has not chosen a language and their browser language is not supported (or detection is off).
* **Language Switcher**: show EN · ID · 한국어 buttons in the chat header. The visitor's choice is remembered in the browser.
* **Browser Language**: start in the visitor's browser language when it is one of the three.
* **Contact URL**: an absolute `https://` URL on an approved domain (inpartnerglobal.com, www.inpartnerglobal.com, or this site's own domain), without a username/password or a custom port. The URL is never fetched by the server.

All settings are stored in the single option `wp_knowledge_chat_settings`. Invalid values are rejected with an error message, and the previous value is kept. Saving changed settings triggers a LiteSpeed Cache purge (`litespeed_purge_all`), because the greetings, languages and contact link are embedded in cached pages.

The admin screens are translated into Bahasa Indonesia (`id_ID`) and Korean (`ko_KR`); translation files are in `languages/`.

== Frequently Asked Questions ==

= The widget does not appear. =

Check, in order:

1. **Enable Chatbot** is ticked.
2. The settings page shows "AI backend: Configured". Both `wp-config.php` constants must be present, the URL must use https, and the secret must be at least 32 characters.
3. No code restricts the widget through the `wp_knowledge_chat_should_load` filter.
4. You are viewing the public site. The widget is never loaded in wp-admin or the Elementor editor preview.
5. The page cache has been purged (automatic for LiteSpeed Cache after a settings change; manual for other caches).

= Every question returns "The assistant is unavailable right now". =

The backend cannot be reached or rejected the request. Use **Test connection** on the settings page.

* "rejected the shared secret": `WP_KNOWLEDGE_CHAT_SHARED_SECRET` differs from the backend's `CHAT_SHARED_SECRET`, or the server clocks differ by more than 5 minutes.
* "knowledge base is empty": the backend is running without `data/kb.json`.
* Other failures: check the backend URL, that the service is running, and that the hosting allows outbound HTTPS.
* If the test passes but chat still fails, check the backend logs (`llm_failed kind=auth` means the Gemini key is invalid; `rate_limited scope=daily` means the daily model-call budget is used up).

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

By default the plugin uses `REMOTE_ADDR` and ignores `X-Forwarded-For`, which visitors can forge. Behind a reverse proxy or CDN, list the proxy addresses in `wp-config.php`:

    define( 'WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES', '10.0.0.0/8, 2001:db8::/32' );

Only when the direct peer (`REMOTE_ADDR`) is in that list does the plugin read `X-Forwarded-For`, taking the right-most address that is not itself a trusted proxy. Use the provider's published proxy ranges, and nothing broader. For a header other than `X-Forwarded-For`, use the `wp_knowledge_chat_client_ip` filter instead.

= Is a WordPress nonce used for the chat requests? =

No, by design. The chat endpoint is public, and a nonce authorises nothing for anonymous visitors. Nonces also break on cached pages. Abuse is limited by origin checks, size limits and rate limits, and requests to the backend are HMAC-signed. Admin actions (saving settings, Test connection) do use nonces.

== Developer filters ==

* `wp_knowledge_chat_should_load` (bool): whether to load the widget on the current request.
* `wp_knowledge_chat_suggested_questions` (string[], $language): suggested questions per language (`en`, `id`, `ko`). Maximum 4, each at most 150 characters.
* `wp_knowledge_chat_allowed_contact_hosts` (string[]): domains allowed for the Contact URL.
* `wp_knowledge_chat_allowed_source_hosts` (string[]): domains allowed for source links.
* `wp_knowledge_chat_allowed_origins` (string[]): hostnames allowed to call the chat endpoint from a browser.
* `wp_knowledge_chat_rate_limit_per_minute` (int, default 6): chat requests per visitor per minute.
* `wp_knowledge_chat_client_ip` (string $ip, string $remote_addr): the IP address used for rate limiting.
* `wp_knowledge_chat_trusted_proxies` (string[]): proxy IPs/CIDR ranges whose `X-Forwarded-For` is trusted (default: `WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES`, empty).

Developer action:

* `wp_knowledge_chat_backend_failure` (string $reason, int $status): fires when a chat request fails because of the backend (`unreachable`, `unauthorized`, `rate_limited`, `http_error`, `invalid_response`). Receives no message text or IP; hook it to log or alert.

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
* WordPress keeps aggregate counts (questions, grounded/fallback, language) and the 25 most recent question snippets for the Analytics & Leads screen. Snippets have e-mail addresses, phone numbers, links and long numbers replaced, but can still contain other personal details a visitor typed. Turn snippets off with `add_filter( 'wp_knowledge_chat_store_query_snippets', '__return_false' );`.
* **Consultation requests (leads).** When a visitor submits the optional form, their name, e-mail, company and message are stored in WordPress (a private post type `wkc_lead`, visible only to administrators on Analytics & Leads) and e-mailed to the site's admin address (filter `wp_knowledge_chat_lead_notification_email`). The IP address is not stored.
* Leads are kept until an administrator deletes them (per lead, "Delete all", or Tools → Erase Personal Data). Set a retention period with `add_filter( 'wp_knowledge_chat_lead_retention_days', fn() => 180 );`. Leads can be exported as CSV or per person via Tools → Export Personal Data.
* Abuse limits on the lead form: 3 submissions per visitor per 10 minutes, 30 per site per hour (filter `wp_knowledge_chat_lead_limit_per_hour`), a hidden honeypot field, field length limits and suppression of identical resubmissions.
* Site owners should mention the AI assistant, Google Gemini and the consultation form in their privacy policy. Suggested wording is added to Settings → Privacy → Policy Guide.

== Uninstall ==

Deactivating keeps the settings, leads and metrics. Deleting the plugin from the Plugins screen runs `uninstall.php`, which removes the `wp_knowledge_chat_settings` option, the metrics, **all stored leads**, the knowledge-base sync state and the plugin's transients. Export leads first (Analytics & Leads → Export CSV) if you need to keep them. Remove the `wp-config.php` constants manually.

== Changelog ==

= 0.2.2 =
* Suggested privacy-policy text now appears under Settings → Privacy → Policy Guide (English, Bahasa Indonesia, Korean).
* Added `Update URI: false` to the plugin header, so a plugin with the same slug on WordPress.org can never be offered as an "update" and overwrite this one.
* Admin screens no longer print inline JavaScript, `onsubmit` handlers or `style` attributes. They load `assets/js/admin.js` and `assets/css/admin.css`, on this plugin's screens only, so a strict Content-Security-Policy works.
* More admin strings are translatable (page categories, lead post-type label, configuration errors); Bahasa Indonesia and Korean updated.
* Uninstall on a multisite network now removes the plugin's data from every site, not only the main one.
* Shorter readme description (150 characters).

= 0.2.1 =
* Security/privacy: leads are stored one per record (a flood of submissions can no longer push out earlier leads), with field length limits, a separate rate limit, a honeypot, duplicate suppression, mail-failure tracking, delete/export actions, retention filter and WordPress privacy exporter/eraser.
* Security: password-protected pages are never indexed; content is rendered as an anonymous visitor for indexing.
* Knowledge base: only pages an administrator synced are updated automatically; new pages are no longer pushed on publish. Trashed pages are now removed (the trash slug suffix broke removal), slug changes replace the old entry, failed updates are retried and shown.
* Widget: the consultation form keeps the visitor's input on errors instead of showing the error as a success, has labelled fields and sends the visitor's language.

= 0.2.0 =
* Korean (한국어) as a third language: interface, error messages and answers.
* Language switcher in the chat header, browser-language detection, and a greeting per language.
* Admin screens translated into Bahasa Indonesia and Korean.

= 0.1.0 =
* Initial MVP: chat widget, four admin settings, signed REST proxy to the AI backend, rate limiting, source links, fallback, English and Indonesian interface.

== Upgrade Notice ==

= 0.2.2 =
No settings or data changes. Purge the page cache so the new version query string is used. On a multisite network, uninstalling now cleans every site.

= 0.2.1 =
Existing leads are moved to the new storage automatically. After upgrading, open WP Knowledge Chat → Knowledge Base and sync your approved pages once: only synced pages receive automatic updates from now on. Purge the page cache.

= 0.2.0 =
Adds Korean and a language switcher. The single greeting becomes one greeting per language; a customised greeting is kept for the default language. Purge the page cache after upgrading.

= 0.1.0 =
First release. Requires the WP Knowledge Chat AI backend and two wp-config.php constants.
