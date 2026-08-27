/**
 * NexReports Learner Course Progress.
 *
 * @module     local_nexreports/learner_course_progress
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
            {key: 'coursename', label: label(root, 'course', 'Course'), type: 'str', sortKey: 'coursename', link: true},
            {key: 'status', label: label(root, 'status', 'Status'), type: 'str', sortKey: 'status', status: true},
            {key: 'enrolledon', label: label(root, 'enrolledon', 'Enrolled on'), type: 'time', sortKey: 'enrolledontime'},
            {key: 'completedon', label: label(root, 'completedon', 'Completed on'), type: 'time', sortKey: 'completedontime'},
            {key: 'lastaccess', label: label(root, 'lastaccess', 'Last access'), type: 'time', sortKey: 'lastaccesstime'},
            {key: 'progress', label: label(root, 'progress', 'Progress'), type: 'num', sortKey: 'progress', suffix: '%'},
            {key: 'grade', label: label(root, 'grade', 'Grade'), type: 'num', sortKey: 'grade'},
            {key: 'totalactivities', label: label(root, 'totalactivities', 'Total activities'), type: 'num', sortKey: 'totalactivities'},
            {key: 'completedactivities', label: label(root, 'completedactivities', 'Completed activities'), type: 'num', sortKey: 'completedactivities'},
            {key: 'attemptedactivities', label: label(root, 'attemptedactivities', 'Attempted activities'), type: 'num', sortKey: 'attemptedactivities'},
            {key: 'codingsolved', label: label(root, 'codingsolved', 'Coding solved'), type: 'num', sortKey: 'codingsolved'},
            {key: 'codingtotal', label: label(root, 'codingtotal', 'Coding total'), type: 'num', sortKey: 'codingtotal'},
            {key: 'visits', label: label(root, 'visits', 'Visits'), type: 'num', sortKey: 'visits'},
            {key: 'timespent', label: label(root, 'timespent', 'Time spent'), type: 'num', sortKey: 'timespent', duration: true},
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

    const fillNamedSelect = function(select, options, selected, allLabel) {
        if (!select) {
            return;
        }
        const keep = String(selected || '');
        select.innerHTML = '<option value="">' + esc(allLabel) + '</option>' +
            (options || []).map(function(o) {
                return '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>';
            }).join('');
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

    /**
     * Searchable learner picker (few names + typeahead).
     */
    const createLearnerCombo = function(root, getFilters, onSelect) {
        const container = root.querySelector('[data-combo="learner"]');
        if (!container) {
            return {setSelected: function() {}, paintOptions: function() {}};
        }
        const toggle = container.querySelector('.nxr-combo__toggle');
        const valueEl = container.querySelector('.nxr-combo__value');
        const panel = container.querySelector('.nxr-combo__panel');
        const input = container.querySelector('.nxr-combo__search');
        const list = container.querySelector('.nxr-combo__list');
        const placeholder = container.getAttribute('data-placeholder') ||
            label(root, 'select-learner', 'Select a learner');
        let options = [];
        let selectedId = 0;
        let selectedName = '';
        let activeIndex = -1;
        let timer = null;
        let seq = 0;

        const rows = function() {
            return list.querySelectorAll('.nxr-combo__option');
        };

        const highlight = function(index) {
            const items = rows();
            activeIndex = Math.max(-1, Math.min(items.length - 1, index));
            items.forEach(function(item, i) {
                item.classList.toggle('is-active', i === activeIndex);
            });
            if (activeIndex >= 0 && items[activeIndex]) {
                items[activeIndex].scrollIntoView({block: 'nearest'});
            }
        };

        const paintMessage = function(message) {
            list.innerHTML = '<li class="nxr-combo__msg">' + esc(message) + '</li>';
        };

        const paint = function() {
            list.innerHTML =
                '<li class="nxr-combo__option' + (!selectedId ? ' is-selected' : '') +
                    '" role="option" data-id="0">' + esc(placeholder) + '</li>' +
                options.map(function(option) {
                    return '<li class="nxr-combo__option' +
                        (String(option.id) === String(selectedId) ? ' is-selected' : '') +
                        '" role="option" data-id="' + esc(option.id) + '">' +
                        esc(option.name) + '</li>';
                }).join('');
            highlight(-1);
        };

        const fetch = function(query) {
            const filters = getFilters();
            if (!filters.year) {
                options = [];
                seq++;
                paintMessage(label(root, 'select-year-first', 'Select a year first'));
                return;
            }
            const id = ++seq;
            paintMessage(label(root, 'searching', 'Searching…'));
            Ajax.call([{
                methodname: 'local_nexreports_get_learner_course_progress',
                args: {
                    userid: selectedId || 0,
                    search: '',
                    year: filters.year,
                    department: filters.department,
                    learnersearch: query || '',
                    metaonly: true,
                    institution: filters.institution,
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                options = data.learners || [];
                if (!options.length && query) {
                    paintMessage(label(root, 'nomatches', 'No matches found'));
                    return null;
                }
                paint();
                return null;
            }).catch(function() {
                if (id === seq) {
                    paintMessage(label(root, 'loaderror', 'Could not load'));
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
            fetch('');
        };

        const choose = function(id, name) {
            selectedId = Number(id) || 0;
            selectedName = selectedId ? name : '';
            valueEl.textContent = selectedId ? selectedName : placeholder;
            container.classList.toggle('is-filtered', !!selectedId);
            close();
            onSelect(selectedId, selectedName);
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
                fetch(query);
            }, 250);
        });

        list.addEventListener('click', function(event) {
            const option = event.target.closest('.nxr-combo__option');
            if (option) {
                choose(option.getAttribute('data-id'), option.textContent);
            }
        });

        input.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const item = rows()[activeIndex];
                if (item) {
                    choose(item.getAttribute('data-id'), item.textContent);
                }
            } else if (event.key === 'Escape') {
                close();
                toggle.focus();
            }
        });

        document.addEventListener('click', function(event) {
            if (!panel.hidden && !container.contains(event.target)) {
                close();
            }
        });

        return {
            setSelected: function(id, name, seedOptions) {
                selectedId = Number(id) || 0;
                selectedName = selectedId && name ? name : '';
                valueEl.textContent = selectedId ? selectedName : placeholder;
                container.classList.toggle('is-filtered', !!selectedId);
                if (seedOptions) {
                    options = seedOptions.slice();
                }
            },
            getSelected: function() {
                return selectedId;
            },
        };
    };

    const renderSummary = function(root, summary) {
        const host = root.querySelector('[data-region="lcp-summary"]');
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
            '<div class="nxr-lcp-summary__identity">' +
            '<a class="nxr-lcp-summary__name" href="' + esc(summary.url || '#') + '">' + esc(summary.fullname) + '</a>' +
            '<span class="nxr-status ' + statusCls + '">' + esc(summary.status || '') + '</span>' +
            '</div>' +
            '<p class="nxr-lcp-summary__last">' + esc(label(root, 'lastaccess', 'Last access')) + ': ' +
            esc(summary.lastaccess || '—') + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__topline">' +
            '<span><strong>' + esc(label(root, 'visitsoncourse', 'Visits on course')) + ':</strong> ' +
            esc(Number(summary.visitsoncourse || 0).toLocaleString()) + '</span>' +
            '<span><strong>' + esc(label(root, 'timespentoncourse', 'Time spent on course')) + ':</strong> ' +
            esc(formatDuration(summary.timespentoncourse)) + '</span>' +
            '<span><strong>' + esc(label(root, 'timespentonsite', 'Time spent on site')) + ':</strong> ' +
            esc(formatDuration(summary.timespentonsite)) + '</span>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metrics">' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(Number(summary.enrolledcourses || 0).toLocaleString()) + '</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'enrolledcourses', 'Enrolled courses')) + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(Number(summary.completionprogress || 0)) + '%</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'completionprogress', 'Completion progress')) + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(Number(summary.totalmarks || 0)) + '</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'totalmarks', 'Total marks')) + '</p>' +
            '</div>' +
            '<div class="nxr-lcp-summary__metric">' +
            '<p class="nxr-lcp-summary__value">' + esc(Number(summary.totalgrade || 0)) + '%</p>' +
            '<p class="nxr-lcp-summary__label">' + esc(label(root, 'totalgrade', 'Total grade')) + '</p>' +
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
                    if (col.link) {
                        return '<td><a href="' + esc(r.courseurl) + '">' + esc(text) + '</a></td>';
                    }
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
        const root = document.querySelector('[data-region="nxr-learner-course-progress"]');
        if (!root) {
            return;
        }
        TableExport.bind(root);
        const cols = columns(root);
        let seq = 0;
        let allRows = [];
        let page = 1;
        let pageSize = 10;
        let sortKey = 'coursename';
        let sortDir = 'asc';
        let suppressChange = false;
        let selectedUserid = cfg.userid || 0;
        let lastSummary = null;

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

        const learnerCombo = createLearnerCombo(root, getFilters, function(userid) {
            selectedUserid = userid || 0;
            load();
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
            const summaryHost = root.querySelector('[data-region="lcp-summary"]');
            if (host) {
                host.innerHTML = '<div class="nxr-skeleton nxr-skeleton--table"></div>';
            }
            if (summaryHost) {
                summaryHost.innerHTML = '<div class="nxr-skeleton nxr-skeleton--kpis"></div>';
            }
            const college = root.querySelector('[data-filter="college"]');
            const year = root.querySelector('[data-filter="year"]');
            const department = root.querySelector('[data-filter="department"]');
            const search = root.querySelector('[data-filter="search"]');
            let userid = learnerCombo.getSelected ? learnerCombo.getSelected() : selectedUserid;
            if (opts.resetLearner) {
                userid = 0;
                selectedUserid = 0;
            } else if (opts.usePrimed && selectedUserid) {
                userid = selectedUserid;
            }
            if (opts.resetYear) {
                if (year) {
                    year.value = '';
                }
                if (department) {
                    department.value = '';
                }
            }

            Ajax.call([{
                methodname: 'local_nexreports_get_learner_course_progress',
                args: {
                    userid: userid,
                    search: search ? search.value : '',
                    year: year ? (year.value || '') : '',
                    department: department ? (department.value || '') : '',
                    learnersearch: '',
                    metaonly: false,
                    institution: college ? (college.value || '') : '',
                },
            }])[0].then(function(data) {
                if (id !== seq) {
                    return null;
                }
                suppressChange = true;
                selectedUserid = data.selecteduserid || 0;
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
                const selectedName = (data.summary && data.summary.fullname) || '';
                learnerCombo.setSelected(data.selecteduserid, selectedName, data.learners || []);
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
                    url.searchParams.set('userid', String(data.selecteduserid || 0));
                    url.searchParams.set('institution', data.selectedinstitution || '');
                    url.searchParams.set('year', data.selectedyear || '');
                    url.searchParams.set('department', data.selecteddepartment || '');
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
        const year = root.querySelector('[data-filter="year"]');
        const department = root.querySelector('[data-filter="department"]');
        const collegeFilter = root.querySelector('[data-filter="college"]');
        if (search) {
            search.addEventListener('input', function() {
                window.clearTimeout(timer);
                timer = window.setTimeout(function() {
                    load();
                }, 300);
            });
        }
        if (collegeFilter) {
            collegeFilter.addEventListener('change', function() {
                if (suppressChange) {
                    return;
                }
                load({resetLearner: true, resetYear: true});
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
                load({resetLearner: true});
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
        root.addEventListener('click', function(e) {
            const th = e.target.closest('th[data-sort]');
            if (th && root.contains(th)) {
                const key = th.getAttribute('data-sort');
                if (key === sortKey) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    const descDefault = {
                        enrolledon: 1,
                        completedon: 1,
                        lastaccess: 1,
                        progress: 1,
                        grade: 1,
                        totalactivities: 1,
                        completedactivities: 1,
                        attemptedactivities: 1,
                        codingsolved: 1,
                        codingtotal: 1,
                        visits: 1,
                        timespent: 1,
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
        load({usePrimed: true});
    };

    return {init: init};
});
