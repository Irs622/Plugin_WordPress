import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const SCRIPT = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../../assets/js/chat-widget.js'), 'utf8');

const BASE_CONFIG = {
	restUrl: 'https://site.test/wp-json/wp-knowledge-chat/v1/chat',
	greeting: "Hello! How can I help you explore Inpartner's services?",
	language: 'en',
	contactUrl: 'https://inpartnerglobal.com/contact/',
	maxLength: 500,
	sourceHosts: ['inpartnerglobal.com', 'www.inpartnerglobal.com'],
	contactHosts: ['inpartnerglobal.com', 'www.inpartnerglobal.com'],
	suggestions: ['What services does Inpartner offer?', 'How can I contact Inpartner?'],
};

function jsonResponse(status, body) {
	return { ok: status >= 200 && status < 300, status, json: async () => body };
}

/** Boots the widget in a fresh DOM with a scripted fetch. */
async function setup({ config = {}, responses = [], storage } = {}) {
	const dom = new JSDOM('<!doctype html><html><body><main><a href="/">Site nav</a></main></body></html>', {
		url: 'https://site.test/',
		runScripts: 'outside-only',
	});
	const { window } = dom;
	if (window.document.readyState === 'loading') {
		await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve));
	}
	const calls = [];
	const queue = [...responses];
	window.fetch = (url, init) => {
		calls.push({ url, init, body: JSON.parse(init.body) });
		const next = queue.shift() ?? jsonResponse(200, { answer: 'ok', sources: [], grounded: false });
		return typeof next === 'function' ? next(url, init) : Promise.resolve(next);
	};
	if (storage) window.sessionStorage.setItem('wkc-history-v1', storage);
	window.wpKnowledgeChatConfig = { ...BASE_CONFIG, ...config };
	window.eval(SCRIPT);
	const $ = (sel) => window.document.querySelector(sel);
	const $$ = (sel) => [...window.document.querySelectorAll(sel)];
	const flush = () => new Promise((r) => setTimeout(r, 0));
	const type = (text) => {
		$('#wkc-input').value = text;
		$('#wkc-input').dispatchEvent(new window.Event('input', { bubbles: true }));
	};
	const pressEnter = () => $('#wkc-input').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
	return { window, $, $$, calls, flush, type, pressEnter };
}

describe('widget structure and accessibility', () => {
	it('renders a closed launcher and an accessible, labelled panel', async () => {
		const { $ } = await setup();
		const launcher = $('.wkc-launcher');
		assert.equal(launcher.getAttribute('aria-expanded'), 'false');
		assert.equal(launcher.getAttribute('aria-controls'), 'wkc-panel');
		assert.ok(launcher.getAttribute('aria-label'));
		assert.equal($('#wkc-panel').hidden, true);
		assert.equal($('#wkc-panel').getAttribute('role'), 'dialog');
		assert.equal($('#wkc-panel').getAttribute('aria-labelledby'), 'wkc-title');
		assert.equal($('.wkc-log').getAttribute('role'), 'log');
		assert.equal($('.wkc-log').getAttribute('aria-live'), 'polite');
		assert.equal($('[role="status"]').getAttribute('aria-live'), 'polite');
		assert.equal($('label[for="wkc-input"]').textContent, 'Type your question');
		assert.equal($('.wkc-close').getAttribute('aria-label'), 'Close chat');
		assert.equal($('#wkc-input').getAttribute('maxlength'), '500');
	});

	it('opens, focuses the input, and closes with Escape returning focus', async () => {
		const { $, window } = await setup();
		$('.wkc-launcher').click();
		assert.equal($('#wkc-panel').hidden, false);
		assert.equal($('.wkc-launcher').getAttribute('aria-expanded'), 'true');
		assert.equal(window.document.activeElement, $('#wkc-input'));
		$('#wkc-input').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
		assert.equal($('#wkc-panel').hidden, true);
		assert.equal(window.document.activeElement, $('.wkc-launcher'));
	});

	it('closes with the close button and the launcher toggle', async () => {
		const { $ } = await setup();
		$('.wkc-launcher').click();
		$('.wkc-close').click();
		assert.equal($('#wkc-panel').hidden, true);
		$('.wkc-launcher').click();
		$('.wkc-launcher').click();
		assert.equal($('#wkc-panel').hidden, true);
	});

	it('uses Indonesian interface strings when configured', async () => {
		const { $ } = await setup({ config: { language: 'id' } });
		assert.equal($('#wkc-title').textContent, 'Asisten AI Inpartner');
		assert.equal($('.wkc-send-label').textContent, 'Kirim');
		assert.equal($('.wkc').getAttribute('lang'), 'id');
	});

	it('initialises only once and does nothing without config', async () => {
		const { window, $$ } = await setup();
		window.eval(SCRIPT);
		assert.equal($$('#wkc-root').length, 1);
		const bare = new JSDOM('<body></body>', { runScripts: 'outside-only' });
		bare.window.eval(SCRIPT);
		assert.equal(bare.window.document.getElementById('wkc-root'), null);
	});
});

describe('safe rendering', () => {
	it('renders the greeting as text, never as HTML', async () => {
		const { $, $$ } = await setup({ config: { greeting: '<img src=x onerror="window.pwned=1">Hi <b>there</b>' } });
		assert.equal($$('.wkc img, .wkc b').length, 0);
		assert.ok($('.wkc-msg--bot .wkc-bubble').textContent.includes('<img src=x'));
	});

	it('renders answers as text and filters unsafe source links', async () => {
		const { $, $$, flush, type, pressEnter, window } = await setup({
			responses: [
				jsonResponse(200, {
					answer: 'Answer <script>window.pwned=1</script><img src=x onerror=alert(1)>',
					grounded: true,
					sources: [
						{ title: 'Good <b>source</b>', url: 'https://inpartnerglobal.com/about-us/' },
						{ title: 'JS', url: 'javascript:alert(1)' },
						{ title: 'Data', url: 'data:text/html,<script>alert(1)</script>' },
						{ title: 'HTTP', url: 'http://inpartnerglobal.com/' },
						{ title: 'Evil', url: 'https://evil.example/' },
						{ title: 'Lookalike', url: 'https://inpartnerglobal.com.evil.example/' },
						{ title: 'Creds', url: 'https://u:p@inpartnerglobal.com/' },
						{ title: 42, url: 'https://inpartnerglobal.com/' },
					],
				}),
			],
		});
		type('What do you do?');
		pressEnter();
		await flush();
		await flush();
		assert.equal($$('.wkc script, .wkc img, .wkc b').length, 0);
		assert.equal(window.pwned, undefined);
		assert.ok($$('.wkc-msg--bot')[1].textContent.includes('<script>'));
		const links = $$('.wkc-sources a');
		assert.equal(links.length, 1);
		assert.equal(links[0].getAttribute('href'), 'https://inpartnerglobal.com/about-us/');
		assert.equal(links[0].getAttribute('target'), '_blank');
		assert.equal(links[0].getAttribute('rel'), 'noopener noreferrer');
		assert.ok(links[0].textContent.includes('(opens in a new tab)'));
	});

	it('hides the contact link when the configured URL is not approved', async () => {
		const { $$ } = await setup({ config: { contactUrl: 'javascript:alert(1)' } });
		assert.equal($$('a[href^="javascript"]').length, 0);
		assert.equal($$('.wkc-contact').length, 0);
	});

	it('never assigns innerHTML for content', async () => {
		assert.ok(!/\.innerHTML\s*=|insertAdjacentHTML|document\.write/.test(SCRIPT));
	});
});

describe('sending messages', () => {
	it('sends the question with the configured language and shows a grounded answer with sources', async () => {
		const { $, $$, calls, flush, type, pressEnter } = await setup({
			config: { language: 'id' },
			responses: [jsonResponse(200, { answer: 'Kami membantu.', grounded: true, sources: [{ title: 'Home', url: 'https://inpartnerglobal.com/' }] })],
		});
		type('  Apa layanan Anda?  ');
		pressEnter();
		await flush();
		await flush();
		assert.equal(calls.length, 1);
		assert.deepEqual(calls[0].body, { message: 'Apa layanan Anda?', language: 'id' });
		assert.equal(calls[0].init.credentials, 'omit');
		assert.equal($$('.wkc-msg--user')[0].textContent.includes('Apa layanan Anda?'), true);
		assert.equal($$('.wkc-cta').length, 0, 'no contact prompt on grounded answers');
		assert.equal($('.wkc-suggestions').hidden, true);
		assert.equal($('#wkc-input').value, '');
	});

	it('shows a contact link with fallback answers', async () => {
		const { $$, flush, type, pressEnter } = await setup({ responses: [jsonResponse(200, { answer: 'Not available.', grounded: false, sources: [] })] });
		type('What are your prices?');
		pressEnter();
		await flush();
		await flush();
		const cta = $$('.wkc-cta a');
		assert.equal(cta.length, 1);
		assert.equal(cta[0].getAttribute('href'), 'https://inpartnerglobal.com/contact/');
	});

	it('prevents duplicate submissions while a request is pending', async () => {
		let resolve;
		const { $, calls, flush, type, pressEnter, window } = await setup({ responses: [() => new Promise((r) => (resolve = r))] });
		type('first');
		pressEnter();
		type('second');
		pressEnter();
		$('.wkc-form').dispatchEvent(new window.Event('submit', { cancelable: true }));
		$('.wkc-chip').click();
		assert.equal(calls.length, 1);
		assert.equal($('.wkc-send').disabled, true);
		assert.equal($('.wkc-log').getAttribute('aria-busy'), 'true');
		assert.ok($('.wkc-typing'), 'loading indicator shown');
		assert.ok($('[role="status"]').textContent.includes('preparing'));
		resolve(jsonResponse(200, { answer: 'done', grounded: false, sources: [] }));
		await flush();
		await flush();
		assert.equal($('.wkc-send').disabled, false);
		assert.equal($('.wkc-typing'), null);
	});

	it('validates empty and over-long messages before sending', async () => {
		const { $, calls, type, pressEnter } = await setup({ config: { maxLength: 20 } });
		type('    ');
		pressEnter();
		assert.equal($('#wkc-form-error').textContent, 'Please type a question first.');
		type('x'.repeat(21));
		pressEnter();
		assert.ok($('#wkc-form-error').textContent.includes('under 20 characters'));
		assert.equal(calls.length, 0);
	});

	it('Shift+Enter does not submit', async () => {
		const { $, calls, type, window } = await setup();
		type('line');
		$('#wkc-input').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true }));
		assert.equal(calls.length, 0);
	});

	it('updates the character counter', async () => {
		const { $, type } = await setup();
		type('hello');
		assert.equal($('#wkc-counter').textContent, '5 of 500 characters');
	});

	it('sends suggested questions when clicked', async () => {
		const { $, calls, flush } = await setup();
		$('.wkc-chip').click();
		await flush();
		assert.equal(calls[0].body.message, 'What services does Inpartner offer?');
	});

	it('includes recent conversation history in follow-up requests', async () => {
		const { calls, flush, type, pressEnter } = await setup({
			responses: [
				jsonResponse(200, { answer: 'First answer', grounded: true, sources: [] }),
				jsonResponse(200, { answer: 'Second answer', grounded: true, sources: [] }),
			],
		});
		type('Question 1');
		pressEnter();
		await flush();
		await flush();
		assert.equal(calls.length, 1);
		assert.equal(calls[0].body.history, undefined);

		type('Question 2');
		pressEnter();
		await flush();
		await flush();
		assert.equal(calls.length, 2);
		assert.deepEqual(calls[1].body.history, [
			{ role: 'user', text: 'Question 1' },
			{ role: 'model', text: 'First answer' },
		]);
	});
});

describe('error states', () => {
	it('shows a labelled rate-limit error (not colour alone)', async () => {
		const { $, flush, type, pressEnter } = await setup({ responses: [jsonResponse(429, { code: 'wkc_rate_limited', message: 'You are sending messages too quickly. Please wait a minute and try again.' })] });
		type('hi');
		pressEnter();
		await flush();
		await flush();
		const error = $('.wkc-msg--error');
		assert.ok(error.textContent.startsWith('⚠ Error:'));
		assert.ok(error.textContent.includes('too quickly'));
		assert.ok($('[role="status"]').textContent.startsWith('Error:'));
	});

	it('uses a generic message for server errors and offers retry', async () => {
		const { $, $$, calls, flush, type, pressEnter } = await setup({
			responses: [jsonResponse(503, { code: 'wkc_unavailable', message: 'internal detail' }), jsonResponse(200, { answer: 'Recovered', grounded: false, sources: [] })],
		});
		type('hi');
		pressEnter();
		await flush();
		await flush();
		assert.ok($('.wkc-msg--error').textContent.includes('unavailable right now'));
		assert.ok(!$('.wkc-msg--error').textContent.includes('internal detail'));
		$('.wkc-retry').click();
		await flush();
		await flush();
		assert.equal(calls.length, 2);
		assert.equal($$('.wkc-msg--error').length, 0);
		assert.ok($$('.wkc-msg--bot').at(-1).textContent.includes('Recovered'));
	});

	it('handles network failures and non-JSON responses', async () => {
		const { $$, flush, type, pressEnter } = await setup({
			responses: [() => Promise.reject(new TypeError('Failed to fetch')), { ok: false, status: 502, json: async () => { throw new SyntaxError('bad'); } }],
		});
		type('one');
		pressEnter();
		await flush();
		await flush();
		type('two');
		pressEnter();
		await flush();
		await flush();
		const errors = $$('.wkc-msg--error');
		assert.equal(errors.length, 2);
		assert.ok(errors[0].textContent.includes('could not be reached'));
		assert.ok(errors[1].textContent.includes('unavailable right now'));
	});

	it('treats a malformed 200 response as an error', async () => {
		const { $, flush, type, pressEnter } = await setup({ responses: [jsonResponse(200, { nope: true })] });
		type('hi');
		pressEnter();
		await flush();
		await flush();
		assert.ok($('.wkc-msg--error'));
	});
});

describe('session history', () => {
	it('persists the conversation and restores it after navigation', async () => {
		const first = await setup({ responses: [jsonResponse(200, { answer: 'Kept answer', grounded: true, sources: [{ title: 'Home', url: 'https://inpartnerglobal.com/' }] })] });
		first.type('Keep me');
		first.pressEnter();
		await first.flush();
		await first.flush();
		const stored = first.window.sessionStorage.getItem('wkc-history-v1');
		assert.equal(JSON.parse(stored).length, 2);

		const second = await setup({ storage: stored });
		assert.ok(second.$$('.wkc-msg--user')[0].textContent.includes('Keep me'));
		assert.equal(second.$$('.wkc-sources a')[0].getAttribute('href'), 'https://inpartnerglobal.com/');
		assert.equal(second.$('.wkc-suggestions').hidden, true);
	});

	it('ignores corrupted or tampered stored history', async () => {
		const tampered = JSON.stringify([
			{ role: 'bot', text: '<img src=x onerror=alert(1)>', sources: [{ title: 'x', url: 'javascript:alert(1)' }] },
			{ role: 'admin', text: 'nope' },
			'garbage',
		]);
		const { $$ } = await setup({ storage: tampered });
		assert.equal($$('.wkc img').length, 0);
		assert.equal($$('a[href^="javascript"]').length, 0);
		assert.equal($$('.wkc-msg').length, 2, 'greeting + one valid restored message');
		await assert.doesNotReject(setup({ storage: '{not json' }));
	});
});
