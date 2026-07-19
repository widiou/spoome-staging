/* Spoome v2 — runtime frontend (vanilla JS, zero dipendenze).
 * Espone window.Spoome: client API centralizzato + toast + client async dichiarativo.
 * La UI resta pienamente funzionante senza JS: qui si aggiunge solo enhancement.
 *
 * Client async centralizzato: un solo dispatcher delegato su `document` intercetta i
 * `form[data-async]`, invia via Spoome.api({csrf:true}) e applica gli effetti dichiarati
 * in `data-async-success` (componibili) o l'hook `data-async-handler` (comment/dm/composer).
 * Aggiungere una nuova azione async = attributi nella vista, zero JS per-feature. */
(function () {
    'use strict';

    var Spoome = {};

    /* ------------------------------------------------------------ meta ---- */
    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') || '' : '';
    }
    Spoome.basePath = function () { return meta('base-path'); };
    Spoome.csrf = function () { return meta('csrf-token'); };

    /* ------------------------------------------------- client API (fetch) ---- */
    /**
     * Chiamata all'API/rotte interne con gestione uniforme dell'envelope { data | errors }.
     * @param {string} path  es. "/api/v1/profiles" (prefissato col base-path se relativo)
     * @param {object} opts  { method, body, token, csrf, headers }
     * @returns {Promise<any>} il campo `data` in caso di successo
     * @throws {Error} con .status, .fields, .payload in caso di errore
     */
    Spoome.api = function (path, opts) {
        opts = opts || {};
        var headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
        var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };

        if (opts.body != null) {
            if (typeof FormData !== 'undefined' && opts.body instanceof FormData) {
                init.body = opts.body; // il browser imposta il boundary
            } else {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(opts.body);
            }
        }
        if (opts.token) { headers['Authorization'] = 'Bearer ' + opts.token; }
        if (opts.csrf) { headers['X-CSRF-Token'] = Spoome.csrf(); }

        var bp = Spoome.basePath();
        var url = /^https?:\/\//.test(path) ? path
            : (bp && path.indexOf(bp + '/') === 0 ? path : (bp + path));

        return fetch(url, init).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (json) {
                if (!res.ok) {
                    var first = json && json.errors && json.errors[0];
                    var err = new Error((first && first.title) || ('HTTP ' + res.status));
                    err.status = res.status;
                    err.fields = (first && first.fields) || {};
                    err.payload = json;
                    throw err;
                }
                return json ? json.data : null;
            });
        });
    };

    /* ------------------------------------------------------------ toast ---- */
    /** Notifica effimera non bloccante. type: 'success' | 'error' | 'info'. */
    Spoome.toast = function (message, type, options) {
        options = options || {};
        var host = document.querySelector('.toast-host');
        if (!host) {
            host = document.createElement('div');
            host.className = 'toast-host';
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        var el = document.createElement('div');
        el.className = 'toast toast-' + (type || 'info');
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.textContent = message;
        host.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('is-in'); });

        var timeout = options.timeout == null ? 4200 : options.timeout;
        function dismiss() {
            el.classList.remove('is-in');
            el.addEventListener('transitionend', function () { el.remove(); }, { once: true });
            setTimeout(function () { if (el.parentNode) el.remove(); }, 500);
        }
        el.addEventListener('click', dismiss);
        if (timeout) { setTimeout(dismiss, timeout); }
        return el;
    };

    window.Spoome = Spoome;

    /* ====================== client async dichiarativo (dispatcher) ====================== */

    var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function primaryButton(form) {
        return form.querySelector('[data-submit]')
            || form.querySelector('button[type="submit"]')
            || form.querySelector('button:not([type])');
    }

    function setLoading(btn, on) {
        if (!btn) return;
        btn.classList.toggle('is-loading', on);
        if (on) { btn.setAttribute('aria-busy', 'true'); btn.disabled = true; }
        else { btn.removeAttribute('aria-busy'); btn.disabled = false; }
    }

    /** Marca (o pulisce) gli errori per-campo dall'envelope { fields }. */
    function markFieldErrors(form, fields) {
        form.querySelectorAll('[aria-invalid="true"]').forEach(function (n) {
            n.removeAttribute('aria-invalid');
            var wrap = n.closest('.field'); if (wrap) wrap.classList.remove('has-error');
        });
        if (!fields) return;
        Object.keys(fields).forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) return;
            input.setAttribute('aria-invalid', 'true');
            var wrap = input.closest('.field'); if (wrap) wrap.classList.add('has-error');
        });
    }

    /* --------------------------------------------------- effetti componibili ---- */

    // toggleState: bottone on/off (like, follow, endorse). Legge data[data-state-key].
    function fx_toggleState(ctx) {
        var form = ctx.form, data = ctx.data || {}, btn = ctx.button;
        var key = form.getAttribute('data-state-key');
        var on = !!data[key];
        if (btn) {
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            var classes = form.getAttribute('data-toggle-classes'); // "offClass/onClass"
            if (classes) {
                var p = classes.split('/');
                if (p[0]) btn.classList.toggle(p[0], !on);
                if (p[1]) btn.classList.toggle(p[1], on);
            }
            var labOn = btn.getAttribute('data-label-on');
            var labOff = btn.getAttribute('data-label-off');
            if (labOn !== null || labOff !== null) {
                var txt = on ? labOn : labOff;
                var labSel = form.getAttribute('data-label-el');
                var labEl = labSel ? (btn.querySelector(labSel) || form.querySelector(labSel)) : null;
                if (labEl) { labEl.textContent = txt; }
                else if (txt !== null) { btn.setAttribute('aria-label', txt); }
            }
        }
        // Riscrive l'action per il prossimo submit: "actionToTurnOn|actionToTurnOff".
        var toggleAction = form.getAttribute('data-toggle-action');
        if (toggleAction) {
            var parts = toggleAction.split('|');
            form.setAttribute('action', on ? (parts[1] || parts[0]) : parts[0]);
        }
    }

    // updateCount: scrive data[key] nei nodi selezionati (o applica un delta se il server non lo ritorna).
    function fx_updateCount(ctx) {
        var form = ctx.form, data = ctx.data || {};
        var sel = form.getAttribute('data-count-selector');
        if (!sel) return;
        var key = form.getAttribute('data-count-key') || 'count';
        var scopeAttr = form.getAttribute('data-count-scope');
        var root = !scopeAttr ? form
            : (scopeAttr === 'document' ? document : (form.closest(scopeAttr) || document));
        var nodes = root.querySelectorAll(sel);
        if (!nodes.length) return;
        var hasVal = typeof data[key] !== 'undefined';
        var delta = form.getAttribute('data-count-delta');
        nodes.forEach(function (n) {
            if (hasVal) { n.textContent = data[key]; }
            else if (delta != null) { n.textContent = String((parseInt(n.textContent, 10) || 0) + parseInt(delta, 10)); }
        });
        // flag opzionale (es. .is-top sulla skill quando il conteggio > 0)
        var flag = form.getAttribute('data-count-flag');
        if (flag && hasVal) {
            var el = form.closest(flag);
            if (el) el.classList.toggle('is-top', Number(data[key]) > 0);
        }
        // reveal opzionale: mostra/nasconde un nodo (es. la riga "Mi piace") secondo il conteggio.
        var reveal = form.getAttribute('data-count-reveal');
        if (reveal && hasVal) {
            var scopeEl = form.closest('.feed-item') || document;
            var host = scopeEl.querySelector(reveal);
            if (host) host.hidden = !(Number(data[key]) > 0);
        }
    }

    function resolveTarget(form) {
        var sel = form.getAttribute('data-target');
        if (sel) { return form.closest(sel) || document.querySelector(sel); }
        return form.closest('[data-async-card]') || form;
    }

    // removeCard: collapse animato (rispetta prefers-reduced-motion) poi .remove(); focus safe.
    function fx_removeCard(ctx) {
        var card = resolveTarget(ctx.form);
        if (!card || card.classList.contains('is-dismissing')) return;

        // Sposta il focus a un elemento vivo prima di rimuovere (no focus nel vuoto).
        var section = card.closest('section, .net-section, .feed-list, .item-list, .edit-skill-list');
        var focusable = section && section.querySelector('h1, h2, [tabindex], a, button');
        if (focusable && card.contains(document.activeElement)) {
            try { focusable.focus({ preventScroll: true }); } catch (e) { try { focusable.focus(); } catch (e2) {} }
        }

        var done = false;
        function finish() {
            if (done) return;
            done = true;
            var parent = card.parentNode;
            card.remove();
            // Griglia suggerimenti svuotata: rimuovi l'intera sezione (il server non la renderizzerebbe).
            if (parent && parent.hasAttribute && parent.hasAttribute('data-suggest-grid')
                && !parent.querySelector('[data-suggest-card]')) {
                var sec = parent.closest('.net-section');
                if (sec) sec.remove();
            }
            // Lista richieste di connessione svuotata: rimuovi l'intera sezione.
            if (parent && parent.classList && parent.classList.contains('req-list')
                && !parent.querySelector('[data-req-item]')) {
                var sec2 = parent.closest('.net-section');
                if (sec2) sec2.remove();
            }
        }

        if (prefersReduced) { finish(); return; }
        var h = card.offsetHeight;
        card.style.maxHeight = h + 'px';
        card.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            card.classList.add('is-dismissing');
            card.style.maxHeight = '0px';
            card.style.marginTop = '0px';
            card.style.marginBottom = '0px';
            card.style.paddingTop = '0px';
            card.style.paddingBottom = '0px';
            card.style.borderWidth = '0px';
        });
        card.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 500);
    }

    // replaceHtml/appendHtml: inserisce un frammento server-rendered da data.html.
    // INVARIANTE DI SICUREZZA: `data.html` DEVE essere un frammento renderizzato dal server ed escapato
    // con e() su OGNI campo dinamico (lo STESSO partial usato per la lista iniziale) — mai costruito da
    // input client, mai da una sorgente non-escapata. È ciò che rende sicura questa iniezione di markup.
    function insertHtml(ctx, append) {
        var target = resolveTarget(ctx.form);
        var html = ctx.data && ctx.data.html;
        if (!target || !html) return;
        if (append) {
            target.insertAdjacentHTML('beforeend', html);
            // Se la lista era vuota, rimuovi il placeholder "empty-row" (fratello diretto del contenitore).
            var parent = target.parentNode;
            var empty = parent && parent.querySelector(':scope > .empty-row');
            if (empty) empty.remove();
        } else {
            // replace: sostituisce l'INTERO elemento bersaglio con il nuovo markup (swap del list-item/blocco).
            target.outerHTML = html;
        }
    }

    var effects = {
        toggleState: fx_toggleState,
        updateCount: fx_updateCount,
        removeCard: fx_removeCard,
        replaceHtml: function (ctx) { insertHtml(ctx, false); },
        appendHtml: function (ctx) { insertHtml(ctx, true); },
        resetForm: function (ctx) { try { ctx.form.reset(); } catch (e) {} },
        toast: function (ctx) { var m = ctx.form.getAttribute('data-toast-ok'); if (m) Spoome.toast(m, 'info'); },
        reload: function () { location.reload(); }
    };

    /* --------------------------------------------------- render messaggio DM ---- */
    /* Condiviso tra invio async (handler `dm`) e poller: bolla identica + dedup su data-mid,
     * così un messaggio inviato in async non viene ri-appeso/duplicato dal polling. */
    function renderMessage(list, m) {
        if (!list) return false;
        if (list.querySelector('[data-mid="' + m.id + '"]')) return false; // dedup
        var empty = list.querySelector('.msg-empty');
        if (empty) empty.remove();
        var li = document.createElement('li');
        li.className = 'msg ' + (m.from_me ? 'msg-me' : 'msg-them');
        li.setAttribute('data-mid', String(m.id));
        var b = document.createElement('span');
        b.className = 'msg-bubble';
        b.textContent = m.body;
        li.appendChild(b);
        list.appendChild(li);
        var cur = parseInt(list.getAttribute('data-last-id') || '0', 10);
        if (Number(m.id) > cur) { list.setAttribute('data-last-id', String(m.id)); }
        return true;
    }
    Spoome.renderMessage = renderMessage;

    /* --------------------------------------------------- hook custom (3) ---- */
    Spoome.handlers = {
        // Commento: append <li> + aggiorna conteggio + reset input. Server ritorna { id, count }.
        comment: function (ctx) {
            var form = ctx.form;
            var postId = form.getAttribute('data-post');
            var input = form.querySelector('input[name="body"]');
            var text = input ? input.value.trim() : '';
            var list = document.querySelector('[data-comment-list="' + postId + '"]');
            if (list && text) {
                var li = document.createElement('li');
                li.className = 'comment';
                li.setAttribute('data-comment-item', '');
                var b = document.createElement('span');
                b.className = 'comment-body';
                b.textContent = text; // textContent = niente XSS
                li.appendChild(b);
                list.appendChild(li);
            }
            var cc = document.querySelector('[data-comment-count="' + postId + '"]');
            if (cc && ctx.data && typeof ctx.data.count !== 'undefined') { cc.textContent = ctx.data.count; }
            // Rivela il link "Vedi tutti i N commenti" quando compare il primo commento.
            var toggle = document.querySelector('.post-comments-toggle[data-comments-toggle="' + postId + '"]');
            if (toggle && ctx.data && Number(ctx.data.count) > 0) { toggle.hidden = false; }
            if (input) { input.value = ''; input.focus(); }
        },

        // DM: append bolla propria riusando renderMessage (dedup col poller) + reset + scroll. Server: { id }.
        dm: function (ctx) {
            var list = document.querySelector('[data-thread]');
            var input = ctx.form.querySelector('[name="body"]');
            var text = input ? input.value : '';
            // Se la risposta NON porta ctx.data.id, non renderizziamo qui: il messaggio comparirà al prossimo
            // poll. Nessun rischio di doppione — renderMessage deduplica su data-mid (l'id vero arriverà dal
            // poller e verrà appeso una sola volta). Reset+focus avvengono comunque per non bloccare l'utente.
            if (list && ctx.data && ctx.data.id) {
                renderMessage(list, { id: ctx.data.id, from_me: true, body: text });
                list.scrollTop = list.scrollHeight;
            }
            try { ctx.form.reset(); } catch (e) {}
            if (input) input.focus();
        },

        // Composer post: prepend della card se il server ritorna un frammento, altrimenti reload (sicuro).
        // INVARIANTE DI SICUREZZA: ctx.data.html è il partial `feed-item` renderizzato server-side con e()
        // su ogni campo (stesso partial della timeline) — mai markup costruito dal testo digitato dall'utente.
        composer: function (ctx) {
            if (Spoome.closeComposerSheet) { Spoome.closeComposerSheet(); }
            if (ctx.data && ctx.data.html) {
                var listEl = document.querySelector('.feed-list');
                if (listEl) {
                    listEl.insertAdjacentHTML('afterbegin', ctx.data.html);
                    if (Spoome.enhanceCaptions) { Spoome.enhanceCaptions(listEl); }
                    try { ctx.form.reset(); } catch (e) {}
                    if (Spoome.clearComposerPreview) { Spoome.clearComposerPreview(ctx.form); }
                    return;
                }
            }
            location.reload();
        },

        // Connetti da un suggerimento Rete: disabilita il bottone e mostra "Richiesta inviata" in-place
        // (nessun reload). Aggiorna eventuali contatori connessioni presenti. Server: { status, connections_count }.
        connectSuggest: function (ctx) {
            var btn = ctx.button;
            if (btn) {
                var sent = ctx.form.getAttribute('data-label-sent') || '';
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                btn.classList.remove('btn-connect');
                btn.classList.add('btn-ghost', 'is-on');
                if (sent) { btn.textContent = sent; } // textContent = niente XSS
            }
            if (ctx.data && typeof ctx.data.connections_count !== 'undefined') {
                document.querySelectorAll('[data-conn-count]').forEach(function (n) { n.textContent = ctx.data.connections_count; });
            }
        }
    };

    /* --------------------------------------------------- orchestratore ---- */
    Spoome.submitAsync = function (form) {
        var confirmMsg = form.getAttribute('data-async-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) return;

        var btn = primaryButton(form);
        if (btn && btn.getAttribute('aria-busy') === 'true') return; // anti doppio-invio
        setLoading(btn, true);
        markFieldErrors(form, null);

        var method = (form.getAttribute('method') || 'post').toUpperCase();
        var action = form.getAttribute('action') || location.href;

        Spoome.api(action, { method: method, csrf: true, body: new FormData(form) })
            .then(function (data) {
                var ctx = { form: form, data: data || {}, button: btn };
                (form.getAttribute('data-async-success') || '').split(/\s+/).forEach(function (name) {
                    if (name && effects[name]) effects[name](ctx);
                });
                var handler = form.getAttribute('data-async-handler');
                if (handler && Spoome.handlers[handler]) { Spoome.handlers[handler](ctx); }
                setLoading(btn, false);
            })
            .catch(function (err) {
                if (!err || typeof err.status === 'undefined') {
                    // Errore hard di rete (fetch reject): fallback al submit nativo → l'utente non resta bloccato.
                    form.removeAttribute('data-async');
                    setLoading(btn, false);
                    try { form.submit(); } catch (e) {}
                    return;
                }
                Spoome.toast(err.message || 'Errore', 'error');
                markFieldErrors(form, err.fields);
                setLoading(btn, false);
            });
    };

    // Un solo listener delegato: qualunque form[data-async] passa dall'orchestratore.
    document.addEventListener('submit', function (ev) {
        var form = ev.target && ev.target.closest ? ev.target.closest('form[data-async]') : null;
        if (!form) return;
        ev.preventDefault();
        Spoome.submitAsync(form);
    });

    /* ============================== micro-interazioni progressive ============================== */

    // Mostra/nascondi password
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group = btn.closest('.input-group');
            var input = group && group.querySelector('[data-password]');
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Nascondi' : 'Mostra';
            btn.setAttribute('aria-label', show ? 'Nascondi password' : 'Mostra password');
        });
    });

    // Auto-submit dei filtri (sostituisce l'handler inline, incompatibile con la CSP)
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { if (el.form) el.form.submit(); });
    });

    // Stato "loading" sul submit dei form NATIVI (redirect). I form async lo gestiscono da sé.
    document.querySelectorAll('form:not([data-async])').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[data-submit]');
            if (btn) {
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
                // fallback: riabilita dopo 8s se la navigazione non avviene
                setTimeout(function () { btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy'); }, 8000);
            }
        });
    });

    /* Caption "… altro": mostra l'expander solo se il testo (line-clamp) è troncato.
     * Progressive: il testo completo è già nel DOM; senza JS resta clampato ma leggibile. */
    Spoome.enhanceCaptions = function (root) {
        (root || document).querySelectorAll('[data-caption]:not([data-caption-done])').forEach(function (cap) {
            cap.setAttribute('data-caption-done', '');
            var body = cap.querySelector('.post-caption-body');
            var more = cap.querySelector('[data-caption-more]');
            if (!body || !more) return;
            if (body.scrollHeight - body.clientHeight > 2) { more.hidden = false; }
        });
    };
    Spoome.enhanceCaptions(document);

    // Espansione caption (delegata: copre anche i post prepended dal composer).
    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('[data-caption-more]') : null;
        if (!btn) return;
        var cap = btn.closest('[data-caption]');
        if (cap) { cap.classList.add('is-expanded'); }
        btn.hidden = true;
    });

    // Condividi = copia il link al post negli appunti + toast.
    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('[data-share-url]') : null;
        if (!btn) return;
        ev.preventDefault();
        var href;
        try { href = new URL(btn.getAttribute('data-share-url'), location.origin).href; }
        catch (e) { href = btn.getAttribute('data-share-url') || location.href; }
        var msg = btn.getAttribute('data-copied') || 'Link copiato';
        function ok() { Spoome.toast(msg, 'info'); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(href).then(ok, function () { legacyCopy(href, ok); });
        } else { legacyCopy(href, ok); }
    });
    function legacyCopy(text, done) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); ta.remove();
            if (done) done();
        } catch (e) { /* silenzioso */ }
    }

    // Toggle commenti: porta il focus al campo commento del post.
    document.querySelectorAll('[data-comments-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-comments-toggle');
            var box = document.querySelector('[data-comments-for="' + id + '"]');
            var input = box && box.querySelector('input[name="body"]');
            if (input) { input.focus(); }
        });
    });

    // Messaggi diretti (thread aperto): il refresh delle bolle NON gira più su un timer proprio a 5s —
    // è PILOTATO DALLO STREAM consolidato (vedi sotto). Quando lo stream segnala un `message.created`
    // (o un incremento di dm_unread), chiama Spoome.threadRefresh, che fa il fetch `?after=<data-last-id>`
    // e appende le nuove bolle via renderMessage (dedup su data-mid: nessun doppione). Meno richieste.
    Spoome.threadRefresh = null;
    (function () {
        var list = document.querySelector('[data-thread]');
        if (!list) return;
        var url = list.getAttribute('data-poll-url');
        var busy = false;

        function poll() {
            if (busy) return;
            busy = true;
            var after = list.getAttribute('data-last-id') || '0';
            Spoome.api(url + '?after=' + encodeURIComponent(after))
                .then(function (data) {
                    var msgs = (data && data.messages) || [];
                    if (!msgs.length) return;
                    var atBottom = (list.scrollHeight - list.scrollTop - list.clientHeight) < 60;
                    var any = false;
                    msgs.forEach(function (m) { if (renderMessage(list, m)) any = true; });
                    if (any && atBottom) { list.scrollTop = list.scrollHeight; }
                })
                .catch(function () { /* silenzioso: lo stream ritenterà al prossimo segnale */ })
                .then(function () { busy = false; });
        }

        Spoome.threadRefresh = poll;
    })();

    /* ============================== Stream realtime consolidato (Phase 1) ==============================
     * Un solo poller globale su GET /api/v1/stream/since (same-origin, credenziali di sessione; è una GET,
     * niente CSRF). Ad ogni risposta: aggiorna live il badge notifiche (campana), il badge DM (nav + dot
     * bottom-nav), la pill "nuovi post" del feed, e — se un thread DM è aperto — pilota Spoome.threadRefresh.
     * Cadenza adattiva (~5s visibile, backoff quando la tab è nascosta), gestione 429 (Retry-After) e degli
     * errori di rete (mantiene lo stato, ritenta con backoff). Non rompe MAI la pagina; senza JS i badge
     * semplicemente non si aggiornano live (fallback accettabile). Gira solo per utenti autenticati. */
    (function () {
        if (!document.querySelector('meta[name="csrf-token"]')) { return; } // il token c'è solo se autenticati

        var PATH = '/api/v1/stream/since';
        var ACTIVE_MS = 5000, HIDDEN_MS = 25000, ERR_MS = 15000;
        var cursorMeta = meta('stream-cursor');
        var lastCursor = cursorMeta !== '' ? cursorMeta : 0; // bootstrap: meta se presente, altrimenti 0 (baseline dal 1° poll)
        var feedCursor = null; // stabilito dalla prima risposta (latest_post_id) → "nuovi post" = post dopo il load
        var prevDm = null;
        var backoffUntil = 0;
        var timer = null;

        var bell = document.querySelector('.nav-bell');
        var dmLink = document.querySelector('[data-nav="dm"]');
        var bnDm = document.querySelector('[data-bn="dm"]');
        var bnNotif = document.querySelector('[data-bn="notif"]');
        var feedWrap = document.querySelector('.feed-wrap');
        var pill = null;

        function setNavBadge(link, n) {
            if (!link) return;
            var badge = link.querySelector('.nav-badge');
            if (n > 0) {
                if (!badge) {
                    link.appendChild(document.createTextNode(' '));
                    badge = document.createElement('span');
                    badge.className = 'nav-badge';
                    link.appendChild(badge);
                }
                badge.textContent = n > 99 ? '99+' : String(n);
            } else if (badge) {
                badge.remove();
            }
        }
        function setDot(link, on) {
            if (!link) return;
            var dot = link.querySelector('.bn-dot');
            if (on && !dot) {
                dot = document.createElement('span');
                dot.className = 'bn-dot';
                var icon = link.querySelector('i');
                if (icon && icon.nextSibling) { link.insertBefore(dot, icon.nextSibling); }
                else { link.appendChild(dot); }
            } else if (!on && dot) {
                dot.remove();
            }
        }
        function ensurePill() {
            if (pill || !feedWrap) return pill;
            pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'feed-new-pill';
            pill.hidden = true;
            pill.setAttribute('aria-live', 'polite');
            pill.addEventListener('click', function () { location.reload(); });
            feedWrap.insertBefore(pill, feedWrap.querySelector('.feed-list'));
            return pill;
        }
        function updatePill(n) {
            if (!feedWrap) return;
            ensurePill();
            if (n > 0) {
                pill.textContent = (n === 1 ? '1 nuovo post' : n + ' nuovi post');
                pill.hidden = false;
            } else {
                pill.hidden = true;
            }
        }
        function applyCounters(c) {
            if (!c) return;
            var notif = Number(c.notif_unread) || 0;
            var dm = Number(c.dm_unread) || 0;
            setNavBadge(bell, notif);
            setNavBadge(dmLink, dm);
            setDot(bnDm, dm > 0);
            setDot(bnNotif, notif > 0);
            if (bell) {
                var base = bell.getAttribute('data-label') || 'Notifiche';
                bell.setAttribute('aria-label', notif > 0 ? base + ' (' + notif + ')' : base);
            }
            // Nuovo DM arrivato mentre un thread è aperto → aggiorna le bolle (fetch incrementale).
            if (prevDm !== null && dm > prevDm && Spoome.threadRefresh) { Spoome.threadRefresh(); }
            prevDm = dm;
        }
        function handleEvents(events) {
            if (!events || !events.length) return;
            for (var i = 0; i < events.length; i++) {
                if (events[i] && events[i].type === 'message.created' && Spoome.threadRefresh) {
                    Spoome.threadRefresh();
                    break;
                }
            }
        }

        function schedule(ms) {
            if (timer) clearTimeout(timer);
            timer = setTimeout(tick, Math.max(300, ms));
        }
        function nextMs() { return document.hidden ? HIDDEN_MS : ACTIVE_MS; }

        function tick() {
            var now = Date.now();
            if (now < backoffUntil) { schedule(backoffUntil - now); return; }
            var qs = '?cursor=' + encodeURIComponent(lastCursor) +
                     '&feed_cursor=' + encodeURIComponent(feedCursor == null ? 0 : feedCursor);
            fetch(Spoome.basePath() + PATH + qs, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (res) {
                if (res.status === 429) {
                    var ra = parseInt(res.headers.get('Retry-After') || '5', 10);
                    backoffUntil = Date.now() + (isNaN(ra) ? 5 : ra) * 1000;
                    schedule(backoffUntil - Date.now());
                    return null;
                }
                if (!res.ok) { schedule(ERR_MS); return null; }
                return res.json().catch(function () { return null; });
            }).then(function (json) {
                if (!json) return; // 429/errore già rischedulati
                var d = json.data || {}, m = json.meta || {};
                applyCounters(d.counters);
                if (d.feed) {
                    if (feedCursor == null && typeof d.feed.latest_post_id !== 'undefined') {
                        feedCursor = Number(d.feed.latest_post_id) || 0;
                    }
                    updatePill(Number(d.feed.new_posts_count) || 0);
                }
                handleEvents(d.events);
                if (m && m.cursor != null) { lastCursor = m.cursor; }
                schedule(nextMs());
            }).catch(function () {
                schedule(ERR_MS); // errore di rete: mantieni lo stato, ritenta con backoff
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { schedule(600); } // tornati attivi: aggiorna presto
        });

        schedule(800); // primo poll poco dopo il load
    })();

    /* ============================== Link unfurl (composer) ============================== */
    /* L'anteprima è costruita via DOM con textContent (niente innerHTML da campi remoti): l'unica
     * sorgente "markup" è img.src = data.image, che è il NOSTRO image-proxy same-origin firmato. */
    (function () {
        var URL_RE = /\bhttps?:\/\/[^\s<>"']+/i;

        function el(tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; }

        // Card composer che rispecchia lo stile del feed (link o video), interamente da textContent.
        function buildCard(data) {
            if (data.type === 'video' && data.embed_url) {
                var v = el('div', 'link-video');
                var poster = el('div', 'link-video-poster');
                if (data.image) { var im = el('img'); im.src = data.image; im.alt = ''; poster.appendChild(im); }
                var play = el('span', 'link-play'); play.innerHTML = '<i class="fa-solid fa-play"></i>'; poster.appendChild(play);
                v.appendChild(poster);
                var meta = el('div', 'link-video-meta');
                if (data.provider) { var p = el('span', 'link-provider'); p.textContent = data.provider; meta.appendChild(p); }
                if (data.title) { var t = el('span', 'link-title'); t.textContent = data.title; meta.appendChild(t); }
                v.appendChild(meta);
                return v;
            }
            var c = el('div', 'link-card');
            if (data.image) { var media = el('span', 'link-card-media'); var img = el('img'); img.src = data.image; img.alt = ''; media.appendChild(img); c.appendChild(media); }
            var body = el('span', 'link-card-body');
            var site = el('span', 'link-card-site'); site.innerHTML = '<i class="fa-solid fa-link"></i> ';
            site.appendChild(document.createTextNode(data.site_name || data.domain || '')); body.appendChild(site);
            if (data.title) { var tt = el('span', 'link-card-title'); tt.textContent = data.title; body.appendChild(tt); }
            if (data.description) { var dd = el('span', 'link-card-desc'); dd.textContent = data.description; body.appendChild(dd); }
            c.appendChild(body);
            return c;
        }

        Spoome.clearComposerPreview = function (form) {
            var box = form.querySelector('[data-link-preview]');
            var hash = form.querySelector('[data-link-hash]');
            if (box) { box.textContent = ''; box.hidden = true; box.classList.remove('is-loading'); }
            if (hash) { hash.value = ''; }
            form._lastUnfurl = '';
        };

        document.querySelectorAll('form[data-unfurl]').forEach(function (form) {
            var src = form.querySelector('[data-link-source]');
            var box = form.querySelector('[data-link-preview]');
            var hash = form.querySelector('[data-link-hash]');
            if (!src || !box || !hash) return;
            var timer = null;
            form._lastUnfurl = '';

            function showPreview(data) {
                box.textContent = '';
                var remove = el('button', 'composer-preview-remove');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Rimuovi anteprima');
                remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                remove.addEventListener('click', function () { Spoome.clearComposerPreview(form); });
                box.appendChild(remove);
                box.appendChild(buildCard(data));
                box.hidden = false;
                box.classList.remove('is-loading');
                hash.value = data.url_hash || '';
            }

            function attempt() {
                var m = URL_RE.exec(src.value || '');
                var found = m ? m[0] : '';
                if (!found) { return; }               // niente URL: non tocca l'anteprima esistente
                if (found === form._lastUnfurl) return; // già processato
                form._lastUnfurl = found;
                box.hidden = false; box.classList.add('is-loading'); box.textContent = '';
                Spoome.api(form.getAttribute('data-unfurl'), { method: 'POST', csrf: true, body: { url: found } })
                    .then(function (data) {
                        if (!data) { Spoome.clearComposerPreview(form); return; }
                        // Se l'utente ha nel frattempo cancellato l'URL, non mostrare.
                        if (!URL_RE.test(src.value || '')) { Spoome.clearComposerPreview(form); return; }
                        showPreview(data);
                    })
                    .catch(function () { box.classList.remove('is-loading'); box.hidden = true; form._lastUnfurl = ''; });
            }

            src.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                // Se l'URL è sparito dal testo, azzera l'anteprima.
                if (!URL_RE.test(src.value || '') && hash.value) { Spoome.clearComposerPreview(form); return; }
                timer = setTimeout(attempt, 700);
            });
        });
    })();

    /* ============================== Video embed (feed) ============================== */
    /* FALLBACK ATTIVO: la card video mostra poster+play e LINKA ALLA SORGENTE (l'anchor .link-video-poster
     * apre il video in una nuova scheda). L'inline-embed è pronto (embed_url + sandbox + CSP frame-src su
     * youtube-nocookie/vimeo) ma è tenuto disattivo finché la CSP frame-src non è propagata su questo host
     * (LiteSpeed cache .htaccess): iniettare l'iframe con CSP non ancora attiva darebbe un riquadro vuoto.
     * FAST-FOLLOW: verificata la CSP, impostare EMBED_INLINE = true per riattivare l'iframe sandboxed. */
    var EMBED_INLINE = false;
    if (EMBED_INLINE) {
        document.addEventListener('click', function (ev) {
            var poster = ev.target && ev.target.closest ? ev.target.closest('[data-link-embed] .link-video-poster') : null;
            if (!poster) return;
            var wrap = poster.closest('[data-link-embed]');
            var url = wrap && wrap.getAttribute('data-embed-url');
            if (!url || !/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(url)) return;
            ev.preventDefault();
            var frame = document.createElement('iframe');
            frame.className = 'link-embed-frame';
            frame.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1&rel=0';
            frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
            frame.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
            frame.setAttribute('referrerpolicy', 'no-referrer');
            frame.setAttribute('allowfullscreen', '');
            frame.setAttribute('title', wrap.getAttribute('data-embed-title') || 'Video');
            wrap.replaceChildren(frame);
        });
    }

    /* ===================== Typeahead ricerca (suggerimenti mentre digiti) ===================== */
    (function () {
        var inputs = document.querySelectorAll('input[data-suggest]');
        if (!inputs.length) { return; }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        var base = (Spoome.basePath && Spoome.basePath()) || '';

        inputs.forEach(function (input) {
            var host = input.closest('.search-input') || input.closest('.nav-search') || input.parentElement;
            if (host && getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
            var panel = document.createElement('div');
            panel.className = 'suggest-panel'; panel.hidden = true; panel.setAttribute('role', 'listbox');
            host.appendChild(panel);
            var timer = null, items = [], active = -1, lastQ = '';

            function close() { panel.hidden = true; panel.innerHTML = ''; active = -1; }
            function render() {
                if (!items.length) { close(); return; }
                panel.innerHTML = '';
                items.forEach(function (it, idx) {
                    var a = document.createElement('a');
                    a.className = 'suggest-item' + (idx === active ? ' is-active' : '');
                    a.href = it.url; a.setAttribute('role', 'option');
                    var av = it.avatar
                        ? '<span class="suggest-av"><img src="' + esc(it.avatar) + '" alt=""></span>'
                        : '<span class="suggest-av">' + esc(it.initials || '') + '</span>';
                    var meta = esc([it.sport, it.type].filter(Boolean).join(' · '));
                    a.innerHTML = av + '<span class="suggest-txt"><b>' + esc(it.name) + '</b><span class="suggest-meta">' + meta + '</span></span>';
                    a.addEventListener('mousedown', function (ev) { ev.preventDefault(); window.location.href = it.url; });
                    panel.appendChild(a);
                });
                panel.hidden = false;
            }
            function fetchSuggest(q) {
                fetch(base + '/cerca/suggerimenti?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (json) { items = (json && (json.data || json)) || []; if (!Array.isArray(items)) items = []; active = -1; render(); })
                    .catch(function () { close(); });
            }
            input.addEventListener('input', function () {
                var q = input.value.trim();
                if (timer) { clearTimeout(timer); }
                if (q.length < 2) { close(); lastQ = ''; return; }
                if (q === lastQ) { return; }
                lastQ = q;
                timer = setTimeout(function () { fetchSuggest(q); }, 200);
            });
            input.addEventListener('keydown', function (ev) {
                if (panel.hidden) { return; }
                if (ev.key === 'ArrowDown') { ev.preventDefault(); active = Math.min(active + 1, items.length - 1); render(); }
                else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(active - 1, 0); render(); }
                else if (ev.key === 'Enter') { if (active >= 0 && items[active]) { ev.preventDefault(); window.location.href = items[active].url; } }
                else if (ev.key === 'Escape') { close(); }
            });
            input.addEventListener('blur', function () { setTimeout(close, 150); });
        });
    })();

    /* ===================== Bottom-sheet composer (mobile, stile IG) ===================== */
    (function () {
        var lastTrigger = null; // per ripristinare il focus alla chiusura (accessibilità)
        function backdrop() { return document.querySelector('.sheet-backdrop'); }
        function sheet() { return document.getElementById('composer-sheet'); }
        function openSheet() {
            var s = sheet(), b = backdrop();
            if (!s) return;
            s.hidden = false; if (b) b.hidden = false;
            requestAnimationFrame(function () { s.classList.add('is-open'); if (b) b.classList.add('is-open'); });
            document.body.style.overflow = 'hidden';
            var ta = s.querySelector('textarea'); if (ta) setTimeout(function () { ta.focus(); }, 260);
        }
        function closeSheet() {
            var s = sheet(), b = backdrop();
            if (!s || s.hidden) return;
            s.classList.remove('is-open'); if (b) b.classList.remove('is-open');
            document.body.style.overflow = '';
            var done = function () { s.hidden = true; if (b) b.hidden = true; };
            var t = setTimeout(done, 340);
            s.addEventListener('transitionend', function h() { s.removeEventListener('transitionend', h); clearTimeout(t); done(); }, { once: true });
            // Ripristina il focus sul trigger ("+") che aveva aperto la sheet.
            if (lastTrigger && typeof lastTrigger.focus === 'function') { lastTrigger.focus(); lastTrigger = null; }
        }
        Spoome.closeComposerSheet = closeSheet;
        document.addEventListener('click', function (ev) {
            if (!ev.target.closest) return;
            var opener = ev.target.closest('[data-composer-open]');
            if (opener) { ev.preventDefault(); lastTrigger = opener; openSheet(); }
            else if (ev.target.closest('[data-composer-close]')) { ev.preventDefault(); closeSheet(); }
        });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeSheet(); });
    })();

    /* ===== Conferma per form NATIVI (non-async): sostituisce onsubmit inline, vietato dalla CSP ===== */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form && form.hasAttribute && form.hasAttribute('data-confirm') && !form.hasAttribute('data-async')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) { ev.preventDefault(); }
        }
    }, true);

    /* ===================== Doppio-tap "mi piace" sui post (stile IG) ===================== */
    (function () {
        var lastT = 0, lastEl = null;
        function interactive(target) { return target.closest('a, button, input, textarea, label, .post-actions, .post-menu, .post-comments'); }
        function burst(post) {
            var host = post.querySelector('.post-media') || post;
            var h = document.createElement('span');
            h.className = 'heart-burst'; h.innerHTML = '<i class="fa-solid fa-heart"></i>';
            host.appendChild(h);
            requestAnimationFrame(function () { h.classList.add('is-anim'); });
            h.addEventListener('animationend', function () { h.remove(); });
            setTimeout(function () { if (h.parentNode) h.remove(); }, 1000);
        }
        function triggerLike(post) {
            var form = post.querySelector('.like-form');
            var btn = form && form.querySelector('.like-btn');
            // doppio-tap = mette il like (mai lo toglie): solo se non è già attivo
            if (form && btn && !btn.classList.contains('is-on') && typeof Spoome.submitAsync === 'function') {
                Spoome.submitAsync(form);
            }
            burst(post);
        }
        document.addEventListener('dblclick', function (ev) {
            var post = ev.target.closest && ev.target.closest('.feed-post');
            if (!post || interactive(ev.target)) return;
            triggerLike(post);
        });
        document.addEventListener('touchend', function (ev) {
            var post = ev.target.closest && ev.target.closest('.feed-post');
            if (!post || interactive(ev.target)) { lastEl = null; return; }
            var now = Date.now();
            if (lastEl === post && now - lastT < 320) {
                ev.preventDefault(); triggerLike(post); lastT = 0; lastEl = null;
            } else { lastT = now; lastEl = post; }
        }, { passive: false });
    })();
})();
