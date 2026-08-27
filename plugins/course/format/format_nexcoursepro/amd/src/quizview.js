/**
 * Restyle Moodle quiz HTML in the left pane to match NexCoursePro learn UI.
 *
 * Overview uses the same status band, chips, and CTA language as other activities.
 * Attempts are moved into the Attempts tab as Pro attempt cards.
 *
 * @module     format_nexcoursepro/quizview
 * @copyright  2026 NexAcademy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const qs = (root, sel) => (root || document).querySelector(sel);
    const qsa = (root, sel) => Array.prototype.slice.call((root || document).querySelectorAll(sel));
    const textOf = (el) => el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : '';

    /**
     * Review links open in a new tab (full-screen review). Continue stays same-tab.
     *
     * @param {Element} el
     */
    const markReviewNewTab = (el) => {
        if (!el || el.tagName !== 'A') {
            return;
        }
        const href = el.getAttribute('href') || '';
        if (!/\/mod\/quiz\/review\.php/i.test(href)) {
            return;
        }
        el.setAttribute('target', '_blank');
        el.setAttribute('rel', 'noopener noreferrer');
        el.setAttribute('data-nxpro-review-tab', '1');
    };

    const isStartControl = (el) => {
        if (!el) {
            return false;
        }
        const name = ((el.getAttribute('name') || '') + ' ' + (el.id || '')).toLowerCase();
        const val = ((el.value || '') + ' ' + textOf(el)).toLowerCase();
        if (/cancel/.test(val) && /preflight|password|cancel/.test(name + val)) {
            return false;
        }
        return /attempt|continue|start|re-attempt|reattempt|preview/i.test(val)
            || /quizstart|startattempt|attemptquiz/i.test(name);
    };

    const isCompletionDone = (el) => {
        if (!el) {
            return false;
        }
        if (el.classList.contains('nxpro-quiz-crit--done') || el.getAttribute('data-nxpro-done') === '1') {
            return true;
        }
        if (el.classList.contains('badge-success') || el.classList.contains('bg-success')
                || el.classList.contains('text-bg-success')) {
            return true;
        }
        if (el.querySelector('.fa-check, .fa-check-circle, .text-success, .completion-complete')) {
            return true;
        }
        return /^done\s*:/i.test(textOf(el));
    };

    const restyleCompletion = (root) => {
        qsa(root, '[data-region="completion-info"], .automatic-completion-conditions, ' +
            '.completion-info, .nxpro-quiz__activityinfo, .activity-information').forEach((scope) => {
            scope.classList.add('nxpro-quiz-crits');
            qsa(scope, 'h2, h3, h4, h5, h6, legend, p, strong, .visually-hidden, .sr-only, .accesshide')
                .forEach((h) => {
                    const t = textOf(h);
                    if (/completion requirements/i.test(t) && t.length < 48) {
                        h.classList.add('nxpro-quiz-crit-label');
                    }
                });
            qsa(scope, '.badge, li, .automatic-completion-conditions > span').forEach((el) => {
                if (el.matches('button, .btn') || el.querySelector('button, .btn')) {
                    return;
                }
                const t = textOf(el);
                if (!t) {
                    return;
                }
                if (/completion requirements/i.test(t)) {
                    el.classList.add('nxpro-quiz-crit-label');
                    return;
                }
                el.classList.add('nxpro-quiz-crit');
                if (isCompletionDone(el)) {
                    el.classList.add('nxpro-quiz-crit--done');
                    el.setAttribute('data-nxpro-done', '1');
                } else {
                    el.classList.add('nxpro-quiz-crit--todo');
                }
            });
        });
    };

    const isPreflightContent = (el) => {
        if (!el) {
            return false;
        }
        if (el.closest('.modal, .modal-dialog, .modal-content, [role="dialog"], .moodle-dialogue, ' +
                '[data-region="modal-container"], .nxpro-quiz-modal-bin')) {
            return true;
        }
        const t = textOf(el).toLowerCase();
        if (/are you sure you wish to start|timer will begin to count down|must finish your attempt before it expires/i.test(t)) {
            return true;
        }
        // Orphan modal chrome after SPA inject (no .modal wrapper).
        if (/\btime limit\b/.test(t) && /start attempt/.test(t) && /cancel/.test(t)) {
            return true;
        }
        return false;
    };

    const isSebAction = (el) => {
        if (!el) {
            return false;
        }
        const href = (el.getAttribute('href') || '').toLowerCase();
        const t = ((el.value || '') + ' ' + textOf(el)).toLowerCase();
        if (/^seb:\/\//.test(href) || /^sebs:\/\//.test(href)) {
            return true;
        }
        if (/quizaccess_seb|\/mod\/quiz\/accessrule\/seb\/|pluginfile\.php\/.*seb/i.test(href)) {
            return true;
        }
        return /safe exam browser|download configuration|launch safe exam|download seb|exit safe exam|seb config/i.test(t);
    };

    const styleCtaNode = (node) => {
        if (!node) {
            return;
        }
        node.classList.add('nxpro-quiz__cta-node');
        qsa(node, 'button, input[type="submit"], a.btn, a').forEach((el) => {
            el.classList.add('nxpro-av__cta');
            if (isSebAction(el)) {
                el.classList.add('nxpro-quiz__seb-cta');
            }
        });
    };

    /**
     * Safe Exam Browser download / launch / config actions from Moodle preventmessages.
     *
     * @param {HTMLElement} body
     * @return {HTMLElement[]}
     */
    const collectSebActions = (body) => {
        const nodes = [];
        const push = (node) => {
            if (!node || node.closest('.nxpro-quiz-attempt, .nxpro-quiz__attempt, table, .modal')) {
                return;
            }
            if (nodes.indexOf(node) === -1 && !nodes.some((n) => n.contains(node) || node.contains(n))) {
                nodes.push(node);
            }
        };

        qsa(body, 'a[href^="seb:"], a[href^="sebs:"], a.btn, .singlebutton a, .singlebutton').forEach((el) => {
            const link = el.matches('a') ? el : qs(el, 'a');
            if (!link || !isSebAction(link)) {
                return;
            }
            push(el.classList.contains('singlebutton') ? el : (link.closest('.singlebutton') || link));
        });

        nodes.forEach(styleCtaNode);
        return nodes;
    };

    /**
     * Access / SEB requirement copy that sits with the prevent buttons.
     *
     * @param {HTMLElement} moodle
     * @return {HTMLElement|null}
     */
    const collectAccessNotice = (moodle) => {
        const bits = [];
        const seen = {};
        const pushText = (t) => {
            const key = (t || '').toLowerCase();
            if (!t || seen[key] || t.length > 280) {
                return;
            }
            // Ignore Moodle rule dumps that glue Attempts / Time limit / Grade into one blob.
            if (/attempts allowed|time limit|grade to pass|grading method/i.test(key)
                    && /safe exam browser|attempts allowed|time limit/i.test(key)) {
                if ((key.match(/attempts allowed|time limit|grade to pass|safe exam browser/gi) || []).length > 1) {
                    return;
                }
            }
            if (/attempts allowed|time limit:|grade to pass|currently not available/i.test(key)) {
                return;
            }
            if (!/safe exam browser|requires seb|not using safe exam|invalid keys|browser exam key/i.test(key)) {
                return;
            }
            seen[key] = true;
            bits.push(t);
        };

            qsa(moodle, '.quizattempt, .box, .generalbox, .alert, .notification, [role="alert"]').forEach((box) => {
            // Prefer direct text nodes / paragraphs without swallowing button labels.
            qsa(box, 'p, .alert-heading, .alert > div, .notification-message').forEach((el) => {
                if (el.querySelector('a.btn, .singlebutton, button')) {
                    return;
                }
                pushText(textOf(el));
            });
            // Do not clone whole .quizinfo / rule boxes — they concatenate unrelated lines.
            if (box.classList.contains('quizinfo') || box.classList.contains('quizattempt')) {
                return;
            }
            if (!box.querySelector('a.btn, .singlebutton') && /safe exam browser/i.test(textOf(box))) {
                const clone = box.cloneNode(true);
                qsa(clone, 'a, button, .singlebutton, form').forEach((n) => n.remove());
                pushText(textOf(clone));
            }
        });

        if (!bits.length) {
            return null;
        }
        const notice = document.createElement('div');
        notice.className = 'nxpro-quiz__seb-notice';
        notice.setAttribute('data-region', 'nxpro-quiz-seb-notice');
        bits.forEach((line) => {
            const p = document.createElement('p');
            p.textContent = line;
            notice.appendChild(p);
        });
        return notice;
    };

    const isOverviewCta = (el) => {
        if (!el || !isStartControl(el)) {
            return false;
        }
        if (isPreflightContent(el)) {
            return false;
        }
        const label = ((el.value || '') + ' ' + textOf(el)).toLowerCase().trim();
        // Modal footer "Start attempt" always sits with Cancel — never a Overview CTA.
        const scope = el.closest('form, .modal-footer, .modal-content, .singlebutton') || el.parentElement;
        if (scope && /cancel/.test(textOf(scope)) && /start attempt/.test(label) && !/preview/.test(label)) {
            return false;
        }
        return true;
    };

    const collectCtas = (body) => {
        const nodes = [];
        const push = (node) => {
            if (!node || node.closest('.nxpro-quiz-attempt, .nxpro-quiz__attempt, table')) {
                return;
            }
            if (isPreflightContent(node)) {
                return;
            }
            if (nodes.indexOf(node) === -1 && !nodes.some((n) => n.contains(node))) {
                nodes.push(node);
            }
        };

        // Prefer Preview / main start — never modal footer controls.
        qsa(body, '.quizstartbuttondiv, .quizstartbuttondiv .singlebutton, .quizstartbuttondiv > .singlebutton, ' +
            '.singlebutton, .continuebutton').forEach((wrap) => {
            if (isPreflightContent(wrap) || wrap.closest('.modal, .modal-dialog, .modal-content, .modal-footer')) {
                return;
            }
            const control = qs(wrap, 'button, input[type="submit"], a.btn, a');
            if (control && isOverviewCta(control)) {
                push(wrap);
            }
        });

        qsa(body, 'a.btn, .singlebutton a, .continuebutton a').forEach((a) => {
            if (!isOverviewCta(a) || a.closest('.modal, .modal-dialog, .modal-content, .modal-footer')) {
                return;
            }
            const href = (a.getAttribute('href') || '').toLowerCase();
            if (/startattempt\.php|\/mod\/quiz\/attempt\.php/.test(href) || /preview/i.test(textOf(a))) {
                push(a.closest('.singlebutton') || a);
            }
        });

        // If we already have Preview, drop bare "Start attempt" duplicates.
        const hasPreview = nodes.some((n) => /preview/i.test(textOf(n)));
        if (hasPreview) {
            for (let i = nodes.length - 1; i >= 0; i -= 1) {
                const t = textOf(nodes[i]).toLowerCase();
                if (/start attempt/.test(t) && !/preview/.test(t)) {
                    nodes.splice(i, 1);
                }
            }
        }

        if (!nodes.length) {
            qsa(body, 'button, input[type="submit"]').forEach((el) => {
                if (!isOverviewCta(el) || el.closest('.modal, .modal-dialog, .modal-content, .modal-footer')) {
                    return;
                }
                const form = el.closest('form');
                if (form && isPreflightContent(form)) {
                    return;
                }
                push(el.closest('.singlebutton') || el);
            });
        }

        nodes.forEach((node) => {
            styleCtaNode(node);
        });
        return nodes;
    };

    const ensureModalBin = (root) => {
        let bin = qs(root, '[data-region="nxpro-quiz-modal-bin"]');
        if (!bin) {
            bin = document.createElement('div');
            bin.setAttribute('data-region', 'nxpro-quiz-modal-bin');
            bin.className = 'nxpro-quiz-modal-bin';
            bin.setAttribute('hidden', 'hidden');
            bin.setAttribute('aria-hidden', 'true');
            root.appendChild(bin);
        }
        return bin;
    };

    /**
     * Keep preflight modal markup out of the Overview layout after SPA swaps.
     * Forms stay in the DOM (hidden) so Moodle can still open them on click.
     *
     * @param {HTMLElement} root
     */
    const quarantinePreflight = (root) => {
        qsa(root, 'script').forEach((el) => el.remove());

        const bin = ensureModalBin(root);

        qsa(root, '.modal, [role="dialog"], .moodle-dialogue, .modal-dialog, ' +
            '[data-region="modal-container"]').forEach((modal) => {
            if (modal.closest('[data-region="nxpro-quiz-modal-bin"]') || modal.classList.contains('nxpro-quiz-modal')) {
                return;
            }
            // Do not quarantine a dialog Moodle has actively opened.
            if (modal.classList.contains('show') || modal.classList.contains('in')) {
                return;
            }
            modal.classList.remove('show', 'in');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            bin.appendChild(modal);
        });

        // Orphan confirmation blocks (unwrapped modal body after AJAX inject).
        qsa(root, '.quizattempt, .quizstartbuttondiv, .box, .generalbox, form, ' +
            '.modal-content, .modal-body, .modal-footer, section, article, div').forEach((el) => {
            if (el.closest('[data-region="nxpro-quiz-modal-bin"], .nxpro-quiz__cta-node')) {
                return;
            }
            if (el.classList.contains('quizstartbuttondiv')
                    || el.querySelector('.quizstartbuttondiv input[type="submit"], .quizstartbuttondiv button')) {
                return;
            }
            if (el.classList.contains('quizattempt') && qs(el, '.quizstartbuttondiv')) {
                return;
            }
            if (el.classList.contains('nxpro-quiz__overview') || el.closest('.nxpro-quiz__outline')) {
                return;
            }
            // Only move reasonably sized confirmation chunks — not the whole shell.
            const t = textOf(el);
            if (t.length > 1200) {
                return;
            }
            if (!isPreflightContent(el)) {
                return;
            }
            // Avoid moving tiny wrappers that only contain a CTA we already kept.
            if (el.classList.contains('nxpro-quiz__cta-node') || el.classList.contains('nxpro-av__cta')) {
                return;
            }
            el.classList.add('nxpro-qv-hide');
            bin.appendChild(el);
        });
    };

    /**
     * Strip any preflight chrome that landed in the Overview status panel.
     *
     * @param {HTMLElement} panel
     * @param {HTMLElement} shell
     */
    const scrubOverviewPreflight = (panel, shell) => {
        if (!panel) {
            return;
        }
        const bin = ensureModalBin(shell || panel);
        Array.prototype.slice.call(panel.childNodes).forEach((node) => {
            if (node.nodeType !== 1) {
                return;
            }
            if (node.classList.contains('nxpro-quiz__cta-row') || node.classList.contains('nxpro-quiz__outline')
                    || node.classList.contains('nxpro-quiz__completion') || node.getAttribute('data-region') === 'nxpro-quiz-timing'
                    || node.classList.contains('nxpro-quiz__info')) {
                // Still scrub children inside CTA row.
                if (node.classList.contains('nxpro-quiz__cta-row')) {
                    Array.prototype.slice.call(node.childNodes).forEach((child) => {
                        if (child.nodeType === 1 && isPreflightContent(child)
                                && !child.classList.contains('nxpro-quiz__cta-node')
                                && !child.querySelector('.quizstartbuttondiv')) {
                            bin.appendChild(child);
                        }
                    });
                }
                return;
            }
            if (isPreflightContent(node) || (/\btime limit\b/i.test(textOf(node)) && /cancel/i.test(textOf(node)))) {
                bin.appendChild(node);
            }
        });
        // Catch text nodes / leftover blocks under panel.
        qsa(panel, 'h3, h4, .modal-title, .modal-body, .modal-footer, .modal-content, form').forEach((el) => {
            if (el.closest('.nxpro-quiz__cta-node') && !isPreflightContent(el.closest('.nxpro-quiz__cta-node'))) {
                return;
            }
            if (isPreflightContent(el) || (/^time limit$/i.test(textOf(el)) && textOf(el).length < 24)) {
                const chunk = el.closest('.modal-content, .modal-dialog, form, .box, .generalbox') || el;
                if (!chunk.closest('.nxpro-quiz-modal-bin') && !chunk.classList.contains('nxpro-quiz__cta-node')) {
                    bin.appendChild(chunk);
                }
            }
        });
    };

    /**
     * Moodle opens the preflight "Start attempt" dialog after click.
     * If it mounts inside the left pane it looks broken — promote to body overlay.
     *
     * @param {HTMLElement} modal
     */
    const promoteModalToBody = (modal) => {
        if (!modal || modal.dataset.nxproPromoted === '1') {
            return;
        }
        // Only promote when Moodle actually opens the dialog (not the hidden template).
        if (!modal.classList.contains('show') && !modal.classList.contains('in')) {
            return;
        }
        if (modal.closest('[data-region="nxpro-quiz-modal-bin"]') && !modal.classList.contains('show')) {
            return;
        }

        const text = textOf(modal).toLowerCase();
        const inPane = !!modal.closest('.nxpro-learn, .nxpro-main, .nxpro-av, .nxpro-quiz');
        const isQuizDialog = /time limit|start attempt|password|preflight|are you sure you wish to start/i.test(text)
            || !!qs(modal, 'form[action*="startattempt"], form[action*="attempt.php"]')
            || modal.classList.contains('mod_quiz-modal')
            || (modal.id || '').toLowerCase().indexOf('quiz') !== -1;
        if (!inPane && !isQuizDialog) {
            return;
        }

        modal.dataset.nxproPromoted = '1';
        modal.classList.add('nxpro-quiz-modal');
        modal.classList.add('show');
        modal.style.display = 'flex';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');

        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        let backdrop = document.querySelector('.nxpro-quiz-modal-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show nxpro-quiz-modal-backdrop';
            document.body.appendChild(backdrop);
        }
        document.body.classList.add('modal-open');

        const dismiss = () => {
            try {
                modal.remove();
            } catch (e) { /* ignore */ }
            const b = document.querySelector('.nxpro-quiz-modal-backdrop');
            if (b) {
                b.remove();
            }
            if (!document.querySelector('.nxpro-quiz-modal, .modal.show')) {
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
            }
        };

        qsa(modal, '[data-action="cancel"], [data-bs-dismiss="modal"], .btn-secondary, ' +
            'button[data-dismiss="modal"], .close, .btn-close').forEach((btn) => {
            if (/start|attempt|preview|submit/i.test(textOf(btn) + ' ' + (btn.value || ''))) {
                return;
            }
            btn.addEventListener('click', () => {
                window.setTimeout(dismiss, 0);
            });
        });

        backdrop.addEventListener('click', dismiss, {once: true});
    };

    /**
     * Watch for Moodle dynamically inserting quiz modals into the learn shell.
     *
     * @param {HTMLElement} root
     */
    const watchQuizModals = (root) => {
        if (!root || root.dataset.nxproModalWatch === '1') {
            return;
        }
        root.dataset.nxproModalWatch = '1';

        const scan = () => {
            // Only act on dialogs Moodle has opened (.show) — never unhide templates.
            qsa(document, '.modal.show, .modal.fade.show, .modal.in').forEach((modal) => {
                if (modal.dataset.nxproPromoted === '1') {
                    return;
                }
                const t = textOf(modal).toLowerCase();
                if (/time limit|start attempt|are you sure you wish to start|quizpassword/i.test(t)
                        || qs(modal, 'form[action*="startattempt"]')
                        || modal.closest('.nxpro-learn')) {
                    promoteModalToBody(modal);
                }
            });
        };

        const obs = new MutationObserver(() => {
            window.requestAnimationFrame(scan);
        });
        obs.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style', 'aria-hidden'],
        });
        scan();
        [50, 200, 500, 1000].forEach((ms) => window.setTimeout(scan, ms));
    };

    /**
     * Remove quiz modals promoted to body (used when switching activities).
     */
    const dismissPromotedModals = () => {
        qsa(document, '.nxpro-quiz-modal, .nxpro-quiz-modal-backdrop').forEach((el) => {
            try {
                el.remove();
            } catch (e) { /* ignore */ }
        });
        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
        }
    };

    /**
     * Turn .quizinfo lines into Pro status chips.
     *
     * @param {HTMLElement} moodle
     * @return {HTMLElement|null}
     */
    const buildInfoChips = (moodle) => {
        const lines = [];
        const skipLine = (t) => {
            const s = (t || '').toLowerCase();
            if (/safe exam browser|requires seb/i.test(s)) {
                return true;
            }
            if (/attempts allowed|time limit|grade to pass|grading method/i.test(s)) {
                return true;
            }
            return /monitored by nexproctor|this (quiz|test) requires|this test is not available|currently not available|no longer available|not available yet/i.test(s);
        };
        qsa(moodle, '.quizinfo, .quizinfo p, .quizinfo li').forEach((el) => {
            if (el.querySelector('p, li')) {
                return;
            }
            const t = textOf(el);
            if (t && !skipLine(t)) {
                lines.push(t);
            }
        });
        // Also pull plain prevent / notice paragraphs near the attempt box.
        qsa(moodle, '.quizattempt > p, .quizstartbuttondiv > p, .box > p').forEach((el) => {
            if (el.querySelector('button, input, a.btn')) {
                return;
            }
            const t = textOf(el);
            if (t && lines.indexOf(t) === -1 && t.length < 220 && !skipLine(t)) {
                lines.push(t);
            }
        });
        if (!lines.length) {
            return null;
        }

        const grid = document.createElement('div');
        grid.className = 'nxpro-av__status-grid nxpro-quiz__info-grid';
        lines.forEach((line) => {
            const chip = document.createElement('div');
            chip.className = 'nxpro-av__chip nxpro-quiz__info-chip';
            const colon = line.indexOf(':');
            if (colon > 0 && colon < 40) {
                chip.innerHTML =
                    '<span class="nxpro-av__chip-label"></span>' +
                    '<strong class="nxpro-av__chip-value"></strong>';
                chip.querySelector('.nxpro-av__chip-label').textContent = line.slice(0, colon).trim();
                chip.querySelector('.nxpro-av__chip-value').textContent = line.slice(colon + 1).trim();
            } else {
                chip.innerHTML = '<strong class="nxpro-av__chip-value nxpro-quiz__info-chip-full"></strong>';
                chip.querySelector('.nxpro-av__chip-value').textContent = line;
            }
            grid.appendChild(chip);
        });
        // Hide originals so they are not duplicated in moodle leftovers.
        qsa(moodle, '.quizinfo').forEach((el) => el.classList.add('nxpro-qv-hide'));
        return grid;
    };

    const enhanceAttemptCards = (root) => {
        qsa(root, 'table.quizreviewsummary').forEach((table) => {
            const card = table.closest('.card') || table.parentElement;
            if (!card || card.dataset.nxproAttempt === '1') {
                return;
            }
            card.dataset.nxproAttempt = '1';
            card.className = 'nxpro-quiz-attempt';

            const list = card.closest('ul.list-unstyled, ul.row, .list-unstyled');
            if (list) {
                list.classList.add('nxpro-quiz-attempts');
            }

            const titleEl = card.querySelector('.card-title, .card-header h4, .card-header h3, h4');
            const attemptName = textOf(titleEl) || 'Attempt';
            const rows = qsa(table, 'tr');
            const items = [];
            let statusText = '';
            rows.forEach((tr) => {
                const th = tr.querySelector('th');
                const td = tr.querySelector('td');
                if (!th || !td) {
                    return;
                }
                const label = textOf(th);
                if (/^status$/i.test(label)) {
                    statusText = textOf(td);
                }
                items.push({label: label, valueHtml: td.innerHTML});
            });

            let kind = 'muted';
            const s = (statusText || '').toLowerCase();
            if (/progress|in progress|unfinished|ongoing|overdue/.test(s)) {
                kind = 'progress';
            } else if (/finish|submitted|complete/.test(s)) {
                kind = 'done';
            }

            const header = document.createElement('div');
            header.className = 'nxpro-quiz-attempt__head';
            header.innerHTML =
                '<strong class="nxpro-quiz-attempt__name"></strong>' +
                '<span class="nxpro-quiz-attempt__badge nxpro-quiz-attempt__badge--' + kind + '"></span>';
            header.querySelector('.nxpro-quiz-attempt__name').textContent = attemptName;
            header.querySelector('.nxpro-quiz-attempt__badge').textContent = statusText || kind;

            const meta = document.createElement('div');
            meta.className = 'nxpro-quiz-attempt__meta';
            items.forEach((item) => {
                if (/^status$/i.test(item.label)) {
                    return;
                }
                const cell = document.createElement('div');
                cell.className = 'nxpro-av__chip';
                cell.innerHTML =
                    '<span class="nxpro-av__chip-label"></span>' +
                    '<strong class="nxpro-av__chip-value"></strong>';
                cell.querySelector('.nxpro-av__chip-label').textContent = item.label;
                cell.querySelector('.nxpro-av__chip-value').innerHTML = item.valueHtml;
                meta.appendChild(cell);
            });

            const actions = document.createElement('div');
            actions.className = 'nxpro-quiz-attempt__actions';
            const oldBody = card.querySelector('.card-body');
            if (oldBody) {
                qsa(oldBody, 'a, button, .singlebutton').forEach((el) => {
                    if (el.matches('a, button')) {
                        el.classList.add('nxpro-quiz-attempt__action');
                        markReviewNewTab(el);
                        if (/continue/i.test(textOf(el))) {
                            el.classList.add('is-continue');
                        }
                    } else {
                        qsa(el, 'a, button, input[type="submit"]').forEach((btn) => {
                            btn.classList.add('nxpro-quiz-attempt__action');
                            markReviewNewTab(btn);
                            if (/continue/i.test(textOf(btn) + ' ' + (btn.value || ''))) {
                                btn.classList.add('is-continue');
                            }
                        });
                    }
                    actions.appendChild(el);
                });
            }

            const oldHeader = card.querySelector('.card-header');
            if (oldHeader) {
                oldHeader.remove();
            }
            table.remove();
            if (oldBody) {
                oldBody.remove();
            }
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

        // Convert plain attempt-summary tables into the same simple rows.
        qsa(root, 'table.quizattemptsummary, table.generaltable').forEach((table) => {
            if (table.dataset.nxproAttemptTable === '1' || table.classList.contains('quizreviewsummary')) {
                return;
            }
            const wrap = table.closest('.table-responsive, .no-overflow') || table;
            if (wrap.dataset.nxproAttempt === '1') {
                return;
            }
            const headers = qsa(table, 'thead th, tr:first-child th').map((th) => textOf(th).toLowerCase());
            if (!headers.length && !/attempt/i.test(textOf(table))) {
                return;
            }
            wrap.dataset.nxproAttempt = '1';
            table.dataset.nxproAttemptTable = '1';

            const list = document.createElement('div');
            list.className = 'nxpro-quiz-attempts';

            qsa(table, 'tbody tr').forEach((tr) => {
                const cells = qsa(tr, 'th, td');
                if (!cells.length) {
                    return;
                }
                const card = document.createElement('article');
                card.className = 'nxpro-quiz-attempt';
                const name = textOf(cells[0]) || 'Attempt';
                let statusText = '';
                const metaItems = [];
                cells.forEach((cell, idx) => {
                    const label = headers[idx] || '';
                    const val = textOf(cell);
                    if (!val) {
                        return;
                    }
                    if (/status|state/.test(label)) {
                        statusText = val;
                        return;
                    }
                    if (idx === 0) {
                        return;
                    }
                    if (cell.querySelector('a, button')) {
                        return;
                    }
                    metaItems.push({label: label || 'Detail', valueHtml: cell.innerHTML});
                });

                let kind = 'muted';
                const s = (statusText || '').toLowerCase();
                if (/progress|unfinished|overdue/.test(s)) {
                    kind = 'progress';
                } else if (/finish|submitted|complete/.test(s)) {
                    kind = 'done';
                }

                const head = document.createElement('div');
                head.className = 'nxpro-quiz-attempt__head';
                head.innerHTML = '<strong class="nxpro-quiz-attempt__name"></strong>' +
                    (statusText
                        ? '<span class="nxpro-quiz-attempt__badge nxpro-quiz-attempt__badge--' + kind + '"></span>'
                        : '');
                head.querySelector('.nxpro-quiz-attempt__name').textContent = name;
                const badge = head.querySelector('.nxpro-quiz-attempt__badge');
                if (badge) {
                    badge.textContent = statusText;
                }
                card.appendChild(head);

                if (metaItems.length) {
                    const meta = document.createElement('div');
                    meta.className = 'nxpro-quiz-attempt__meta';
                    metaItems.forEach((item) => {
                        const chip = document.createElement('div');
                        chip.className = 'nxpro-av__chip';
                        chip.innerHTML = '<span class="nxpro-av__chip-label"></span><strong class="nxpro-av__chip-value"></strong>';
                        chip.querySelector('.nxpro-av__chip-label').textContent = item.label;
                        chip.querySelector('.nxpro-av__chip-value').innerHTML = item.valueHtml;
                        meta.appendChild(chip);
                    });
                    card.appendChild(meta);
                }

                const links = qsa(tr, 'a, button');
                if (links.length) {
                    const actions = document.createElement('div');
                    actions.className = 'nxpro-quiz-attempt__actions';
                    links.forEach((el) => {
                        el.classList.add('nxpro-quiz-attempt__action');
                        markReviewNewTab(el);
                        if (/continue/i.test(textOf(el))) {
                            el.classList.add('is-continue');
                        }
                        actions.appendChild(el);
                    });
                    card.appendChild(actions);
                }
                list.appendChild(card);
            });

            if (list.childNodes.length) {
                wrap.parentNode.insertBefore(list, wrap);
                wrap.remove();
            }
        });
    };

    const moveAttemptsToTab = (moodle, attemptsHost, emptyEl, countEl) => {
        if (!attemptsHost) {
            return 0;
        }
        attemptsHost.innerHTML = '';
        const movers = [];

        qsa(moodle, '.quizattemptcounts, .grading-action, #feedback, .feedbackbox').forEach((el) => {
            movers.push(el);
        });

        qsa(moodle, 'ul.list-unstyled, ul.row').forEach((list) => {
            if (list.querySelector('table.quizreviewsummary, .card')) {
                movers.push(list);
                const prev = list.previousElementSibling;
                if (prev && /^H[1-4]$/i.test(prev.tagName) && /attempt/i.test(textOf(prev))) {
                    movers.unshift(prev);
                }
            }
        });

        qsa(moodle, 'table.generaltable, table.quizattemptsummary').forEach((table) => {
            if (table.classList.contains('quizreviewsummary')) {
                return;
            }
            const wrap = table.closest('.table-responsive, .no-overflow') || table;
            const prev = wrap.previousElementSibling;
            if (prev && /^H[1-4]$/i.test(prev.tagName) && /attempt/i.test(textOf(prev))) {
                movers.push(prev);
            }
            movers.push(wrap);
        });

        const seen = new Set();
        movers.forEach((el) => {
            if (!el || seen.has(el) || !moodle.contains(el)) {
                return;
            }
            seen.add(el);
            attemptsHost.appendChild(el);
        });

        enhanceAttemptCards(attemptsHost);
        qsa(attemptsHost, 'a').forEach(markReviewNewTab);

        // Style attempt summary boxes like Pro cards.
        qsa(attemptsHost, '.quizattemptcounts, .grading-action, .feedbackbox, #feedback').forEach((el) => {
            el.classList.add('nxpro-quiz__summary');
        });
        qsa(attemptsHost, 'h2, h3, h4').forEach((h) => {
            if (/attempt/i.test(textOf(h))) {
                h.classList.add('nxpro-quiz__section-title');
            }
        });

        const cards = qsa(attemptsHost, '.nxpro-quiz-attempt, table tbody tr');
        const count = qsa(attemptsHost, '.nxpro-quiz-attempt').length
            || (textOf(attemptsHost) ? Math.max(1, cards.length) : 0);
        if (emptyEl) {
            emptyEl.classList.toggle('is-hidden', count > 0 || textOf(attemptsHost) !== '');
        }
        if (countEl) {
            const n = qsa(attemptsHost, '.nxpro-quiz-attempt').length;
            countEl.textContent = String(n);
            countEl.classList.toggle('is-hidden', n < 1);
        }
        return count;
    };

    /**
     * @param {HTMLElement} root nxpro-learn root
     */
    const enhance = (root) => {
        const shell = qs(root, '[data-region="nxpro-qv"]');
        if (!shell || shell.dataset.nxproEnhanced === '1') {
            return;
        }
        shell.dataset.nxproEnhanced = '1';
        shell.classList.add('nxpro-quiz__shell');

        const moodle = qs(shell, '[data-region="nxpro-qv-moodle"]') || shell;

        qsa(moodle, '.page-header-headings, .page-context-header, .activity-header h1, h1, h2')
            .forEach((el) => {
                if (el.closest('[data-region="nxpro-qv-outline"]')) {
                    return;
                }
                el.classList.add('nxpro-qv-hide');
            });
        qsa(moodle, '.tertiary-navigation').forEach((el) => el.classList.add('nxpro-qv-hide'));

        dismissPromotedModals();
        // Do not quarantine before CTAs are collected — preflight markup sits beside the start button.

        // Same status band used by video / page activities in the Pro shell.
        const panel = document.createElement('section');
        panel.className = 'nxpro-av__status nxpro-quiz__overview';
        panel.setAttribute('data-region', 'nxpro-qv-status');

        const badges = document.createElement('div');
        badges.className = 'nxpro-quiz__completion';
        let infoSrc = qs(shell, '[data-region="nxpro-qv-activityinfo"]');
        if (!infoSrc) {
            // Prefer the Pro completion block rendered above the quiz tabs.
            const outer = qs(root, '[data-region="nxpro-completion-body"]');
            if (outer && textOf(outer)) {
                infoSrc = document.createElement('div');
                infoSrc.setAttribute('data-region', 'nxpro-qv-activityinfo');
                infoSrc.className = 'nxpro-quiz__activityinfo';
                infoSrc.innerHTML = outer.innerHTML;
            }
        }
        if (infoSrc) {
            infoSrc.classList.add('nxpro-quiz__activityinfo');
            const label = document.createElement('div');
            label.className = 'nxpro-quiz-crit-label';
            label.textContent = 'Completion requirements';
            badges.appendChild(label);
            badges.appendChild(infoSrc);
            // Hide the duplicate outer strip once it's in Overview.
            const outerWrap = qs(root, '[data-region="nxpro-completion"]');
            if (outerWrap) {
                outerWrap.classList.add('is-hidden');
                outerWrap.setAttribute('hidden', 'hidden');
            }
        } else {
            let completion = qs(moodle, '[data-region="activity-information"]')
                || qs(moodle, '.activity-information');
            if (completion) {
                completion.classList.add('nxpro-quiz__activityinfo');
                badges.appendChild(completion);
            } else {
                completion = qs(moodle, '[data-region="completion-info"]')
                    || qs(moodle, '.automatic-completion-conditions, .completion-info');
                if (completion) {
                    badges.appendChild(completion);
                }
            }
        }
        if (badges.childNodes.length) {
            panel.appendChild(badges);
            restyleCompletion(badges);
        }

        const timing = qs(shell, '[data-region="nxpro-quiz-timing"]');
        if (timing) {
            panel.appendChild(timing);
        }

        const notices = qs(shell, '[data-region="nxpro-quiz-notices"]');
        if (notices) {
            panel.appendChild(notices);
        }

        const infoGrid = buildInfoChips(moodle);
        if (infoGrid) {
            panel.appendChild(infoGrid);
        }

        // Moodle's generic "currently not available" duplicates the dated Availability chip.
        qsa(moodle, '.quizinfo, .quizinfo p, .quizattempt > p, .box > p, .alert, .notification').forEach((el) => {
            if (/^this quiz is currently not available\.?$/i.test(textOf(el))) {
                el.classList.add('nxpro-qv-hide');
            }
        });

        const sebNotice = collectAccessNotice(moodle);
        if (sebNotice) {
            panel.appendChild(sebNotice);
        }

        const ctaHost = document.createElement('div');
        ctaHost.className = 'nxpro-av__actions nxpro-quiz__cta-row';
        collectCtas(moodle).forEach((node) => ctaHost.appendChild(node));
        // SEB download / launch / config must stay visible when quiz requires Safe Exam Browser.
        collectSebActions(moodle).forEach((node) => ctaHost.appendChild(node));
        const actionsSrc = qs(root, '[data-region="nxpro-quiz-actions"]');
        if (!ctaHost.childNodes.length && actionsSrc) {
            collectCtas(actionsSrc).forEach((node) => ctaHost.appendChild(node));
            collectSebActions(actionsSrc).forEach((node) => ctaHost.appendChild(node));
        }
        // Last resort: move whatever Moodle put in the actions region into Overview.
        if (!ctaHost.childNodes.length && actionsSrc) {
            Array.prototype.slice.call(actionsSrc.childNodes).forEach((node) => {
                if (node.nodeType === 1) {
                    styleCtaNode(node);
                    ctaHost.appendChild(node);
                }
            });
        }
        if (actionsSrc) {
            // Hide source only when Overview already has the CTA.
            if (ctaHost.childNodes.length) {
                actionsSrc.classList.add('nxpro-qv-hide');
                actionsSrc.classList.add('is-hidden');
            } else {
                actionsSrc.classList.remove('nxpro-qv-hide');
                actionsSrc.classList.remove('is-hidden');
                actionsSrc.hidden = false;
            }
        }
        if (ctaHost.childNodes.length) {
            panel.appendChild(ctaHost);
        }

        const outline = qs(shell, '[data-region="nxpro-qv-outline"]');
        if (outline) {
            outline.classList.remove('is-src');
            outline.removeAttribute('hidden');
            outline.classList.add('nxpro-quiz__outline');
            panel.appendChild(outline);
        }

        shell.insertBefore(panel, shell.firstChild);
        scrubOverviewPreflight(panel, shell);
        // Late Moodle AMD can re-inject confirmation into the pane after SPA swaps.
        quarantinePreflight(shell);
        scrubOverviewPreflight(panel, shell);

        const attemptsHost = qs(root, '[data-region="nxpro-quiz-attempts-host"]');
        const emptyEl = qs(root, '[data-region="nxpro-quiz-attempts-empty"]');
        const countEl = qs(root, '[data-region="nxpro-quiz-attempt-count"]');
        moveAttemptsToTab(moodle, attemptsHost, emptyEl, countEl);
        qsa(root, '[data-region="nxpro-quiz-attempts"] a').forEach(markReviewNewTab);

        // Hide Moodle leftovers that were moved or duplicated into the Pro panel.
        qsa(moodle, '.box, .generalbox, .card, .quizattempt, .quizstartbuttondiv, ' +
            '.activity-header, .singlebutton, .continuebutton, form, .modal, .modal-dialog, .modal-content')
            .forEach((el) => {
                if (el.closest('.nxpro-quiz__overview') || el.closest('[data-region="nxpro-quiz-attempts-host"]')
                        || el.closest('[data-region="nxpro-quiz-modal-bin"]')) {
                    return;
                }
                el.classList.add('nxpro-qv-hide');
            });

        moodle.classList.add('nxpro-qv-hide');

        watchQuizModals(root);

        if (shell.dataset.nxproPreflightWatch !== '1') {
            shell.dataset.nxproPreflightWatch = '1';
            const rescrub = () => {
                quarantinePreflight(shell);
                scrubOverviewPreflight(panel, shell);
            };
            const obs = new MutationObserver(() => window.requestAnimationFrame(rescrub));
            obs.observe(shell, {childList: true, subtree: true});
            [0, 50, 150, 400, 1000].forEach((ms) => window.setTimeout(rescrub, ms));
        }
    };

    return {
        enhance: enhance,
        watchQuizModals: watchQuizModals,
        dismissPromotedModals: dismissPromotedModals,
    };
});
