/* eslint-disable */
/**
 * RTO Compliance — floating AI assistant widget (v5.9.456).
 *
 * A polished bottom-right chat assistant. Reads its config from #rtoc-asst-root's
 * data-* attributes (endpoint, sesskey, page, credit cost, remaining credits) that
 * the before_footer hook injects, builds the bubble + chat panel, and talks to
 * assistant.php. No inline script (CSP-safe); all styling comes from the injected
 * stylesheet. Grounded, Claude-powered answers — one credit per question.
 */
(function () {
    'use strict';

    var root = document.getElementById('rtoc-asst-root');
    if (!root || root.dataset.mounted === '1') { return; }
    root.dataset.mounted = '1';

    var ENDPOINT = root.getAttribute('data-endpoint');
    var SESSKEY  = root.getAttribute('data-sesskey');
    var PAGE     = root.getAttribute('data-page') || '';
    var COST     = root.getAttribute('data-cost') || '1';
    var CREDITS  = root.getAttribute('data-credits') || '';

    var history = [];     // {role:'user'|'assistant', content:''}
    var busy = false;

    // ---- helpers ----------------------------------------------------------
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (html != null) { e.innerHTML = html; }
        return e;
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // Minimal, SAFE markdown → HTML (escape first, then a few inline/block rules).
    function renderMarkdown(md) {
        var text = escapeHtml(md).replace(/\r\n/g, '\n');
        // Code fences ```...```
        text = text.replace(/```([\s\S]*?)```/g, function (_, c) {
            return '<pre class="rtoc-asst-pre"><code>' + c.replace(/^\n/, '') + '</code></pre>';
        });
        var lines = text.split('\n');
        var out = [];
        var inUl = false, inOl = false;
        function closeLists() {
            if (inUl) { out.push('</ul>'); inUl = false; }
            if (inOl) { out.push('</ol>'); inOl = false; }
        }
        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            if (/^\s*[-*]\s+/.test(ln)) {
                if (!inUl) { closeLists(); out.push('<ul class="rtoc-asst-ul">'); inUl = true; }
                out.push('<li>' + inline(ln.replace(/^\s*[-*]\s+/, '')) + '</li>');
            } else if (/^\s*\d+[.)]\s+/.test(ln)) {
                if (!inOl) { closeLists(); out.push('<ol class="rtoc-asst-ol">'); inOl = true; }
                out.push('<li>' + inline(ln.replace(/^\s*\d+[.)]\s+/, '')) + '</li>');
            } else if (/^\s*#{1,4}\s+/.test(ln)) {
                closeLists();
                out.push('<div class="rtoc-asst-h">' + inline(ln.replace(/^\s*#{1,4}\s+/, '')) + '</div>');
            } else if (ln.trim() === '') {
                closeLists();
                out.push('');
            } else if (/^<pre/.test(ln) || /^<\/pre|^<code|^<\/code/.test(ln)) {
                out.push(ln);
            } else {
                closeLists();
                out.push('<p>' + inline(ln) + '</p>');
            }
        }
        closeLists();
        return out.join('\n');
    }
    function inline(s) {
        return s
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener">$1</a>');
    }

    // ---- build DOM --------------------------------------------------------
    var fab = el('button', 'rtoc-asst-fab', ''
        + '<span class="rtoc-asst-fab-ic">'
        +   '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4z"/><path d="M18 14l.9 2.2L21 17l-2.1.8L18 20l-.9-2.2L15 17l2.1-.8z"/></svg>'
        + '</span>'
        + '<span class="rtoc-asst-fab-lbl">Ask AI</span>');
    fab.setAttribute('type', 'button');
    fab.setAttribute('aria-label', 'Open the RTO Compliance assistant');

    var panel = el('div', 'rtoc-asst-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'RTO Compliance assistant');

    var creditsChip = CREDITS !== '' ? '<span class="rtoc-asst-credits" title="Credits remaining">' + escapeHtml(CREDITS) + ' credits</span>' : '';
    panel.appendChild(el('div', 'rtoc-asst-head', ''
        + '<div class="rtoc-asst-head-l">'
        +   '<div class="rtoc-asst-orb"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4z"/></svg></div>'
        +   '<div><div class="rtoc-asst-ttl">RTO Compliance Assistant</div>'
        +   '<div class="rtoc-asst-sub">Powered by Claude · knows your software</div></div>'
        + '</div>'
        + '<div class="rtoc-asst-head-r">' + creditsChip
        +   '<button type="button" class="rtoc-asst-x" aria-label="Close">&times;</button></div>'));

    var body = el('div', 'rtoc-asst-body');
    panel.appendChild(body);

    var foot = el('div', 'rtoc-asst-foot');
    foot.appendChild(el('div', 'rtoc-asst-cost', ''
        + '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> '
        + '<span>' + escapeHtml(COST) + ' credit per question</span>'));
    var inputRow = el('div', 'rtoc-asst-inrow');
    var ta = el('textarea', 'rtoc-asst-input');
    ta.setAttribute('rows', '1');
    ta.setAttribute('placeholder', 'Ask anything about RTO Compliance…');
    var send = el('button', 'rtoc-asst-send', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>');
    send.setAttribute('type', 'button');
    send.setAttribute('aria-label', 'Send');

    // Voice input (Web Speech API). Added only where the browser supports speech
    // recognition, so it simply does not appear elsewhere (graceful degrade). Works on
    // Chrome/Edge (desktop + Android) and Safari (iOS 14.5+). HTTPS + mic permission.
    var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
    var mic = null;
    if (SpeechRec) {
        mic = el('button', 'rtoc-asst-mic', '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>');
        mic.setAttribute('type', 'button');
        mic.setAttribute('aria-label', 'Dictate your question');
        mic.setAttribute('title', 'Tap to speak your question');
    }
    inputRow.appendChild(ta);
    if (mic) { inputRow.appendChild(mic); }
    inputRow.appendChild(send);
    foot.appendChild(inputRow);
    panel.appendChild(foot);

    root.appendChild(panel);
    root.appendChild(fab);

    // ---- greeting + suggestions ------------------------------------------
    function addMsg(role, html, opts) {
        opts = opts || {};
        var wrap = el('div', 'rtoc-asst-msg ' + (role === 'user' ? 'is-user' : 'is-bot'));
        if (role !== 'user') {
            wrap.appendChild(el('div', 'rtoc-asst-av', '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4z"/></svg>'));
        }
        var b = el('div', 'rtoc-asst-bub' + (opts.error ? ' is-error' : ''), html);
        wrap.appendChild(b);
        body.appendChild(wrap);
        body.scrollTop = body.scrollHeight;
        return b;
    }

    function greet() {
        addMsg('bot', '<p>Hi! I\'m your RTO Compliance assistant — I know every page and feature of this plugin. '
            + 'Ask me how to do anything: issuing certificates, verifying USIs, building qualifications, syncing results, NAT exports, and more.</p>');
        var chips = el('div', 'rtoc-asst-chips');
        var qs = [
            'How do I issue certificates for a qualification?',
            'How do I verify a student\'s USI?',
            'Create the S1/S2 archive versions of a qual',
            'Why is my certificate template not saving?'
        ];
        qs.forEach(function (q) {
            var c = el('button', 'rtoc-asst-chip', escapeHtml(q));
            c.setAttribute('type', 'button');
            c.addEventListener('click', function () { ta.value = q; submit(); });
            chips.appendChild(c);
        });
        body.appendChild(chips);
    }

    // ---- page context -----------------------------------------------------
    // Send only the ids of the record on screen, never the raw query string: the server has
    // no use for anything else, and a whitelist built here means nothing free-form from the
    // address bar is transmitted at all. Read back with PARAM_INT server-side.
    var PAGE_PARAM_KEYS = ['qualid', 'courseid', 'userid', 'studentid', 'certid'];

    function pageParams() {
        var out = {};
        try {
            var qs = new URLSearchParams(window.location.search || '');
            PAGE_PARAM_KEYS.forEach(function (key) {
                var val = parseInt(qs.get(key), 10);
                if (!isNaN(val) && val > 0) {
                    out[key] = val;
                }
            });
        } catch (e) {
            return {};
        }
        return out;
    }

    // ---- send flow --------------------------------------------------------
    function setBusy(v) {
        busy = v;
        send.disabled = v;
        send.classList.toggle('is-busy', v);
    }
    function typingRow() {
        var wrap = el('div', 'rtoc-asst-msg is-bot');
        wrap.appendChild(el('div', 'rtoc-asst-av', '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4z"/></svg>'));
        wrap.appendChild(el('div', 'rtoc-asst-bub', '<span class="rtoc-asst-dots"><i></i><i></i><i></i></span>'));
        body.appendChild(wrap);
        body.scrollTop = body.scrollHeight;
        return wrap;
    }

    function submit() {
        if (busy) { return; }
        var q = ta.value.trim();
        if (!q) { return; }
        ta.value = '';
        autoGrow();
        // Remove any suggestion chips once a conversation starts.
        var chips = body.querySelector('.rtoc-asst-chips');
        if (chips) { chips.remove(); }

        addMsg('user', escapeHtml(q).replace(/\n/g, '<br>'));
        history.push({ role: 'user', content: q });
        setBusy(true);
        var typing = typingRow();

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sesskey: SESSKEY, page: PAGE, pageparams: pageParams(), messages: history })
        }).then(function (r) { return r.json(); }).then(function (data) {
            typing.remove();
            setBusy(false);
            if (data && data.ok && data.reply) {
                addMsg('bot', renderMarkdown(data.reply));
                history.push({ role: 'assistant', content: data.reply });
                if (data.credits != null) {
                    var chip = panel.querySelector('.rtoc-asst-credits');
                    if (!chip) {
                        chip = el('span', 'rtoc-asst-credits');
                        panel.querySelector('.rtoc-asst-head-r').insertBefore(chip, panel.querySelector('.rtoc-asst-x'));
                    }
                    chip.textContent = data.credits + ' credits';
                }
            } else {
                addMsg('bot', escapeHtml((data && data.error) || 'Sorry — I could not answer that just now.'), { error: true });
            }
        }).catch(function () {
            typing.remove();
            setBusy(false);
            addMsg('bot', 'Network error — please try again.', { error: true });
        });
    }

    // ---- interactions -----------------------------------------------------
    function autoGrow() {
        ta.style.height = 'auto';
        ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
    }
    ta.addEventListener('input', autoGrow);
    ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
    });
    send.addEventListener('click', submit);

    // ---- voice input (Web Speech API) ------------------------------------
    var recognition = null, listening = false;
    function stopListening() { if (recognition) { try { recognition.stop(); } catch (e) {} } }
    if (mic && SpeechRec) {
        var setListening = function (v) {
            listening = v;
            mic.classList.toggle('is-listening', v);
            mic.setAttribute('aria-pressed', v ? 'true' : 'false');
            mic.setAttribute('title', v ? 'Listening… tap to stop' : 'Tap to speak your question');
        };
        mic.addEventListener('click', function () {
            if (listening) { stopListening(); return; }
            try { recognition = new SpeechRec(); } catch (e) { return; }
            recognition.lang = document.documentElement.lang || navigator.language || 'en-AU';
            recognition.interimResults = true;
            recognition.continuous = false;
            recognition.maxAlternatives = 1;
            // Preserve anything already typed; dictation appends to it.
            var baseText = ta.value.replace(/\s+$/, '');
            if (baseText) { baseText += ' '; }
            recognition.onstart = function () { setListening(true); };
            recognition.onerror = function (ev) {
                setListening(false);
                if (ev && (ev.error === 'not-allowed' || ev.error === 'service-not-allowed')) {
                    mic.setAttribute('title', 'Microphone blocked — allow mic access in your browser, then try again.');
                }
            };
            recognition.onend = function () { setListening(false); ta.focus(); };
            recognition.onresult = function (ev) {
                var interim = '', finalt = '';
                for (var i = ev.resultIndex; i < ev.results.length; i++) {
                    var tr = ev.results[i][0].transcript;
                    if (ev.results[i].isFinal) { finalt += tr; } else { interim += tr; }
                }
                ta.value = baseText + finalt + interim;
                autoGrow();
                if (finalt) { baseText = ta.value.replace(/\s+$/, '') + ' '; }
            };
            try { recognition.start(); } catch (e) { setListening(false); }
        });
    }

    var opened = false;
    function toggle(open) {
        if (!open) { stopListening(); }
        root.classList.toggle('is-open', open);
        if (open && !opened) { opened = true; greet(); }
        if (open) { setTimeout(function () { ta.focus(); }, 120); }
    }
    fab.addEventListener('click', function () { toggle(!root.classList.contains('is-open')); });
    panel.querySelector('.rtoc-asst-x').addEventListener('click', function () { toggle(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('is-open')) { toggle(false); }
    });
})();
