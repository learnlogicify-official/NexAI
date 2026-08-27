define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {
    /**
     * Pro Enrol users modal — college / year / department filters + bulk select.
     *
     * @module format_nexcoursepro/enrol_roster
     */

    const state = {
        courseid: 0,
        college: '',
        year: '',
        department: '',
        query: '',
        roleid: 0,
        page: 0,
        perpage: 80,
        total: 0,
        selected: new Set(),
        busy: false,
        loadSeq: 0,
        labels: {},
    };

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const optionHtml = (values, selected, allLabel, disabledHint) => {
        const emptyLabel = (!values || !values.length) && disabledHint ? disabledHint : allLabel;
        let html = '<option value="">' + esc(emptyLabel) + '</option>';
        (values || []).forEach((v) => {
            const sel = String(v) === String(selected) ? ' selected' : '';
            html += '<option value="' + esc(v) + '"' + sel + '>' + esc(v) + '</option>';
        });
        return html;
    };

    /**
     * Rebuild filter dropdowns from server option lists; keep client selection.
     *
     * @param {Object} data
     */
    const fillFilters = (data) => {
        const root = ensureModal();
        const collegeSel = root.querySelector('[data-filter="college"]');
        const yearSel = root.querySelector('[data-filter="year"]');
        const deptSel = root.querySelector('[data-filter="department"]');

        const colleges = data.colleges || [];
        const years = data.years || [];
        const departments = data.departments || [];

        // Preserve client cascade; only clear children if parent options no longer include them.
        if (state.year && years.length && years.indexOf(state.year) === -1) {
            state.year = '';
            state.department = '';
        }
        if (state.department && departments.length && departments.indexOf(state.department) === -1) {
            state.department = '';
        }
        if (!state.college) {
            state.year = '';
            state.department = '';
        } else if (!state.year) {
            state.department = '';
        }

        collegeSel.innerHTML = optionHtml(
            colleges,
            state.college,
            state.labels.choosecollege || 'Select college'
        );

        const hasCollege = !!state.college;
        yearSel.disabled = !hasCollege;
        yearSel.innerHTML = optionHtml(
            hasCollege ? years : [],
            state.year,
            state.labels.chooseyear || 'Select year of passing',
            state.labels.choosecollegefirst || 'Select a college first'
        );
        // Re-apply value after rebuild (browsers can drop selected on innerHTML).
        if (hasCollege && state.year) {
            yearSel.value = state.year;
        }

        const hasYear = hasCollege && !!state.year;
        deptSel.disabled = !hasYear;
        deptSel.innerHTML = optionHtml(
            hasYear ? departments : [],
            state.department,
            state.labels.choosedepartment || 'Select department',
            state.labels.chooseyearfirst || 'Select year of passing first'
        );
        if (hasYear && state.department) {
            deptSel.value = state.department;
        }

        const q = root.querySelector('[data-filter="query"]');
        q.value = state.query || '';
        q.disabled = !hasCollege;

        const roleSel = root.querySelector('[data-filter="role"]');
        if (roleSel && Array.isArray(data.roles)) {
            let roleHtml = '';
            const preferred = state.roleid || data.roleid || 0;
            data.roles.forEach((role) => {
                const id = parseInt(role.id, 10) || 0;
                const sel = id === preferred ? ' selected' : '';
                roleHtml += '<option value="' + id + '"' + sel + '>' + esc(role.name) + '</option>';
            });
            roleSel.innerHTML = roleHtml ||
                ('<option value="0">' + esc(state.labels.role || 'Assign role') + '</option>');
            if (preferred) {
                roleSel.value = String(preferred);
                state.roleid = preferred;
            } else if (roleSel.options.length) {
                state.roleid = parseInt(roleSel.value, 10) || 0;
            }
        }
    };

    const renderRows = (users, needCollege) => {
        const root = ensureModal();
        const list = root.querySelector('[data-region="nxpro-enrol-list"]');
        if (needCollege) {
            list.innerHTML = '<p class="nxpro-enrol__empty">' +
                esc(state.labels.needcollege || 'Select a college to load users.') + '</p>';
            return;
        }
        if (!state.year) {
            list.innerHTML = '<p class="nxpro-enrol__empty">' +
                esc(state.labels.needyear || 'Select a year of passing to continue.') + '</p>';
            return;
        }
        if (!users || !users.length) {
            list.innerHTML = '<p class="nxpro-enrol__empty">' + esc(state.labels.empty || 'No matching users.') + '</p>';
            return;
        }
        list.innerHTML = users.map((u) => {
            const checked = state.selected.has(u.id) ? ' checked' : '';
            return (
                '<label class="nxpro-enrol__row">' +
                '<input type="checkbox" data-userid="' + u.id + '"' + checked + '>' +
                '<span class="nxpro-enrol__who">' +
                (u.avatar || '') +
                '<span class="nxpro-enrol__meta">' +
                '<strong>' + esc(u.fullname) + '</strong>' +
                '<span class="nxpro-enrol__handle">@' + esc(u.username) + '</span>' +
                '<span class="nxpro-enrol__tags">' +
                '<span>' + esc(u.college) + '</span>' +
                '<span>' + esc(u.year) + '</span>' +
                '<span>' + esc(u.department) + '</span>' +
                '</span>' +
                '</span></span></label>'
            );
        }).join('');
    };

    const load = () => {
        const root = ensureModal();
        const seq = ++state.loadSeq;
        root.classList.add('is-loading');
        const args = {
            courseid: state.courseid,
            college: state.college,
            year: state.year,
            department: state.department,
            query: state.query,
            page: state.page,
            perpage: state.perpage,
        };
        return Ajax.call([{
            methodname: 'format_nexcoursepro_search_enrol_users',
            args: args,
        }])[0].then((data) => {
            if (seq !== state.loadSeq) {
                return data; // Stale response — ignore UI update.
            }
            state.total = data.total || 0;
            fillFilters(data);
            const needCollege = !state.college || !!data.needcollege;
            renderRows(data.users || [], needCollege);
            syncSubmit();
            root.classList.remove('is-loading');
            return data;
        }).catch((err) => {
            if (seq !== state.loadSeq) {
                return;
            }
            root.classList.remove('is-loading');
            Notification.exception(err);
        });
    };

    const ensureModal = () => {
        let root = document.getElementById('nxpro-enrol-modal');
        if (root) {
            return root;
        }
        root = document.createElement('div');
        root.id = 'nxpro-enrol-modal';
        root.className = 'nxpro-enrol';
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
        root.innerHTML =
            '<div class="nxpro-enrol__backdrop" data-action="nxpro-enrol-close"></div>' +
            '<div class="nxpro-enrol__panel" role="dialog" aria-modal="true" aria-labelledby="nxpro-enrol-title">' +
            '  <header class="nxpro-enrol__head">' +
            '    <div>' +
            '      <h2 id="nxpro-enrol-title" class="nxpro-enrol__title"></h2>' +
            '      <p class="nxpro-enrol__sub" data-region="nxpro-enrol-sub"></p>' +
            '    </div>' +
            '    <button type="button" class="nxpro-btn nxpro-btn--ghost nxpro-btn--sm" data-action="nxpro-enrol-close"' +
            '      aria-label="Close">×</button>' +
            '  </header>' +
            '  <div class="nxpro-enrol__filters">' +
            '    <label class="nxpro-enrol__field">' +
            '      <span data-label="college"></span>' +
            '      <select data-filter="college"></select>' +
            '    </label>' +
            '    <label class="nxpro-enrol__field">' +
            '      <span data-label="year"></span>' +
            '      <select data-filter="year"></select>' +
            '    </label>' +
            '    <label class="nxpro-enrol__field">' +
            '      <span data-label="department"></span>' +
            '      <select data-filter="department"></select>' +
            '    </label>' +
            '    <label class="nxpro-enrol__field nxpro-enrol__field--grow">' +
            '      <span data-label="search"></span>' +
            '      <input type="search" data-filter="query" placeholder="" autocomplete="off">' +
            '    </label>' +
            '  </div>' +
            '  <div class="nxpro-enrol__toolbar">' +
            '    <label class="nxpro-enrol__checkall">' +
            '      <input type="checkbox" data-action="nxpro-enrol-select-all">' +
            '      <span data-label="selectall"></span>' +
            '    </label>' +
            '    <label class="nxpro-enrol__role">' +
            '      <span data-label="role"></span>' +
            '      <select data-filter="role"></select>' +
            '    </label>' +
            '    <span class="nxpro-enrol__count" data-region="nxpro-enrol-count"></span>' +
            '  </div>' +
            '  <div class="nxpro-enrol__list" data-region="nxpro-enrol-list"></div>' +
            '  <footer class="nxpro-enrol__foot">' +
            '    <button type="button" class="nxpro-btn nxpro-btn--ghost" data-action="nxpro-enrol-close"' +
            '      data-label="cancel"></button>' +
            '    <button type="button" class="nxpro-btn nxpro-btn--solid" data-action="nxpro-enrol-submit"' +
            '      data-label="enrol" disabled></button>' +
            '  </footer>' +
            '</div>';
        document.body.appendChild(root);

        root.addEventListener('click', (e) => {
            if (e.target.closest('[data-action="nxpro-enrol-close"]')) {
                e.preventDefault();
                close();
            }
        });
        root.querySelector('[data-action="nxpro-enrol-select-all"]').addEventListener('change', (e) => {
            const on = !!e.target.checked;
            root.querySelectorAll('[data-region="nxpro-enrol-list"] input[type="checkbox"][data-userid]')
                .forEach((cb) => {
                    cb.checked = on;
                    const id = parseInt(cb.getAttribute('data-userid'), 10);
                    if (on) {
                        state.selected.add(id);
                    } else {
                        state.selected.delete(id);
                    }
                });
            syncSubmit();
        });
        root.querySelector('[data-action="nxpro-enrol-submit"]').addEventListener('click', () => enrolSelected());
        root.querySelectorAll('[data-filter]').forEach((el) => {
            const evt = el.tagName === 'INPUT' ? 'input' : 'change';
            el.addEventListener(evt, () => {
                const key = el.getAttribute('data-filter');
                if (key === 'query') {
                    window.clearTimeout(root._nxproQ);
                    root._nxproQ = window.setTimeout(() => {
                        state.query = el.value || '';
                        state.page = 0;
                        state.selected.clear();
                        load();
                    }, 280);
                    return;
                }
                const value = el.value || '';
                if (key === 'college') {
                    state.college = value;
                    state.year = '';
                    state.department = '';
                } else if (key === 'year') {
                    state.year = value;
                    state.department = '';
                } else if (key === 'department') {
                    state.department = value;
                } else if (key === 'role') {
                    state.roleid = parseInt(value, 10) || 0;
                    return; // Role does not re-query the user list.
                }
                state.page = 0;
                state.selected.clear();
                load();
            });
        });
        root.querySelector('[data-region="nxpro-enrol-list"]').addEventListener('change', (e) => {
            const cb = e.target.closest('input[type="checkbox"][data-userid]');
            if (!cb) {
                return;
            }
            const id = parseInt(cb.getAttribute('data-userid'), 10);
            if (cb.checked) {
                state.selected.add(id);
            } else {
                state.selected.delete(id);
            }
            syncSubmit();
        });
        return root;
    };

    const syncSubmit = () => {
        const root = ensureModal();
        const btn = root.querySelector('[data-action="nxpro-enrol-submit"]');
        const n = state.selected.size;
        btn.disabled = n < 1 || state.busy;
        const base = state.labels.enrol || 'Enrol selected';
        btn.textContent = n > 0 ? (base + ' (' + n + ')') : base;
        root.querySelector('[data-region="nxpro-enrol-count"]').textContent =
            (state.labels.showing || 'Showing {$a} users').replace('{$a}', String(state.total));
    };

    const enrolSelected = () => {
        if (state.selected.size < 1) {
            return;
        }
        const userids = Array.from(state.selected);
        const count = userids.length;
        const courseid = state.courseid;
        const roleSel = document.querySelector('#nxpro-enrol-modal [data-filter="role"]');
        const roleid = roleSel ? (parseInt(roleSel.value, 10) || state.roleid || 0) : (state.roleid || 0);

        // Close immediately — enrol continues server-side after the response.
        state.selected.clear();
        close();

        const pendingMsg = (state.labels.enrolpending ||
            '{$a} students will be enrolled in the background.')
            .replace('{$a}', String(count));
        Notification.addNotification({
            message: pendingMsg,
            type: 'info',
        });

        // Native fetch — avoids jQuery ajaxStart loaders / RemUI full-page freeze.
        const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
        const sesskey = (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : '';
        const url = wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(sesskey);
        const payload = JSON.stringify([{
            index: 0,
            methodname: 'format_nexcoursepro_enrol_users',
            args: {courseid: courseid, userids: userids, roleid: roleid},
        }]);

        window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: payload,
            keepalive: true,
        }).then((res) => res.json()).then((data) => {
            const row = Array.isArray(data) ? data[0] : null;
            if (row && row.error) {
                Notification.addNotification({
                    message: (row.exception && row.exception.message) || 'Enrol failed',
                    type: 'error',
                });
                return;
            }
            // Queued response is enough — toast already shown. Optional quiet confirm.
            const queued = row && row.data && row.data.queued ? row.data.queued : count;
            if (queued > 0) {
                return;
            }
        }).catch(() => {
            // Keep the pending toast; cron adhoc task is the safety net.
        });
    };

    const open = (courseid) => {
        state.courseid = courseid;
        state.college = '';
        state.year = '';
        state.department = '';
        state.query = '';
        state.roleid = 0;
        state.page = 0;
        state.selected.clear();
        const root = ensureModal();
        root.hidden = false;
        root.setAttribute('aria-hidden', 'false');
        document.body.classList.add('nxpro-enrol-open');
        load();
    };

    const close = () => {
        const root = document.getElementById('nxpro-enrol-modal');
        if (!root) {
            return;
        }
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('nxpro-enrol-open');
    };

    const isEnrolTrigger = (el) => {
        if (!el || !el.closest) {
            return false;
        }
        if (el.closest('#nxpro-enrol-modal')) {
            return false;
        }
        const hit = el.closest(
            '.enrolusersbutton, .enrol_manual_plugin, [data-action="open-enrolusers"], ' +
            'a[href*="enrol/manual"], button, a, input[type="submit"], input[type="button"]'
        );
        if (!hit) {
            return false;
        }
        const text = ((hit.value || '') + ' ' + (hit.textContent || '')).replace(/\s+/g, ' ').trim();
        if (/enrol users/i.test(text) || /enroll users/i.test(text)) {
            return true;
        }
        if (hit.classList && (hit.classList.contains('enrolusersbutton') ||
                hit.closest('.enrolusersbutton'))) {
            return true;
        }
        return false;
    };

    const init = (cfg) => {
        const courseid = parseInt((cfg && cfg.courseid) || 0, 10);
        if (!courseid) {
            return;
        }
        if (document.body.dataset.nxproEnrolBound === '1') {
            return;
        }
        document.body.dataset.nxproEnrolBound = '1';

        Str.get_strings([
            {key: 'enroluserstitle', component: 'format_nexcoursepro'},
            {key: 'enroluserssub', component: 'format_nexcoursepro'},
            {key: 'enrolcollege', component: 'format_nexcoursepro'},
            {key: 'enrolyear', component: 'format_nexcoursepro'},
            {key: 'enroldepartment', component: 'format_nexcoursepro'},
            {key: 'enrolsearch', component: 'format_nexcoursepro'},
            {key: 'enrolchoosecollege', component: 'format_nexcoursepro'},
            {key: 'enrolchooseyear', component: 'format_nexcoursepro'},
            {key: 'enrolchoosedepartment', component: 'format_nexcoursepro'},
            {key: 'enrolchoosecollegefirst', component: 'format_nexcoursepro'},
            {key: 'enrolchooseyearfirst', component: 'format_nexcoursepro'},
            {key: 'enrolneedcollege', component: 'format_nexcoursepro'},
            {key: 'enrolneedyear', component: 'format_nexcoursepro'},
            {key: 'enrolselectall', component: 'format_nexcoursepro'},
            {key: 'enrolrole', component: 'format_nexcoursepro'},
            {key: 'enrolselected', component: 'format_nexcoursepro'},
            {key: 'cancel', component: 'moodle'},
            {key: 'enrolshowing', component: 'format_nexcoursepro'},
            {key: 'enrolempty', component: 'format_nexcoursepro'},
            {key: 'enrolpending', component: 'format_nexcoursepro'},
            {key: 'enrolsuccess', component: 'format_nexcoursepro'},
        ]).then((s) => {
            state.labels = {
                title: s[0],
                sub: s[1],
                college: s[2],
                year: s[3],
                department: s[4],
                search: s[5],
                choosecollege: s[6],
                chooseyear: s[7],
                choosedepartment: s[8],
                choosecollegefirst: s[9],
                chooseyearfirst: s[10],
                needcollege: s[11],
                needyear: s[12],
                selectall: s[13],
                role: s[14],
                enrol: s[15],
                cancel: s[16],
                showing: s[17],
                empty: s[18],
                enrolpending: s[19],
                enrolledok: s[20],
                enrolsuccess: s[20],
            };
            const root = ensureModal();
            root.querySelector('#nxpro-enrol-title').textContent = state.labels.title;
            root.querySelector('[data-region="nxpro-enrol-sub"]').textContent = state.labels.sub;
            root.querySelector('[data-label="college"]').textContent = state.labels.college;
            root.querySelector('[data-label="year"]').textContent = state.labels.year;
            root.querySelector('[data-label="department"]').textContent = state.labels.department;
            root.querySelector('[data-label="search"]').textContent = state.labels.search;
            root.querySelector('[data-filter="query"]').placeholder = state.labels.search;
            root.querySelector('[data-label="selectall"]').textContent = state.labels.selectall;
            root.querySelector('[data-label="role"]').textContent = state.labels.role;
            root.querySelector('[data-label="cancel"]').textContent = state.labels.cancel;
            root.querySelector('[data-label="enrol"]').textContent = state.labels.enrol;
        }).catch(() => {
            state.labels = {
                title: 'Enrol users',
                sub: 'Select college → year of passing → department, then enrol the listed users.',
                college: 'College',
                year: 'Year of passing',
                department: 'Department',
                search: 'Search name or email',
                choosecollege: 'Select college',
                chooseyear: 'Select year of passing',
                choosedepartment: 'Select department',
                choosecollegefirst: 'Select a college first',
                chooseyearfirst: 'Select year of passing first',
                needcollege: 'Select a college to load users.',
                needyear: 'Select a year of passing to continue.',
                selectall: 'Select all on this page',
                role: 'Assign role',
                enrol: 'Enrol selected',
                cancel: 'Cancel',
                showing: 'Showing {$a} users',
                empty: 'No matching users.',
                enrolpending: '{$a} students will be enrolled in the background.',
                enrolledok: 'Enrolled {$a} users.',
                enrolsuccess: 'Enrolled {$a} users.',
            };
            const root = ensureModal();
            root.querySelector('#nxpro-enrol-title').textContent = state.labels.title;
            root.querySelector('[data-region="nxpro-enrol-sub"]').textContent = state.labels.sub;
            root.querySelector('[data-label="college"]').textContent = state.labels.college;
            root.querySelector('[data-label="year"]').textContent = state.labels.year;
            root.querySelector('[data-label="department"]').textContent = state.labels.department;
            root.querySelector('[data-label="search"]').textContent = state.labels.search;
            root.querySelector('[data-filter="query"]').placeholder = state.labels.search;
            root.querySelector('[data-label="selectall"]').textContent = state.labels.selectall;
            root.querySelector('[data-label="role"]').textContent = state.labels.role;
            root.querySelector('[data-label="cancel"]').textContent = state.labels.cancel;
            root.querySelector('[data-label="enrol"]').textContent = state.labels.enrol;
        });

        document.addEventListener('click', (e) => {
            if (!isEnrolTrigger(e.target)) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            open(courseid);
        }, true);
    };

    return {init: init, open: open, close: close};
});
