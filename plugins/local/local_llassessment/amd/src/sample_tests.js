/**
 * Restyle CodeRunner "For example" tables into Sample Test Cases cards.
 *
 * @module     local_llassessment/sample_tests
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /**
     * @param {string} text
     * @return {string}
     */
    const cleanCell = function(text) {
        return (text || '').replace(/\u00a0/g, ' ').trim();
    };

    /**
     * Convert one examples table into Input / Expected Output cards.
     *
     * @param {HTMLTableElement} table
     * @return {Element}
     */
    const tableToCards = function(table) {
        const wrap = document.createElement('div');
        wrap.className = 'll-samples';

        const heads = Array.prototype.map.call(
            table.querySelectorAll('thead th, tr:first-child th'),
            function(th) {
                return cleanCell(th.textContent).toLowerCase();
            }
        );

        // Moodle may put heads only in first row as th, or use thead.
        let headerCells = table.querySelectorAll('thead th');
        if (!headerCells.length) {
            const firstRow = table.querySelector('tr');
            if (firstRow) {
                headerCells = firstRow.querySelectorAll('th');
            }
        }
        const headers = Array.prototype.map.call(headerCells, function(th) {
            return cleanCell(th.textContent);
        });

        const findCol = function(names) {
            for (let i = 0; i < headers.length; i++) {
                const h = headers[i].toLowerCase();
                for (let j = 0; j < names.length; j++) {
                    if (h.indexOf(names[j]) !== -1) {
                        return i;
                    }
                }
            }
            return -1;
        };

        // Prefer Input/stdin; do not steal the "Test" column when Input exists.
        let inputIdx = findCol(['stdin', 'input']);
        if (inputIdx < 0) {
            inputIdx = findCol(['test']);
        }
        let outputIdx = findCol(['expected']);
        if (outputIdx < 0) {
            outputIdx = findCol(['result', 'output']);
        }
        // Common 2-column layout: Test / Result or Input / Expected.
        if (inputIdx < 0 && headers.length >= 1) {
            inputIdx = 0;
        }
        if (outputIdx < 0 && headers.length >= 2) {
            outputIdx = headers.length - 1;
        }
        if (outputIdx === inputIdx && headers.length >= 2) {
            outputIdx = inputIdx === 0 ? 1 : 0;
        }

        const rows = table.querySelectorAll('tbody tr');
        const rowList = rows.length ? rows : table.querySelectorAll('tr');

        Array.prototype.forEach.call(rowList, function(tr) {
            const cells = tr.querySelectorAll('td');
            if (!cells.length) {
                return; // Skip header row.
            }
            const inputText = cleanCell(
                (inputIdx >= 0 && cells[inputIdx]) ? cells[inputIdx].textContent : ''
            );
            const outputText = cleanCell(
                (outputIdx >= 0 && cells[outputIdx]) ? cells[outputIdx].textContent : ''
            );

            const card = document.createElement('div');
            card.className = 'll-samples__card';

            const inBlock = document.createElement('div');
            inBlock.className = 'll-samples__field';
            const inLabel = document.createElement('div');
            inLabel.className = 'll-samples__label';
            inLabel.textContent = 'Input:';
            const inBox = document.createElement('pre');
            inBox.className = 'll-samples__box';
            inBox.textContent = inputText || '—';
            inBlock.appendChild(inLabel);
            inBlock.appendChild(inBox);

            const outBlock = document.createElement('div');
            outBlock.className = 'll-samples__field';
            const outLabel = document.createElement('div');
            outLabel.className = 'll-samples__label';
            outLabel.textContent = 'Expected Output:';
            const outBox = document.createElement('pre');
            outBox.className = 'll-samples__box';
            outBox.textContent = outputText || '—';
            outBlock.appendChild(outLabel);
            outBlock.appendChild(outBox);

            card.appendChild(inBlock);
            card.appendChild(outBlock);
            wrap.appendChild(card);
        });

        return wrap;
    };

    /**
     * Wrap prose (not sample cases) in a bordered problem card.
     *
     * @param {Element} stemHost
     */
    const wrapProblemCard = function(stemHost) {
        if (!stemHost || stemHost.querySelector(':scope > .ll-problem-card')) {
            return;
        }
        const card = document.createElement('div');
        card.className = 'll-problem-card';
        const deferred = [];
        Array.prototype.slice.call(stemHost.childNodes).forEach(function(node) {
            if (node.nodeType === 1) {
                const cls = node.className || '';
                if (node.classList.contains('ll-samples-wrap')
                    || node.classList.contains('ll-samples')
                    || node.classList.contains('ll-samples__heading')
                    || node.classList.contains('for-example-para')
                    || node.classList.contains('coderunner-examples')
                    || node.matches('table.coderunnerexamples')) {
                    deferred.push(node);
                    return;
                }
            }
            card.appendChild(node);
        });
        if (!card.childNodes.length) {
            return;
        }
        stemHost.insertBefore(card, stemHost.firstChild);
        deferred.forEach(function(n) {
            stemHost.appendChild(n);
        });
    };

    /**
     * @param {Element} [root]
     */
    const enhance = function(root) {
        root = root || document;

        root.querySelectorAll('.ll-arena-split__stem').forEach(function(stem) {
            wrapProblemCard(stem);
        });

        root.querySelectorAll('.for-example-para').forEach(function(p) {
            if (p.dataset.llSamplesTitled) {
                return;
            }
            p.dataset.llSamplesTitled = '1';
            p.classList.add('ll-samples__heading');
            p.innerHTML = '<span class="ll-samples__heading-icon" aria-hidden="true">📄</span>' +
                '<span class="ll-samples__heading-text">Sample Test Cases</span>';
        });

        root.querySelectorAll('.coderunner-examples, table.coderunnerexamples').forEach(function(box) {
            if (box.dataset.llSamplesDone) {
                return;
            }
            const table = box.matches('table') ? box : box.querySelector('table');
            if (!table) {
                return;
            }
            // Prefer wrapping the outer examples container.
            const host = box.matches('table') ? (box.closest('.coderunner-examples') || box.parentElement) : box;
            if (host && host.dataset && host.dataset.llSamplesDone) {
                return;
            }
            box.dataset.llSamplesDone = '1';
            if (host && host.dataset) {
                host.dataset.llSamplesDone = '1';
            }
            const cards = tableToCards(table);
            table.replaceWith(cards);
            if (host && host.classList) {
                host.classList.add('ll-samples-wrap');
            }
            // Ensure a heading exists above samples when Moodle omitted "For example".
            const stem = (host && host.closest('.ll-arena-split__stem')) || null;
            if (stem && !stem.querySelector('.ll-samples__heading')) {
                const heading = document.createElement('div');
                heading.className = 'll-samples__heading';
                heading.innerHTML = '<span class="ll-samples__heading-icon" aria-hidden="true">📄</span>' +
                    '<span class="ll-samples__heading-text">Sample Test Cases</span>';
                if (host && host.parentNode === stem) {
                    stem.insertBefore(heading, host);
                } else {
                    stem.appendChild(heading);
                }
            }
        });
    };

    /**
     * Fill a host element with the same Sample Test Cases cards as the left panel.
     *
     * @param {Element} host
     * @param {Element} [root]
     */
    const fillHost = function(host, root) {
        if (!host) {
            return false;
        }
        root = root || document;
        // Prefer already-styled cards from the problem pane.
        let src = root.querySelector('.ll-arena-split__stem .ll-samples, .ll-samples-wrap .ll-samples, .ll-samples');
        if (!src) {
            // Try converting examples first, then look again.
            enhance(root);
            src = root.querySelector('.ll-arena-split__stem .ll-samples, .ll-samples-wrap .ll-samples, .ll-samples');
        }
        host.innerHTML = '';
        const heading = document.createElement('div');
        heading.className = 'll-samples__heading';
        heading.innerHTML = '<span class="ll-samples__heading-icon" aria-hidden="true">📄</span>' +
            '<span class="ll-samples__heading-text">Sample Test Cases</span>';
        host.appendChild(heading);

        if (src) {
            const clone = src.cloneNode(true);
            clone.classList.add('ll-samples--tab');
            host.appendChild(clone);
            return true;
        }

        const empty = document.createElement('span');
        empty.className = 'll-cr-placeholder';
        empty.textContent = 'No sample test cases are available for this question.';
        host.appendChild(empty);
        return false;
    };

    return {
        enhance: enhance,
        fillHost: fillHost,
        tableToCards: tableToCards
    };
});
