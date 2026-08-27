/**
 * NexReports students engagement / All learner summary.
 *
 * @module     local_nexreports/students
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

    const label = function(root, key, fallback) {
        return root.getAttribute('data-label-' + key) || fallback;
    };

    const columns = function(root) {
        return [
            {key: 'firstname', label: label(root, 'firstname', 'First name'), type: 'str', sortKey: 'firstname', link: true},
            {key: 'lastname', label: label(root, 'lastname', 'Last name'), type: 'str', sortKey: 'lastname'},
            {key: 'username', label: label(root, 'username', 'Username'), type: 'str', sortKey: 'username'},
            {key: 'email', label: label(root, 'email', 'Email'), type: 'str', sortKey: 'email'},
            {key: 'institution', label: label(root, 'institution', 'Institution'), type: 'str', sortKey: 'institution'},
            {key: 'yearofpassing', label: label(root, 'yearofpassing', 'Year of passing'), type: 'str', sortKey: 'yearofpassing'},
            {key: 'department', label: label(root, 'department', 'Department'), type: 'str', sortKey: 'department'},
            {key: 'status', label: label(root, 'status', 'Status'), type: 'str', sortKey: 'status', status: true},
            {key: 'lastaccess', label: label(root, 'lastaccess', 'Last access'), type: 'time', sortKey: 'lastaccesstime'},
            {key: 'enrolledcourses', label: label(root, 'enrolledcourses', 'Enrolled courses'), type: 'num', sortKey: 'enrolledcourses'},
            {key: 'inprogress', label: label(root, 'inprogresscourses', 'In-progress courses'), type: 'num', sortKey: 'inprogress'},
            {key: 'completed', label: label(root, 'completedcourses', 'Completed courses'), type: 'num', sortKey: 'completed'},
            {key: 'avgprogress', label: label(root, 'completionprogress', 'Completion progress'), type: 'num', sortKey: 'avgprogress', suffix: '%'},
            {key: 'totalgrade', label: label(root, 'totalgrade', 'Total grade'), type: 'num', sortKey: 'totalgrade'},
            {key: 'codingsolved', label: label(root, 'codingsolved', 'Coding solved'), type: 'num', sortKey: 'codingsolved'},
            {key: 'codingtotal', label: label(root, 'codingtotal', 'Coding total'), type: 'num', sortKey: 'codingtotal'},
            {key: 'timespentonsite', label: label(root, 'timespentonsite', 'Time spent on site'), type: 'num', sortKey: 'timespentonsite', duration: true},
            {key: 'timespentoncourse', label: label(root, 'timespentoncourse', 'Time spent on course'), type: 'num', sortKey: 'timespentoncourse', duration: true},
            {key: 'activitiescompleted', label: label(root, 'activitiescompleted', 'Activities completed'), type: 'num', sortKey: 'activitiescompleted'},
            {key: 'visits', label: label(root, 'visitsoncourse', 'Visits on course'), type: 'num', sortKey: 'visits'},
            {key: 'completedassignments', label: label(root, 'completedassignments', 'Completed assignments'), type: 'num', sortKey: 'completedassignments'},
            {key: 'completedquizzes', label: label(root, 'completedquizzes', 'Completed quizzes'), type: 'num', sortKey: 'completedquizzes'},
            {key: 'completedscorms', label: label(root, 'completedscorms', 'Completed scorms'), type: 'num', sortKey: 'completedscorms'},
        ];
    };

    const sortValue = function(row, col) {
        if (col.type === 'num' || col.type === 'time') {
            const raw = row[col.sortKey];
            const n = Number(raw);
            if (raw == null || raw === '' || Number.isNaN(n) || (col.type === 'time' && n <= 0)) {
                return null;
            }
            return n;
        }
        return String(row[col.sortKey] == null ? '' : row[col.sortKey]).toLowerCase();
    };

    const sortRows = function(rows, cols, sortKey, sortDir) {
        const col = cols.find(function(c) {
            return c.key === sortKey;
        });
        if (!col) {
            return rows.slice();
        }
        const dir = sortDir === 'desc' ? -1 : 1;
        return rows.slice().sort(function(a, b) {
            const av = sortValue(a, col);
            const bv = sortValue(b, col);
            if (av === null && bv === null) {
                return 0;
            }
            if (av === null) {
                return 1;
            }
            if (bv === null) {
                return -1;
            }
            if (av < bv) {
                return -1 * dir;
            }
            if (av > bv) {
                return 1 * dir;
            }
            return 0;
        });
    };

    const fillCourseOnce = function(select, options, selected, allLabel) {
        if (!select) {
            return;
        }
        if (select.getAttribute('data-primed') === '1') {
            if (selected != null) {
                select.value = String(selected);
            }
            return;
        }
        select.innerHTML = '<option value="0">' + esc(allLabel) + '</option>' +
            (options || []).map(function(o) {
                return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
            }).join('');
        select.value = String(selected || 0);
        select.setAttribute('data-primed', '1');
    };

    const fillNamedSelect = function(select, options, selected, allLabel, courseid) {
        if (!select) {
            return;
        }
        const key = String(courseid || 0);
        // Prefer explicit empty over falsy coercion so "All …" stays selected.
        const keep = String(selected == null ? '' : selected).trim();
        select.innerHTML = '<option value="">' + esc(allLabel) + '</option>' +
            (options || []).map(function(o) {
                return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
            }).join('');
        select.setAttribute('data-course', key);
        select.selectedIndex = 0;
        if (keep) {
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === keep) {
                    select.selectedIndex = i;
                    break;
                }
            }
        }
        // Empty value="" can fail to stick in some browsers / after autofill.
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

    const renderSummary = function(root, summary) {
        const host = root.querySelector('[data-region="students-summary"]');
        if (!host) {
            return;
        }
        summary = summary || {};
        const totalVisits = Number(summary.totalvisits) || 0;
        const avgVisits = Number(summary.avgvisits) || 0;
        const totalLearners = Number(summary.totallearners) || 0;
        const totalTime = formatDuration(summary.totaltimespent);
        const avgTime = formatDuration(summary.avgtimespent);
        host.innerHTML =
            '<div class="nxr-learner-summary__box">' +
            '<div class="nxr-learner-summary__topline">' +
            '<span><strong>' + esc(label(root, 'totalvisits', 'Total visits')) + ':</strong> ' +
            esc(totalVisits.toLocaleString()) + '</span>' +
            '<span><strong>' + esc(label(root, 'avgvisits', 'Avg. visits')) + ':</strong> ' +
            esc(avgVisits.toLocaleString()) + '</span>' +
            '</div>' +
            '<div class="nxr-learner-summary__metrics">' +
            '<div class="nxr-learner-summary__metric">' +
            '<span class="nxr-learner-summary__icon nxr-learner-summary__icon--learners" aria-hidden="true"></span>' +
            '<div><p class="nxr-learner-summary__value">' + esc(totalLearners.toLocaleString()) + '</p>' +
            '<p class="nxr-learner-summary__label">' + esc(label(root, 'totallearners', 'Total learners')) + '</p></div>' +
            '</div>' +
            '<div class="nxr-learner-summary__metric">' +
            '<span class="nxr-learner-summary__icon nxr-learner-summary__icon--clock" aria-hidden="true"></span>' +
            '<div><p class="nxr-learner-summary__value">' + esc(totalTime) + '</p>' +
            '<p class="nxr-learner-summary__label">' + esc(label(root, 'totaltimespent', 'Total time spent on course(s)')) + '</p></div>' +
            '</div>' +
            '<div class="nxr-learner-summary__metric">' +
            '<span class="nxr-learner-summary__icon nxr-learner-summary__icon--stopwatch" aria-hidden="true"></span>' +
            '<div><p class="nxr-learner-summary__value">' + esc(avgTime) + '</p>' +
            '<p class="nxr-learner-summary__label">' + esc(label(root, 'avgtimespent', 'Avg time spent on course(s)')) + '</p></div>' +
            '</div>' +
            '</div></div>';
    };

    const renderPager = function(root, page, pageSize, total) {
        const host = root.querySelector('[data-region="pager"]');
        if (!host) {
            return;
        }
        const pages = Math.max(1, Math.ceil(total / pageSize));
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

    const cellValue = function(row, col) {
        if (col.duration) {
            return formatDuration(row[col.sortKey]);
        }
        let value = row[col.key];
        if (value == null || value === '') {
            value = '—';
        }
        if (col.suffix && value !== '—') {
            return String(value) + col.suffix;
        }
        return String(value);
    };

    const renderTable = function(root, rows, cols, sortKey, sortDir) {
        const host = root.querySelector('[data-region="students-table"]');
        if (!host) {
            return;
        }
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(root.getAttribute('data-label-nodata')) + '</p>';
            return;
        }
        host.innerHTML =
            '<div class="nxr-table-wrap"><table class="nxr-table"><thead><tr>' +
            cols.map(function(col) {
                let cls = '';
                if (sortKey === col.key) {
                    cls = sortDir === 'desc' ? ' is-desc' : ' is-asc';
                }
                return '<th class="nxr-th-sort' + cls + '" data-sort="' + esc(col.key) + '" scope="col">' +
                    esc(col.label) + '</th>';
            }).join('') +
            '</tr></thead><tbody>' +
            rows.map(function(r) {
                return '<tr>' + cols.map(function(col) {
                    const text = cellValue(r, col);
                    if (col.link) {
                        return '<td><a href="' + esc(r.url) + '">' + esc(text) + '</a></td>';
                    }
                    if (col.status) {
                        const cls = Number(r.statusactive) ? 'is-active' : 'is-inactive';
                        return '<td><span class="nxr-status ' + cls + '">' + esc(text) + '</span></td>';
                    }
                    return '<td>' + esc(text) + '</td>';
                }).join('') + '</tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    const init = function() {
        const root = document.querySelector('[data-region="nxr-students"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        const cols = columns(root);
        let seq = 0;
        let allRows = [];
        let page = 1;
        let pageSize = 10;
        let sortKey = 'lastname';
        let sortDir = 'asc';
        let suppressChange = false;
        let lastSummary = null;

        const paint = function() {
            const sorted = sortRows(allRows, cols, sortKey, sortDir);
            const total = sorted.length;
            const pages = Math.max(1, Math.ceil(total / pageSize) || 1);
            if (page > pages) {
                page = pages;
            }
            const start = (page - 1) * pageSize;
            renderTable(root, sorted.slice(start, start + pageSize), cols, sortKey, sortDir);
            renderPager(root, page, pageSize, total);
            if (lastSummary) {
                renderSummary(root, lastSummary);
            }
        };

        const load = function(opts) {
            opts = opts || {};
            const id = ++seq;
            const host = root.querySelector('[data-region="students-table"]');
            const summaryHost = root.querySelector('[data-region="students-summary"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            if (summaryHost) {
                summaryHost.innerHTML = '<div class="nxr-skeleton nxr-skeleton--kpis"></div>';
            }
            const course = root.querySelector('[data-filter="course"]');
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            const inactive = root.querySelector('[data-filter="inactive"]');
            const search = root.querySelector('[data-filter="search"]');

            Ajax.call([{
                methodname: 'local_nexreports_get_students_engagement',
                args: {
                    courseid: course ? (parseInt(course.value, 10) || 0) : 0,
                    cohortid: 0,
                    search: search ? search.value : '',
                    limit: 2000,
                    year: year ? (year.value || '') : '',
                    department: department ? (department.value || '') : '',
                    inactive: inactive ? (inactive.value || 'all') : 'all',
                    institution: college ? (college.value || '') : '',
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                suppressChange = true;
                fillCourseOnce(
                    root.querySelector('[data-filter="course"]'),
                    data.courses,
                    data.selectedcourseid,
                    root.getAttribute('data-label-all-courses') || 'All courses'
                );
                if (root.querySelector('[data-filter="college"]')) {
                    fillNamedSelect(
                        root.querySelector('[data-filter="college"]'),
                        data.colleges,
                        data.selectedinstitution,
                        root.getAttribute('data-label-all-colleges') || 'All colleges',
                        data.selectedcourseid
                    );
                }
                fillNamedSelect(
                    root.querySelector('[data-filter="year"]'),
                    data.years,
                    data.selectedyear,
                    root.getAttribute('data-label-all-years') || 'All years',
                    data.selectedcourseid
                );
                fillNamedSelect(
                    root.querySelector('[data-filter="department"]'),
                    data.departments,
                    data.selecteddepartment,
                    root.getAttribute('data-label-all-departments') || 'All departments',
                    data.selectedcourseid
                );
                if (inactive && data.selectedinactive) {
                    inactive.value = data.selectedinactive;
                }
                // Re-assert filter UI against server selection (defeats browser autofill).
                const intendedCollege = data.selectedinstitution || '';
                const intendedYear = data.selectedyear || '';
                const intendedDepartment = data.selecteddepartment || '';
                window.setTimeout(function() {
                    syncNamedSelect(root.querySelector('[data-filter="college"]'), intendedCollege);
                    syncNamedSelect(root.querySelector('[data-filter="year"]'), intendedYear);
                    syncNamedSelect(root.querySelector('[data-filter="department"]'), intendedDepartment);
                    suppressChange = false;
                }, 0);
                allRows = data.rows || [];
                lastSummary = data.summary || null;
                if (!opts.keepPage) {
                    page = 1;
                }
                paint();
                const url = new URL(root.getAttribute('data-export-url'), window.location.origin);
                    url.searchParams.set('courseid', String(data.selectedcourseid || 0));
                    url.searchParams.set('institution', intendedCollege);
                    url.searchParams.set('year', intendedYear);
                    url.searchParams.set('department', intendedDepartment);
                    url.searchParams.set('inactive', data.selectedinactive || 'all');
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
        const course = root.querySelector('[data-filter="course"]');
        const year = root.querySelector('[data-filter="year"]');
        const department = root.querySelector('[data-filter="department"]');
        const inactive = root.querySelector('[data-filter="inactive"]');
        if (search) {
            search.addEventListener('input', function() {
                window.clearTimeout(timer);
                timer = window.setTimeout(function() {
                    load();
                }, 300);
            });
        }
        if (course) {
            course.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                const c = root.querySelector('[data-filter="college"]');
                const y = root.querySelector('[data-filter="year"]');
                const d = root.querySelector('[data-filter="department"]');
                if (c) {
                    c.value = '';
                }
                if (y) {
                    y.value = '';
                }
                if (d) {
                    d.value = '';
                }
                load();
            });
        }
        const collegeFilter = root.querySelector('[data-filter="college"]');
        if (collegeFilter) {
            collegeFilter.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                const y = root.querySelector('[data-filter="year"]');
                const d = root.querySelector('[data-filter="department"]');
                if (y) {
                    y.value = '';
                }
                if (d) {
                    d.value = '';
                }
                load();
            });
        }
        if (year) {
            year.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                const d = root.querySelector('[data-filter="department"]');
                if (d) {
                    d.value = '';
                }
                load();
            });
        }
        if (department) {
            department.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                load();
            });
        }
        if (inactive) {
            inactive.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                load();
            });
        }
        root.addEventListener('click', function(e) {
            const th = e.target.closest('th[data-sort]');
            if (th && root.contains(th)) {
                const key = th.getAttribute('data-sort');
                if (key === sortKey) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    const descDefault = {
                        lastaccess: 1,
                        enrolledcourses: 1,
                        inprogress: 1,
                        completed: 1,
                        avgprogress: 1,
                        totalgrade: 1,
                        codingsolved: 1,
                        codingtotal: 1,
                        timespentonsite: 1,
                        timespentoncourse: 1,
                        activitiescompleted: 1,
                        visits: 1,
                        completedassignments: 1,
                        completedquizzes: 1,
                        completedscorms: 1,
                    };
                    sortDir = descDefault[key] ? 'desc' : 'asc';
                }
                page = 1;
                paint();
                return;
            }
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
                pageSize = parseInt(e.target.value, 10) || 10;
                page = 1;
                paint();
            }
        });
        load();
    };

    return {init: init};
});
