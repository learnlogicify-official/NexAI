/**
 * NexReports All Courses Summary (Edwiser column parity).
 *
 * @module     local_nexreports/courses
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'local_nexreports/table_export'], function(Ajax, TableExport) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    /** Edwiser-style HHH:MM:SS (hours may exceed 24). */
    const formatDuration = function(seconds) {
        let secs = Math.max(0, Math.floor(Number(seconds) || 0));
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        const s = secs % 60;
        const pad = function(n) {
            return n < 10 ? '0' + n : String(n);
        };
        return pad(h) + ':' + pad(m) + ':' + pad(s);
    };

    const drill = function(url) {
        return '<a class="nxr-drill" href="' + esc(url) + '" title="Open report" aria-label="Open report">' +
            '<span class="nxr-drill__icon" aria-hidden="true"></span></a>';
    };

    const selectedExclude = function(root) {
        return Array.prototype.slice.call(root.querySelectorAll('[data-exclude]:checked'))
            .map(function(el) { return el.value; })
            .filter(Boolean)
            .join(',');
    };

    const updateExcludeSummary = function(root) {
        const summary = root.querySelector('[data-region="exclude-summary"]');
        if (!summary) {
            return;
        }
        const labels = Array.prototype.slice.call(root.querySelectorAll('[data-exclude]:checked'))
            .map(function(el) {
                const span = el.parentElement && el.parentElement.querySelector('span');
                return span ? span.textContent.trim() : el.value;
            });
        const fallback = root.getAttribute('data-label-exclude') || 'Exclude';
        summary.textContent = labels.length ? labels.join(', ') : fallback;
    };

    const bindExclude = function(root, onChange) {
        const wrap = root.querySelector('[data-region="exclude-wrap"]');
        const toggle = root.querySelector('[data-region="exclude-toggle"]');
        const menu = root.querySelector('[data-region="exclude-menu"]');
        if (!wrap || !toggle || !menu) {
            return;
        }
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const open = menu.hasAttribute('hidden');
            if (open) {
                menu.removeAttribute('hidden');
                toggle.setAttribute('aria-expanded', 'true');
                wrap.classList.add('is-open');
            } else {
                menu.setAttribute('hidden', 'hidden');
                toggle.setAttribute('aria-expanded', 'false');
                wrap.classList.remove('is-open');
            }
        });
        document.addEventListener('click', function(e) {
            if (!wrap.contains(e.target)) {
                menu.setAttribute('hidden', 'hidden');
                toggle.setAttribute('aria-expanded', 'false');
                wrap.classList.remove('is-open');
            }
        });
        root.querySelectorAll('[data-exclude]').forEach(function(cb) {
            cb.addEventListener('change', function() {
                updateExcludeSummary(root);
                onChange();
            });
        });
        updateExcludeSummary(root);
    };

    const renderPager = function(root, page, pageSize, total) {
        const host = root.querySelector('[data-region="pager"]');
        if (!host) {
            return;
        }
        const pages = Math.max(1, Math.ceil(total / pageSize) || 1);
        page = Math.min(Math.max(1, page), pages);
        const from = total ? ((page - 1) * pageSize + 1) : 0;
        const to = Math.min(total, page * pageSize);
        host.innerHTML =
            '<div class="nxr-pager">' +
            '<span class="nxr-pager__info">Showing ' + from + ' to ' + to + ' of ' + total + ' entries</span>' +
            '<label class="nxr-pager__size">Show ' +
            '<select data-filter="pagesize">' +
            [10, 25, 50, 100].map(function(n) {
                return '<option value="' + n + '"' + (n === pageSize ? ' selected' : '') + '>' + n + '</option>';
            }).join('') +
            '</select></label>' +
            '<div class="nxr-pager__btns">' +
            '<button type="button" class="nxr-pager__btn" data-page="prev"' + (page <= 1 ? ' disabled' : '') + '>Prev</button>' +
            '<span class="nxr-pager__page">Page ' + page + ' / ' + pages + '</span>' +
            '<button type="button" class="nxr-pager__btn" data-page="next"' + (page >= pages ? ' disabled' : '') + '>Next</button>' +
            '</div></div>';
    };

    const fillFilters = function(root, data) {
        const pill = root.querySelector('[data-region="enrolment-label"]');
        if (pill) {
            pill.textContent = data.enrolmentlabel || 'All Time';
        }
    };

    const renderTable = function(root, rows) {
        const host = root.querySelector('[data-region="courses-table"]');
        if (!host) {
            return;
        }
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(root.getAttribute('data-label-nodata')) + '</p>';
            return;
        }

        host.innerHTML =
            '<div class="nxr-table-wrap nxr-table-wrap--acs"><table class="nxr-table nxr-table--acs"><thead><tr>' +
            '<th class="nxr-col-course">Course Name</th>' +
            '<th class="nxr-col-category">Category</th>' +
            '<th class="nxr-table__num">Enrolled</th>' +
            '<th class="nxr-table__num">Completed</th>' +
            '<th class="nxr-table__num">Not<br>Started</th>' +
            '<th class="nxr-table__num">In<br>Progress</th>' +
            '<th class="nxr-table__num nxr-col-wide">At Least One<br>Activity Started</th>' +
            '<th class="nxr-table__num">Total<br>Activities</th>' +
            '<th class="nxr-table__num">Avg.<br>Progress</th>' +
            '<th class="nxr-table__num">Avg.<br>Grade</th>' +
            '<th class="nxr-table__num">Highest<br>Grade</th>' +
            '<th class="nxr-table__num">Lowest<br>Grade</th>' +
            '<th class="nxr-table__num nxr-col-time">Total<br>Time Spent</th>' +
            '<th class="nxr-table__num nxr-col-time">Avg.<br>Time Spent</th>' +
            '</tr></thead><tbody>' +
            rows.map(function(r) {
                return '<tr>' +
                    '<td class="nxr-col-course"><a href="' + esc(r.url) + '">' + esc(r.name) + '</a></td>' +
                    '<td class="nxr-col-category">' + esc(r.category) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.enrolments) + ' ' + drill(r.completionurl) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.completed) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.notstarted) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.inprogress) + '</td>' +
                    '<td class="nxr-table__num nxr-col-wide">' + esc(r.atleastoneactivitystarted) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.totalactivities) +
                        (Number(r.totalactivities) > 0 ? (' ' + drill(r.activitiesurl)) : '') + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.avgprogress) + '%</td>' +
                    '<td class="nxr-table__num">' + esc(r.avggrade) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.highestgrade) + '</td>' +
                    '<td class="nxr-table__num">' + esc(r.lowestgrade) + '</td>' +
                    '<td class="nxr-table__num nxr-col-time">' + esc(formatDuration(r.totaltimespent)) + '</td>' +
                    '<td class="nxr-table__num nxr-col-time">' + esc(formatDuration(r.avgtimespent)) + '</td>' +
                    '</tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    const init = function() {
        const root = document.querySelector('[data-region="nxr-courses"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        let seq = 0;
        let allRows = [];
        let page = 1;
        let pageSize = 25;

        const paint = function() {
            const total = allRows.length;
            const pages = Math.max(1, Math.ceil(total / pageSize) || 1);
            if (page > pages) {
                page = pages;
            }
            const start = (page - 1) * pageSize;
            renderTable(root, allRows.slice(start, start + pageSize));
            renderPager(root, page, pageSize, total);
        };

        const load = function() {
            const id = ++seq;
            const host = root.querySelector('[data-region="courses-table"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            const enrolment = root.querySelector('[data-filter="enrolment"]');
            const search = root.querySelector('[data-filter="search"]');
            Ajax.call([{
                methodname: 'local_nexreports_get_courses_summary',
                args: {
                    enrolment: enrolment ? enrolment.value : 'all',
                    exclude: selectedExclude(root),
                    search: search ? search.value : '',
                    limit: 2000,
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                fillFilters(root, data);
                allRows = data.rows || [];
                page = 1;
                paint();
                const url = new URL(root.getAttribute('data-export-url'), window.location.origin);
                    url.searchParams.set('enrolment', data.enrolment || 'all');
                    url.searchParams.set('exclude', data.exclude || '');
                    url.searchParams.set('search', data.search || '');
                    TableExport.sync(root, url);
                return null;
            }).catch(function() {
                if (id === seq && host) {
                    host.innerHTML = '<p class="nxr-error">' +
                        esc(root.getAttribute('data-label-loaderror')) + '</p>';
                }
            });
        };

        let timer = null;
        const search = root.querySelector('[data-filter="search"]');
        const enrolment = root.querySelector('[data-filter="enrolment"]');
        if (search) {
            search.addEventListener('input', function() {
                window.clearTimeout(timer);
                timer = window.setTimeout(load, 300);
            });
        }
        if (enrolment) {
            enrolment.addEventListener('change', load);
        }
        bindExclude(root, load);
        root.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-page]');
            if (!btn || btn.disabled) {
                return;
            }
            if (btn.getAttribute('data-page') === 'prev') {
                page = Math.max(1, page - 1);
            } else if (btn.getAttribute('data-page') === 'next') {
                page = page + 1;
            }
            paint();
        });
        root.addEventListener('change', function(e) {
            if (e.target && e.target.matches('[data-filter="pagesize"]')) {
                pageSize = parseInt(e.target.value, 10) || 25;
                page = 1;
                paint();
            }
        });
        load();
    };

    return {init: init};
});
