/**
 * Custom Test tab — run student code with custom stdin/testcode through
 * the CodeRunner question template (local_llassessment_run_custom_test).
 *
 * @module     local_llassessment/custom_test
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    /**
     * @param {string} text
     * @return {boolean}
     */
    const looksLikeTestCode = function(text) {
        const t = String(text || '').trim();
        if (!t) {
            return false;
        }
        // Function-style checks usually invoke something rather than pure data.
        if (/^(print|assert|System\.out|cout\s*<<|printf|console\.|fmt\.|puts\s*\()/m.test(t)) {
            return true;
        }
        if (/\w+\s*\([^)]*\)/.test(t) && !/^\d+(\s+\d+)*$/.test(t)) {
            return true;
        }
        return false;
    };

    /**
     * @param {Element} split
     * @return {string}
     */
    const getSourceCode = function(split) {
        if (!split) {
            return '';
        }
        const aceEl = split.querySelector('.ace_editor');
        if (aceEl && window.ace) {
            try {
                const ed = window.ace.edit(aceEl);
                if (ed && typeof ed.getValue === 'function') {
                    return ed.getValue();
                }
            } catch (e) {
                // Fall through.
            }
        }
        const ta = split.querySelector(
            'textarea[name*="answer"], .coderunner-answer textarea, .ll-cr-ide__editor textarea'
        );
        return ta ? String(ta.value || '') : '';
    };

    /**
     * Prefer the language <select> value (what CodeRunner expects).
     *
     * @param {Element} split
     * @return {string}
     */
    const getLanguageValue = function(split) {
        const select = split.querySelector(
            '.ll-cr-ide__lang select, .ll-lang select, select[name*="language"], select[name*="lang"]'
        );
        if (select) {
            return String(select.value || '');
        }
        return '';
    };

    /**
     * @param {Element} split
     * @return {{attemptid: number, cmid: number, slot: number}}
     */
    const getAttemptMeta = function(split) {
        const que = (split && split.closest('.que')) || document.querySelector('.que');
        const id = (que && que.id) || '';
        const m = id.match(/^q(\d+):(\d+)$/);
        const form = document.getElementById('responseform')
            || document.querySelector('form#responseform, form.questionflagsaveform, form');
        let attemptid = 0;
        if (form) {
            const att = form.querySelector('input[name="attempt"]');
            if (att && att.value) {
                attemptid = Number(att.value) || 0;
            }
        }
        if (!attemptid) {
            try {
                attemptid = Number(new URLSearchParams(window.location.search).get('attempt') || 0);
            } catch (e) {}
        }

        let slot = 0;
        if (split && split.getAttribute('data-slot')) {
            slot = Number(split.getAttribute('data-slot')) || 0;
        }
        if (!slot && m) {
            slot = Number(m[2]) || 0;
        }
        if (!slot && que) {
            const wrap = que.closest('[data-slot]') || que.querySelector('[data-slot]');
            if (wrap) {
                slot = Number(wrap.getAttribute('data-slot')) || 0;
            }
        }

        let cmid = 0;
        try {
            const tagged = document.body.getAttribute('data-ll-cmid')
                || document.body.getAttribute('data-cmid');
            if (tagged) {
                cmid = Number(tagged) || 0;
            }
            if (!cmid) {
                const u = new URL(window.location.href);
                cmid = Number(u.searchParams.get('cmid') || 0);
            }
            if (!cmid) {
                const bodyCm = (document.body.className || '').match(/\bcmid-(\d+)/);
                if (bodyCm) {
                    cmid = Number(bodyCm[1]) || 0;
                }
            }
            if (!cmid) {
                const link = document.querySelector(
                    '#mod_quiz_navblock a[href*="cmid="], #responseform a[href*="cmid="],' +
                    ' a.qnbutton[href*="cmid="], a.endtestlink[href*="cmid="], a[href*="cmid="]'
                );
                if (link) {
                    cmid = Number(new URL(link.href, window.location.origin).searchParams.get('cmid') || 0);
                }
            }
            if (!cmid) {
                const view = document.querySelector(
                    'a.ll-arena__back[href*="id="], .breadcrumb a[href*="/mod/quiz/view.php"]'
                );
                if (view) {
                    cmid = Number(new URL(view.href, window.location.origin).searchParams.get('id') || 0);
                }
            }
        } catch (e) {}

        return {attemptid: attemptid, cmid: cmid, slot: slot};
    };

    /**
     * @param {Element} card
     * @return {{stdin: string, testcode: string, expected: string}|null}
     */
    const parseSampleCard = function(card) {
        if (!card) {
            return null;
        }
        let stdin = '';
        let testcode = '';
        let expected = '';
        card.querySelectorAll('.ll-samples__field, .ll-sample__field').forEach(function(field) {
            const label = field.querySelector('.ll-samples__label, .ll-sample__label');
            const box = field.querySelector('.ll-samples__box, .ll-sample__box, pre');
            if (!label || !box) {
                return;
            }
            const text = (box.textContent || '').replace(/\u00a0/g, ' ');
            const bare = text === '—' ? '' : text;
            const lt = (label.textContent || '').toLowerCase();
            if (/test\s*code|testcode|\btest\b/.test(lt) && !/stdin|input|expected|output/.test(lt)) {
                testcode = bare;
            } else if (/input|stdin/.test(lt)) {
                if (looksLikeTestCode(bare) && !testcode) {
                    testcode = bare;
                } else {
                    stdin = bare;
                }
            } else if (/expected|output|result/.test(lt)) {
                expected = bare;
            }
        });
        if (!stdin && !testcode && !expected) {
            return null;
        }
        return {stdin: stdin, testcode: testcode, expected: expected};
    };

    /**
     * @param {Element} split
     * @param {Element} chipsHost
     * @param {HTMLTextAreaElement} inputEl
     * @param {HTMLTextAreaElement} testcodeEl
     * @param {HTMLTextAreaElement} expectedEl
     */
    const fillSampleChips = function(split, chipsHost, inputEl, testcodeEl, expectedEl) {
        if (!chipsHost || !inputEl) {
            return;
        }
        const fillWrap = chipsHost.closest('.ll-custom__fill');
        chipsHost.innerHTML = '';
        const samples = [];
        const root = split.closest('.que') || split;
        root.querySelectorAll('.ll-samples__card').forEach(function(card) {
            const parsed = parseSampleCard(card);
            if (parsed) {
                samples.push(parsed);
            }
        });
        if (!samples.length) {
            root.querySelectorAll('table.coderunnerexamples tbody tr, .coderunner-examples tbody tr').forEach(function(tr) {
                const cells = tr.querySelectorAll('td');
                if (!cells.length) {
                    return;
                }
                const first = (cells[0].textContent || '').replace(/\u00a0/g, ' ').trim();
                const last = cells.length > 1
                    ? (cells[cells.length - 1].textContent || '').replace(/\u00a0/g, ' ').trim()
                    : '';
                if (looksLikeTestCode(first)) {
                    samples.push({stdin: '', testcode: first, expected: last});
                } else {
                    samples.push({stdin: first, testcode: '', expected: last});
                }
            });
        }

        const unique = [];
        samples.forEach(function(s) {
            const key = (s.stdin || '') + '\0' + (s.testcode || '') + '\0' + (s.expected || '');
            if (!s.stdin && !s.testcode && !s.expected) {
                return;
            }
            if (unique.some(function(u) {
                return (u.stdin || '') + '\0' + (u.testcode || '') + '\0' + (u.expected || '') === key;
            })) {
                return;
            }
            if (unique.length < 6) {
                unique.push(s);
            }
        });
        if (!unique.length) {
            chipsHost.classList.add('ll-cr-hidden');
            if (fillWrap) {
                fillWrap.classList.add('ll-cr-hidden');
            }
            return;
        }
        chipsHost.classList.remove('ll-cr-hidden');
        if (fillWrap) {
            fillWrap.classList.remove('ll-cr-hidden');
        }
        chipsHost.className = 'll-res__pills ll-custom__chips';
        unique.forEach(function(s, i) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'll-res__pill';
            chip.textContent = 'Case ' + (i + 1);
            chip.title = (s.testcode || s.stdin || '(empty)').slice(0, 120);
            chip.addEventListener('click', function() {
                chipsHost.querySelectorAll('.ll-res__pill').forEach(function(p) {
                    p.classList.remove('is-active');
                });
                chip.classList.add('is-active');
                inputEl.value = s.stdin || '';
                if (testcodeEl) {
                    testcodeEl.value = s.testcode || '';
                }
                if (expectedEl) {
                    expectedEl.value = s.expected || '';
                }
                (testcodeEl && s.testcode ? testcodeEl : inputEl).focus();
            });
            chipsHost.appendChild(chip);
        });
    };

    /**
     * @param {Element} outHost
     * @param {Object} data
     */
    const renderResult = function(outHost, data) {
        outHost.innerHTML = '';
        outHost.classList.remove('ll-cr-hidden');
        outHost.className = 'll-res ll-custom__out';

        const compared = !!data.compared;
        const matched = !!data.matched;
        const okRun = !!data.ok;
        let tone = 'mid';
        let title = data.message || 'Finished';
        let sub = '';
        if (okRun && compared) {
            tone = matched ? 'good' : 'bad';
            title = matched ? 'Passed custom test' : 'Failed custom test';
            sub = matched
                ? 'Your output matched the expected result.'
                : 'Your output did not match the expected result.';
        } else if (okRun) {
            tone = 'good';
            title = data.message || 'Run completed';
            sub = 'Custom test finished. This does not affect your grade.';
        } else {
            tone = 'bad';
            title = data.message || 'Custom test error';
            sub = data.status ? ('Status: ' + data.status) : '';
        }

        if (tone === 'good' || tone === 'bad') {
            const banner = document.createElement('div');
            banner.className = 'll-res__banner ll-res__banner--' + tone;
            banner.innerHTML =
                '<div class="ll-res__banner-icon">' + (tone === 'good' ? '✓' : '!') + '</div>' +
                '<div><strong></strong><p></p></div>';
            banner.querySelector('strong').textContent = title;
            banner.querySelector('p').textContent = sub;
            outHost.appendChild(banner);
        }

        const status = document.createElement('div');
        status.className = 'll-res__status' + (tone === 'good' ? ' is-pass' : (tone === 'bad' ? ' is-fail' : ''));
        const badge = document.createElement('span');
        badge.className = 'll-res__badge' + (tone === 'bad' ? ' ll-res__badge--bad' : '');
        if (okRun && compared) {
            badge.textContent = matched ? 'Accepted' : 'Wrong Answer';
        } else if (okRun) {
            badge.textContent = 'Finished';
        } else {
            badge.textContent = 'Error';
        }
        const left = document.createElement('span');
        left.textContent = title;
        status.appendChild(left);
        status.appendChild(badge);
        outHost.appendChild(status);

        const block = function(label, value, variant) {
            const el = document.createElement('div');
            el.className = 'll-res__io' + (variant ? ' ll-res__io--' + variant : '');
            const head = document.createElement('div');
            head.className = 'll-res__io-head';
            const leftEl = document.createElement('span');
            leftEl.textContent = label;
            head.appendChild(leftEl);
            if (variant === 'correct') {
                const ok = document.createElement('span');
                ok.className = 'll-res__badge ll-res__badge--sm';
                ok.textContent = 'Correct';
                head.appendChild(ok);
            } else if (variant === 'wrong') {
                const bad = document.createElement('span');
                bad.className = 'll-res__badge ll-res__badge--sm ll-res__badge--bad';
                bad.textContent = 'Incorrect';
                head.appendChild(bad);
            } else {
                const chars = document.createElement('span');
                chars.className = 'll-res__chars';
                const text = (value === '' || value === null || value === undefined) ? '' : String(value);
                chars.textContent = text.length + ' characters';
                head.appendChild(chars);
            }
            const body = document.createElement('pre');
            body.className = 'll-res__io-body';
            const empty = (value === '' || value === null || value === undefined);
            if (empty && /your output/i.test(label)) {
                body.textContent = 'No Output';
                body.classList.add('ll-res__io-body--empty');
            } else {
                body.textContent = empty ? '—' : String(value);
            }
            el.appendChild(head);
            el.appendChild(body);
            return el;
        };

        if (data.testcode) {
            outHost.appendChild(block('Test Code', data.testcode, ''));
        }
        outHost.appendChild(block('Input', data.stdin, ''));
        if (data.expected !== '') {
            outHost.appendChild(block('Expected Output', data.expected, ''));
        }
        outHost.appendChild(block(
            'Your Output',
            data.output,
            compared ? (matched ? 'correct' : 'wrong') : ''
        ));
        if (data.cmpinfo) {
            outHost.appendChild(block('Compiler', data.cmpinfo, 'wrong'));
        }
        if (data.stderr) {
            outHost.appendChild(block('Stderr', data.stderr, 'wrong'));
        }
    };

    /**
     * @param {Element} panel
     * @param {Element} split
     */
    const mount = function(panel, split) {
        if (!panel) {
            return;
        }
        if (panel.getAttribute('data-ll-custom') === '1') {
            const stdinEl = panel.querySelector('[data-ll-custom-stdin]');
            const testcodeEl = panel.querySelector('[data-ll-custom-testcode]');
            const expectedEl = panel.querySelector('[data-ll-custom-expected]');
            const chipsHost = panel.querySelector('[data-ll-custom-chips]');
            fillSampleChips(split, chipsHost, stdinEl, testcodeEl, expectedEl);
            return;
        }
        panel.setAttribute('data-ll-custom', '1');
        panel.classList.add('ll-custom', 'll-res');
        panel.innerHTML =
            '<div class="ll-samples__heading ll-custom__heading">' +
                '<span class="ll-samples__heading-icon" aria-hidden="true">🧪</span>' +
                '<span class="ll-samples__heading-text">Custom Test</span>' +
            '</div>' +
            '<p class="ll-res__sub ll-custom__hint">Run your solution through this question’s template. ' +
                'Does not affect your grade. Use <strong>Test Code</strong> for functions ' +
                '(e.g. <code>print(foo(3))</code>), or <strong>Input</strong> for full programs.</p>' +
            '<div class="ll-custom__fill">' +
                '<div class="ll-samples__label">Fill from sample</div>' +
                '<div class="ll-res__pills ll-custom__chips ll-cr-hidden" data-ll-custom-chips></div>' +
            '</div>' +
            '<div class="ll-custom__form">' +
                '<div class="ll-samples__field">' +
                    '<div class="ll-samples__label">Test Code:</div>' +
                    '<textarea class="ll-samples__box ll-custom__textarea" data-ll-custom-testcode rows="3" ' +
                        'spellcheck="false" placeholder="e.g. print(my_function(2, 3))"></textarea>' +
                '</div>' +
                '<div class="ll-samples__field">' +
                    '<div class="ll-samples__label">Input:</div>' +
                    '<textarea class="ll-samples__box ll-custom__textarea" data-ll-custom-stdin rows="5" ' +
                        'spellcheck="false" placeholder="Standard input (optional)…"></textarea>' +
                '</div>' +
                '<div class="ll-samples__field ll-custom__field--full">' +
                    '<div class="ll-samples__label">Expected Output:</div>' +
                    '<textarea class="ll-samples__box ll-custom__textarea" data-ll-custom-expected rows="4" ' +
                        'spellcheck="false" placeholder="Optional — leave blank to only view output"></textarea>' +
                '</div>' +
            '</div>' +
            '<div class="ll-custom__actions">' +
                '<button type="button" class="ll-cr-btn ll-cr-btn--run" data-ll-custom-run>▶  Run</button>' +
                '<span class="ll-custom__status" data-ll-custom-status></span>' +
            '</div>' +
            '<div class="ll-res ll-custom__out ll-cr-hidden" data-ll-custom-out></div>';

        const stdinEl = panel.querySelector('[data-ll-custom-stdin]');
        const testcodeEl = panel.querySelector('[data-ll-custom-testcode]');
        const expectedEl = panel.querySelector('[data-ll-custom-expected]');
        const runBtn = panel.querySelector('[data-ll-custom-run]');
        const statusEl = panel.querySelector('[data-ll-custom-status]');
        const outHost = panel.querySelector('[data-ll-custom-out]');
        const chipsHost = panel.querySelector('[data-ll-custom-chips]');

        fillSampleChips(split, chipsHost, stdinEl, testcodeEl, expectedEl);
        window.setTimeout(function() {
            fillSampleChips(split, chipsHost, stdinEl, testcodeEl, expectedEl);
        }, 600);

        const setBusy = function(busy, msg) {
            runBtn.disabled = !!busy;
            runBtn.classList.toggle('is-busy', !!busy);
            statusEl.textContent = msg || '';
        };

        runBtn.addEventListener('click', function() {
            const source = getSourceCode(split);
            if (!source.trim()) {
                setBusy(false, 'Write some code first.');
                return;
            }
            const meta = getAttemptMeta(split);
            if (!meta.attemptid || !meta.slot) {
                setBusy(false, 'Missing attempt/slot — reload the page.');
                return;
            }
            if (!meta.cmid) {
                setBusy(false, 'Missing course module id — reload the page.');
                return;
            }

            const stdin = stdinEl.value || '';
            const testcode = testcodeEl.value || '';
            const expected = expectedEl.value || '';
            if (!stdin.trim() && !testcode.trim()) {
                setBusy(false, 'Enter test code and/or stdin.');
                return;
            }

            setBusy(true, 'Running…');
            outHost.classList.add('ll-cr-hidden');

            Ajax.call([{
                methodname: 'local_llassessment_run_custom_test',
                args: {
                    attemptid: meta.attemptid,
                    cmid: meta.cmid,
                    slot: meta.slot,
                    answer: source,
                    stdin: stdin,
                    testcode: testcode,
                    expected: expected,
                    language: getLanguageValue(split)
                }
            }])[0].then(function(result) {
                renderResult(outHost, {
                    ok: !!(result && result.ok),
                    status: result && result.status,
                    message: result && result.message,
                    compared: !!(result && result.compared),
                    matched: !!(result && result.matched),
                    stdin: stdin,
                    testcode: testcode,
                    expected: expected,
                    output: result && result.output,
                    stderr: result && result.stderr,
                    cmpinfo: result && result.cmpinfo
                });
                setBusy(false, (result && result.message) || 'Done');
            }).catch(function(err) {
                const msg = (err && (err.message || err.error || err.exception))
                    ? String(err.message || err.error || err.exception)
                    : 'Custom test failed';
                outHost.innerHTML = '';
                outHost.classList.remove('ll-cr-hidden');
                const box = document.createElement('div');
                box.className = 'll-res__banner ll-res__banner--bad';
                box.innerHTML =
                    '<div class="ll-res__banner-icon">!</div>' +
                    '<div><strong>Could not run custom test</strong><p></p></div>';
                box.querySelector('p').textContent = msg;
                outHost.appendChild(box);
                setBusy(false, 'Failed');
            });
        });
    };

    return {
        mount: mount
    };
});
