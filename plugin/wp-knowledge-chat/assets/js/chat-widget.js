/**
 * WP Knowledge Chat widget (vanilla JS, no dependencies).
 *
 * Security notes:
 * - All dynamic text is inserted with textContent; innerHTML is never used for content.
 * - Links are rendered only for https URLs on hosts approved by the server config.
 * - The widget talks only to the same-origin WordPress REST endpoint.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'wkc-history-v1';
	var LANG_KEY = 'wkc-lang';
	var MAX_HISTORY = 30;
	var REQUEST_TIMEOUT_MS = 30000;

	var STRINGS = {
		en: {
			title: 'Inpartner AI Assistant',
			subtitle: 'AI assistant · answers from inpartnerglobal.com',
			open: 'Open the Inpartner AI assistant',
			close: 'Close chat',
			language: 'Language',
			inputLabel: 'Type your question',
			placeholder: 'Ask a question…',
			send: 'Send',
			suggestions: 'Suggested questions',
			you: 'You said:',
			assistant: 'Assistant said:',
			typing: 'The assistant is preparing an answer…',
			received: 'New answer received.',
			sources: 'Sources',
			newTab: '(opens in a new tab)',
			contact: 'Contact the Inpartner team',
			contactPrompt: 'Need a specific answer? Contact the Inpartner team',
			errorLabel: 'Error:',
			retry: 'Try again',
			disclaimer: 'Automated answers based on public website content. Not professional or investment advice.',
			tooLong: 'Your question is too long. Please keep it under {max} characters.',
			empty: 'Please type a question first.',
			errNetwork: 'The assistant could not be reached. Please check your connection and try again.',
			errTimeout: 'The assistant is taking too long to respond. Please try again.',
			errRate: 'You are sending messages too quickly. Please wait a minute and try again.',
			errGeneric: 'The assistant is unavailable right now. Please try again later or contact us directly.',
			counter: '{n} of {max} characters',
			clear: 'Clear chat',
			requestConsult: 'Request Consultation',
			leadName: 'Your Name',
			leadEmail: 'Work Email',
			leadCompany: 'Company (Optional)',
			leadMessage: 'Consultation Inquiry',
			leadSubmit: 'Send Request',
			leadCancel: 'Cancel',
			leadSuccess: 'Thank you! Your consultation request has been sent to the Inpartner team.',
			leadError: 'Please provide a valid name and email address.'
		},
		id: {
			title: 'Asisten AI Inpartner',
			subtitle: 'Asisten AI · jawaban dari inpartnerglobal.com',
			open: 'Buka asisten AI Inpartner',
			close: 'Tutup obrolan',
			language: 'Bahasa',
			inputLabel: 'Tulis pertanyaan Anda',
			placeholder: 'Tulis pertanyaan…',
			send: 'Kirim',
			suggestions: 'Pertanyaan yang disarankan',
			you: 'Anda:',
			assistant: 'Asisten:',
			typing: 'Asisten sedang menyiapkan jawaban…',
			received: 'Jawaban baru diterima.',
			sources: 'Sumber',
			newTab: '(terbuka di tab baru)',
			contact: 'Hubungi tim Inpartner',
			contactPrompt: 'Butuh jawaban spesifik? Hubungi tim Inpartner',
			errorLabel: 'Galat:',
			retry: 'Coba lagi',
			disclaimer: 'Jawaban otomatis berdasarkan konten publik situs. Bukan nasihat profesional atau investasi.',
			tooLong: 'Pertanyaan terlalu panjang. Maksimal {max} karakter.',
			empty: 'Silakan tulis pertanyaan terlebih dahulu.',
			errNetwork: 'Asisten tidak dapat dihubungi. Periksa koneksi Anda lalu coba lagi.',
			errTimeout: 'Asisten terlalu lama merespons. Silakan coba lagi.',
			errRate: 'Anda mengirim pesan terlalu cepat. Silakan tunggu sebentar lalu coba lagi.',
			errGeneric: 'Asisten sedang tidak tersedia. Silakan coba lagi nanti atau hubungi kami secara langsung.',
			counter: '{n} dari {max} karakter',
			clear: 'Hapus obrolan',
			requestConsult: 'Minta Konsultasi',
			leadName: 'Nama Anda',
			leadEmail: 'Email Kerja',
			leadCompany: 'Perusahaan (Opsional)',
			leadMessage: 'Kebutuhan Konsultasi',
			leadSubmit: 'Kirim Permintaan',
			leadCancel: 'Batal',
			leadSuccess: 'Terima kasih! Permintaan konsultasi Anda telah dikirim ke tim Inpartner.',
			leadError: 'Silakan isi nama dan alamat email yang valid.'
		},
		ko: {
			title: 'Inpartner AI 어시스턴트',
			subtitle: 'AI 어시스턴트 · inpartnerglobal.com 기반 답변',
			open: 'Inpartner AI 어시스턴트 열기',
			close: '채팅 닫기',
			language: '언어',
			inputLabel: '질문을 입력하세요',
			placeholder: '질문을 입력하세요…',
			send: '보내기',
			suggestions: '추천 질문',
			you: '나:',
			assistant: '어시스턴트:',
			typing: '어시스턴트가 답변을 준비하고 있습니다…',
			received: '새 답변이 도착했습니다.',
			sources: '출처',
			newTab: '(새 탭에서 열림)',
			contact: 'Inpartner 팀에 문의하기',
			contactPrompt: '구체적인 답변이 필요하신가요? Inpartner 팀에 문의하세요',
			errorLabel: '오류:',
			retry: '다시 시도',
			disclaimer: '웹사이트 공개 콘텐츠를 바탕으로 한 자동 답변입니다. 전문적인 조언이나 투자 자문이 아닙니다.',
			tooLong: '질문이 너무 깁니다. {max}자 이내로 입력해 주세요.',
			empty: '먼저 질문을 입력해 주세요.',
			errNetwork: '어시스턴트에 연결할 수 없습니다. 인터넷 연결을 확인한 후 다시 시도해 주세요.',
			errTimeout: '어시스턴트의 응답이 너무 늦어지고 있습니다. 다시 시도해 주세요.',
			errRate: '메시지를 너무 빠르게 보내고 있습니다. 잠시 후 다시 시도해 주세요.',
			errGeneric: '지금은 어시스턴트를 이용할 수 없습니다. 잠시 후 다시 시도하시거나 직접 문의해 주세요.',
			counter: '{n} / {max}자',
			clear: '대화 지우기',
			requestConsult: '상담 요청하기',
			leadName: '성함',
			leadEmail: '이메일',
			leadCompany: '회사명 (선택)',
			leadMessage: '상담 문의 내용',
			leadSubmit: '요청 보내기',
			leadCancel: '취소',
			leadSuccess: '감사합니다! Inpartner 자문팀으로 상담 요청이 전송되었습니다.',
			leadError: '올바른 성함과 이메일 주소를 입력해 주세요.'
		}
	};

	/** Switcher labels: short visible code plus the language's own name for screen readers. */
	var LANGUAGE_LABELS = {
		en: { short: 'EN', name: 'English' },
		id: { short: 'ID', name: 'Bahasa Indonesia' },
		ko: { short: '한국어', name: '' }
	};

	function format(template, values) {
		return template.replace(/\{(\w+)\}/g, function (_, key) {
			return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : '';
		});
	}

	/** Returns a normalised https URL if it is on an approved host, otherwise null. */
	function safeUrl(value, allowedHosts) {
		if (typeof value !== 'string' || value.length > 2048) return null;
		var url;
		try {
			url = new URL(value);
		} catch (e) {
			return null;
		}
		if (url.protocol !== 'https:' || url.username || url.password) return null;
		if (allowedHosts.indexOf(url.hostname.toLowerCase()) === -1) return null;
		return url.href;
	}

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) {
				node.setAttribute(key, attrs[key]);
			});
		}
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	}

	function storage() {
		try {
			return window.sessionStorage;
		} catch (e) {
			return null;
		}
	}

	function readLanguageChoice() {
		try {
			return window.localStorage.getItem(LANG_KEY);
		} catch (e) {
			return null;
		}
	}

	function saveLanguageChoice(code) {
		try {
			window.localStorage.setItem(LANG_KEY, code);
		} catch (e) {
			/* storage blocked: the choice lasts for this page only */
		}
	}

	/** Maps a BCP 47 tag (ko-KR, id, in-ID, en-GB) onto a supported language, or null. */
	function matchLanguage(tag, languages) {
		if (typeof tag !== 'string') return null;
		var primary = tag.toLowerCase().split(/[-_]/)[0];
		if (primary === 'in') primary = 'id'; // legacy code for Indonesian
		return languages.indexOf(primary) !== -1 ? primary : null;
	}

	/** Visitor's earlier choice, then the browser language (when enabled), then the admin default. */
	function resolveLanguage(config, languages, fallback) {
		if (config.switcher) {
			var saved = readLanguageChoice();
			if (languages.indexOf(saved) !== -1) return saved;
		}
		if (config.detectBrowser && window.navigator) {
			var tags = window.navigator.languages && window.navigator.languages.length ? window.navigator.languages : [window.navigator.language];
			for (var i = 0; i < tags.length; i++) {
				var match = matchLanguage(tags[i], languages);
				if (match) return match;
			}
		}
		return fallback;
	}

	function createWidget(config) {
		var defaultLang = STRINGS[config.language] ? config.language : 'en';
		var languages = (Array.isArray(config.languages) ? config.languages : []).filter(function (code, i, all) {
			return Object.prototype.hasOwnProperty.call(STRINGS, code) && all.indexOf(code) === i;
		});
		if (languages.indexOf(defaultLang) === -1) languages.unshift(defaultLang);
		var lang = resolveLanguage(config, languages, defaultLang);
		var t = STRINGS[lang];
		var maxLength = Number(config.maxLength) > 0 ? Number(config.maxLength) : 500;
		var sourceHosts = Array.isArray(config.sourceHosts) ? config.sourceHosts : [];
		var contactHosts = Array.isArray(config.contactHosts) ? config.contactHosts : [];
		var contactUrl = safeUrl(config.contactUrl, contactHosts);
		var history = [];
		var pending = false;
		var lastQuestion = '';

		/* ---------- DOM ---------- */
		var root = el('div', { id: 'wkc-root', class: 'wkc', lang: lang });

		var launcher = el('button', {
			type: 'button',
			class: 'wkc-launcher',
			'aria-expanded': 'false',
			'aria-controls': 'wkc-panel',
			'aria-label': t.open
		});
		launcher.appendChild(chatIcon());

		var panel = el('section', { id: 'wkc-panel', class: 'wkc-panel', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'wkc-title', 'aria-describedby': 'wkc-subtitle' });
		panel.hidden = true;

		var header = el('div', { class: 'wkc-header' });
		var headings = el('div', { class: 'wkc-headings' });
		var title = el('h2', { id: 'wkc-title', class: 'wkc-title' }, t.title);
		var subtitle = el('p', { id: 'wkc-subtitle', class: 'wkc-subtitle' }, t.subtitle);
		headings.appendChild(title);
		headings.appendChild(subtitle);
		var closeBtn = el('button', { type: 'button', class: 'wkc-close', 'aria-label': t.close });
		closeBtn.appendChild(el('span', { 'aria-hidden': 'true' }, '×'));
		header.appendChild(headings);

		var switcher = null;
		if (config.switcher && languages.length > 1) {
			switcher = el('div', { class: 'wkc-langs', role: 'group', 'aria-label': t.language });
			languages.forEach(function (code) {
				var labels = LANGUAGE_LABELS[code] || { short: code.toUpperCase(), name: '' };
				var btn = el('button', { type: 'button', class: 'wkc-lang', lang: code, 'data-lang': code, 'aria-pressed': 'false' }, labels.short);
				if (labels.name) btn.appendChild(el('span', { class: 'wkc-sr-only' }, ' ' + labels.name));
				btn.addEventListener('click', function () {
					if (code === lang) return;
					saveLanguageChoice(code);
					applyLanguage(code);
				});
				switcher.appendChild(btn);
			});
			header.appendChild(switcher);
		}

		var clearBtn = el('button', { type: 'button', class: 'wkc-clear', 'aria-label': t.clear, title: t.clear });
		clearBtn.appendChild(el('span', { 'aria-hidden': 'true' }, '↺'));
		clearBtn.addEventListener('click', function () {
			history = [];
			persist();
			while (log.firstChild) log.removeChild(log.firstChild);
			renderGreeting();
			renderSuggestions();
			input.value = '';
			updateCounter();
			formError.textContent = '';
			input.focus();
		});
		header.appendChild(clearBtn);
		header.appendChild(closeBtn);

		var log = el('div', { class: 'wkc-log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', 'aria-label': t.title, tabindex: '0' });
		var status = el('div', { class: 'wkc-sr-only', role: 'status', 'aria-live': 'polite' });

		var suggestions = el('div', { class: 'wkc-suggestions', role: 'group', 'aria-label': t.suggestions });

		var form = el('form', { class: 'wkc-form', novalidate: 'novalidate' });
		var label = el('label', { for: 'wkc-input', class: 'wkc-sr-only' }, t.inputLabel);
		var input = el('textarea', { id: 'wkc-input', class: 'wkc-input', rows: '1', maxlength: String(maxLength), placeholder: t.placeholder, 'aria-describedby': 'wkc-counter wkc-form-error', autocomplete: 'off' });
		var sendBtn = el('button', { type: 'submit', class: 'wkc-send' });
		sendBtn.appendChild(sendIcon());
		var sendLabel = el('span', { class: 'wkc-send-label' }, t.send);
		sendBtn.appendChild(sendLabel);
		var meta = el('div', { class: 'wkc-form-meta' });
		var formError = el('p', { id: 'wkc-form-error', class: 'wkc-form-error', role: 'alert' });
		var counter = el('p', { id: 'wkc-counter', class: 'wkc-counter' }, format(t.counter, { n: 0, max: maxLength }));
		meta.appendChild(formError);
		meta.appendChild(counter);
		form.appendChild(label);
		var row = el('div', { class: 'wkc-input-row' });
		row.appendChild(input);
		row.appendChild(sendBtn);
		form.appendChild(row);
		form.appendChild(meta);

		var footer = el('div', { class: 'wkc-footer' });

		panel.appendChild(header);
		panel.appendChild(log);
		panel.appendChild(suggestions);
		panel.appendChild(status);
		panel.appendChild(form);
		panel.appendChild(footer);
		root.appendChild(panel);
		root.appendChild(launcher);
		document.body.appendChild(root);

		/* ---------- Rendering ---------- */
		function chatIcon() {
			var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('viewBox', '0 0 24 24');
			svg.setAttribute('aria-hidden', 'true');
			svg.setAttribute('focusable', 'false');
			var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			path.setAttribute('d', 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm3 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm5 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm5 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z');
			svg.appendChild(path);
			return svg;
		}

		function sendIcon() {
			var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('viewBox', '0 0 24 24');
			svg.setAttribute('aria-hidden', 'true');
			svg.setAttribute('focusable', 'false');
			var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			path.setAttribute('d', 'M3 20.5 21 12 3 3.5 3 10l12 2-12 2z');
			svg.appendChild(path);
			return svg;
		}

		function externalLink(href, text, className) {
			var a = el('a', { href: href, target: '_blank', rel: 'noopener noreferrer', class: className || '' }, text);
			a.appendChild(el('span', { class: 'wkc-sr-only' }, ' ' + t.newTab));
			return a;
		}

		function renderLeadForm(parentItem) {
			if (parentItem.querySelector('.wkc-lead-card')) return;
			var card = el('div', { class: 'wkc-lead-card' });
			var formTitle = el('p', { class: 'wkc-lead-title' }, t.requestConsult);
			card.appendChild(formTitle);

			var nameInput = el('input', { type: 'text', class: 'wkc-lead-input', placeholder: t.leadName });
			var emailInput = el('input', { type: 'email', class: 'wkc-lead-input', placeholder: t.leadEmail });
			var compInput = el('input', { type: 'text', class: 'wkc-lead-input', placeholder: t.leadCompany });
			var msgInput = el('textarea', { class: 'wkc-lead-textarea', rows: '2', placeholder: t.leadMessage });

			card.appendChild(nameInput);
			card.appendChild(emailInput);
			card.appendChild(compInput);
			card.appendChild(msgInput);

			var leadErr = el('div', { class: 'wkc-lead-error', 'aria-live': 'polite' });
			card.appendChild(leadErr);

			var btnRow = el('div', { class: 'wkc-lead-actions' });
			var submitBtn = el('button', { type: 'button', class: 'wkc-lead-submit' }, t.leadSubmit);
			var cancelBtn = el('button', { type: 'button', class: 'wkc-lead-cancel' }, t.leadCancel);

			cancelBtn.addEventListener('click', function () {
				card.remove();
			});

			submitBtn.addEventListener('click', function () {
				var nameVal = nameInput.value.trim();
				var emailVal = emailInput.value.trim();
				if (!nameVal || !emailVal || emailVal.indexOf('@') === -1) {
					leadErr.textContent = t.leadError;
					return;
				}
				submitBtn.disabled = true;
				cancelBtn.disabled = true;
				leadErr.textContent = '';

				var leadEndpoint = (config.restUrl || '').replace(/\/chat$/, '/lead');
				fetch(leadEndpoint, {
					method: 'POST',
					credentials: 'omit',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({
						name: nameVal,
						email: emailVal,
						company: compInput.value.trim(),
						message: msgInput.value.trim()
					})
				})
					.then(function (res) { return res.json(); })
					.then(function (data) {
						card.remove();
						addMessage({
							role: 'bot',
							text: (data && data.message) ? data.message : t.leadSuccess,
							grounded: true
						});
					})
					.catch(function () {
						submitBtn.disabled = false;
						cancelBtn.disabled = false;
						leadErr.textContent = t.errNetwork;
					});
			});

			btnRow.appendChild(submitBtn);
			btnRow.appendChild(cancelBtn);
			card.appendChild(btnRow);
			parentItem.appendChild(card);
			nameInput.focus();
		}

		function renderMessage(msg) {
			var item = el('div', { class: 'wkc-msg wkc-msg--' + msg.role });
			item.appendChild(el('span', { class: 'wkc-sr-only' }, msg.role === 'user' ? t.you : t.assistant));
			var bubble = el('div', { class: 'wkc-bubble' });
			String(msg.text).split(/\n{2,}/).forEach(function (para) {
				var lines = para.split(/\n/);
				var hasBullets = lines.some(function (l) { return /^[-*•]\s+/.test(l.trim()); });
				if (hasBullets) {
					var ul = el('ul', { class: 'wkc-bullet-list' });
					lines.forEach(function (line) {
						var trimmed = line.trim();
						if (/^[-*•]\s+/.test(trimmed)) {
							ul.appendChild(el('li', null, trimmed.replace(/^[-*•]\s+/, '')));
						} else if (trimmed) {
							ul.appendChild(el('li', null, trimmed));
						}
					});
					bubble.appendChild(ul);
				} else {
					var p = el('p');
					lines.forEach(function (line, idx) {
						if (idx > 0) p.appendChild(document.createElement('br'));
						p.appendChild(document.createTextNode(line));
					});
					bubble.appendChild(p);
				}
			});
			item.appendChild(bubble);

			if (msg.role === 'bot') {
				var sources = (Array.isArray(msg.sources) ? msg.sources : [])
					.map(function (s) {
						return s && typeof s.title === 'string' ? { title: s.title, url: safeUrl(s.url, sourceHosts) } : null;
					})
					.filter(function (s) {
						return s && s.url;
					});
				if (sources.length) {
					var wrap = el('div', { class: 'wkc-sources' });
					wrap.appendChild(el('p', { class: 'wkc-sources-label' }, t.sources));
					var list = el('ul');
					sources.forEach(function (s) {
						var li = el('li');
						li.appendChild(externalLink(s.url, s.title));
						list.appendChild(li);
					});
					wrap.appendChild(list);
					item.appendChild(wrap);
				}
				if (!msg.grounded && !msg.isGreeting) {
					if (contactUrl) {
						var cta = el('p', { class: 'wkc-cta' });
						cta.appendChild(externalLink(contactUrl, t.contactPrompt));
						item.appendChild(cta);
					}
					var leadAction = el('div', { class: 'wkc-lead-trigger' });
					var consultBtn = el('button', { type: 'button', class: 'wkc-lead-btn' }, t.requestConsult);
					consultBtn.addEventListener('click', function () {
						renderLeadForm(item);
					});
					leadAction.appendChild(consultBtn);
					item.appendChild(leadAction);
				}
			}
			log.appendChild(item);
			log.scrollTop = log.scrollHeight;
			return item;
		}

		function renderError(text, retryable) {
			var item = el('div', { class: 'wkc-msg wkc-msg--error' });
			var bubble = el('div', { class: 'wkc-bubble' });
			var p = el('p');
			p.appendChild(el('strong', { class: 'wkc-error-label' }, '⚠ ' + t.errorLabel + ' '));
			p.appendChild(document.createTextNode(text));
			bubble.appendChild(p);
			if (retryable && lastQuestion) {
				var retry = el('button', { type: 'button', class: 'wkc-retry' }, t.retry);
				retry.addEventListener('click', function () {
					item.remove();
					send(lastQuestion);
				});
				bubble.appendChild(retry);
			}
			if (contactUrl) {
				var cta = el('p', { class: 'wkc-cta' });
				cta.appendChild(externalLink(contactUrl, t.contact));
				bubble.appendChild(cta);
			}
			item.appendChild(bubble);
			log.appendChild(item);
			log.scrollTop = log.scrollHeight;
		}

		function greetingFor(code) {
			if (config.greetings && typeof config.greetings[code] === 'string') return config.greetings[code];
			return typeof config.greeting === 'string' ? config.greeting : '';
		}

		function suggestionsFor(code) {
			if (Array.isArray(config.suggestions)) return config.suggestions;
			return config.suggestions && Array.isArray(config.suggestions[code]) ? config.suggestions[code] : [];
		}

		var greetingItem = null;
		function renderGreeting() {
			if (greetingItem) greetingItem.remove();
			greetingItem = null;
			var text = greetingFor(lang);
			if (!text) return;
			greetingItem = renderMessage({ role: 'bot', text: text, grounded: true, isGreeting: true });
			log.insertBefore(greetingItem, log.firstChild);
		}

		function renderSuggestions() {
			while (suggestions.firstChild) suggestions.removeChild(suggestions.firstChild);
			suggestionsFor(lang).slice(0, 4).forEach(function (q) {
				if (typeof q !== 'string' || !q) return;
				var chip = el('button', { type: 'button', class: 'wkc-chip' }, q);
				chip.disabled = pending;
				chip.addEventListener('click', function () {
					submit(q);
				});
				suggestions.appendChild(chip);
			});
		}

		function renderFooter() {
			while (footer.firstChild) footer.removeChild(footer.firstChild);
			if (contactUrl) footer.appendChild(externalLink(contactUrl, t.contact, 'wkc-contact'));
			footer.appendChild(el('p', { class: 'wkc-disclaimer' }, t.disclaimer));
		}

		/** Switches every interface string to another language; the conversation is kept. */
		function applyLanguage(code) {
			lang = code;
			t = STRINGS[code];
			root.setAttribute('lang', code);
			title.textContent = t.title;
			subtitle.textContent = t.subtitle;
			closeBtn.setAttribute('aria-label', t.close);
			if (clearBtn) {
				clearBtn.setAttribute('aria-label', t.clear);
				clearBtn.setAttribute('title', t.clear);
			}
			launcher.setAttribute('aria-label', panel.hidden ? t.open : t.close);
			log.setAttribute('aria-label', t.title);
			suggestions.setAttribute('aria-label', t.suggestions);
			label.textContent = t.inputLabel;
			input.setAttribute('placeholder', t.placeholder);
			sendLabel.textContent = t.send;
			formError.textContent = '';
			if (switcher) {
				switcher.setAttribute('aria-label', t.language);
				Array.prototype.forEach.call(switcher.querySelectorAll('button'), function (b) {
					b.setAttribute('aria-pressed', b.getAttribute('data-lang') === code ? 'true' : 'false');
				});
			}
			updateCounter();
			renderFooter();
			renderSuggestions();
			renderGreeting();
		}

		function renderTyping() {
			var item = el('div', { class: 'wkc-msg wkc-msg--bot wkc-typing', 'aria-hidden': 'true' });
			var bubble = el('div', { class: 'wkc-bubble' });
			for (var i = 0; i < 3; i++) bubble.appendChild(el('span', { class: 'wkc-dot' }));
			item.appendChild(bubble);
			log.appendChild(item);
			log.scrollTop = log.scrollHeight;
			return item;
		}

		/* ---------- State ---------- */
		function persist() {
			var store = storage();
			if (!store) return;
			try {
				store.setItem(STORAGE_KEY, JSON.stringify(history.slice(-MAX_HISTORY)));
			} catch (e) {
				/* storage full or blocked: history simply is not kept */
			}
		}

		function restore() {
			var store = storage();
			if (!store) return [];
			try {
				var parsed = JSON.parse(store.getItem(STORAGE_KEY) || '[]');
				return Array.isArray(parsed)
					? parsed.filter(function (m) {
							return m && (m.role === 'user' || m.role === 'bot') && typeof m.text === 'string';
						}).slice(-MAX_HISTORY)
					: [];
			} catch (e) {
				return [];
			}
		}

		function addMessage(msg) {
			history.push(msg);
			persist();
			renderMessage(msg);
		}

		function setPending(value) {
			pending = value;
			sendBtn.disabled = value;
			input.setAttribute('aria-disabled', value ? 'true' : 'false');
			log.setAttribute('aria-busy', value ? 'true' : 'false');
			Array.prototype.forEach.call(suggestions.querySelectorAll('button'), function (b) {
				b.disabled = value;
			});
		}

		function updateCounter() {
			var n = input.value.length;
			// Grow with the content up to the CSS max-height, then scroll.
			input.style.height = 'auto';
			if (input.scrollHeight > input.clientHeight) input.style.height = Math.min(input.scrollHeight + 2, 120) + 'px';
			counter.textContent = format(t.counter, { n: n, max: maxLength });
			counter.classList.toggle('wkc-counter--near', n > maxLength * 0.9);
		}

		/* ---------- Networking ---------- */
		function errorMessageFor(status) {
			if (status === 429) return t.errRate;
			return t.errGeneric;
		}

		function send(question) {
			if (pending) return;
			lastQuestion = question;
			setPending(true);
			status.textContent = t.typing;
			var typing = renderTyping();

			var controller = typeof AbortController === 'function' ? new AbortController() : null;
			var timer = setTimeout(function () {
				if (controller) controller.abort();
			}, REQUEST_TIMEOUT_MS);

			var payload = { message: question, language: lang };
			var prevHistory = history.slice(0, -1);
			var recentHistory = prevHistory
				.filter(function (m) {
					return m && !m.isGreeting && (m.role === 'user' || m.role === 'bot') && typeof m.text === 'string';
				})
				.slice(-4)
				.map(function (m) {
					return { role: m.role === 'bot' ? 'model' : 'user', text: m.text.slice(0, m.role === 'bot' ? 1500 : 500) };
				});
			if (recentHistory.length > 0) {
				payload.history = recentHistory;
			}

			fetch(config.restUrl, {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify(payload),
				signal: controller ? controller.signal : undefined
			})
				.then(function (res) {
					return res
						.json()
						.catch(function () {
							return null;
						})
						.then(function (data) {
							return { ok: res.ok, status: res.status, data: data };
						});
				})
				.then(function (result) {
					typing.remove();
					var d = result.data;
					if (result.ok && d && typeof d.answer === 'string') {
						addMessage({ role: 'bot', text: d.answer, sources: Array.isArray(d.sources) ? d.sources : [], grounded: d.grounded === true });
						status.textContent = t.received;
					} else {
						var serverMsg = d && typeof d.message === 'string' && result.status >= 400 && result.status < 500 && result.status !== 403 ? d.message : null;
						renderError(serverMsg || errorMessageFor(result.status), result.status !== 400);
						status.textContent = t.errorLabel + ' ' + (serverMsg || errorMessageFor(result.status));
					}
				})
				.catch(function (err) {
					typing.remove();
					var msg = err && err.name === 'AbortError' ? t.errTimeout : t.errNetwork;
					renderError(msg, true);
					status.textContent = t.errorLabel + ' ' + msg;
				})
				.then(function () {
					clearTimeout(timer);
					setPending(false);
				});
		}

		function submit(raw) {
			if (pending) return;
			var question = String(raw || '').replace(/\s+/g, ' ').trim();
			formError.textContent = '';
			if (!question) {
				formError.textContent = t.empty;
				input.focus();
				return;
			}
			if (question.length > maxLength) {
				formError.textContent = format(t.tooLong, { max: maxLength });
				input.focus();
				return;
			}
			suggestions.hidden = true;
			input.value = '';
			updateCounter();
			addMessage({ role: 'user', text: question });
			send(question);
		}

		/* ---------- Open / close ---------- */
		function open() {
			panel.hidden = false;
			root.classList.add('wkc--open');
			launcher.setAttribute('aria-expanded', 'true');
			launcher.setAttribute('aria-label', t.close);
			log.scrollTop = log.scrollHeight;
			input.focus();
		}

		function close() {
			panel.hidden = true;
			root.classList.remove('wkc--open');
			launcher.setAttribute('aria-expanded', 'false');
			launcher.setAttribute('aria-label', t.open);
			launcher.focus();
		}

		launcher.addEventListener('click', function () {
			if (panel.hidden) open();
			else close();
		});
		closeBtn.addEventListener('click', close);
		root.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !panel.hidden) {
				e.stopPropagation();
				close();
			}
		});
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			submit(input.value);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				submit(input.value);
			}
		});
		input.addEventListener('input', function () {
			formError.textContent = '';
			updateCounter();
		});

		/* ---------- Initial state ---------- */
		applyLanguage(lang);
		var restored = restore();
		if (restored.length) {
			history = restored;
			suggestions.hidden = true;
			restored.forEach(renderMessage);
		}

		return {
			root: root,
			open: open,
			close: close,
			submit: submit,
			setLanguage: function (code) {
				if (languages.indexOf(code) !== -1) applyLanguage(code);
			},
			getLanguage: function () {
				return lang;
			}
		};
	}

	function init() {
		var config = window.wpKnowledgeChatConfig;
		if (!config || typeof config.restUrl !== 'string' || document.getElementById('wkc-root')) return;
		window.wpKnowledgeChat = createWidget(config);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
