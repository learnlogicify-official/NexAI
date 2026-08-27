/**
 * NexReports Weekly improvement insights.
 *
 * @module     local_nexreports/weekly_insights
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

    const label = function(root, key, fallback) {
        return root.getAttribute('data-label-' + key) || fallback;
    };

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

    const fillNamedSelect = function(select, options, selected, allLabel) {
        if (!select) {
            return;
        }
        const keep = String(selected == null ? '' : selected).trim();
        select.innerHTML = '<option value="">' + esc(allLabel) + '</option>' +
            (options || []).map(function(o) {
                return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
            }).join('');
        select.selectedIndex = 0;
        if (keep) {
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === keep) {
                    select.selectedIndex = i;
                    break;
                }
            }
        }
        if (!keep && select.value !== '') {
            select.selectedIndex = 0;
        }
    };

    const syncNamedSelect = function(select, selected) {
        if (!select) {
            return;
        }
        const keep = String(selected == null ? '' : selected).trim();
        if (!keep) {
            select.selectedIndex = 0;
            return;
        }
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === keep) {
                select.selectedIndex = i;
                return;
            }
        }
        select.selectedIndex = 0;
    };

    const statusBadge = function(root, status) {
        const map = {
            improving: label(root, 'improving', 'Improving'),
            declining: label(root, 'declining', 'Declining'),
            stable: label(root, 'stable', 'Stable'),
            new: label(root, 'neworidle', 'New / idle'),
            idle: label(root, 'neworidle', 'New / idle'),
            neworidle: label(root, 'neworidle', 'New / idle'),
        };
        const text = map[status] || status || '—';
        const cls = 'nxr-insight-status nxr-insight-status--' + esc(status || 'idle');
        return '<span class="' + cls + '">' + esc(text) + '</span>';
    };

    const spark = function(series, key) {
        const vals = (series || []).map(function(p) {
            return Number(p[key]) || 0;
        });
        if (!vals.length) {
            return '<span class="nxr-spark nxr-spark--empty">—</span>';
        }
        const max = Math.max.apply(null, vals.concat([1]));
        const bars = vals.map(function(v) {
            const h = Math.max(2, Math.round((v / max) * 18));
            return '<i style="height:' + h + 'px" title="' + esc(v) + '"></i>';
        }).join('');
        return '<span class="nxr-spark" aria-hidden="true">' + bars + '</span>';
    };

    const deltaCell = function(delta, isTime) {
        const n = Number(delta) || 0;
        let text = isTime ? formatDuration(Math.abs(n)) : String(Math.abs(n));
        if (n > 0) {
            text = '+' + text;
        } else if (n < 0) {
            text = '−' + text;
        } else {
            text = isTime ? formatDuration(0) : '0';
        }
        const cls = n > 0 ? 'is-up' : (n < 0 ? 'is-down' : 'is-flat');
        return '<td class="nxr-delta ' + cls + '">' + esc(text) + '</td>';
    };

    const renderKpis = function(root, summary, weekLabel) {
        const host = root.querySelector('[data-region="insights-kpis"]');
        if (!host) {
            return;
        }
        summary = summary || {};
        host.innerHTML =
            '<article class="nxr-kpi"><div class="nxr-kpi__body">' +
            '<div class="nxr-kpi__label">' + esc(label(root, 'improving', 'Improving')) + '</div>' +
            '<div class="nxr-kpi__metrics"><strong class="nxr-kpi__value">' +
            esc(summary.improving || 0) + '</strong></div></div></article>' +
            '<article class="nxr-kpi"><div class="nxr-kpi__body">' +
            '<div class="nxr-kpi__label">' + esc(label(root, 'declining', 'Declining')) + '</div>' +
            '<div class="nxr-kpi__metrics"><strong class="nxr-kpi__value">' +
            esc(summary.declining || 0) + '</strong></div></div></article>' +
            '<article class="nxr-kpi"><div class="nxr-kpi__body">' +
            '<div class="nxr-kpi__label">' + esc(label(root, 'stable', 'Stable')) + '</div>' +
            '<div class="nxr-kpi__metrics"><strong class="nxr-kpi__value">' +
            esc(summary.stable || 0) + '</strong></div></div></article>' +
            '<article class="nxr-kpi"><div class="nxr-kpi__body">' +
            '<div class="nxr-kpi__label">' + esc(label(root, 'neworidle', 'New / idle')) + '</div>' +
            '<div class="nxr-kpi__metrics"><strong class="nxr-kpi__value">' +
            esc(summary.neworidle || 0) + '</strong></div></div></article>';

        const wl = root.querySelector('[data-region="insights-weeklabel"]');
        if (wl) {
            wl.textContent = weekLabel
                ? (label(root, 'latestweek', 'Latest week') + ': ' + weekLabel
                    + ' · ' + label(root, 'delta', 'Δ vs prior week'))
                : '';
        }
    };

    const renderTable = function(root, data) {
        const host = root.querySelector('[data-region="insights-table"]');
        if (!host) {
            return;
        }
        if (!Number(data.historyready)) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'building', 'Building…')) + '</p>';
            return;
        }
        const rows = data.rows || [];
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(root.getAttribute('data-label-nodata')) + '</p>';
            return;
        }

        const head =
            '<th>#</th>' +
            '<th>' + esc(label(root, 'firstname', 'First name')) + '</th>' +
            '<th>' + esc(label(root, 'lastname', 'Last name')) + '</th>' +
            '<th>' + esc(label(root, 'username', 'Username')) + '</th>' +
            '<th>' + esc(label(root, 'institution', 'College')) + '</th>' +
            '<th>' + esc(label(root, 'yearofpassing', 'Year')) + '</th>' +
            '<th>' + esc(label(root, 'department', 'Department')) + '</th>' +
            '<th>' + esc(label(root, 'overall', 'Overall')) + '</th>' +
            '<th>' + esc(label(root, 'timespent', 'Time spent')) + '</th>' +
            '<th>Δ</th>' +
            '<th>' + esc(label(root, 'visits', 'Visits')) + '</th>' +
            '<th>Δ</th>' +
            '<th>' + esc(label(root, 'activedays', 'Active days')) + '</th>' +
            '<th>Δ</th>' +
            '<th>' + esc(label(root, 'activities', 'Activities')) + '</th>' +
            '<th>Δ</th>' +
            '<th>' + esc(label(root, 'coding', 'Coding')) + '</th>' +
            '<th>Δ</th>' +
            '<th>' + esc(label(root, 'quiz', 'Quiz')) + '</th>' +
            '<th>Δ</th>' +
            '<th>8w</th>';

        const body = rows.map(function(r) {
            return '<tr>' +
                '<td>' + esc(r.rank) + '</td>' +
                '<td><a href="' + esc(r.url) + '">' + esc(r.firstname) + '</a></td>' +
                '<td>' + esc(r.lastname) + '</td>' +
                '<td>' + esc(r.username) + '</td>' +
                '<td>' + esc(r.institution) + '</td>' +
                '<td>' + esc(r.yearofpassing) + '</td>' +
                '<td>' + esc(r.department) + '</td>' +
                '<td>' + statusBadge(root, r.status) + '</td>' +
                '<td>' + esc(formatDuration(r.timespent)) + '</td>' +
                deltaCell(r.deltatimespent, true) +
                '<td>' + esc(r.visits) + '</td>' +
                deltaCell(r.deltavisits, false) +
                '<td>' + esc(r.activedays) + '</td>' +
                deltaCell(r.deltaactivedays, false) +
                '<td>' + esc(r.activitiescompleted) + '</td>' +
                deltaCell(r.deltaactivities, false) +
                '<td>' + esc(r.codingsolved) + '</td>' +
                deltaCell(r.deltacoding, false) +
                '<td>' + esc(r.quizattempts) + '</td>' +
                deltaCell(r.deltaquiz, false) +
                '<td>' + spark(r.weekseries, 'codingsolved') + '</td>' +
                '</tr>';
        }).join('');

        host.innerHTML =
            '<div class="nxr-table-wrap nxr-table-wrap--insights">' +
            '<table class="nxr-table nxr-table--insights"><thead><tr>' + head +
            '</tr></thead><tbody>' + body + '</tbody></table></div>';
    };

    const init = function() {
        const root = document.querySelector('[data-region="nxr-weekly-insights"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        let seq = 0;
        let searchTimer = null;
        let suppressChange = false;

        const load = function() {
            const id = ++seq;
            const host = root.querySelector('[data-region="insights-table"]');
            const kpis = root.querySelector('[data-region="insights-kpis"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            if (kpis) {
                kpis.innerHTML = '<div class="nxr-skeleton nxr-skeleton--kpis"></div>';
            }
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            const search = root.querySelector('[data-filter="search"]');

            Ajax.call([{
                methodname: 'local_nexreports_get_weekly_insights',
                args: {
                    institution: college ? (college.value || '') : '',
                    year: year ? (year.value || '') : '',
                    department: department ? (department.value || '') : '',
                    search: search ? search.value : '',
                    limit: 500,
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                suppressChange = true;
                fillNamedSelect(
                    root.querySelector('[data-filter="college"]'),
                    data.colleges,
                    data.selectedinstitution,
                    root.getAttribute('data-label-all-colleges') || 'All colleges'
                );
                fillNamedSelect(
                    root.querySelector('[data-filter="year"]'),
                    data.years,
                    data.selectedyear,
                    root.getAttribute('data-label-all-years') || 'All years'
                );
                fillNamedSelect(
                    root.querySelector('[data-filter="department"]'),
                    data.departments,
                    data.selecteddepartment,
                    root.getAttribute('data-label-all-departments') || 'All departments'
                );
                const intendedCollege = data.selectedinstitution || '';
                const intendedYear = data.selectedyear || '';
                const intendedDepartment = data.selecteddepartment || '';
                window.setTimeout(function() {
                    syncNamedSelect(root.querySelector('[data-filter="college"]'), intendedCollege);
                    syncNamedSelect(root.querySelector('[data-filter="year"]'), intendedYear);
                    syncNamedSelect(root.querySelector('[data-filter="department"]'), intendedDepartment);
                    suppressChange = false;
                }, 0);

                renderKpis(root, data.summary, data.latestweeklabel || '');
                renderTable(root, data);

                const url = new URL(root.getAttribute('data-export-url'), window.location.origin);
                url.searchParams.set('institution', intendedCollege);
                url.searchParams.set('year', intendedYear);
                url.searchParams.set('department', intendedDepartment);
                url.searchParams.set('search', data.search || '');
                TableExport.sync(root, url);
                return null;
            }).catch(function() {
                if (id !== seq || !host) {
                    return;
                }
                host.innerHTML = '<p class="nxr-empty">' +
                    esc(root.getAttribute('data-label-loaderror')) + '</p>';
            });
        };

        const onFilterChange = function(clearYear, clearDept) {
            return function() {
                if (suppressChange) {
                    return;
                }
                if (clearYear) {
                    const y = root.querySelector('[data-filter="year"]');
                    if (y) {
                        y.value = '';
                    }
                }
                if (clearDept) {
                    const d = root.querySelector('[data-filter="department"]');
                    if (d) {
                        d.value = '';
                    }
                }
                load();
            };
        };

        const collegeFilter = root.querySelector('[data-filter="college"]');
        if (collegeFilter) {
            collegeFilter.addEventListener('change', onFilterChange(true, true));
        }
        const yearFilter = root.querySelector('[data-filter="year"]');
        if (yearFilter) {
            yearFilter.addEventListener('change', onFilterChange(false, true));
        }
        const deptFilter = root.querySelector('[data-filter="department"]');
        if (deptFilter) {
            deptFilter.addEventListener('change', onFilterChange(false, false));
        }
        const search = root.querySelector('[data-filter="search"]');
        if (search) {
            search.addEventListener('input', function() {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(load, 280);
            });
        }
        load();
    };

    return {init: init};
});
