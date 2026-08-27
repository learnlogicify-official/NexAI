/**
 * Restyle CodeRunner Precheck/Check results into platform-style result views.
 *
 * @module     local_llassessment/result_view
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const clean = function(t) {
        return (t || '').replace(/\u00a0/g, ' ').trim();
    };

    /**
     * @param {Element} cell
     * @return {boolean|null} true pass, false fail, null unknown
     */
    const cellPassed = function(cell) {
        if (!cell) {
            return null;
        }
        if (cell.querySelector('img[src*="grade_correct"], img[src*="correct"], .icon.fa-check, .text-success')) {
            return true;
        }
        if (cell.querySelector('img[src*="grade_incorrect"], img[src*="incorrect"], .icon.fa-remove, .text-danger')) {
            return false;
        }
        const cls = (cell.className || '') + ' ' + ((cell.querySelector('[class]') || {}).className || '');
        if (/correct|pass|good/i.test(cls) && !/incorrect|fail|bad/i.test(cls)) {
            return true;
        }
        if (/incorrect|fail|bad/i.test(cls)) {
            return false;
        }
        const txt = clean(cell.textContent).toLowerCase();
        if (txt === '1' || txt === 'yes' || txt === 'true') {
            return true;
        }
        if (txt === '0' || txt === 'no' || txt === 'false') {
            return false;
        }
        return null;
    };

    /**
     * Prefer exact header match, then includes-match. Skip used columns.
     *
     * @param {string[]} headers
     * @param {string[]} exact
     * @param {string[]} fuzzy
     * @param {number[]} used
     * @return {number}
     */
    const findCol = function(headers, exact, fuzzy, used) {
        used = used || [];
        const free = function(i) {
            return used.indexOf(i) === -1;
        };
        let i;
        for (i = 0; i < headers.length; i++) {
            if (!free(i)) {
                continue;
            }
            for (let j = 0; j < exact.length; j++) {
                if (headers[i] === exact[j]) {
                    return i;
                }
            }
        }
        for (i = 0; i < headers.length; i++) {
            if (!free(i)) {
                continue;
            }
            for (let j = 0; j < fuzzy.length; j++) {
                if (headers[i].indexOf(fuzzy[j]) !== -1) {
                    return i;
                }
            }
        }
        return -1;
    };

    /**
     * True if this table cell is a CodeRunner tick/cross status column.
     *
     * @param {Element} cell
     * @return {boolean}
     */
    const isStatusCell = function(cell) {
        if (!cell) {
            return false;
        }
        if (cell.querySelector(
            'img[src*="grade_"], img[src*="correct"], img[src*="incorrect"],' +
            'img.icon, .icon.fa-check, .icon.fa-remove, .icon.fa-times, .icon.fa-xmark,' +
            '.fa-check, .fa-remove, .fa-times, .fa-xmark, .text-success, .text-danger'
        )) {
            // Status cells are almost empty aside from the icon.
            return clean(cell.textContent).length <= 2;
        }
        return false;
    };

    /**
     * Resolve Input / Expected / Got / Correct columns for CodeRunner tables.
     * Default CR headers: (tick) | Test? | Input | Expected | Got | (tick).
     *
     * @param {string[]} headers
     * @return {{input: number, expected: number, got: number, correct: number}}
     */
    const resolveColumns = function(headers) {
        let idxGot = findCol(headers, ['got'], ['got', 'actual'], []);
        let idxExpected = findCol(headers, ['expected'], ['expected'], idxGot >= 0 ? [idxGot] : []);
        let idxInput = findCol(
            headers,
            ['input', 'stdin'],
            ['stdin', 'input'],
            [idxGot, idxExpected].filter(function(x) {
                return x >= 0;
            })
        );
        // Only use "test" when there is no Input/stdin column at all.
        if (idxInput < 0) {
            idxInput = findCol(
                headers,
                ['test'],
                [],
                [idxGot, idxExpected].filter(function(x) {
                    return x >= 0;
                })
            );
        }

        let idxCorrect = findCol(headers, ['iscorrect', 'correct', 'mark', 'ok'], ['iscorrect'], []);
        if (idxCorrect < 0) {
            for (let i = 0; i < headers.length; i++) {
                if (headers[i] === '') {
                    idxCorrect = i;
                    break;
                }
            }
        }

        if (idxExpected < 0 || idxGot < 0) {
            const dataCols = [];
            for (let i = 0; i < headers.length; i++) {
                if (i === idxCorrect || headers[i] === '') {
                    continue;
                }
                dataCols.push(i);
            }
            if (dataCols.length >= 1 && idxGot < 0) {
                idxGot = dataCols[dataCols.length - 1];
            }
            if (dataCols.length >= 2 && idxExpected < 0) {
                idxExpected = dataCols[dataCols.length - 2];
            }
            if (dataCols.length >= 3 && idxInput < 0) {
                idxInput = dataCols[dataCols.length - 3];
            }
        }

        return {
            input: idxInput,
            expected: idxExpected,
            got: idxGot,
            correct: idxCorrect
        };
    };

    /**
     * Map named columns onto real <td> indices.
     * Themes often drop empty iscorrect <th></th>, so header count < cell count
     * and naive indexing shifts Input→Expected→Got by one (exactly the bug we saw).
     *
     * @param {string[]} headers
     * @param {NodeList|Element[]} sampleCells
     * @return {{input: number, expected: number, got: number, correct: number}}
     */
    const resolveColumnsForCells = function(headers, sampleCells) {
        const cells = Array.prototype.slice.call(sampleCells || []);
        headers = (headers || []).slice();
        if (!cells.length) {
            return resolveColumns(headers);
        }

        // Pad missing blank status headers so names line up with <td>s.
        if (headers.length < cells.length) {
            let guard = 0;
            while (headers.length < cells.length && guard < 4) {
                guard++;
                const deficit = cells.length - headers.length;
                const firstStatus = isStatusCell(cells[0]);
                const lastStatus = isStatusCell(cells[cells.length - 1]);
                if (deficit >= 1 && firstStatus && headers[0] !== '') {
                    headers = [''].concat(headers);
                } else if (deficit >= 1 && lastStatus && headers[headers.length - 1] !== '') {
                    headers = headers.concat(['']);
                } else if (deficit >= 1) {
                    // Default CodeRunner: leading tick column header is ''.
                    headers = [''].concat(headers);
                } else {
                    break;
                }
            }
        }

        if (headers.length === cells.length) {
            const mapped = resolveColumns(headers);
            // If Input still looks empty but next data col has content, and we
            // landed on a status cell, something is still wrong — fall through.
            if (mapped.input >= 0 && !isStatusCell(cells[mapped.input])) {
                return mapped;
            }
            if (mapped.input < 0 || mapped.got >= 0) {
                return mapped;
            }
        }

        const dataIndices = [];
        const statusIndices = [];
        for (let i = 0; i < cells.length; i++) {
            if (isStatusCell(cells[i])) {
                statusIndices.push(i);
            } else {
                dataIndices.push(i);
            }
        }

        let dataHeaders = headers.filter(function(h) {
            return h !== '' && h !== 'iscorrect';
        });
        if (dataHeaders.length !== dataIndices.length && headers.length === dataIndices.length) {
            dataHeaders = headers.slice();
        }

        if (dataHeaders.length === dataIndices.length && dataIndices.length) {
            const mapped = resolveColumns(dataHeaders);
            return {
                input: mapped.input >= 0 ? dataIndices[mapped.input] : -1,
                expected: mapped.expected >= 0 ? dataIndices[mapped.expected] : -1,
                got: mapped.got >= 0 ? dataIndices[mapped.got] : -1,
                correct: statusIndices.length ? statusIndices[0] : -1
            };
        }

        // Positional: last data = Got, second-last = Expected, third-last = Input.
        const n = dataIndices.length;
        return {
            got: n >= 1 ? dataIndices[n - 1] : -1,
            expected: n >= 2 ? dataIndices[n - 2] : -1,
            input: n >= 3 ? dataIndices[n - 3] : -1,
            correct: statusIndices.length ? statusIndices[0] : (cells.length ? cells.length - 1 : -1)
        };
    };

    /**
     * CodeRunner puts "precheck" on a WRAPPER div, while the <table> also has
     * coderunner-test-results — so closest('.coderunner-test-results') can miss .precheck.
     *
     * @param {Element} table
     * @param {Element} feedbackNode
     * @return {boolean}
     */
    const detectPrecheck = function(table, feedbackNode) {
        const nodes = [feedbackNode, table];
        if (table && table.parentElement) {
            nodes.push(table.parentElement);
        }
        if (feedbackNode && feedbackNode.parentElement) {
            nodes.push(feedbackNode.parentElement);
        }
        for (let i = 0; i < nodes.length; i++) {
            const n = nodes[i];
            if (!n) {
                continue;
            }
            if (n.classList && n.classList.contains('precheck')) {
                return true;
            }
            if (n.closest && n.closest('.precheck, .coderunner-test-results.precheck')) {
                return true;
            }
        }
        const blob = ((feedbackNode && feedbackNode.textContent) || '')
            + ' '
            + ((table && table.parentElement && table.parentElement.textContent) || '');
        if (/precheck\s*only/i.test(blob) || /\bprecheck\b/i.test(blob.slice(0, 400))) {
            return true;
        }
        // Hidden-test rows present + few visible often means a full Check; absence of
        // hidden rows with a short table is typical of example Precheck — handled by class above.
        return false;
    };

    /**
     * CodeRunner puts good/bad on a WRAPPER div; the <table> also has
     * coderunner-test-results. table.closest(...) matches the table first and
     * misses the wrapper class — which made us treat failed hidden runs as pass.
     *
     * @param {Element} table
     * @param {Element} feedbackNode
     * @return {Element|null}
     */
    const resolveResultsWrap = function(table, feedbackNode) {
        const candidates = [];
        if (feedbackNode) {
            candidates.push(feedbackNode);
            if (feedbackNode.parentElement) {
                candidates.push(feedbackNode.parentElement);
            }
        }
        if (table && table.parentElement) {
            const outer = table.parentElement.closest
                ? table.parentElement.closest('.coderunner-test-results, .coderunner-feedback')
                : null;
            if (outer) {
                candidates.push(outer);
            }
            candidates.push(table.parentElement);
        }
        if (table) {
            candidates.push(table);
        }
        for (let i = 0; i < candidates.length; i++) {
            const n = candidates[i];
            if (n && n.classList && (n.classList.contains('good') || n.classList.contains('bad'))) {
                return n;
            }
        }
        for (let j = 0; j < candidates.length; j++) {
            const n2 = candidates[j];
            if (n2 && n2.classList && n2.classList.contains('coderunner-test-results')) {
                return n2;
            }
        }
        return table || feedbackNode || null;
    };

    /**
     * Pull mark / state / penalty from Moodle outcome near the feedback.
     *
     * @param {Element} feedbackNode
     * @param {Element} wrap
     * @return {{markText: string, stateText: string, penaltyText: string, fraction: number|null, isCorrect: boolean|null}}
     */
    const extractOutcomeSummary = function(feedbackNode, wrap) {
        const que = (feedbackNode && feedbackNode.closest && feedbackNode.closest('.que'))
            || (wrap && wrap.closest && wrap.closest('.que'))
            || null;
        const root = que || wrap || feedbackNode || document;
        let markText = '';
        let stateText = '';
        let penaltyText = '';
        let fraction = null;
        let isCorrect = null;

        if (que) {
            if (que.classList.contains('incorrect') || que.classList.contains('partiallycorrect')) {
                isCorrect = false;
            } else if (que.classList.contains('correct')) {
                isCorrect = true;
            }
        }

        const stateEl = root.querySelector
            ? root.querySelector('.info .state, .state, .outcome .state')
            : null;
        if (stateEl) {
            stateText = clean(stateEl.textContent);
            if (/incorrect|partial|wrong|not\s+correct/i.test(stateText)) {
                isCorrect = false;
            } else if (/^correct$/i.test(stateText) || (/\bcorrect\b/i.test(stateText)
                    && !/incorrect|partial/i.test(stateText))) {
                isCorrect = true;
            }
        }

        const gradeEl = root.querySelector
            ? root.querySelector('.info .grade, .grade, .outcome .grade')
            : null;
        if (gradeEl) {
            markText = clean(gradeEl.textContent);
        }

        const outcomeEl = root.querySelector ? root.querySelector('.outcome') : null;
        const blob = clean(
            ((outcomeEl && outcomeEl.textContent) || '')
            + ' '
            + ((feedbackNode && feedbackNode.textContent) || '')
            + ' '
            + markText
        );

        if (!markText) {
            const marksLine = blob.match(
                /marks?\s+for\s+this\s+submission\s*[:.]?\s*[\d.]+\s*(?:\/|out of)\s*[\d.]+/i
            ) || blob.match(/mark\s+[\d.]+\s*(?:\/|out of)\s*[\d.]+/i);
            if (marksLine) {
                markText = clean(marksLine[0]);
            }
        }

        const markMatch = (markText || blob).match(/([\d.]+)\s*(?:\/|out of)\s*([\d.]+)/i);
        if (markMatch) {
            const got = parseFloat(markMatch[1]);
            const max = parseFloat(markMatch[2]);
            if (!isNaN(got) && !isNaN(max) && max > 0) {
                fraction = got / max;
                if (fraction < 0.999) {
                    isCorrect = false;
                }
            }
        }

        const pen = blob.match(
            /(?:this\s+submission\s+attracted\s+a\s+)?penalty(?:\s+of)?\s*[^\n.]{0,60}/i
        ) || blob.match(/penalty\s+regime[^\n.]{0,40}/i);
        if (pen) {
            penaltyText = clean(pen[0]);
        }

        return {
            markText: markText,
            stateText: stateText,
            penaltyText: penaltyText,
            fraction: fraction,
            isCorrect: isCorrect
        };
    };

    /**
     * Parse a CodeRunner results table into structured tests.
     *
     * @param {HTMLTableElement} table
     * @param {Element} feedbackNode
     * @return {Object}
     */
    const parseResults = function(table, feedbackNode) {
        const wrap = resolveResultsWrap(table, feedbackNode);
        const isPrecheck = detectPrecheck(table, feedbackNode || wrap);
        const wrapGood = !!(wrap && wrap.classList.contains('good'));
        const wrapBad = !!(wrap && wrap.classList.contains('bad'));
        const outcome = extractOutcomeSummary(feedbackNode, wrap);

        let headers = [];
        const headCells = table.querySelectorAll('thead th');
        if (headCells.length) {
            headers = Array.prototype.map.call(headCells, function(th) {
                return clean(th.textContent).toLowerCase();
            });
        } else {
            const first = table.querySelector('tr');
            if (first && first.querySelectorAll('th').length) {
                headers = Array.prototype.map.call(first.querySelectorAll('th'), function(th) {
                    return clean(th.textContent).toLowerCase();
                });
            }
        }

        const rows = table.querySelectorAll('tbody tr');
        const rowList = rows.length ? rows : table.querySelectorAll('tr');

        // Align headers to cells — empty iscorrect <th> are often missing in the DOM.
        let sampleCells = null;
        Array.prototype.some.call(rowList, function(tr) {
            const tds = tr.querySelectorAll('td');
            if (tds.length) {
                sampleCells = tds;
                return true;
            }
            return false;
        });
        const cols = resolveColumnsForCells(headers, sampleCells);

        const tests = [];

        Array.prototype.forEach.call(rowList, function(tr) {
            const cells = tr.querySelectorAll('td');
            if (!cells.length) {
                return;
            }
            const hidden = tr.classList.contains('hidden-test')
                || tr.getAttribute('data-hidden') === '1'
                || /hidden-test|ishidden/i.test(tr.className || '');
            // If CodeRunner/CSS has visually suppressed the row, treat as hidden too.
            let cssHidden = false;
            try {
                if (typeof window !== 'undefined' && window.getComputedStyle) {
                    const st = window.getComputedStyle(tr);
                    cssHidden = st.display === 'none' || st.visibility === 'hidden';
                }
            } catch (e) {
                cssHidden = false;
            }
            let passed = cols.correct >= 0 ? cellPassed(cells[cols.correct]) : null;
            if (passed === null) {
                for (let s = cells.length - 1; s >= 0; s--) {
                    if (isStatusCell(cells[s])) {
                        passed = cellPassed(cells[s]);
                        break;
                    }
                }
            }
            if (passed === null) {
                passed = cellPassed(cells[cells.length - 1]);
            }
            // Only inherit wrap status for unknown cells when wrap is definitive.
            if (passed === null && wrapGood && !wrapBad) {
                passed = true;
            }
            if (passed === null && wrapBad) {
                // Hidden failing rows may omit icons; do not force fail on visible rows.
                if (hidden || cssHidden) {
                    passed = false;
                }
            }

            const getIo = function(idx) {
                if (idx < 0 || !cells[idx]) {
                    return {text: '', hasPre: false};
                }
                const pre = cells[idx].querySelector('pre');
                if (pre) {
                    return {text: clean(pre.textContent), hasPre: true};
                }
                return {text: clean(cells[idx].textContent), hasPre: false};
            };

            let gotInfo = getIo(cols.got);
            let expInfo = getIo(cols.expected);
            let gotText = gotInfo.text;
            let expectedText = expInfo.text;
            const inputText = getIo(cols.input).text;

            // When stdout is empty, themes/column shifts often make "Got" read the
            // Expected cell — so a failing case wrongly shows Expected as Your Output.
            if (passed === false && gotText !== '' && expectedText !== '' && gotText === expectedText) {
                const gotCell = cols.got >= 0 ? cells[cols.got] : null;
                const expCell = cols.expected >= 0 ? cells[cols.expected] : null;
                if (cols.got === cols.expected) {
                    gotText = '';
                } else if (gotCell && expCell && !gotCell.querySelector('pre') && expCell.querySelector('pre')) {
                    // Expected has <pre>; mis-pointed Got cell has no stdout pre.
                    gotText = '';
                } else {
                    // Prefer an empty data cell (true empty Got) after Expected.
                    for (let i = 0; i < cells.length; i++) {
                        if (isStatusCell(cells[i])) {
                            continue;
                        }
                        if (i === cols.input || i === cols.expected) {
                            continue;
                        }
                        const info = getIo(i);
                        if (info.text === '') {
                            gotText = '';
                            break;
                        }
                    }
                }
            }

            tests.push({
                input: inputText,
                expected: expectedText,
                got: gotText,
                // Keep null as null only when still unknown — coerce for UI later.
                passed: passed === null ? null : !!passed,
                hidden: !!(hidden || cssHidden)
            });
        });

        const anyKnownFail = tests.some(function(t) {
            return t.passed === false;
        });
        const anyUnknown = tests.some(function(t) {
            return t.passed === null;
        });
        // Visible-only "all passed" must NOT override CodeRunner/Moodle overall fail
        // (Display=HIDE removes failing hidden rows from the table entirely).
        let allGood;
        if (wrapBad || outcome.isCorrect === false
                || (outcome.fraction !== null && outcome.fraction < 0.999)) {
            allGood = false;
        } else if (wrapGood && !anyKnownFail) {
            allGood = true;
        } else if (outcome.isCorrect === true && !anyKnownFail) {
            allGood = true;
        } else if (anyKnownFail) {
            allGood = false;
        } else if (tests.length > 0 && !anyUnknown
                && tests.every(function(t) {
                    return t.passed === true;
                })) {
            // All rows present and known-pass, and no wrap/outcome saying otherwise.
            allGood = true;
        } else {
            allGood = false;
        }

        // Normalize for UI consumers that expect boolean passed.
        tests.forEach(function(t) {
            if (t.passed === null) {
                // Unknown row: assume pass only when overall is definitively good.
                t.passed = !!allGood;
            }
        });

        return {
            isPrecheck: isPrecheck,
            allGood: allGood,
            wrapBad: wrapBad,
            outcome: outcome,
            tests: tests
        };
    };

    /**
     * @param {Object} data
     * @param {number} activeIdx
     * @param {Function} onSelect
     * @return {Element}
     */
    const buildDetailView = function(data, activeIdx, onSelect) {
        const root = document.createElement('div');
        root.className = 'll-res ll-res--detail';

        const pills = document.createElement('div');
        pills.className = 'll-res__pills';
        data.tests.forEach(function(t, i) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'll-res__pill' + (i === activeIdx ? ' is-active' : '') + (t.passed ? ' is-pass' : ' is-fail');
            b.innerHTML = 'Test ' + (i + 1) + (t.passed ? ' <span class="ll-res__tick">✓</span>' : ' <span class="ll-res__tick">✗</span>');
            b.addEventListener('click', function() {
                onSelect(i);
            });
            pills.appendChild(b);
        });
        root.appendChild(pills);

        const t = data.tests[activeIdx] || data.tests[0];
        if (!t) {
            return root;
        }

        const status = document.createElement('div');
        status.className = 'll-res__status' + (t.passed ? ' is-pass' : ' is-fail');
        const passedCount = data.tests.filter(function(x) {
            return x.passed;
        }).length;
        let badge = t.passed ? 'Accepted' : 'Wrong Answer';
        let summary = t.passed ? (passedCount + ' Passed') : 'Failed';
        if (t.passed && data.allGood === false) {
            badge = 'Visible pass';
            summary = passedCount + ' visible passed';
        }
        status.innerHTML = '<span>' + summary + '</span>' +
            '<span class="ll-res__badge">' + badge + '</span>';
        root.appendChild(status);
        if (t.passed && data.allGood === false) {
            const note = document.createElement('p');
            note.className = 'll-res__hidden-fail-note';
            note.textContent = 'This visible case passed, but hidden tests failed — see Results for marks/penalty.';
            root.appendChild(note);
        }

        const formatIoValue = function(label, value) {
            const empty = value === '' || value === null || value === undefined;
            if (empty) {
                // Your Output / Got should say No Output; other fields keep an em dash.
                if (/your output|^got$/i.test(label)) {
                    return 'No Output';
                }
                return '—';
            }
            return String(value);
        };

        const block = function(label, value, variant) {
            const el = document.createElement('div');
            el.className = 'll-res__io' + (variant ? ' ll-res__io--' + variant : '');
            const head = document.createElement('div');
            head.className = 'll-res__io-head';
            const left = document.createElement('span');
            left.textContent = label;
            head.appendChild(left);
            const display = formatIoValue(label, value);
            const isNoOutput = display === 'No Output';
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
                chars.textContent = isNoOutput ? '0 characters'
                    : (display === '—' ? '0 characters' : display.length + ' characters');
                head.appendChild(chars);
            }
            const body = document.createElement('pre');
            body.className = 'll-res__io-body' + (isNoOutput ? ' ll-res__io-body--empty' : '');
            body.textContent = display;
            el.appendChild(head);
            el.appendChild(body);
            return el;
        };

        root.appendChild(block('Input', t.input, ''));
        root.appendChild(block('Expected Output', t.expected, ''));
        root.appendChild(block(
            'Your Output',
            t.got,
            t.passed ? 'correct' : 'wrong'
        ));

        return root;
    };

    /**
     * Format mark + penalty lines for banners.
     *
     * @param {Object} [outcome]
     * @return {string} HTML fragment (escaped text only)
     */
    const outcomeLinesHtml = function(outcome) {
        if (!outcome) {
            return '';
        }
        const lines = [];
        if (outcome.stateText) {
            lines.push(outcome.stateText);
        }
        if (outcome.markText) {
            lines.push(outcome.markText);
        }
        if (outcome.penaltyText) {
            lines.push(outcome.penaltyText);
        }
        if (!lines.length) {
            return '';
        }
        return '<p class="ll-res__outcome">' + lines.map(function(l) {
            return String(l).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }).join(' · ') + '</p>';
    };

    /**
     * @param {Object} data
     * @param {Function} [onSelect] optional click handler for test grid cells
     * @return {Element}
     */
    const buildOverview = function(data, onSelect) {
        const root = document.createElement('div');
        root.className = 'll-res ll-res--overview';

        const visiblePassed = data.tests.filter(function(t) {
            return t.passed;
        }).length;
        const failed = data.tests.length - visiblePassed;
        const hiddenFailCount = Math.max(
            data.hiddenFailCount || 0,
            (!data.allGood && !failed) ? 1 : 0
        );
        const hiddenPassedCount = Math.max((data.hiddenCount || 0) - hiddenFailCount, 0);
        const totalPassed = visiblePassed + hiddenPassedCount;
        const overallGood = !!data.allGood;
        // Do not claim 100% success when hidden cases failed overall.
        const rate = overallGood && data.tests.length
            ? Math.round((visiblePassed / data.tests.length) * 100)
            : (data.tests.length && failed
                ? Math.round((visiblePassed / data.tests.length) * 100)
                : (overallGood ? 100 : 0));
        const outcomeHtml = outcomeLinesHtml(data.outcome);

        if (overallGood) {
            const banner = document.createElement('div');
            banner.className = 'll-res__banner ll-res__banner--good';
            banner.innerHTML = '<div class="ll-res__banner-icon">✓</div>' +
                '<div><strong>Excellent Work!</strong>' +
                '<p>All test cases passed successfully. Your solution is correct!</p>' +
                outcomeHtml + '</div>';
            root.appendChild(banner);
        } else if (failed) {
            const banner = document.createElement('div');
            banner.className = 'll-res__banner ll-res__banner--bad';
            banner.innerHTML = '<div class="ll-res__banner-icon">!</div>' +
                '<div><strong>Some tests failed</strong>' +
                '<p>' + visiblePassed + ' visible passed, ' + failed + ' visible failed.' +
                (data.hiddenCount ? ' Additional cases are hidden by Display settings.' : '') +
                '</p>' + outcomeHtml + '</div>';
            root.appendChild(banner);
        } else {
            // No visible failures, but overall run is not good (hidden failures / penalty).
            const banner = document.createElement('div');
            banner.className = 'll-res__banner ll-res__banner--bad';
            const markHint = (data.outcome && data.outcome.markText)
                ? data.outcome.markText
                : 'Your submission did not earn full marks.';
            banner.innerHTML = '<div class="ll-res__banner-icon">!</div>' +
                '<div><strong>Hidden tests failed</strong>' +
                '<p>Visible cases passed, but one or more hidden test cases failed. '
                + markHint + '</p>' + outcomeHtml + '</div>';
            root.appendChild(banner);
        }

        const card = document.createElement('div');
        card.className = 'll-res__card';
        card.innerHTML = '<div class="ll-res__card-head"><h3>Test Results Overview</h3>' +
            '<span class="ll-res__rate">' + rate + '% '
            + (overallGood ? 'Success Rate' : (failed ? 'Visible pass rate' : 'Overall'))
            + '</span></div>';
        const stats = document.createElement('div');
        stats.className = 'll-res__stats';
        stats.innerHTML =
            '<div class="ll-res__stat ll-res__stat--pass"><div class="ll-res__stat-num">' + totalPassed + '</div><div>Passed</div></div>' +
            '<div class="ll-res__stat ll-res__stat--fail"><div class="ll-res__stat-num">' + hiddenFailCount + '</div><div>Hidden Failed</div></div>' +
            '<div class="ll-res__stat"><div class="ll-res__stat-num">' + hiddenPassedCount + '</div><div>Hidden Passed</div></div>' +
            '<div class="ll-res__stat"><div class="ll-res__stat-num">' + visiblePassed + '</div><div>Visible Passed</div></div>';
        card.appendChild(stats);

        if (!overallGood && data.outcome && (data.outcome.markText || data.outcome.penaltyText)) {
            const gradeNote = document.createElement('div');
            gradeNote.className = 'll-res__grade-note';
            const parts = [];
            if (data.outcome.markText) {
                parts.push(data.outcome.markText);
            }
            if (data.outcome.penaltyText) {
                parts.push(data.outcome.penaltyText);
            }
            gradeNote.textContent = parts.join(' · ');
            card.appendChild(gradeNote);
        }

        root.appendChild(card);

        const all = document.createElement('div');
        all.className = 'll-res__card';
        all.innerHTML = '<h3>Visible Test Cases</h3><p class="ll-res__sub">Overview of ' +
            data.tests.length + ' visible test case' + (data.tests.length === 1 ? '' : 's') +
            (!overallGood && !failed
                ? ' — hidden failures are not shown by the question Display settings'
                : '') +
            '</p>';
        const grid = document.createElement('div');
        grid.className = 'll-res__grid';
        data.tests.forEach(function(t, i) {
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'll-res__cell' + (t.passed ? ' is-pass' : ' is-fail');
            cell.textContent = String(i + 1);
            cell.title = 'View Test ' + (i + 1);
            if (typeof onSelect === 'function') {
                cell.addEventListener('click', function() {
                    onSelect(i);
                });
            }
            grid.appendChild(cell);
        });
        all.appendChild(grid);
        root.appendChild(all);

        return root;
    };

    /**
     * Replace raw CodeRunner feedback with styled views inside host panels.
     *
     * @param {Element} feedbackNode
     * @param {Object} panels {sampleHost, resultsHost, activate, preferSample}
     */
    const render = function(feedbackNode, panels) {
        if (!feedbackNode || !panels) {
            return;
        }

        const table = feedbackNode.matches && feedbackNode.matches('table')
            ? feedbackNode
            : feedbackNode.querySelector('table.coderunner-test-results, table');

        if (!table) {
            const simple = document.createElement('div');
            simple.className = 'll-res ll-res--simple';
            const precheckOnly = detectPrecheck(null, feedbackNode) || !!panels.preferSample;
            const outcome = extractOutcomeSummary(feedbackNode, feedbackNode);
            let ok = feedbackNode.classList.contains('good')
                && !feedbackNode.classList.contains('bad');
            if (feedbackNode.classList.contains('bad') || outcome.isCorrect === false
                    || (outcome.fraction !== null && outcome.fraction < 0.999)) {
                ok = false;
            } else if (outcome.isCorrect === true) {
                ok = true;
            } else if (!feedbackNode.classList.contains('good')
                    && !feedbackNode.classList.contains('bad')) {
                ok = /passed|accepted/i.test(feedbackNode.textContent)
                    && !/fail|incorrect|wrong/i.test(feedbackNode.textContent);
            }
            simple.innerHTML = '<div class="ll-res__banner ' + (ok ? 'll-res__banner--good' : 'll-res__banner--bad') + '">' +
                '<div class="ll-res__banner-icon">' + (ok ? '✓' : '!') + '</div>' +
                '<div><strong>' + (precheckOnly ? 'Precheck ' : '') + (ok ? 'Passed' : 'Failed') + '</strong>' +
                '<p>' + clean(feedbackNode.textContent).slice(0, 240) + '</p>' +
                outcomeLinesHtml(outcome) + '</div></div>';
            const host = precheckOnly ? panels.sampleHost : panels.resultsHost;
            host.innerHTML = '';
            host.appendChild(simple);
            feedbackNode.classList.add('ll-cr-hidden');
            host.appendChild(feedbackNode);
            if (panels.autoSwitch) {
                panels.activate(precheckOnly ? 'sample' : 'results');
            }
            return;
        }

        const data = parseResults(table, feedbackNode);
        if (panels.preferSample) {
            data.isPrecheck = true;
        }

        if (!data.tests.length) {
            panels.sampleHost.innerHTML = '';
            panels.sampleHost.appendChild(feedbackNode);
            if (panels.autoSwitch) {
                panels.activate('sample');
            }
            return;
        }

        // Honour CodeRunner per-testcase Display:
        // SHOW | HIDE | HIDE_IF_FAIL | HIDE_IF_SUCCEED (+ hide-rest-if-fail).
        // Those are already applied server-side; hidden rows get .hidden-test
        // (teachers with viewhiddentestcases still see them in the raw table).
        const visibleTests = data.tests.filter(function(t) {
            return !t.hidden;
        });
        const hiddenCount = data.tests.length - visibleTests.length;

        // Never fall back to showing hidden rows — empty visible is a valid state.
        // Detail view "allGood" is only about visible cases for per-test UI;
        // overall correctness stays on data.allGood (includes wrap/outcome).
        const detailData = {
            isPrecheck: data.isPrecheck,
            allGood: !!data.allGood,
            tests: visibleTests
        };

        // Overall correctness from CodeRunner wrap + Moodle outcome (includes hidden).
        const overallGood = !!data.allGood;

        let active = 0;
        const firstFail = detailData.tests.findIndex(function(t) {
            return !t.passed;
        });

        const isCheck = !(data.isPrecheck || panels.preferSample);
        if (firstFail >= 0) {
            active = firstFail;
        }

        const paintDetail = function() {
            panels.sampleHost.innerHTML = '';
            if (!detailData.tests.length) {
                const empty = document.createElement('div');
                empty.className = 'll-res ll-res--simple';
                empty.innerHTML = '<div class="ll-res__banner ' +
                    (overallGood ? 'll-res__banner--good' : 'll-res__banner--bad') + '">' +
                    '<div class="ll-res__banner-icon">' + (overallGood ? '✓' : '!') + '</div>' +
                    '<div><strong>' + (overallGood ? 'Passed' : 'Failed') + '</strong>' +
                    '<p>' + (overallGood
                        ? (hiddenCount
                            ? 'No visible test cases for this run (hidden by Display settings).'
                            : 'No test case details to show.')
                        : (hiddenCount
                            ? 'Hidden test cases failed. Details are not shown by Display settings.'
                            : 'Submission failed — see marks below.')) +
                    '</p>' + outcomeLinesHtml(data.outcome) + '</div></div>';
                panels.sampleHost.appendChild(empty);
            } else {
                panels.sampleHost.appendChild(buildDetailView(detailData, active, function(i) {
                    active = i;
                    paintDetail();
                }));
            }
            feedbackNode.classList.add('ll-cr-hidden');
            panels.sampleHost.appendChild(feedbackNode);
        };

        paintDetail();
        panels.resultsHost.innerHTML = '';
        panels.resultsHost.appendChild(buildOverview({
            isPrecheck: false,
            // Banner reflects full run; grid/stats only visible cases.
            allGood: overallGood,
            hiddenCount: hiddenCount,
            hiddenFailCount: data.tests.filter(function(t) {
                return t.hidden && !t.passed;
            }).length,
            outcome: data.outcome || null,
            tests: visibleTests
        }, function(i) {
            if (i >= 0 && i < detailData.tests.length) {
                active = i;
                paintDetail();
                panels.activate('sample');
            }
        }));

        // Run → Sample Tests; Submit → Hidden Tests (always).
        if (panels.autoSwitch) {
            panels.activate(isCheck ? 'results' : 'sample');
        }
    };

    /**
     * Full expanded list of each visible test case (review page).
     *
     * @param {Object} data from parseResults
     * @return {Element}
     */
    const buildExpandedList = function(data) {
        const root = document.createElement('div');
        root.className = 'll-res ll-res--expanded';
        const tests = (data.tests || []).filter(function(t) {
            return !t.hidden;
        });
        if (!tests.length) {
            const empty = document.createElement('div');
            empty.className = 'll-review-tests__empty';
            empty.textContent = 'No visible test case details are available for this question.';
            root.appendChild(empty);
            return root;
        }

        const passed = tests.filter(function(t) {
            return t.passed;
        }).length;
        const summary = document.createElement('div');
        summary.className = 'll-review-tests__summary';
        summary.textContent = passed + ' / ' + tests.length + ' visible tests passed';
        root.appendChild(summary);

        tests.forEach(function(t, i) {
            const card = document.createElement('article');
            card.className = 'll-review-testcase' + (t.passed ? ' is-pass' : ' is-fail');

            const head = document.createElement('div');
            head.className = 'll-review-testcase__head';
            head.innerHTML =
                '<span class="ll-review-testcase__title">Test case ' + (i + 1) + '</span>' +
                '<span class="ll-review-testcase__badge">' + (t.passed ? 'Passed' : 'Failed') + '</span>';
            card.appendChild(head);

            const block = function(label, value, variant) {
                const el = document.createElement('div');
                el.className = 'll-review-testcase__io' + (variant ? ' ll-review-testcase__io--' + variant : '');
                const h = document.createElement('div');
                h.className = 'll-review-testcase__io-label';
                h.textContent = label;
                const pre = document.createElement('pre');
                pre.className = 'll-review-testcase__io-body';
                const empty = value === '' || value === null || value === undefined;
                if (empty && /got|your output/i.test(label)) {
                    pre.textContent = 'No Output';
                    pre.classList.add('ll-review-testcase__io-body--empty');
                } else {
                    pre.textContent = empty ? '—' : String(value);
                }
                el.appendChild(h);
                el.appendChild(pre);
                return el;
            };

            card.appendChild(block('Input', t.input));
            card.appendChild(block('Expected', t.expected));
            card.appendChild(block('Got', t.got, t.passed ? 'ok' : 'bad'));
            root.appendChild(card);
        });

        return root;
    };

    return {
        render: render,
        parseResults: parseResults,
        buildExpandedList: buildExpandedList
    };
});
