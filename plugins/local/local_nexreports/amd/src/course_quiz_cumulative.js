/**
 * NexReports Course Completion ( Without Pass Grade Condition ).
 *
 * @module     local_nexreports/course_quiz_cumulative
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
            {key: 'rank', label: '#', type: 'num', sortKey: 'rank'},
            {key: 'firstname', label: label(root, 'firstname', 'First name'), type: 'str', sortKey: 'firstname', link: true},
            {key: 'lastname', label: label(root, 'lastname', 'Last name'), type: 'str', sortKey: 'lastname'},
            {key: 'username', label: label(root, 'username', 'Username'), type: 'str', sortKey: 'username'},
            {key: 'email', label: label(root, 'email', 'Email'), type: 'str', sortKey: 'email'},
            {key: 'institution', label: label(root, 'institution', 'Institution'), type: 'str', sortKey: 'institution'},
            {key: 'department', label: label(root, 'department', 'Department'), type: 'str', sortKey: 'department'},
            {key: 'yearofpassing', label: label(root, 'yearofpassing', 'Year of passing'), type: 'str', sortKey: 'yearofpassing'},
            {key: 'enrolledon', label: label(root, 'enrolledon', 'Enrolled on'), type: 'time', sortKey: 'enrolledontime'},
            {key: 'lastaccess', label: label(root, 'lastaccess', 'Last access'), type: 'time', sortKey: 'lastaccesstime'},
            {key: 'progress', label: label(root, 'progress', 'Progress'), type: 'num', sortKey: 'progress', suffix: '%'},
            {key: 'completedlabel', label: label(root, 'status', 'Status'), type: 'str', sortKey: 'completedlabel'},
            {key: 'completedon', label: label(root, 'completedon', 'Completed on'), type: 'time', sortKey: 'completedontime'},
            {key: 'completedactivities', label: label(root, 'completedactivities', 'Completed activities'), type: 'num', sortKey: 'completedactivities'},
            {key: 'totalactivities', label: label(root, 'totalactivities', 'Total activities'), type: 'num', sortKey: 'totalactivities'},
            {key: 'codingsolved', label: label(root, 'codingsolved', 'Coding solved'), type: 'num', sortKey: 'codingsolved'},
            {key: 'codingtotal', label: label(root, 'codingtotal', 'Coding total'), type: 'num', sortKey: 'codingtotal'},
            {key: 'visits', label: label(root, 'visits', 'Visits'), type: 'num', sortKey: 'visits'},
            {key: 'timespent', label: label(root, 'timespent', 'Time spent'), type: 'num', sortKey: 'timespent', duration: true},
        ];
    };

    const sortValue = function(row, col) {
        if (col.type === 'num' || col.type === 'time') {
            let raw = row[col.sortKey];
            if (col.duration && (raw == null || raw === '')) {
                raw = (Number(row.timespentminutes) || 0) * 60;
            }
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

    const fillCourseOnce = function(select, options, selected) {
        if (!select || select.getAttribute('data-primed') === '1') {
            if (select && selected != null) {
                select.value = String(selected);
            }
            return;
        }
        select.innerHTML = (options || []).map(function(o) {
            return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
        }).join('');
        if (!options || !options.length) {
            select.innerHTML = '<option value="0">—</option>';
        }
        select.value = String(selected || (options[0] && options[0].id) || 0);
        select.setAttribute('data-primed', '1');
    };

    const fillNamedSelect = function(select, options, selected, allLabel, courseid) {
        if (!select) {
            return;
        }
        const key = String(courseid || 0);
        const keep = String(selected || '');
        select.innerHTML = '<option value="">' + esc(allLabel) + '</option>' +
            (options || []).map(function(o) {
                return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
            }).join('');
        select.setAttribute('data-course', key);
        select.value = '';
        if (keep) {
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value === keep) {
                    select.selectedIndex = i;
                    break;
                }
            }
        }
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

    const cellValue = function(row, col, displayRank) {
        if (col.key === 'rank') {
            return String(displayRank);
        }
        if (col.duration) {
            const secs = row.timespent != null ? row.timespent : ((Number(row.timespentminutes) || 0) * 60);
            return formatDuration(secs);
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

    const renderTable = function(root, rows, cols, sortKey, sortDir, pageStart) {
        const host = root.querySelector('[data-region="report-table"]');
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
            rows.map(function(r, idx) {
                const displayRank = pageStart + idx + 1;
                return '<tr>' + cols.map(function(col) {
                    const text = cellValue(r, col, displayRank);
                    if (col.link) {
                        return '<td><a href="' + esc(r.url) + '">' + esc(text) + '</a></td>';
                    }
                    if (col.key === 'completedactivities') {
                        return '<td title="P ' + esc(r.passed) + ' · F ' + esc(r.failed) +
                            ' · IP ' + esc(r.inprogress) + '">' + esc(text) + '</td>';
                    }
                    return '<td>' + esc(text) + '</td>';
                }).join('') + '</tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    const init = function(cfg) {
        cfg = cfg || {};
        const root = document.querySelector('[data-region="nxr-course-quiz-cumulative"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        const cols = columns(root);
        let seq = 0;
        let coursePrimed = cfg.courseid || 0;
        let yearPrimed = cfg.year || '';
        let departmentPrimed = cfg.department || '';
        let institutionPrimed = cfg.institution || '';
        let allRows = [];
        let page = 1;
        let pageSize = 25;
        let sortKey = 'lastname';
        let sortDir = 'asc';
        let suppressChange = false;

        const paint = function() {
            const sorted = sortRows(allRows, cols, sortKey, sortDir);
            const total = sorted.length;
            const pages = Math.max(1, Math.ceil(total / pageSize) || 1);
            if (page > pages) {
                page = pages;
            }
            const start = (page - 1) * pageSize;
            renderTable(root, sorted.slice(start, start + pageSize), cols, sortKey, sortDir, start);
            renderPager(root, page, pageSize, total);
        };

        const load = function(opts) {
            opts = opts || {};
            const id = ++seq;
            const host = root.querySelector('[data-region="report-table"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            const course = root.querySelector('[data-filter="course"]');
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            const search = root.querySelector('[data-filter="search"]');
            const courseid = course && course.options.length
                ? (parseInt(course.value, 10) || 0)
                : (coursePrimed || 0);
            let yearValue = year ? (year.value || '') : '';
            let departmentValue = department ? (department.value || '') : '';
            let institutionValue = college ? (college.value || '') : '';
            if (opts.usePrimed) {
                if (institutionPrimed) {
                    institutionValue = institutionPrimed;
                }
                if (yearPrimed) {
                    yearValue = yearPrimed;
                }
                if (departmentPrimed) {
                    departmentValue = departmentPrimed;
                }
            }

            Ajax.call([{
                methodname: 'local_nexreports_get_course_quiz_cumulative',
                args: {
                    courseid: courseid,
                    cohortid: 0,
                    groupid: 0,
                    search: search ? search.value : '',
                    limit: 2000,
                    year: yearValue,
                    department: departmentValue,
                    institution: institutionValue,
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                coursePrimed = 0;
                yearPrimed = '';
                departmentPrimed = '';
                institutionPrimed = '';
                suppressChange = true;
                fillCourseOnce(root.querySelector('[data-filter="course"]'), data.courses, data.selectedcourseid);
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
                window.setTimeout(function() {
                    suppressChange = false;
                }, 0);
                allRows = data.rows || [];
                if (!opts.keepPage) {
                    page = 1;
                }
                paint();
                const url = new URL(root.getAttribute('data-export-url'), window.location.origin);
                    url.searchParams.set('courseid', String(data.selectedcourseid || 0));
                    url.searchParams.set('institution', data.selectedinstitution || '');
                    url.searchParams.set('year', data.selectedyear || '');
                    url.searchParams.set('department', data.selecteddepartment || '');
                    url.searchParams.set('search', data.search || '');
                    TableExport.sync(root, url);
                return null;
            }).catch(function(ex) {
                if (id === seq && host) {
                    let detail = '';
                    if (ex) {
                        detail = ex.error || ex.message || '';
                        if (ex.errorcode) {
                            detail = (detail ? detail + ' ' : '') + '(' + ex.errorcode + ')';
                        }
                    }
                    host.innerHTML = '<p class="nxr-error">' +
                        esc(root.getAttribute('data-label-loaderror')) +
                        (detail ? '<br><small>' + esc(detail) + '</small>' : '') +
                        '</p>';
                }
            });
        };

        let timer = null;
        const search = root.querySelector('[data-filter="search"]');
        const course = root.querySelector('[data-filter="course"]');
        const year = root.querySelector('[data-filter="year"]');
        const department = root.querySelector('[data-filter="department"]');
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
        root.addEventListener('click', function(e) {
            const th = e.target.closest('th[data-sort]');
            if (th && root.contains(th)) {
                const key = th.getAttribute('data-sort');
                if (key === sortKey) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    sortDir = key === 'progress' || key === 'completedactivities' || key === 'totalactivities' ||
                        key === 'codingsolved' || key === 'codingtotal' || key === 'visits' || key === 'timespent' ||
                        key === 'enrolledon' || key === 'lastaccess' || key === 'completedon'
                        ? 'desc' : 'asc';
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
                pageSize = parseInt(e.target.value, 10) || 25;
                page = 1;
                paint();
            }
        });
        load({usePrimed: true});
    };

    return {init: init};
});
