/**
 * End-to-end HTTP checks against a running local stack:
 *   WordPress (php -S) -> plugin REST proxy -> backend -> mock Gemini.
 * Started by tests/e2e/run.sh. Uses only disposable local test users.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { after, describe, it } from 'node:test';

const WP = process.env.WP_URL ?? 'http://127.0.0.1:8899';
const WP_CLI = process.env.WP_CLI;
const SECRET = process.env.WKC_SHARED_SECRET;
const GEMINI_KEY = process.env.E2E_GEMINI_KEY;
const CHAT = `${WP}/wp-json/wp-knowledge-chat/v1/chat`;
const VERSION = /^ \* Version:\s*(\S+)/m.exec(readFileSync(new URL('../../wp-knowledge-chat.php', import.meta.url), 'utf8'))[1];
const DEFAULT_GREETINGS = {
	en: "Hello! How can I help you explore Inpartner's services?",
	id: 'Halo! Ada yang bisa saya bantu terkait layanan Inpartner?',
	ko: '안녕하세요! Inpartner의 서비스에 대해 무엇을 도와드릴까요?',
};

const wpcli = (...args) => execFileSync(WP_CLI, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
// First line only: Elementor's logger may append a "PHP: ... notice" line to WP-CLI stdout.
const getSettings = () => JSON.parse(wpcli('option', 'get', 'wp_knowledge_chat_settings', '--format=json').split('\n')[0]);
const clearRateLimits = () => wpcli('transient', 'delete', '--all');

class Session {
	cookies = new Map();
	async request(url, init = {}) {
		const headers = new Headers(init.headers ?? {});
		if (this.cookies.size) headers.set('Cookie', [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '));
		const res = await fetch(url, { ...init, headers, redirect: 'manual' });
		for (const c of res.headers.getSetCookie()) {
			const [pair] = c.split(';');
			const [k, ...v] = pair.split('=');
			this.cookies.set(k.trim(), v.join('='));
		}
		return res;
	}
	async login(user, pass) {
		this.cookies.set('wordpress_test_cookie', 'WP%20Cookie%20check');
		const res = await this.request(`${WP}/wp-login.php`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ log: user, pwd: pass, 'wp-submit': 'Log In', testcookie: '1', redirect_to: `${WP}/wp-admin/` }),
		});
		assert.equal(res.status, 302, `login ${user}`);
		return this;
	}
}

async function saveSettings(session, fields, { nonce, referer } = {}) {
	const page = await session.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat`);
	const html = await page.text();
	const formNonce = /name=['"]option_page['"][^>]*>.*?name="_wpnonce" value="([a-f0-9]+)"/s.exec(html)?.[1];
	const body = new URLSearchParams({ option_page: 'wp_knowledge_chat', action: 'update', _wpnonce: nonce ?? formNonce ?? '', _wp_http_referer: referer ?? '/wp-admin/admin.php?page=wp-knowledge-chat' });
	// 'greetings[ko]' posts as wp_knowledge_chat_settings[greetings][ko].
	for (const [k, v] of Object.entries(fields)) if (v !== undefined) body.set(`wp_knowledge_chat_settings${k.split(/[[\]]+/).filter(Boolean).map((p) => `[${p}]`).join('')}`, v);
	return session.request(`${WP}/wp-admin/options.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
}

const ask = (message, extra = {}) =>
	fetch(CHAT, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(extra.headers ?? {}) }, body: JSON.stringify({ message, language: extra.language ?? 'en' }) });

after(() => clearRateLimits());

describe('public site', () => {
	it('loads the widget on the Elementor front page without exposing secrets', async () => {
		clearRateLimits();
		const res = await fetch(`${WP}/`);
		assert.equal(res.status, 200);
		const html = await res.text();
		assert.ok(html.includes(`chat-widget.js?ver=${VERSION}`), `script version ${VERSION}`);
		assert.ok(html.includes(`chat-widget.css?ver=${VERSION}`), `style version ${VERSION}`);
		assert.match(html, /window\.wpKnowledgeChatConfig = \{/);
		const tag = /<script[^>]*chat-widget\.js[^>]*>/.exec(html)?.[0] ?? '';
		assert.match(tag, /\bdefer\b/, 'widget script is deferred');
		assert.match(html, /elementor/i, 'page is rendered by Elementor');
		assert.ok(!html.includes(SECRET), 'shared secret not in page');
		assert.ok(!html.includes('127.0.0.1:8787'), 'backend URL not in page');
		assert.ok(!html.includes(GEMINI_KEY), 'Gemini key not in page');
	});

	it('answers a real question from the Inpartner knowledge base with valid sources', async () => {
		const res = await ask('What does Distribution Partner Sourcing Advisory include?');
		assert.equal(res.status, 200);
		const body = await res.json();
		assert.equal(body.grounded, true);
		assert.ok(body.sources.length >= 1);
		for (const s of body.sources) assert.match(s.url, /^https:\/\/inpartnerglobal\.com\//);
		assert.ok(body.sources.some((s) => s.url === 'https://inpartnerglobal.com/distribution-partner-sourcing-advisory/'));
	});

	it('returns the fallback for unsupported questions', async () => {
		const res = await ask('What is the weather on Mars tomorrow?', { language: 'id' });
		const body = await res.json();
		assert.equal(res.status, 200);
		assert.equal(body.grounded, false);
		assert.deepEqual(body.sources, []);
		assert.match(body.answer, /hubungi tim kami/);
		const ko = await (await ask('화성의 내일 날씨는 어떤가요?', { language: 'ko' })).json();
		assert.equal(ko.grounded, false);
		assert.match(ko.answer, /Inpartner 자문팀/);
	});

	it('rejects cross-site browser requests and invalid input', async () => {
		assert.equal((await ask('hi', { headers: { Origin: 'https://evil.example' } })).status, 403);
		assert.equal((await ask('x'.repeat(501))).status, 400);
		assert.equal((await ask('hi', { language: 'fr' })).status, 400);
		const get = await fetch(CHAT);
		assert.equal(get.status, 404);
	});

	it('degrades gracefully when the AI provider fails or times out', async () => {
		clearRateLimits();
		for (const trigger of ['__error__', '__slow__']) {
			const res = await ask(`distribution partners ${trigger}`);
			assert.equal(res.status, 503);
			const body = await res.json();
			assert.match(body.message, /unavailable right now/);
			assert.ok(!JSON.stringify(body).includes('8787'));
		}
	});

	it('applies the per-visitor rate limit', async () => {
		clearRateLimits();
		const statuses = [];
		for (let i = 0; i < 7; i++) statuses.push((await ask(`What services does Inpartner offer? ${i}`)).status);
		assert.deepEqual(statuses.slice(0, 6), [200, 200, 200, 200, 200, 200]);
		assert.equal(statuses[6], 429);
		clearRateLimits();
	});
});

describe('admin settings (real options.php flow)', () => {
	it('blocks anonymous users and subscribers from the settings page and options.php', async () => {
		const anon = new Session();
		const res = await anon.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat`);
		assert.equal(res.status, 302);
		assert.match(res.headers.get('location') ?? '', /wp-login\.php/);

		const sub = await new Session().login('subscriber', 'subscriber-pass-123');
		const page = await sub.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat`);
		assert.equal(page.status, 403);
		const before = getSettings();
		const post = await saveSettings(sub, { enabled: '1', 'greetings[id]': 'Hacked', language: 'id', contact_url: 'https://inpartnerglobal.com/x' }, { nonce: 'deadbeef00' });
		assert.ok([403, 500].includes(post.status), `subscriber options.php status ${post.status}`);
		assert.deepEqual(getSettings(), before);
	});

	it('blocks editors (no manage_options)', async () => {
		const editor = await new Session().login('editor', 'editor-pass-123');
		assert.equal((await editor.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat`)).status, 403);
	});

	it('rejects an administrator request without a valid nonce', async () => {
		const admin = await new Session().login('admin', 'admin-pass-123');
		const before = getSettings();
		const res = await saveSettings(admin, { enabled: '1', 'greetings[en]': 'No nonce', language: 'en', contact_url: 'https://inpartnerglobal.com' }, { nonce: '0000000000' });
		assert.equal(res.status, 403);
		assert.deepEqual(getSettings(), before);
	});

	it('persists valid settings and rejects invalid values server-side', async () => {
		const admin = await new Session().login('admin', 'admin-pass-123');
		const ok = await saveSettings(admin, {
			enabled: '1',
			'greetings[en]': '',
			'greetings[id]': 'Halo! <b>Ada</b> yang bisa dibantu?',
			'greetings[ko]': '반갑습니다! 무엇을 도와드릴까요?',
			language: 'ko',
			language_switcher: '1',
			contact_url: 'https://inpartnerglobal.com/contact/',
		});
		assert.equal(ok.status, 302);
		assert.match(ok.headers.get('location') ?? '', /settings-updated=true/);
		const saved = {
			enabled: true,
			greetings: { en: DEFAULT_GREETINGS.en, id: 'Halo! Ada yang bisa dibantu?', ko: '반갑습니다! 무엇을 도와드릴까요?' },
			language: 'ko',
			contact_url: 'https://inpartnerglobal.com/contact/',
			language_switcher: true,
			detect_browser_language: false,
		};
		assert.deepEqual(getSettings(), saved);

		const bad = await saveSettings(admin, { enabled: '1', 'greetings[ko]': '<script>alert(1)</script>', language: 'xx', language_switcher: '1', contact_url: 'http://evil.example/' });
		assert.equal(bad.status, 302);
		assert.deepEqual(getSettings(), saved);
		const after = await (await admin.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat&settings-updated=true`)).text();
		assert.match(after, /Greeting message is required/);
		assert.match(after, /Contact URL must be an absolute https/);
		assert.match(after, /Default language must be/);
		assert.ok(!after.includes(SECRET));

		const html = await (await fetch(`${WP}/`)).text();
		assert.match(html, /"language":"ko"/);
		assert.match(html, /"languages":\["en","id","ko"\]/);
		assert.match(html, /"switcher":true,"detectBrowser":false/);
		assert.ok(html.includes('반갑습니다! 무엇을 도와드릴까요?'), 'Korean greeting is embedded unescaped');
	});

	it('disabling the chatbot removes the widget and closes the endpoint; re-enabling restores it', async () => {
		const admin = await new Session().login('admin', 'admin-pass-123');
		await saveSettings(admin, { language: 'en', contact_url: 'https://inpartnerglobal.com/contact/' });
		assert.equal(getSettings().enabled, false);
		assert.ok(!(await (await fetch(`${WP}/`)).text()).includes('chat-widget.js'));
		assert.equal((await ask('What services does Inpartner offer?')).status, 503);

		// Restore the defaults the browser checks expect.
		await saveSettings(admin, {
			enabled: '1',
			'greetings[en]': '',
			'greetings[id]': '',
			'greetings[ko]': '',
			language: 'en',
			language_switcher: '1',
			detect_browser_language: '1',
			contact_url: 'https://inpartnerglobal.com/contact/',
		});
		assert.deepEqual(getSettings().greetings, DEFAULT_GREETINGS);
		assert.equal(getSettings().enabled, true);
		assert.ok((await (await fetch(`${WP}/`)).text()).includes('chat-widget.js'));
	});

	it('runs the nonce-protected connection test', async () => {
		const admin = await new Session().login('admin', 'admin-pass-123');
		const page = await (await admin.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat`)).text();
		const nonce = /name="action" value="wkc_test_connection" \/>\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([a-f0-9]+)"/.exec(page)?.[1];
		assert.ok(nonce);
		const res = await admin.request(`${WP}/wp-admin/admin-post.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'wkc_test_connection', _wpnonce: nonce }) });
		assert.equal(res.status, 302);
		const after = await (await admin.request(`${WP}/wp-admin/admin.php?page=wp-knowledge-chat`)).text();
		assert.match(after, /backend is reachable, the shared secret is accepted and the knowledge base is ready/);
	});
});
