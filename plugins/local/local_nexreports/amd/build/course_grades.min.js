/**
 * NexReports Full course grades (section × activity matrix).
 *
 * @module     local_nexreports/course_grades
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

    const renderTable = function(root, sections, rows, pageStart, coursetotalmax) {
        const host = root.querySelector('[data-region="report-table"]');
        if (!host) {
            return;
        }
        if (!(sections || []).length) {
            host.innerHTML = '<p class="nxr-empty">' +
                esc(label(root, 'noactivities', 'No gradeable activities in this course.')) + '</p>';
            return;
        }
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(root.getAttribute('data-label-nodata')) + '</p>';
            return;
        }

        const learnerCols = 8;
        let top = '<tr>' +
            '<th rowspan="2">#</th>' +
            '<th rowspan="2">' + esc(label(root, 'firstname', 'First name')) + '</th>' +
            '<th rowspan="2">' + esc(label(root, 'lastname', 'Last name')) + '</th>' +
            '<th rowspan="2">' + esc(label(root, 'username', 'Username')) + '</th>' +
            '<th rowspan="2">' + esc(label(root, 'email', 'Email')) + '</th>' +
            '<th rowspan="2">' + esc(label(root, 'institution', 'College')) + '</th>' +
            '<th rowspan="2">' + esc(label(root, 'yearofpassing', 'Year of passing')) + '</th>' +
            '<th rowspan="2">' + esc(label(root, 'department', 'Department')) + '</th>';

        sections.forEach(function(sec) {
            const span = (sec.activities || []).length;
            if (!span) {
                return;
            }
            top += '<th class="nxr-pf-group" colspan="' + span + '">' + esc(sec.name) + '</th>';
        });
        top += '<th rowspan="2" class="nxr-cg-total">' + esc(label(root, 'total', 'Total'));
        if (coursetotalmax) {
            top += '<span class="nxr-cg-max"> / ' + esc(coursetotalmax) + '</span>';
        }
        top += '</th></tr><tr>';

        sections.forEach(function(sec) {
            (sec.activities || []).forEach(function(act) {
                let title = act.name || '';
                if (act.maxgrade) {
                    title += ' (' + label(root, 'max', 'Max') + ' ' + act.maxgrade + ')';
                }
                top += '<th class="nxr-pf-sub" title="' + esc(title) + '">' + esc(act.name) +
                    (act.maxgrade ? '<span class="nxr-cg-max">/' + esc(act.maxgrade) + '</span>' : '') +
                    '</th>';
            });
        });
        top += '</tr>';

        const body = rows.map(function(r, idx) {
            const byCm = {};
            (r.gradecells || []).forEach(function(c) {
                byCm[String(c.cmid)] = c;
            });
            let cells = '<td>' + esc(pageStart + idx + 1) + '</td>' +
                '<td class="nxr-pf-learner"><a href="' + esc(r.url) + '">' + esc(r.firstname || '') + '</a></td>' +
                '<td>' + esc(r.lastname || '') + '</td>' +
                '<td>' + esc(r.username || '') + '</td>' +
                '<td>' + esc(r.email || '') + '</td>' +
                '<td>' + esc(r.institution || '—') + '</td>' +
                '<td>' + esc(r.yearofpassing || '—') + '</td>' +
                '<td>' + esc(r.department || '—') + '</td>';
            sections.forEach(function(sec) {
                (sec.activities || []).forEach(function(act) {
                    const cell = byCm[String(act.cmid)] || {display: '—', value: -1};
                    const empty = cell.value == null || Number(cell.value) < 0;
                    cells += '<td class="nxr-pf-num' + (empty ? ' nxr-pf-empty' : '') + '">' +
                        esc(cell.display || '—') + '</td>';
                });
            });
            const totalEmpty = r.totalvalue == null || Number(r.totalvalue) < 0;
            cells += '<td class="nxr-pf-num nxr-cg-total' + (totalEmpty ? ' nxr-pf-empty' : '') + '">' +
                esc(r.total || '—') + '</td>';
            return '<tr>' + cells + '</tr>';
        }).join('');

        host.innerHTML =
            '<div class="nxr-table-wrap nxr-table-wrap--portfolio nxr-table-wrap--course-grades">' +
            '<table class="nxr-table nxr-table--portfolio nxr-table--course-grades">' +
            '<thead>' + top + '</thead><tbody>' + body + '</tbody></table></div>';
        // learnerCols kept for clarity / future sticky cols.
        void learnerCols;
    };

    const init = function(cfg) {
        const root = document.querySelector('[data-region="nxr-course-grades"]');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        TableExport.bind(root);

        let seq = 0;
        let allRows = [];
        let sections = [];
        let coursetotalmax = '';
        let page = 1;
        let pageSize = 25;
        let searchTimer = null;
        let suppressChange = false;
        let coursePrimed = cfg.courseid || 0;
        let yearPrimed = cfg.year || '';
        let departmentPrimed = cfg.department || '';
        let institutionPrimed = cfg.institution || '';

        const paint = function() {
            const total = allRows.length;
            const pages = Math.max(1, Math.ceil(total / pageSize));
            page = Math.min(Math.max(1, page), pages);
            const start = (page - 1) * pageSize;
            renderTable(root, sections, allRows.slice(start, start + pageSize), start, coursetotalmax);
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
                methodname: 'local_nexreports_get_course_grades',
                args: {
                    courseid: courseid,
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
                sections = data.sections || [];
                allRows = data.rows || [];
                coursetotalmax = data.coursetotalmax || '';
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
                    }
                    host.innerHTML = '<p class="nxr-empty">' +
                        esc(root.getAttribute('data-label-loaderror')) +
                        (detail ? ' (' + esc(detail) + ')' : '') + '</p>';
                }
            });
        };

        root.addEventListener('change', function(e) {
            if (suppressChange) {
                return;
            }
            const t = e.target;
            if (!t || !t.getAttribute) {
                return;
            }
            const filter = t.getAttribute('data-filter');
            if (!filter) {
                return;
            }
            if (filter === 'pagesize') {
                pageSize = parseInt(t.value, 10) || 25;
                page = 1;
                paint();
                return;
            }
            if (filter === 'course') {
                const year = root.querySelector('[data-filter="year"]');
                const department = root.querySelector('[data-filter="department"]');
                const college = root.querySelector('[data-filter="college"]');
                if (year) {
                    year.value = '';
                }
                if (department) {
                    department.value = '';
                }
                if (college) {
                    college.value = '';
                }
            }
            if (filter === 'college') {
                const year = root.querySelector('[data-filter="year"]');
                const department = root.querySelector('[data-filter="department"]');
                if (year) {
                    year.value = '';
                }
                if (department) {
                    department.value = '';
                }
            }
            if (filter === 'year') {
                const department = root.querySelector('[data-filter="department"]');
                if (department) {
                    department.value = '';
                }
            }
            load();
        });

        root.addEventListener('input', function(e) {
            const t = e.target;
            if (!t || t.getAttribute('data-filter') !== 'search') {
                return;
            }
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function() {
                load();
            }, 350);
        });

        root.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-page]');
            if (!btn || btn.disabled) {
                return;
            }
            const dir = btn.getAttribute('data-page');
            if (dir === 'prev') {
                page = Math.max(1, page - 1);
            } else if (dir === 'next') {
                page += 1;
            }
            paint();
        });

        load({usePrimed: true});
    };

    return {init: init};
});
