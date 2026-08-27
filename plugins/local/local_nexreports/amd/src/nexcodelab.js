/**
 * NexReports NexCodeLab — XP, missions, and challenge solves.
 *
 * @module     local_nexreports/nexcodelab
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

    const dash = '—';

    const label = function(root, key, fallback) {
        return root.getAttribute('data-label-' + key) || fallback;
    };

    const fillSelect = function(select, options, allLabel, selected, blankAll) {
        if (!select) {
            return;
        }
        const allValue = blankAll ? '' : '0';
        select.innerHTML = '<option value="' + allValue + '">' + esc(allLabel) + '</option>' +
            (options || []).map(function(o) {
                return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
            }).join('');
        const want = selected == null || selected === '' ? allValue : String(selected);
        select.value = want;
        if (select.value !== want) {
            select.value = allValue;
        }
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

    const render = function(root, data) {
        fillSelect(
            root.querySelector('[data-filter="cohort"]'),
            data.cohorts || [],
            root.getAttribute('data-label-all-cohorts'),
            data.selectedcohortid,
            false
        );
        fillNamedSelect(
            root.querySelector('[data-filter="college"]'),
            data.colleges || [],
            data.selectedinstitution,
            root.getAttribute('data-label-all-colleges') || 'All colleges'
        );
        fillNamedSelect(
            root.querySelector('[data-filter="year"]'),
            data.years || [],
            data.selectedyear,
            root.getAttribute('data-label-all-years') || 'All years'
        );
        fillNamedSelect(
            root.querySelector('[data-filter="department"]'),
            data.departments || [],
            data.selecteddepartment,
            root.getAttribute('data-label-all-departments') || 'All departments'
        );

        const summary = data.summary || {};
        root.querySelectorAll('[data-kpi]').forEach(function(el) {
            const key = el.getAttribute('data-kpi');
            el.textContent = String(summary[key] != null ? summary[key] : 0);
        });

        const host = root.querySelector('[data-region="codelab-table"]');
        if (!host) {
            return;
        }
        const rows = data.rows || [];
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(root.getAttribute('data-label-nodata')) + '</p>';
            return;
        }

        const head = '<tr>' +
            '<th>#</th>' +
            '<th>' + esc(label(root, 'firstname', 'First name')) + '</th>' +
            '<th>' + esc(label(root, 'lastname', 'Last name')) + '</th>' +
            '<th>' + esc(label(root, 'username', 'Username')) + '</th>' +
            '<th>' + esc(label(root, 'institution', 'College')) + '</th>' +
            '<th>' + esc(label(root, 'yearofpassing', 'Year of passing')) + '</th>' +
            '<th>' + esc(label(root, 'department', 'Department')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'xp', 'XP')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'missions', 'Missions')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'started', 'Started')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'solved', 'Solved')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'streak', 'Streak')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'longest', 'Longest')) + '</th>' +
            '<th class="nxr-pr-num">' + esc(label(root, 'attempts', 'Attempts')) + '</th>' +
            '<th>' + esc(label(root, 'lastactivity', 'Last activity')) + '</th>' +
            '</tr>';

        const body = rows.map(function(r) {
            return '<tr>' +
                '<td>' + esc(r.rank) + '</td>' +
                '<td class="nxr-pr-learner"><a href="' + esc(r.url) + '">' + esc(r.firstname || '') + '</a></td>' +
                '<td>' + esc(r.lastname || '') + '</td>' +
                '<td>' + esc(r.username || '') + '</td>' +
                '<td>' + esc(r.institution || dash) + '</td>' +
                '<td>' + esc(r.yearofpassing || dash) + '</td>' +
                '<td>' + esc(r.department || dash) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.xp) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.missionscompleted) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.missionsstarted) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.solved) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.streak) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.longest) + '</td>' +
                '<td class="nxr-pr-num">' + esc(r.attempts) + '</td>' +
                '<td>' + esc(r.lastactivity || dash) + '</td>' +
                '</tr>';
        }).join('');

        host.innerHTML =
            '<div class="nxr-table-wrap nxr-table-wrap--practice">' +
            '<table class="nxr-table nxr-table--practice">' +
            '<thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
    };

    const init = function() {
        const root = document.querySelector('[data-region="nxr-codelab"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        let seq = 0;
        let searchTimer = null;
        let suppressChange = false;

        const load = function() {
            const id = ++seq;
            const host = root.querySelector('[data-region="codelab-table"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            const cohort = root.querySelector('[data-filter="cohort"]');
            const search = root.querySelector('[data-filter="search"]');
            Ajax.call([{
                methodname: 'local_nexreports_get_codelab_leaderboard',
                args: {
                    cohortid: cohort ? (parseInt(cohort.value, 10) || 0) : 0,
                    search: search ? search.value : '',
                    limit: 500,
                    institution: college ? (college.value || '') : '',
                    year: year ? (year.value || '') : '',
                    department: department ? (department.value || '') : '',
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                suppressChange = true;
                render(root, data);
                const intendedCollege = data.selectedinstitution || '';
                const intendedYear = data.selectedyear || '';
                const intendedDepartment = data.selecteddepartment || '';
                window.setTimeout(function() {
                    syncNamedSelect(root.querySelector('[data-filter="college"]'), intendedCollege);
                    syncNamedSelect(root.querySelector('[data-filter="year"]'), intendedYear);
                    syncNamedSelect(root.querySelector('[data-filter="department"]'), intendedDepartment);
                    suppressChange = false;
                }, 0);
                const url = new URL(root.getAttribute('data-export-url'), window.location.origin);
                url.searchParams.set('cohortid', String(data.selectedcohortid || 0));
                url.searchParams.set('search', data.search || '');
                url.searchParams.set('institution', intendedCollege);
                url.searchParams.set('year', intendedYear);
                url.searchParams.set('department', intendedDepartment);
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
        root.querySelector('[data-filter="cohort"]').addEventListener('change', onFilterChange(false, false));
        root.querySelector('[data-filter="search"]').addEventListener('input', function() {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(load, 280);
        });
        load();
    };

    return {init: init};
});
