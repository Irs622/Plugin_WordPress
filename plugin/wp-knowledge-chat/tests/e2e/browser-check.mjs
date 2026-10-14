/**
 * Real-browser check of the widget using headless Chrome over the DevTools
 * protocol (no Puppeteer dependency; Node's built-in WebSocket).
 * Verifies desktop + mobile layouts, keyboard operation, answer/error states,
 * axe-core accessibility rules and console errors. Saves screenshots.
 */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const WP = process.env.WP_URL ?? 'http://127.0.0.1:8899';
const CHROME = process.env.CHROME_BIN ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const OUT = process.env.SCREENSHOT_DIR ?? join(process.cwd(), 'e2e-screenshots');
const PORT = 9333;
mkdirSync(OUT, { recursive: true });

const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'wkc-chrome-'))}`, '--no-first-run', '--no-default-browser-check', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });

let ws;
let id = 0;
const pending = new Map();
const consoleErrors = [];

async function connect() {
	for (let i = 0; i < 50; i++) {
		try {
			const targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
			const page = targets.find((t) => t.type === 'page');
			if (page) {
				ws = new WebSocket(page.webSocketDebuggerUrl);
				await new Promise((resolve, reject) => {
					ws.onopen = resolve;
					ws.onerror = reject;
				});
				ws.onmessage = (event) => {
					const msg = JSON.parse(event.data);
					if (msg.id && pending.has(msg.id)) {
						const { resolve, reject } = pending.get(msg.id);
						pending.delete(msg.id);
						msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
					} else if (msg.method === 'Runtime.exceptionThrown') {
						consoleErrors.push(msg.params.exceptionDetails.text);
					} else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
						consoleErrors.push(msg.params.args.map((a) => a.value ?? a.description).join(' '));
					}
				};
				return;
			}
		} catch {
			/* Chrome not ready yet */
		}
		await sleep(200);
	}
	throw new Error('Could not connect to Chrome');
}

function send(method, params = {}) {
	const msgId = ++id;
	ws.send(JSON.stringify({ id: msgId, method, params }));
	return new Promise((resolve, reject) => pending.set(msgId, { resolve, reject }));
}

async function evaluate(expression) {
	const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
	if (result.exceptionDetails) throw new Error(`Evaluation failed: ${result.exceptionDetails.text} ${result.exceptionDetails.exception?.description ?? ''}`);
	return result.result.value;
}

async function waitFor(expression, timeoutMs = 15000) {
	const end = Date.now() + timeoutMs;
	while (Date.now() < end) {
		if (await evaluate(expression)) return;
		await sleep(100);
	}
	throw new Error(`Timed out waiting for: ${expression}`);
}

async function screenshot(name) {
	const { data } = await send('Page.captureScreenshot', { format: 'png' });
	writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, 'base64'));
}

async function key(keyName, code, keyCode, modifiers = 0) {
	// Enter must carry text "\r" for Chrome to synthesise the activation a physical key press produces.
	const text = keyName === 'Enter' ? '\r' : undefined;
	await send('Input.dispatchKeyEvent', { type: 'keyDown', key: keyName, code, windowsVirtualKeyCode: keyCode, modifiers, text, unmodifiedText: text });
	await send('Input.dispatchKeyEvent', { type: 'keyUp', key: keyName, code, windowsVirtualKeyCode: keyCode, modifiers });
}

async function typeText(text) {
	await send('Input.insertText', { text });
}

async function load(viewport) {
	await send('Emulation.setDeviceMetricsOverride', viewport);
	await send('Page.navigate', { url: `${WP}/` });
	await waitFor("document.readyState === 'complete' && !!document.querySelector('.wkc-launcher')");
	await evaluate("sessionStorage.clear(); true");
	await send('Page.reload', {});
	await waitFor("document.readyState === 'complete' && !!document.querySelector('.wkc-launcher')");
}

async function askViaKeyboard(question) {
	await evaluate("document.getElementById('wkc-input').focus(); true");
	await typeText(question);
	const before = await evaluate("document.querySelectorAll('.wkc-msg--bot:not(.wkc-typing), .wkc-msg--error').length");
	await key('Enter', 'Enter', 13);
	await waitFor(`document.querySelectorAll('.wkc-msg--bot:not(.wkc-typing), .wkc-msg--error').length > ${before} && !document.querySelector('.wkc-typing')`, 20000);
}

const results = [];
async function check(name, fn) {
	try {
		await fn();
		results.push({ name, ok: true });
		console.log(`  ✔ ${name}`);
	} catch (err) {
		results.push({ name, ok: false, error: err.message });
		console.log(`  ✖ ${name}\n      ${err.message}`);
	}
}

const DESKTOP = { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false };
const MOBILE = { width: 390, height: 844, deviceScaleFactor: 2, mobile: true };

try {
	await connect();
	await send('Page.enable');
	await send('Runtime.enable');

	console.log('Desktop (1366x900)');
	await load(DESKTOP);
	await screenshot('01-desktop-closed');

	await check('launcher does not overlap the primary navigation', async () => {
		const overlap = await evaluate(`(() => {
			const l = document.querySelector('.wkc-launcher').getBoundingClientRect();
			const navs = [...document.querySelectorAll('header, nav, .site-header')].map((n) => n.getBoundingClientRect());
			return navs.some((n) => n.width && !(l.right < n.left || l.left > n.right || l.bottom < n.top || l.top > n.bottom));
		})()`);
		assert.equal(overlap, false);
	});

	await check('theme styles do not leak into widget controls (Hello Elementor / Elementor kit)', async () => {
		const styles = await evaluate(`(() => {
			const cs = (sel) => getComputedStyle(document.querySelector(sel));
			const l = cs('.wkc-launcher');
			return { launcherBg: l.backgroundColor, launcherRadius: l.borderRadius, launcherBorder: l.borderTopColor };
		})()`);
		assert.equal(styles.launcherBg, 'rgb(6, 50, 50)');
		assert.equal(styles.launcherRadius, '50%');
		assert.equal(styles.launcherBorder, 'rgb(249, 214, 124)');
		await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1366 - 16 - 29, y: 900 - 16 - 29 });
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.wkc-launcher')).backgroundColor"), 'rgb(6, 50, 50)', 'hover keeps brand colour');
		await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 10, y: 400 });
	});

	await check('keyboard: Tab reaches the launcher, Enter opens, focus moves to input', async () => {
		await evaluate('document.activeElement && document.activeElement.blur(); true');
		let reached = false;
		for (let i = 0; i < 60 && !reached; i++) {
			await key('Tab', 'Tab', 9);
			reached = await evaluate("document.activeElement === document.querySelector('.wkc-launcher')");
		}
		assert.ok(reached, 'launcher reachable by Tab');
		const outline = await evaluate("getComputedStyle(document.activeElement).outlineStyle");
		assert.notEqual(outline, 'none', 'visible focus indicator');
		await screenshot('02-desktop-launcher-focus');
		await key('Enter', 'Enter', 13);
		await waitFor("!document.getElementById('wkc-panel').hidden");
		assert.ok(await evaluate("document.activeElement.id === 'wkc-input'"));
	});

	await screenshot('03-desktop-open-greeting');

	await check('open panel controls use widget styles, not theme styles', async () => {
		const st = await evaluate(`(() => {
			document.getElementById('wkc-input').blur();
			const cs = (sel) => getComputedStyle(document.querySelector(sel));
			return { send: cs('.wkc-send').backgroundColor, sendColor: cs('.wkc-send').color, close: cs('.wkc-close').borderTopColor, chipWrap: cs('.wkc-chip').whiteSpace, input: cs('#wkc-input').borderTopColor };
		})()`);
		assert.equal(st.send, 'rgb(6, 50, 50)');
		assert.equal(st.sendColor, 'rgb(255, 255, 255)');
		assert.equal(st.close, 'rgba(255, 255, 255, 0.4)');
		assert.equal(st.chipWrap, 'normal');
		assert.equal(st.input, 'rgb(138, 149, 153)');
	});

	await check('answers a question with sources (grounded)', async () => {
		await askViaKeyboard('What does Distribution Partner Sourcing Advisory include?');
		const links = await evaluate("[...document.querySelectorAll('.wkc-sources a')].map(a => [a.href, a.target, a.rel])");
		assert.ok(links.length >= 1, 'source links present');
		for (const [href, target, rel] of links) {
			assert.match(href, /^https:\/\/inpartnerglobal\.com\//);
			assert.equal(target, '_blank');
			assert.equal(rel, 'noopener noreferrer');
		}
		await screenshot('04-desktop-answer');
	});

	await check('unsupported question shows fallback with contact link', async () => {
		await askViaKeyboard('What is the weather on Mars?');
		assert.ok(await evaluate("[...document.querySelectorAll('.wkc-msg--bot')].at(-1).querySelector('.wkc-cta a')?.href === 'https://inpartnerglobal.com/contact/'"));
		await screenshot('05-desktop-fallback');
	});

	await check('provider failure shows an understandable, labelled error with retry', async () => {
		await askViaKeyboard('distribution partners __error__');
		const text = await evaluate("document.querySelector('.wkc-msg--error').textContent");
		assert.match(text, /Error:/);
		assert.match(text, /unavailable right now/);
		assert.ok(await evaluate("!!document.querySelector('.wkc-retry')"));
		await screenshot('06-desktop-error');
	});

	await check('axe-core: no WCAG 2.1 A/AA violations in the open widget', async () => {
		await evaluate(`${AXE}; true`);
		const violations = await evaluate(`axe.run('#wkc-root', { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }).then(r => r.violations.map(v => v.id + ': ' + v.help + ' (' + v.nodes.length + ')'))`);
		assert.deepEqual(violations, []);
	});

	await check('Escape closes the panel and returns focus to the launcher', async () => {
		await evaluate("document.getElementById('wkc-input').focus(); true");
		await key('Escape', 'Escape', 27);
		assert.ok(await evaluate("document.getElementById('wkc-panel').hidden && document.activeElement === document.querySelector('.wkc-launcher')"));
	});

	await check('conversation persists across page navigation in the session', async () => {
		await send('Page.reload', {});
		await waitFor("document.readyState === 'complete' && !!document.querySelector('.wkc-launcher')");
		assert.ok(await evaluate("document.querySelectorAll('.wkc-msg--user').length >= 2"));
	});

	console.log('Mobile (390x844)');
	await load(MOBILE);
	await screenshot('07-mobile-closed');

	await check('mobile: panel fits the viewport without horizontal scroll', async () => {
		await evaluate("document.querySelector('.wkc-launcher').click(); true");
		await waitFor("!document.getElementById('wkc-panel').hidden");
		const m = await evaluate(`(() => { const r = document.getElementById('wkc-panel').getBoundingClientRect(); return { left: r.left, right: r.right, width: r.width, vw: innerWidth, top: r.top, vh: innerHeight, scroll: document.documentElement.scrollWidth }; })()`);
		assert.ok(m.left >= 0 && m.right <= m.vw + 0.5, `panel within viewport ${JSON.stringify(m)}`);
		assert.ok(m.width >= m.vw - 1, 'full-width bottom sheet');
		assert.ok(m.top > 0, 'top of page still visible above the sheet');
		assert.ok(m.scroll <= m.vw, 'no horizontal scroll');
		const chipsOverflow = await evaluate("[...document.querySelectorAll('.wkc-chip')].some(c => c.getBoundingClientRect().right > innerWidth)");
		assert.equal(chipsOverflow, false, 'suggestion chips wrap inside the viewport');
		const fontSize = await evaluate("parseFloat(getComputedStyle(document.getElementById('wkc-input')).fontSize)");
		assert.ok(fontSize >= 16, 'input font-size >= 16px avoids iOS zoom');
		await screenshot('08-mobile-open');
	});

	await check('mobile: touch targets are at least 44px', async () => {
		const sizes = await evaluate("[...document.querySelectorAll('.wkc-send, .wkc-close')].map(b => { const r = b.getBoundingClientRect(); return Math.min(r.width, r.height); })");
		assert.ok(sizes.every((s) => s >= 36), JSON.stringify(sizes));
	});

	await check('mobile: answer renders', async () => {
		await askViaKeyboard('How can I contact Inpartner?');
		await screenshot('09-mobile-answer');
	});

	await check('no JavaScript errors from the widget', async () => {
		const ours = consoleErrors.filter((e) => /wkc|knowledge|chat-widget/i.test(e));
		assert.deepEqual(ours, []);
	});
} finally {
	try {
		ws?.close();
	} catch {}
	chrome.kill('SIGTERM');
}

writeFileSync(join(OUT, 'results.json'), JSON.stringify({ results, consoleErrors }, null, 2));
const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed}/${results.length} browser checks passed. Screenshots: ${OUT}`);
process.exit(failed ? 1 : 0);
