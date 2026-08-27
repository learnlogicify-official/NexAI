/**
 * Client-side sequential LeetCode import with live progress.
 *
 * @module     qbank_leetcodeimport/import
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * @param {Object} cfg
     */
    const init = (cfg) => {
        const root = document.getElementById('qbank-lc-runner');
        if (!root) {
            return;
        }

        const bar = root.querySelector('[data-role="bar"]');
        const barLabel = root.querySelector('[data-role="barlabel"]');
        const log = root.querySelector('[data-role="log"]');
        const summary = root.querySelector('[data-role="summary"]');
        const resultsBody = root.querySelector('[data-role="results"]');
        const downloadBtn = root.querySelector('[data-role="download"]');
        const doneBox = root.querySelector('[data-role="done"]');

        const problems = Array.isArray(cfg.problems) ? cfg.problems : [];
        const total = problems.length;
        let imported = 0;
        let failed = 0;
        let skipped = 0;
        const xmlParts = [];

        const appendLog = (msg, cls) => {
            if (!log) {
                return;
            }
            const line = document.createElement('div');
            line.className = 'qbank-lc-logline' + (cls ? ' ' + cls : '');
            line.textContent = msg;
            log.appendChild(line);
            log.scrollTop = log.scrollHeight;
        };

        const setProgress = (done) => {
            const pct = total ? Math.round((done / total) * 100) : 100;
            if (bar) {
                bar.style.width = pct + '%';
                bar.setAttribute('aria-valuenow', String(pct));
            }
            if (barLabel) {
                barLabel.textContent = done + ' / ' + total + ' (' + pct + '%)';
            }
        };

        const addResultRow = (name, status, detail) => {
            if (!resultsBody) {
                return;
            }
            const tr = document.createElement('tr');
            const tdName = document.createElement('td');
            const tdStatus = document.createElement('td');
            const tdDetail = document.createElement('td');
            tdName.textContent = name || '—';
            tdStatus.textContent = status;
            tdStatus.className = status === 'OK' ? 'text-success'
                : (status === 'Skipped' ? 'text-warning' : 'text-danger');
            tdDetail.textContent = detail || '';
            tr.appendChild(tdName);
            tr.appendChild(tdStatus);
            tr.appendChild(tdDetail);
            resultsBody.appendChild(tr);
        };

        const extractQuestionXml = (quizXml) => {
            if (!quizXml) {
                return '';
            }
            const m = quizXml.match(/<question[\s\S]*<\/question>/i);
            return m ? m[0] : '';
        };

        const processNext = async (index) => {
            if (index >= total) {
                setProgress(total);
                if (summary) {
                    summary.textContent = (cfg.strings.summary || '')
                        .replace('{total}', String(total))
                        .replace('{imported}', String(imported))
                        .replace('{skipped}', String(skipped))
                        .replace('{failed}', String(failed));
                    summary.classList.remove('d-none');
                }
                if (xmlParts.length && downloadBtn) {
                    const full = '<?xml version="1.0" encoding="UTF-8"?>\n<quiz>\n'
                        + xmlParts.join('\n') + '\n</quiz>\n';
                    const blob = new Blob([full], {type: 'application/xml'});
                    downloadBtn.href = URL.createObjectURL(blob);
                    downloadBtn.download = 'leetcode-coderunner.xml';
                    downloadBtn.classList.remove('d-none');
                }
                if (doneBox) {
                    doneBox.classList.remove('d-none');
                }
                appendLog(cfg.strings.complete || 'Done.', 'is-ok');
                return;
            }

            const problem = problems[index];
            const n = index + 1;
            setProgress(index);
            appendLog('[' + n + '/' + total + '] Starting: ' + problem);

            const body = new URLSearchParams();
            body.set('sesskey', cfg.sesskey);
            body.set('problem', problem);
            body.set('n', String(n));
            body.set('total', String(total));
            body.set('courseid', String(cfg.courseid));
            body.set('cat', cfg.cat);
            body.set('options', JSON.stringify(cfg.options || {}));

            let data;
            try {
                const res = await fetch(cfg.ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: body.toString(),
                });
                const raw = await res.text();
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    const stripped = String(raw || '')
                        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                        .replace(/<[^>]+>/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim()
                        .slice(0, 400);
                    throw new Error(
                        'Server returned HTML/non-JSON (HTTP ' + res.status + '): '
                        + (stripped || '(empty body)')
                    );
                }
            } catch (err) {
                failed += 1;
                appendLog('[' + n + '/' + total + '] Error: ' + (err && err.message ? err.message : err), 'is-err');
                addResultRow(problem, 'Failed', String(err && err.message ? err.message : err));
                if (cfg.options && cfg.options.stoponerror) {
                    setProgress(n);
                    if (doneBox) {
                        doneBox.classList.remove('d-none');
                    }
                    return;
                }
                await processNext(index + 1);
                return;
            }

            const payload = (data && data.data) ? data.data : {};
            const steps = Array.isArray(payload.steps) ? payload.steps : [];
            steps.forEach((s) => appendLog(s));

            if (data && data.success && payload.ok) {
                if (payload.status === 'skipped') {
                    skipped += 1;
                    appendLog('[' + n + '/' + total + '] Skipped — ' + (payload.detail || payload.name || problem), 'is-skip');
                    addResultRow(payload.name || problem, 'Skipped', payload.detail || '');
                } else if (cfg.options && cfg.options.dryrun) {
                    skipped += 1;
                    appendLog('[' + n + '/' + total + '] OK — ' + (payload.name || problem), 'is-ok');
                    addResultRow(payload.name || problem, 'OK', payload.detail || '');
                    const qxml = extractQuestionXml(payload.xml || '');
                    if (qxml) {
                        xmlParts.push(qxml);
                    }
                } else {
                    imported += 1;
                    appendLog('[' + n + '/' + total + '] OK — ' + (payload.name || problem), 'is-ok');
                    addResultRow(payload.name || problem, 'OK', payload.detail || '');
                    const qxml = extractQuestionXml(payload.xml || '');
                    if (qxml) {
                        xmlParts.push(qxml);
                    }
                }
            } else {
                failed += 1;
                const err = (data && data.error) || payload.detail || 'Failed';
                appendLog('[' + n + '/' + total + '] Failed — ' + err, 'is-err');
                addResultRow(payload.name || problem, 'Failed', err);
                if (cfg.options && cfg.options.stoponerror) {
                    setProgress(n);
                    if (summary) {
                        summary.textContent = (cfg.strings.summary || '')
                            .replace('{total}', String(total))
                            .replace('{imported}', String(imported))
                            .replace('{skipped}', String(skipped))
                            .replace('{failed}', String(failed));
                        summary.classList.remove('d-none');
                    }
                    if (doneBox) {
                        doneBox.classList.remove('d-none');
                    }
                    return;
                }
            }

            setProgress(n);
            await processNext(index + 1);
        };

        appendLog((cfg.strings.start || 'Starting import…') + ' (' + total + ')');
        setProgress(0);
        processNext(0);
    };

    return {init: init};
});
