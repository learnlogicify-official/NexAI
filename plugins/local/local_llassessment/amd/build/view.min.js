/**
 * Quiz activity landing page (view.php) — match course + arena visual language.
 *
 * Keeps Moodle forms/buttons intact; wraps chrome into an LL card layout:
 * hero (eyebrow + title) → status panel (back, completion, info, primary CTA)
 * → body (description, attempts, grades) → activity navigation strip.
 *
 * The status panel adapts to every completion setup: automatic conditions,
 * manual "Mark as done", or completion tracking switched off entirely.
 *
 * @module local_llassessment/view
 */
define([], function() {

    const qs = function(root, sel) {
        return (root || document).querySelector(sel);
    };

    const qsa = function(root, sel) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    };

    const textOf = function(el) {
        return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : '';
    };

    const findCourseUrl = function(config) {
        if (config && config.courseUrl) {
            return config.courseUrl;
        }
        const crumb = document.querySelector(
            '.breadcrumb a[href*="/course/view.php"], .breadcrumb a[href*="course/view.php"]'
        );
        if (crumb) {
            return crumb.getAttribute('href');
        }
        return '';
    };

    const findQuizTitle = function(config, main) {
        if (config && config.quizName) {
            return config.quizName;
        }
        const candidates = [
            main.querySelector('.page-header-headings h1'),
            main.querySelector('.activity-header h1'),
            main.querySelector('.page-context-header h1'),
            main.querySelector('h1'),
            main.querySelector('h2'),
            document.querySelector('#page-header h1'),
            document.querySelector('.page-header-headings h1')
        ];
        for (let i = 0; i < candidates.length; i++) {
            const t = textOf(candidates[i]);
            if (t) {
                return t;
            }
        }
        return document.title || 'Quiz';
    };

    const isStartControl = function(el) {
        if (!el) {
            return false;
        }
        const name = ((el.getAttribute('name') || '') + ' ' + (el.id || '')).toLowerCase();
        const val = ((el.value || '') + ' ' + textOf(el)).toLowerCase();
        if (/preflight|password|cancel|preview/.test(name + val) && /cancel/.test(val)) {
            return false;
        }
        return /attempt|continue|start|re-attempt|reattempt|preview/i.test(val)
            || /quizstart|startattempt|attemptquiz/i.test(name);
    };

    /**
     * Promote primary attempt CTAs and wrap content into .ll-qv shell.
     *
     * @param {Object} config
     */
    const enhance = function(config) {
        const main = qs(document, '#region-main')
            || qs(document, '[role="main"]')
            || qs(document, '#region-main-box');
        if (!main || main.dataset.llQuizView === '1') {
            return;
        }
        main.dataset.llQuizView = '1';
        document.body.classList.add('ll-quiz-view', 'has-ll-quiz-view');

        const brand = (config && config.brandColor) || '#2563eb';
        document.documentElement.style.setProperty('--ll-brand', brand);

        const courseUrl = findCourseUrl(config);
        const title = findQuizTitle(config, main);
        const backLabel = (config && config.backToCourseLabel) || 'Back to course';
        const assessmentLabel = (config && config.assessmentLabel) || 'Assessment';

        // Collect nodes to keep (everything currently in main).
        const keep = Array.prototype.slice.call(main.childNodes);

        const shell = document.createElement('div');
        shell.className = 'll-qv';
        shell.setAttribute('data-region', 'll-quiz-view');

        const back = document.createElement('a');
        back.className = 'll-qv__back';
        if (courseUrl) {
            back.href = courseUrl;
        } else {
            back.href = '#';
            back.addEventListener('click', function(e) {
                e.preventDefault();
                window.history.back();
            });
        }
        back.innerHTML =
            '<span class="ll-qv__back-icon" aria-hidden="true"></span>' +
            '<span class="ll-qv__back-label">' + backLabel + '</span>';

        const hero = document.createElement('header');
        hero.className = 'll-qv__hero';
        hero.innerHTML =
            '<p class="ll-qv__eyebrow">' + assessmentLabel + '</p>' +
            '<h1 class="ll-qv__title"></h1>';
        hero.querySelector('.ll-qv__title').textContent = title;
        shell.appendChild(hero);

        const body = document.createElement('div');
        body.className = 'll-qv__body';
        keep.forEach(function(node) {
            body.appendChild(node);
        });
        shell.appendChild(body);

        main.appendChild(shell);

        // Hide duplicate Moodle/RemUI headings now that we have a hero title.
        qsa(body, '.page-header-headings, .page-context-header, .activity-header h1, ' +
            '.activity-header .page-header-headings, #page-header .page-header-headings').forEach(function(el) {
            // Keep completion / activity dates if nested; only hide pure heading blocks.
            if (el.matches('h1') || el.classList.contains('page-header-headings')
                    || el.classList.contains('page-context-header')) {
                el.classList.add('ll-qv-hide');
            }
        });
        qsa(body, 'h1').forEach(function(h) {
            if (textOf(h) === title) {
                h.classList.add('ll-qv-hide');
            }
        });

        // Soften info lists into chips when they are plain paragraphs/lists.
        qsa(body, '.quizinfo, .quizinfo p, .infobox').forEach(function(el) {
            el.classList.add('ll-qv-meta');
        });

        // Style classic attempt tables (legacy) + Moodle 5 attempt cards.
        qsa(body, 'table.generaltable, table.quizattemptsummary, .generaltable').forEach(function(table) {
            if (table.classList.contains('quizreviewsummary')) {
                return; // Handled by enhanceAttemptsCards.
            }
            table.classList.add('ll-qv-table');
            const wrap = document.createElement('div');
            wrap.className = 'll-qv-table-wrap';
            if (table.parentNode && !table.parentNode.classList.contains('ll-qv-table-wrap')) {
                table.parentNode.insertBefore(wrap, table);
                wrap.appendChild(table);
            }
        });

        enhanceAttemptsCards(body);
        buildStatusPanel(shell, body, back);
        renderSectionOutline(shell, body, config);

        // Primary CTAs — scan the whole shell: actions may sit in the status panel.
        qsa(shell, 'form .singlebutton, form button[type="submit"], form input[type="submit"], ' +
            '.singlebutton input, .singlebutton button, a.btn').forEach(function(el) {
            if (el.closest('.ll-qv-nav') || el.closest('.ll-qv-attempt')) {
                return;
            }
            if (isStartControl(el)) {
                el.classList.add('ll-qv-cta');
                const host = el.closest('.ll-qv__cta-node, .singlebutton, form, .continuebutton') || el.parentElement;
                if (host) {
                    host.classList.add('ll-qv-cta-host');
                }
            }
        });

        // Password / preflight boxes.
        qsa(shell, '#id_quizpassword, input[name="quizpassword"], .quizpasswordelement').forEach(function(el) {
            const box = el.closest('.fitem, .form-group, fieldset, form') || el.parentElement;
            if (box) {
                box.classList.add('ll-qv-preflight');
            }
        });

        enhanceActivityNav(shell, body, config);
        pruneEmpty(body);
    };

    /**
     * Status panel: back link, completion state, quiz info and the primary CTA.
     *
     * Every piece is optional — the panel collapses to a plain back link when a
     * quiz has no completion conditions, no info lines and no available action.
     *
     * @param {HTMLElement} shell
     * @param {HTMLElement} body
     * @param {HTMLElement} back
     */
    const buildStatusPanel = function(shell, body, back) {
        const panel = document.createElement('section');
        panel.className = 'll-qv__status';
        panel.setAttribute('data-region', 'll-qv-status');

        const top = document.createElement('div');
        top.className = 'll-qv__status-top';
        top.appendChild(back);

        const badges = document.createElement('div');
        badges.className = 'll-qv__badges';

        // Keep the screen-reader label ("Completion requirements") with the badges.
        qsa(body, '.activity-header > .visually-hidden, .activity-header > .sr-only, ' +
            '.activity-header > .accesshide').forEach(function(label) {
            badges.appendChild(label);
        });

        // Core re-renders the whole activity-information region after a manual
        // completion toggle, so move the region itself — not just its badges.
        let completion = qs(body, '[data-region="activity-information"]') || qs(body, '.activity-information');
        if (completion && (textOf(completion) !== '' || completion.querySelector('button, .badge'))) {
            completion.classList.add('ll-qv__activityinfo');
            badges.appendChild(completion);
        } else {
            completion = qs(body, '[data-region="completion-info"]')
                || qs(body, '.activity-completion, .automatic-completion-conditions, .completion-info');
            if (completion) {
                completion.classList.add('ll-qv__completion');
                badges.appendChild(completion);
            }
            const dates = qs(body, '[data-region="activity-dates"], .activity-dates');
            if (dates && textOf(dates) !== '') {
                dates.classList.add('ll-qv__dates');
                badges.appendChild(dates);
            }
        }

        if (badges.childNodes.length) {
            top.appendChild(badges);
            restyleCompletionCriteria(badges);
        }
        panel.appendChild(top);

        const info = document.createElement('div');
        info.className = 'll-qv__status-info';
        qsa(body, '.quizinfo').forEach(function(el) {
            el.classList.remove('ll-qv-meta');
            qsa(el, '.ll-qv-meta').forEach(function(child) {
                child.classList.remove('ll-qv-meta');
            });
            info.appendChild(el);
        });

        const ctaHost = document.createElement('div');
        ctaHost.className = 'll-qv__cta-host';
        collectCtas(body).forEach(function(node) {
            node.classList.add('ll-qv__cta-node');
            ctaHost.appendChild(node);
        });
        // A preflight (password) form needs a full-width row, not a button slot.
        if (qs(ctaHost, 'input[type="password"], #id_quizpassword, input[name="quizpassword"]')) {
            ctaHost.classList.add('ll-qv__cta-host--form');
        }

        moveAccessNotices(body, info);

        const mainrow = document.createElement('div');
        mainrow.className = 'll-qv__status-main';
        if (info.childNodes.length) {
            mainrow.appendChild(info);
        }
        if (ctaHost.childNodes.length) {
            mainrow.appendChild(ctaHost);
        }
        if (mainrow.childNodes.length) {
            panel.appendChild(mainrow);
        }

        if (!badges.childNodes.length && !info.childNodes.length && !ctaHost.childNodes.length) {
            // Nothing but the back link — do not draw an empty panel.
            panel.classList.add('ll-qv__status--bare');
        }
        if (ctaHost.classList.contains('ll-qv__cta-host--form')) {
            panel.classList.add('ll-qv__status--stacked');
        }

        shell.insertBefore(panel, body);
    };

    /**
     * Turn Moodle completion lists / badges into green/grey pills.
     *
     * @param {HTMLElement} root
     */
    const isCompletionDone = function(el) {
        if (!el) {
            return false;
        }
        if (el.getAttribute('data-ll-done') === '1' || el.classList.contains('ll-qv-crit--done')) {
            return true;
        }
        if (el.classList.contains('badge-success') || el.classList.contains('bg-success')
                || el.classList.contains('text-bg-success') || el.classList.contains('alert-success')) {
            return true;
        }
        if (el.querySelector(
            '.fa-check, .fa-check-circle, .fa-check-square, .icon-check, .text-success, ' +
            '[data-flex-icon="check"], .completion-complete, .icon.fa-check'
        )) {
            return true;
        }
        const t = textOf(el);
        if (/^done\s*:/i.test(t)) {
            return true;
        }
        const aria = ((el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('title') || '')).toLowerCase();
        if (/(done|completed|complete)/.test(aria) && !/(not\s+(done|complete)|to\s*do|todo|incomplete)/.test(aria)) {
            return true;
        }
        return /\b(completed|completion-complete|is-complete)\b/.test(el.className);
    };

    const restyleCompletionCriteria = function(root) {
        if (!root) {
            return;
        }
        const scopes = qsa(root, '[data-region="completion-info"], .automatic-completion-conditions, ' +
            '.completion-info, .ll-qv__activityinfo, .ll-qv__completion');
        if (!scopes.length) {
            scopes.push(root);
        }
        scopes.forEach(function(scope) {
            scope.classList.add('ll-qv-crits');
            Array.prototype.slice.call(scope.childNodes).forEach(function(node) {
                if (node.nodeType === 3 && /completion requirements/i.test(node.textContent || '')) {
                    const label = document.createElement('div');
                    label.className = 'll-qv-crit-label';
                    label.textContent = (node.textContent || '').replace(/\s+/g, ' ').trim();
                    node.parentNode.replaceChild(label, node);
                }
            });
            qsa(scope, 'h2, h3, h4, h5, h6, legend, p, strong, b').forEach(function(h) {
                const t = textOf(h);
                if (/^completion requirements:?$/i.test(t) || (h.children.length === 0 && /^completion/i.test(t) && t.length < 48)) {
                    h.classList.add('ll-qv-crit-label');
                }
            });
            qsa(scope, '.badge, li, .automatic-completion-conditions > span').forEach(function(el) {
                if (el.matches('button, .btn, form, .ll-qv-crit-label')
                        || el.querySelector('button, .btn, input[type="submit"]')) {
                    return;
                }
                const t = textOf(el);
                if (!t) {
                    return;
                }
                if (/^completion requirements:?$/i.test(t)) {
                    el.classList.add('ll-qv-crit-label');
                    return;
                }
                el.classList.add('ll-qv-crit');
                if (isCompletionDone(el)) {
                    el.classList.add('ll-qv-crit--done');
                    el.classList.remove('ll-qv-crit--todo');
                    el.setAttribute('data-ll-done', '1');
                } else {
                    el.classList.add('ll-qv-crit--todo');
                }
            });
        });
    };

    /**
     * Table of quiz sections: name, question count, and question types.
     * Placed inside the blue status panel.
     *
     * @param {HTMLElement} shell
     * @param {HTMLElement} body
     * @param {Object} config
     */
    const renderSectionOutline = function(shell, body, config) {
        if (!shell || qs(shell, '.ll-qv-outline')) {
            return;
        }

        const place = function(wrap) {
            wrap.removeAttribute('hidden');
            wrap.removeAttribute('id');
            wrap.classList.add('ll-qv-outline');
            const status = qs(shell, '.ll-qv__status');
            if (status) {
                status.classList.remove('ll-qv__status--bare');
                status.appendChild(wrap);
                return;
            }
            if (body && body.parentNode === shell) {
                shell.insertBefore(wrap, body);
            } else {
                shell.appendChild(wrap);
            }
        };

        const src = document.getElementById('ll-qv-outline-src');
        if (src) {
            place(src);
            return;
        }

        const sections = (config && config.sections) || [];
        if (!sections.length) {
            return;
        }

        const esc = function(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        };

        const wrap = document.createElement('section');
        wrap.className = 'll-qv-outline';
        wrap.setAttribute('data-region', 'll-qv-outline');

        let rows = '';
        sections.forEach(function(section) {
            const chips = (section.types || []).map(function(type) {
                const label = type.label || type.qtype || '';
                const n = type.count || 0;
                return '<span class="ll-qv-type ll-qv-type--' + esc(type.qtype || '') + '">' +
                    esc(label) + (n ? ' <em>' + esc(n) + '</em>' : '') +
                    '</span>';
            }).join('');
            rows += '<tr>' +
                '<td class="ll-qv-outline__name">' + esc(section.name) + '</td>' +
                '<td class="ll-qv-outline__count"><span>' + esc(section.count) + '</span></td>' +
                '<td><div class="ll-qv-outline__types">' + (chips || '—') + '</div></td>' +
                '</tr>';
        });

        wrap.innerHTML =
            '<h2 class="ll-qv-outline__title">' +
                esc((config && config.sectionsTitle) || 'Question outline') +
            '</h2>' +
            '<div class="ll-qv-table-wrap">' +
                '<table class="ll-qv-table ll-qv-outline__table">' +
                    '<thead><tr>' +
                        '<th>' + esc((config && config.sectionCol) || 'Section') + '</th>' +
                        '<th>' + esc((config && config.questionsCol) || 'Questions') + '</th>' +
                        '<th>' + esc((config && config.typesCol) || 'Question types') + '</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table>' +
            '</div>';
        place(wrap);
    };

    /**
     * Primary action nodes (start / continue / re-attempt / preview / preflight).
     *
     * @param {HTMLElement} body
     * @return {HTMLElement[]}
     */
    const collectCtas = function(body) {
        const nodes = [];
        const push = function(node) {
            if (!node || node.closest('.ll-qv-attempt, .ll-qv-attempts, .ll-qv-nav, table')) {
                // Per-attempt actions stay on their own card.
                return;
            }
            if (nodes.indexOf(node) === -1 && !nodes.some(function(n) {
                return n.contains(node);
            })) {
                nodes.push(node);
            }
        };

        qsa(body, 'form').forEach(function(form) {
            const action = (form.getAttribute('action') || '').toLowerCase();
            if (!/startattempt\.php|\/mod\/quiz\/attempt\.php/.test(action)) {
                return;
            }
            if (!form.querySelector('button, input[type="submit"], a.btn')) {
                return;
            }
            push(form.closest('.singlebutton') || form);
        });

        qsa(body, 'a.btn, .singlebutton a, .continuebutton a').forEach(function(a) {
            const href = (a.getAttribute('href') || '').toLowerCase();
            if (/startattempt\.php|\/mod\/quiz\/attempt\.php/.test(href)) {
                push(a.closest('.singlebutton') || a);
            }
        });

        if (!nodes.length) {
            // Fallback for themes/versions that rename the action URLs.
            qsa(body, '.quizattempt button, .quizattempt input[type="submit"], ' +
                '.quizattempt a.btn, .singlebutton button, .singlebutton input[type="submit"]')
                .forEach(function(el) {
                    if (isStartControl(el)) {
                        push(el.closest('.singlebutton, form') || el);
                    }
                });
        }

        nodes.forEach(function(node, index) {
            node.classList.add(index === 0 ? 'll-qv__cta--primary' : 'll-qv__cta--secondary');
        });
        return nodes;
    };

    /**
     * Access rule messages ("not available", "no more attempts") live in the
     * same box as the buttons; surface them inside the panel.
     *
     * @param {HTMLElement} body
     * @param {HTMLElement} info
     */
    const moveAccessNotices = function(body, info) {
        qsa(body, '.quizattempt, .box.quizattempt, .quizstartbuttondiv').forEach(function(box) {
            qsa(box, 'p, .alert, .quizattemptcounts').forEach(function(el) {
                if (textOf(el) === '' || el.querySelector('button, input[type="submit"]')) {
                    return;
                }
                el.classList.add('ll-qv__notice');
                info.appendChild(el);
            });
        });
    };

    /** Containers that Moodle styles as cards/boxes and we may empty out. */
    const BOXY = '.ll-qv__body > *, .box, .generalbox, .card, .activity-header, .activity-information, ' +
        '[data-region="activity-information"], [data-region="activity-dates"], [data-region="completion-info"], ' +
        '.quizattempt, .quizstartbuttondiv, .quizinfo, .quizattemptcounts, .feedbackbox, .grading-action, ' +
        '.singlebutton, .continuebutton, .ll-qv-table-wrap, .ll-qv-meta, ' +
        '.tertiary-navigation, .tertiary-navigation .navitem';

    /** Branches whose text never shows on screen. */
    const UNSEEN = '.ll-qv-hide, .visually-hidden, .sr-only, .accesshide, .screenreader-only, ' +
        '.hidden, .d-none, [hidden], [aria-hidden="true"]';

    /**
     * Text content, ignoring branches we hid and screen-reader-only labels.
     *
     * @param {HTMLElement} el
     * @return {string}
     */
    const visibleText = function(el) {
        let out = '';
        Array.prototype.forEach.call(el.childNodes, function(node) {
            if (node.nodeType === 3) {
                out += node.textContent;
            } else if (node.nodeType === 1 && !node.matches(UNSEEN)) {
                out += visibleText(node);
            }
        });
        return out.replace(/\s+/g, ' ').trim();
    };

    /**
     * Hidden preflight forms and sesskey inputs must not keep a box alive.
     *
     * @param {HTMLElement} el
     * @return {boolean}
     */
    const isHidden = function(el) {
        if (el.closest(UNSEEN)) {
            return true;
        }
        if ((el.getAttribute('type') || '').toLowerCase() === 'hidden') {
            return true;
        }
        const style = window.getComputedStyle ? window.getComputedStyle(el) : null;
        return !!style && (style.display === 'none' || style.visibility === 'hidden');
    };

    /**
     * @param {HTMLElement} el
     * @return {boolean}
     */
    const isEmptyBox = function(el) {
        if (visibleText(el) !== '') {
            return false;
        }
        return !qsa(el, 'img, svg, input, button, select, textarea, iframe, canvas, video, table')
            .some(function(child) {
                if (isHidden(child)) {
                    return false;
                }
                // Zero-sized leftovers (sesskey inputs, collapsed forms) are not content.
                return child.offsetWidth > 2 || child.offsetHeight > 2;
            });
    };

    /**
     * Hide wrappers left behind after their content moved into the panel.
     * Repeated passes let parents collapse once their children are hidden.
     *
     * @param {HTMLElement} root
     */
    const pruneEmpty = function(root) {
        for (let pass = 0; pass < 3; pass++) {
            // Page chrome such as tertiary navigation sits outside the shell.
            qsa(root, BOXY).concat(qsa(document, '.tertiary-navigation')).forEach(function(el) {
                if (el.classList.contains('ll-qv-hide') || el.closest('.ll-qv-nav')
                        || el.closest('.ll-qv__status')) {
                    return;
                }
                if (isEmptyBox(el)) {
                    el.classList.add('ll-qv-hide');
                }
            });
        }
    };

    /**
     * Modernize Moodle 5 "Your attempts" cards (list_of_attempts).
     *
     * @param {HTMLElement} body
     */
    const enhanceAttemptsCards = function(body) {
        const tables = qsa(body, 'table.quizreviewsummary');
        if (!tables.length) {
            return;
        }

        // Mark the section heading.
        tables.forEach(function(table) {
            const card = table.closest('.card') || table.parentElement;
            if (!card || card.dataset.llQvAttempt === '1') {
                return;
            }
            card.dataset.llQvAttempt = '1';
            card.classList.add('ll-qv-attempt');

            const list = card.closest('ul.list-unstyled, ul.row, .list-unstyled');
            if (list) {
                list.classList.add('ll-qv-attempts');
            }
            let heading = null;
            if (list && list.previousElementSibling && /^H[1-4]$/i.test(list.previousElementSibling.tagName)) {
                heading = list.previousElementSibling;
            } else {
                heading = body.querySelector('h3, h2, h4');
                // Prefer a nearby heading that mentions attempts.
                const candidates = qsa(body, 'h2, h3, h4');
                for (let i = 0; i < candidates.length; i++) {
                    if (/attempt/i.test(textOf(candidates[i]))) {
                        heading = candidates[i];
                        break;
                    }
                }
            }
            if (heading) {
                heading.classList.add('ll-qv-attempts__title');
            }

            const titleEl = card.querySelector('.card-title, .card-header h4, .card-header h3, h4');
            const attemptName = textOf(titleEl) || 'Attempt';

            // Parse summary rows into meta items.
            const rows = qsa(table, 'tr');
            const items = [];
            let statusText = '';
            rows.forEach(function(tr) {
                const th = tr.querySelector('th');
                const td = tr.querySelector('td');
                if (!th || !td) {
                    return;
                }
                const label = textOf(th);
                const value = textOf(td);
                if (!label && !value) {
                    return;
                }
                if (/^status$/i.test(label)) {
                    statusText = value;
                }
                items.push({label: label, valueHtml: td.innerHTML, value: value});
            });

            const statusKind = classifyStatus(statusText);

            // Rebuild card content.
            const header = document.createElement('div');
            header.className = 'll-qv-attempt__head';
            header.innerHTML =
                '<div class="ll-qv-attempt__identity">' +
                    '<span class="ll-qv-attempt__icon" aria-hidden="true"></span>' +
                    '<span class="ll-qv-attempt__name"></span>' +
                '</div>' +
                '<span class="ll-qv-attempt__badge ll-qv-attempt__badge--' + statusKind + '"></span>';
            header.querySelector('.ll-qv-attempt__name').textContent = attemptName;
            header.querySelector('.ll-qv-attempt__badge').textContent = statusText || statusKind;

            const meta = document.createElement('div');
            meta.className = 'll-qv-attempt__meta';
            items.forEach(function(item) {
                if (/^status$/i.test(item.label)) {
                    return; // Shown as badge.
                }
                const cell = document.createElement('div');
                cell.className = 'll-qv-attempt__meta-item';
                cell.innerHTML =
                    '<span class="ll-qv-attempt__meta-label"></span>' +
                    '<span class="ll-qv-attempt__meta-value"></span>';
                cell.querySelector('.ll-qv-attempt__meta-label').textContent = item.label;
                cell.querySelector('.ll-qv-attempt__meta-value').innerHTML = item.valueHtml;
                meta.appendChild(cell);
            });

            // Preserve review / continue links from card-body.
            const oldBody = card.querySelector('.card-body');
            const actions = document.createElement('div');
            actions.className = 'll-qv-attempt__actions';
            if (oldBody) {
                qsa(oldBody, 'a, button, .singlebutton').forEach(function(el) {
                    el.classList.add('ll-qv-attempt__action');
                    actions.appendChild(el);
                });
            }

            // Clear and rebuild.
            const oldHeader = card.querySelector('.card-header');
            if (oldHeader) {
                oldHeader.remove();
            }
            table.remove();
            if (oldBody) {
                oldBody.remove();
            }
            // Remove leftover empty nodes.
            while (card.firstChild) {
                card.removeChild(card.firstChild);
            }
            card.appendChild(header);
            if (meta.childNodes.length) {
                card.appendChild(meta);
            }
            if (actions.childNodes.length) {
                card.appendChild(actions);
            }
        });
    };

    /**
     * @param {string} status
     * @return {string}
     */
    const classifyStatus = function(status) {
        const s = (status || '').toLowerCase();
        if (/progress|in progress|unfinished|ongoing/.test(s)) {
            return 'progress';
        }
        if (/finish|submitted|complete|overdue/.test(s)) {
            return 'done';
        }
        if (/abandon|never|not/.test(s)) {
            return 'muted';
        }
        return 'progress';
    };

    /**
     * Rebuild Moodle activity prev / jump / next into a modern footer strip.
     *
     * @param {HTMLElement} shell
     * @param {HTMLElement} body
     * @param {Object} config
     */
    const enhanceActivityNav = function(shell, body, config) {
        const nav = qs(body, '.activity-navigation')
            || qs(document, '#region-main .activity-navigation')
            || qs(document, '.activity-navigation');
        if (!nav || nav.dataset.llQvNav === '1') {
            return;
        }
        nav.dataset.llQvNav = '1';
        nav.classList.add('ll-qv-nav');

        // Pull nav out of the scrolling body and pin as card footer.
        if (nav.parentNode !== shell) {
            shell.appendChild(nav);
        }

        const strip = document.createElement('div');
        strip.className = 'll-qv-nav__strip';

        const prevHost = document.createElement('div');
        prevHost.className = 'll-qv-nav__side ll-qv-nav__side--prev';
        const jumpHost = document.createElement('div');
        jumpHost.className = 'll-qv-nav__side ll-qv-nav__side--jump';
        const nextHost = document.createElement('div');
        nextHost.className = 'll-qv-nav__side ll-qv-nav__side--next';

        // Collect anchors / buttons that look like prev/next.
        const links = qsa(nav, 'a, button, input[type="submit"]').filter(function(el) {
            return !el.closest('select') && el.tagName !== 'OPTION';
        });
        let prev = null;
        let next = null;
        links.forEach(function(el) {
            const t = ((el.getAttribute('data-action') || '') + ' ' +
                (el.className || '') + ' ' + textOf(el) + ' ' + (el.value || '')).toLowerCase();
            if (!prev && /(^|\s)prev|previous|←|‹/.test(t)) {
                prev = el;
            } else if (!next && /(^|\s)next|→|›/.test(t)) {
                next = el;
            }
        });
        // Fallback: first / last link in nav.
        if (!prev && !next && links.length >= 2) {
            prev = links[0];
            next = links[links.length - 1];
        } else if (!prev && links.length === 1) {
            const t = textOf(links[0]).toLowerCase();
            if (/next|→/.test(t)) {
                next = links[0];
            } else {
                prev = links[0];
            }
        }

        const select = qs(nav, 'select.urlselect, select[name="jump"], select.form-control, select');
        if (select) {
            const wrap = document.createElement('div');
            wrap.className = 'll-qv-jump';
            const label = document.createElement('span');
            label.className = 'll-qv-jump__label';
            label.textContent = (config && config.jumpLabel) || 'Jump to';
            // Hide Moodle's adjacent "Jump to..." text nodes / labels if present.
            qsa(nav, 'label, .urlselect label, .jumpmenu label').forEach(function(el) {
                el.classList.add('ll-qv-hide');
            });
            select.classList.add('ll-qv-jump__select');
            // Remove default first empty "Jump to..." option label noise by keeping options.
            wrap.appendChild(label);
            if (select.parentNode) {
                select.parentNode.insertBefore(wrap, select);
            }
            wrap.appendChild(select);
            // Move whole wrap into jump host.
            jumpHost.appendChild(wrap);
        }

        if (prev) {
            prev.classList.add('ll-qv-nav__btn', 'll-qv-nav__btn--prev');
            prevHost.appendChild(prev);
        }
        if (next) {
            next.classList.add('ll-qv-nav__btn', 'll-qv-nav__btn--next');
            nextHost.appendChild(next);
        }

        // Clear leftover Moodle grid markup, keep our strip.
        while (nav.firstChild) {
            nav.removeChild(nav.firstChild);
        }
        strip.appendChild(prevHost);
        strip.appendChild(jumpHost);
        strip.appendChild(nextHost);
        nav.appendChild(strip);

        if (!prev && !next && !select) {
            nav.classList.add('ll-qv-hide');
        }
    };

    const init = function(config) {
        config = config || {};
        const run = function() {
            try {
                enhance(config);
            } catch (e) {
                if (window.console && console.warn) {
                    console.warn('local_llassessment/view', e);
                }
            }
        };
        // Nav and late-rendered blocks: re-run cleanup after the first paint.
        const sweep = function() {
            try {
                const shell = qs(document, '.ll-qv');
                const body = qs(document, '.ll-qv__body');
                if (!shell || !body) {
                    run();
                    return;
                }
                enhanceActivityNav(shell, body, config);
                restyleCompletionCriteria(qs(shell, '.ll-qv__badges'));
                renderSectionOutline(shell, body, config);
                pruneEmpty(body);
            } catch (e) {
                // ignore
            }
        };
        run();
        window.setTimeout(sweep, 250);
        window.setTimeout(sweep, 900);
        window.addEventListener('load', function() {
            window.setTimeout(sweep, 60);
        });
    };

    return {init: init};
});
