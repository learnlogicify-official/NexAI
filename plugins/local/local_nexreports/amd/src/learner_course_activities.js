/**
 * NexReports Learner Course Activities.
 *
 * @module     local_nexreports/learner_course_activities
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
            {key: 'activity', label: label(root, 'activity', 'Activity'), type: 'str', sortKey: 'activity'},
            {key: 'type', label: label(root, 'type', 'Type'), type: 'str', sortKey: 'type'},
            {key: 'status', label: label(root, 'status', 'Status'), type: 'str', sortKey: 'status', status: true},
            {key: 'completedon', label: label(root, 'completedon', 'Completed on'), type: 'time', sortKey: 'completedontime'},
            {key: 'grade', label: label(root, 'grade', 'Grade'), type: 'num', sortKey: 'gradevalue'},
            {key: 'gradedon', label: label(root, 'gradedon', 'Graded on'), type: 'time', sortKey: 'gradedontime'},
            {key: 'attempts', label: label(root, 'attempts', 'Attempts'), type: 'num', sortKey: 'attempts'},
            {key: 'highestgrade', label: label(root, 'highestgrade', 'Highest grade'), type: 'num', sortKey: 'highestgradevalue'},
            {key: 'lowestgrade', label: label(root, 'lowestgrade', 'Lowest grade'), type: 'num', sortKey: 'lowestgradevalue'},
            {key: 'firstaccess', label: label(root, 'firstaccess', 'First access'), type: 'time', sortKey: 'firstaccesstime'},
            {key: 'lastaccess', label: label(root, 'lastaccess', 'Last access'), type: 'time', sortKey: 'lastaccesstime'},
            {key: 'visits', label: label(root, 'visits', 'Visits'), type: 'num', sortKey: 'visits'},
            {key: 'timespent', label: label(root, 'timespent', 'Time spent'), type: 'num', sortKey: 'timespent', duration: true},
        ];
    };

    const sortValue = function(row, col) {
        if (col.type === 'num' || col.type === 'time') {
            const raw = row[col.sortKey];
            const n = Number(raw);
            if (raw == null || raw === '' || Number.isNaN(n) || n < 0 || (col.type === 'time' && n <= 0)) {
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
        if (!select) {
            return;
        }
        if (select.getAttribute('data-primed') === '1') {
            if (selected != null) {
                select.value = String(selected);
            }
            return;
        }
        select.innerHTML = (options || []).map(function(o) {
            return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
        }).join('') || '<option value="0">—</option>';
        select.value = String(selected || (options[0] && options[0].id) || 0);
        select.setAttribute('data-primed', '1');
    };

    const fillNamedSelect = function(select, options, selected, allLabel) {
        if (!select) {
            return;
        }
        const keep = String(selected == null ? '' : selected);
        const prefix = allLabel != null
            ? '<option value="">' + esc(allLabel) + '</option>'
            : '';
        select.innerHTML = prefix + (options || []).map(function(o) {
            return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
        }).join('');
        select.value = keep;
        if (select.value !== keep && select.options.length) {
            select.selectedIndex = 0;
        }
    };

    const createCombo = function(root, container, opts) {
        if (!container) {
            return {setSelected: function() {}, getSelected: function() {
                return opts.stringId ? '' : 0;
            }, setOptions: function() {}};
        }
        const toggle = container.querySelector('.nxr-combo__toggle');
        const valueEl = container.querySelector('.nxr-combo__value');
        const panel = container.querySelector('.nxr-combo__panel');
        const input = container.querySelector('.nxr-combo__search');
        const list = container.querySelector('.nxr-combo__list');
        const placeholder = container.getAttribute('data-placeholder') || '';
        const stringId = !!opts.stringId;
        let options = [];
        let selectedId = stringId ? '-1' : 0;
        let selectedName = '';
        let activeIndex = -1;
        let timer = null;
        let seq = 0;

        const isEmpty = function(id) {
            if (stringId) {
                return id === '' || id == null || String(id) === '-1';
            }
            return !Number(id);
        };

        const rows = function() {
            return list.querySelectorAll('.nxr-combo__option');
        };

        const paint = function(message) {
            if (message) {
                list.innerHTML = '<li class="nxr-combo__msg">' + esc(message) + '</li>';
                return;
            }
            const emptyId = stringId ? '-1' : '0';
            list.innerHTML =
                '<li class="nxr-combo__option' + (isEmpty(selectedId) ? ' is-selected' : '') +
                    '" role="option" data-id="' + emptyId + '">' + esc(placeholder) + '</li>' +
                options.map(function(option) {
                    if (stringId && String(option.id) === '-1') {
                        return '';
                    }
                    return '<li class="nxr-combo__option' +
                        (String(option.id) === String(selectedId) ? ' is-selected' : '') +
                        '" role="option" data-id="' + esc(option.id) + '">' +
                        esc(option.name) + '</li>';
                }).join('');
            activeIndex = -1;
        };

        const fetchLocal = function(query) {
            const q = String(query || '').toLowerCase();
            const source = opts.getOptions ? opts.getOptions() : options;
            options = (source || []).filter(function(o) {
                if (stringId && String(o.id) === '-1') {
                    return false;
                }
                return !q || String(o.name).toLowerCase().indexOf(q) !== -1;
            });
            if (!options.length && q) {
                paint(label(root, 'nomatches', 'No matches found'));
            } else {
                paint(null);
            }
        };

        const fetchRemote = function(query) {
            const id = ++seq;
            paint(label(root, 'searching', 'Searching…'));
            opts.search(query).then(function(result) {
                if (id !== seq) {
                    return null;
                }
                if (result && result.__emptyMessage) {
                    options = [];
                    paint(result.__emptyMessage);
                    return null;
                }
                options = result || [];
                if (!options.length && query) {
                    paint(label(root, 'nomatches', 'No matches found'));
                    return null;
                }
                paint(null);
                return null;
            }).catch(function() {
                if (id === seq) {
                    paint(label(root, 'loaderror', 'Could not load'));
                }
            });
        };

        const close = function() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            container.classList.remove('is-open');
        };

        const open = function() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            container.classList.add('is-open');
            input.value = '';
            input.focus();
            if (opts.search) {
                fetchRemote('');
            } else {
                fetchLocal('');
            }
        };

        const choose = function(id, name) {
            if (stringId) {
                selectedId = (id == null || id === '') ? '-1' : String(id);
            } else {
                selectedId = Number(id) || 0;
            }
            selectedName = isEmpty(selectedId) ? '' : name;
            valueEl.textContent = isEmpty(selectedId) ? placeholder : selectedName;
            container.classList.toggle('is-filtered', !isEmpty(selectedId));
            close();
            if (opts.onSelect) {
                opts.onSelect(selectedId, selectedName);
            }
        };

        toggle.addEventListener('click', function() {
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });
        input.addEventListener('input', function() {
            window.clearTimeout(timer);
            const query = input.value;
            timer = window.setTimeout(function() {
                if (opts.search) {
                    fetchRemote(query);
                } else {
                    fetchLocal(query);
                }
            }, 250);
        });
        list.addEventListener('click', function(event) {
            const option = event.target.closest('.nxr-combo__option');
            if (option) {
                choose(option.getAttribute('data-id'), option.textContent);
            }
        });
        document.addEventListener('click', function(event) {
            if (!panel.hidden && !container.contains(event.target)) {
                close();
            }
        });

        return {
            setSelected: function(id, name, seed) {
                if (stringId) {
                    selectedId = (id == null || id === '') ? '-1' : String(id);
                } else {
                    selectedId = Number(id) || 0;
                }
                selectedName = (!isEmpty(selectedId) && name) ? name : '';
                valueEl.textContent = isEmpty(selectedId) ? placeholder : selectedName;
                container.classList.toggle('is-filtered', !isEmpty(selectedId));
                if (seed) {
                    options = seed.slice();
                }
            },
            getSelected: function() {
                return selectedId;
            },
            setOptions: function(listOptions) {
                options = listOptions || [];
            },
        };
    };

    const renderSummary = function(root, summary) {
        const host = root.querySelector('[data-region="lca-summary"]');
        if (!host) {
            return;
        }
        summary = summary || {};
        if (!summary.fullname) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'select-learner', 'Select a learner')) + '</p>';
            return;
        }
        const statusCls = Number(summary.statusactive) ? 'is-active' : 'is-inactive';
        host.innerHTML =
            '<div class="nxr-lcp-summary__box">' +
            '<div class="nxr-lcp-summary__head">' +
            '<p class="nxr-lcp-summary__last"><strong>Course :</strong> ' + esc(summary.coursename || '') + '</p>' +
            '<div class="nxr-lcp-summary__identity">' +
            '<a class="nxr-lcp-summary__name" href="' + esc(summary.url || '#') + '">' + esc(summary.fullname) + '</a>' +
            '<span class="nxr-status ' + statusCls + '">' + esc(summary.status || '') + '</span>' +
            '</div>' +
            '<p class="nxr-lcp-summary__last">' + esc(label(root, 'lastaccess', 'Last access')) + ' : ' +
            esc(summary.lastaccess || '—') + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__topline">' +
            '<span><strong>' + esc(label(root, 'visitsoncourse', 'Visits on course')) + ' :</strong> ' +
            esc(Number(summary.visitsoncourse || 0).toLocaleString()) + '</span>' +
            '<span><strong>' + esc(label(root, 'enrollmentdate', 'Enrollment date')) + ' :</strong> ' +
            esc(summary.enrolledon || '—') + '</span>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metrics">' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(formatDuration(summary.timespent)) + '</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'timespent', 'Time spent')) + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(Number(summary.marks || 0)) + '</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'marks', 'Marks')) + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(Number(summary.gradepercent || 0)) + '%</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'totalgrade', 'Grade')) + '</p>' +
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
        return String(value);
    };

    const renderTable = function(root, rows, cols, sortKey, sortDir) {
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
            rows.map(function(r) {
                return '<tr>' + cols.map(function(col) {
                    const text = cellValue(r, col);
                    if (col.status) {
                        return '<td><span class="nxr-lcp-status nxr-lcp-status--' + esc(r.statuskey || '') + '">' +
                            esc(text) + '</span></td>';
                    }
                    return '<td>' + esc(text) + '</td>';
                }).join('') + '</tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    const init = function(cfg) {
        cfg = cfg || {};
        const root = document.querySelector('[data-region="nxr-learner-course-activities"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        const cols = columns(root);
        let seq = 0;
        let allRows = [];
        let page = 1;
        let pageSize = 10;
        let sortKey = 'activity';
        let sortDir = 'asc';
        let suppressChange = false;
        let selectedUserid = cfg.userid || 0;
        let selectedSection = -1;
        let sectionOptions = [];
        let lastSummary = null;

        const getCourseId = function() {
            const course = root.querySelector('[data-filter="course"]');
            return course ? (parseInt(course.value, 10) || 0) : 0;
        };

        const getFilters = function() {
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            return {
                institution: college ? (college.value || '') : '',
                year: year ? (year.value || '') : '',
                department: department ? (department.value || '') : '',
            };
        };

        const learnerCombo = createCombo(root, root.querySelector('[data-combo="learner"]'), {
            search: function(query) {
                const filters = getFilters();
                if (!filters.year) {
                    return Promise.resolve({
                        __emptyMessage: label(root, 'select-year-first', 'Select a year first'),
                    });
                }
                return Ajax.call([{
                    methodname: 'local_nexreports_get_learner_course_activities',
                    args: {
                        courseid: getCourseId(),
                        userid: selectedUserid || 0,
                        section: selectedSection,
                        search: '',
                        activitytype: '',
                        completionstatus: 'all',
                        learnersearch: query || '',
                        metaonly: true,
                        year: filters.year,
                        department: filters.department,
                        institution: filters.institution,
                    },
                }])[0].then(function(data) {
                    return data.learners || [];
                });
            },
            onSelect: function(userid) {
                selectedUserid = Number(userid) || 0;
                load();
            },
        });

        const sectionCombo = createCombo(root, root.querySelector('[data-combo="section"]'), {
            stringId: true,
            getOptions: function() {
                return sectionOptions;
            },
            onSelect: function(section) {
                selectedSection = parseInt(section, 10);
                if (Number.isNaN(selectedSection)) {
                    selectedSection = -1;
                }
                load();
            },
        });

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
            renderSummary(root, lastSummary);
        };

        const load = function(opts) {
            opts = opts || {};
            const id = ++seq;
            const host = root.querySelector('[data-region="report-table"]');
            const summaryHost = root.querySelector('[data-region="lca-summary"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            if (summaryHost) {
                summaryHost.innerHTML = '<div class="nxr-skeleton nxr-skeleton--kpis"></div>';
            }
            const search = root.querySelector('[data-filter="search"]');
            const activitytype = root.querySelector('[data-filter="activitytype"]');
            const completionstatus = root.querySelector('[data-filter="completionstatus"]');
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            let userid = learnerCombo.getSelected();
            if (opts.resetLearner) {
                userid = 0;
                selectedUserid = 0;
            } else if (opts.usePrimed && selectedUserid) {
                userid = selectedUserid;
            }
            if (opts.resetSection) {
                selectedSection = -1;
            }
            if (opts.resetYear) {
                if (college) {
                    college.value = '';
                }
                if (year) {
                    year.value = '';
                }
                if (department) {
                    department.value = '';
                }
            } else if (opts.resetDepartment && department) {
                department.value = '';
            }

            Ajax.call([{
                methodname: 'local_nexreports_get_learner_course_activities',
                args: {
                    courseid: getCourseId() || (cfg.courseid || 0),
                    userid: userid,
                    section: selectedSection,
                    search: search ? search.value : '',
                    activitytype: activitytype ? (activitytype.value || '') : '',
                    completionstatus: completionstatus ? (completionstatus.value || 'all') : 'all',
                    learnersearch: '',
                    metaonly: false,
                    year: year ? (year.value || '') : '',
                    department: department ? (department.value || '') : '',
                    institution: college ? (college.value || '') : '',
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                suppressChange = true;
                selectedUserid = data.selecteduserid || 0;
                selectedSection = data.selectedsection != null ? data.selectedsection : -1;
                sectionOptions = data.sections || [];
                fillCourseOnce(
                    root.querySelector('[data-filter="course"]'),
                    data.courses,
                    data.selectedcourseid
                );
                if (root.querySelector('[data-filter="college"]')) {
                    fillNamedSelect(
                        root.querySelector('[data-filter="college"]'),
                        data.colleges,
                        data.selectedinstitution,
                        label(root, 'all-colleges', 'All colleges')
                    );
                }
                fillNamedSelect(
                    root.querySelector('[data-filter="year"]'),
                    data.years,
                    data.selectedyear,
                    label(root, 'all-years', 'All years')
                );
                fillNamedSelect(
                    root.querySelector('[data-filter="department"]'),
                    data.departments,
                    data.selecteddepartment,
                    data.selectedyear
                        ? label(root, 'all-departments', 'All departments')
                        : label(root, 'select-year-first', 'Select a year first')
                );
                fillNamedSelect(
                    root.querySelector('[data-filter="activitytype"]'),
                    data.activitytypes,
                    data.selectedactivitytype || ''
                );
                const selectedName = (data.summary && data.summary.fullname) || '';
                learnerCombo.setSelected(data.selecteduserid, selectedName, data.learners || []);
                let sectionName = label(root, 'all-sections', 'All sections');
                (data.sections || []).forEach(function(s) {
                    if (String(s.id) === String(selectedSection)) {
                        sectionName = s.name;
                    }
                });
                sectionCombo.setSelected(String(selectedSection), sectionName, data.sections || []);
                sectionCombo.setOptions(data.sections || []);
                window.setTimeout(function() {
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
                    url.searchParams.set('userid', String(data.selecteduserid || 0));
                    url.searchParams.set('section', String(data.selectedsection));
                    url.searchParams.set('search', data.search || '');
                    url.searchParams.set('activitytype', data.selectedactivitytype || '');
                    url.searchParams.set('completionstatus', data.selectedcompletionstatus || 'all');
                    url.searchParams.set('institution', data.selectedinstitution || '');
                    url.searchParams.set('year', data.selectedyear || '');
                    url.searchParams.set('department', data.selecteddepartment || '');
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
        const activitytype = root.querySelector('[data-filter="activitytype"]');
        const completionstatus = root.querySelector('[data-filter="completionstatus"]');
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
                load({resetLearner: true, resetSection: true, resetYear: true});
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
                load({resetLearner: true});
            });
        }
        if (year) {
            year.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                load({resetLearner: true, resetDepartment: true});
            });
        }
        if (department) {
            department.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                load({resetLearner: true});
            });
        }
        if (activitytype) {
            activitytype.addEventListener('change', function() {
                if (!suppressChange) {
                    load();
                }
            });
        }
        if (completionstatus) {
            completionstatus.addEventListener('change', function() {
                if (!suppressChange) {
                    load();
                }
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
                    sortDir = (key === 'activity' || key === 'type' || key === 'status') ? 'asc' : 'desc';
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
        load({usePrimed: true});
    };

    return {init: init};
});
