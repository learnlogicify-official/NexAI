/**
 * In-rail course editing for NexCoursePro — DnD, inline rename, native chooser slots.
 *
 * @module     format_nexcoursepro/editor
 * @copyright  2026 NexAcademy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/notification',
    'core/str',
], function(Ajax, Notification, Str) {

    const qs = (root, sel) => (root || document).querySelector(sel);
    const qsa = (root, sel) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

    const escapeHtml = (s) => String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const errorMessage = (err) => {
        if (!err) {
            return 'Request failed.';
        }
        if (typeof err === 'string') {
            return err;
        }
        const msg = err.message || err.error || err.errorcode || '';
        if (msg && msg !== 'generalexceptionmessage' && msg !== 'error') {
            return String(msg);
        }
        if (err.debuginfo) {
            return String(err.debuginfo).split('\n')[0];
        }
        if (err.errorcode && err.errorcode !== 'generalexceptionmessage') {
            return String(err.errorcode);
        }
        return 'Something went wrong while updating the course. Check Site administration → Notifications, then purge caches.';
    };

    const notifyError = (err) => {
        const msg = errorMessage(err);
        if (window.console && console.error) {
            console.error('NexCoursePro editor', err);
        }
        // Prefer a clear toast over Moodle's empty "generalexceptionmessage" modal.
        if (Notification && typeof Notification.addNotification === 'function') {
            Notification.addNotification({
                message: msg,
                type: 'error',
            });
            return;
        }
        Notification.exception(err);
    };

    const callUpdate = (action, courseid, ids, targetsectionid, targetcmid) => {
        const args = {action: action, courseid: courseid, ids: ids || []};
        if (targetsectionid) {
            args.targetsectionid = targetsectionid;
        }
        if (targetcmid) {
            args.targetcmid = targetcmid;
        }
        return Ajax.call([{
            methodname: 'core_courseformat_update_course',
            args: args,
        }])[0];
    };

    const editChromeSel = '[data-region="nxpro-sec-drag"], [data-region="nxpro-act-drag"], ' +
        '[data-region="nxpro-act-edit"], [data-region="nxpro-act-delete"], [data-region="nxpro-sec-delete"], ' +
        '[data-region="nxpro-insert"], [data-region="nxpro-sec-foot"], ' +
        '[data-region="nxpro-between-sec"], [data-region="nxpro-rename"]';

    const deleteIconSvg = '<svg class="nxpro-act__delete-ico" viewBox="0 0 24 24" width="14" height="14" ' +
        'aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="M9 3h6l1 2h5v2H3V5h5l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9zm-1 12h12a1 1 0 0 0 1-1V7H5v13a1 1 0 0 0 1 1z"/>' +
        '</svg>';

    const setEditing = (root, on) => {
        root.classList.toggle('is-editing', on);
        root.setAttribute('data-editing', on ? '1' : '0');
        const bar = qs(root, '[data-region="nxpro-editbar"]');
        if (bar) {
            bar.classList.toggle('is-hidden', !on);
            bar.hidden = !on;
        }
        const label = qs(root, '[data-region="nxpro-edit-label"]');
        const btn = qs(root, '[data-action="nxpro-edit-toggle"]');
        if (label) {
            Str.get_strings([
                {key: 'editcourse', component: 'format_nexcoursepro'},
                {key: 'doneediting', component: 'format_nexcoursepro'},
            ]).then((strings) => {
                label.textContent = on ? strings[1] : strings[0];
            }).catch(() => {
                label.textContent = on ? 'Done' : 'Edit';
            });
        }
        if (btn) {
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.classList.toggle('is-active', on);
        }

        qsa(root, editChromeSel).forEach((el) => {
            el.classList.toggle('is-hidden', !on);
            if (el.hasAttribute('hidden')) {
                el.hidden = !on;
            }
        });
        qsa(root, '.nxpro-act').forEach((el) => {
            el.draggable = !!on;
        });
        qsa(root, '.nxpro-sec').forEach((el) => {
            if (on) {
                el.open = true;
            }
        });
        // Swap name control: link text vs rename button.
        qsa(root, '.nxpro-act__name--view').forEach((el) => {
            el.classList.toggle('is-hidden', on);
        });
        qsa(root, '[data-region="nxpro-rename"]').forEach((el) => {
            el.classList.toggle('is-hidden', !on);
        });
    };

    const insertSlotHtml = (sectionnum, sectionid, beforemod, editing) => {
        const before = beforemod ? String(beforemod) : '';
        // Moodle core listens for button.section-modchooser-link and reads
        // data-sectionnum (+ data-section-id). data-sectionid alone is wrong:
        // core overwrites sectionnum with the DB id.
        return '<li class="nxpro-insert' + (editing ? '' : ' is-hidden') +
            '" data-region="nxpro-insert">' +
            '<button type="button" class="nxpro-insert__btn section-modchooser-link"' +
            ' data-action="open-chooser"' +
            ' data-sectionnum="' + sectionnum + '"' +
            ' data-section-id="' + sectionid + '"' +
            (before ? ' data-beforemod="' + before + '"' : '') +
            ' title="Add activity here" aria-label="Add activity here">' +
            '<span aria-hidden="true">+</span></button></li>';
    };

    const activityRowHtml = (act, sec, editing, canmanage) => {
        return '<li class="nxpro-act' + (act.active ? ' is-active' : '') +
            (act.completed ? ' is-done' : '') + (act.failed ? ' is-failed' : '') +
            (act.isnested ? ' is-nested' : '') +
            '" data-search="' + escapeHtml(act.searchtext || '') +
            '" data-cmid="' + act.id +
            '" data-sectionid="' + (act.sectionid || sec.sectionid) +
            '" data-modname="' + escapeHtml(act.modname || '') +
            '" data-modurl="' + escapeHtml(act.modurl || '') +
            '" data-viewurl="' + escapeHtml(act.viewurl || '') +
            '" data-editurl="' + escapeHtml(act.editurl || '') +
            '" draggable="' + (editing ? 'true' : 'false') + '">' +
            '<span class="nxpro-act__drag' + (editing ? '' : ' is-hidden') +
            '" data-region="nxpro-act-drag" aria-hidden="true"></span>' +
            '<a class="nxpro-act__link" href="' + escapeHtml(act.viewurl || '#') +
            '" data-action="nxpro-nav" data-cmid="' + act.id + '">' +
            '<span class="nxpro-act__name nxpro-act__name--view' + (editing ? ' is-hidden' : '') + '">' +
            escapeHtml(act.name) + '</span>' +
            '<span class="nxpro-act__check' + (act.completed ? ' is-done' : '') +
            (act.failed ? ' is-failed' : '') +
            '" aria-hidden="true"></span></a>' +
            '<button type="button" class="nxpro-act__rename' + (editing ? '' : ' is-hidden') +
            '" data-region="nxpro-rename" data-action="nxpro-rename" data-cmid="' + act.id +
            '" title="Rename">' + escapeHtml(act.name) + '</button>' +
            '<a class="nxpro-act__edit' + (editing ? '' : ' is-hidden') +
            '" data-region="nxpro-act-edit" href="' + escapeHtml(act.editurl || '#') +
            '" title="Edit settings">✎</a>' +
            (canmanage ?
                '<button type="button" class="nxpro-act__delete' + (editing ? '' : ' is-hidden') +
                '" data-region="nxpro-act-delete" data-action="nxpro-delete" data-cmid="' + act.id +
                '" title="Delete activity" aria-label="Delete activity">' + deleteIconSvg + '</button>' : '') +
            '</li>';
    };

    const ringHtml = (sec, small) => {
        if (!sec.hasprogress) {
            return '';
        }
        const pct = parseInt(sec.progresspct || 0, 10) || 0;
        const label = sec.progresslabel || (pct + '%');
        return '<span class="nxpro-ring' + (small ? ' nxpro-ring--sm' : '') +
            (sec.sectioncomplete ? ' is-done' : '') +
            '" style="--nxpro-ring: ' + pct + ';" title="' +
            escapeHtml((sec.completedcount || 0) + ' / ' + (sec.activitycount || 0)) +
            '" role="img" aria-label="' + escapeHtml(label) +
            '"><span class="nxpro-ring__val">' + pct + '</span></span>';
    };

    const renderOutline = (root, data) => {
        const host = qs(root, '[data-region="nxpro-outline"]');
        if (!host || !data) {
            return;
        }
        const sections = data.sections || [];
        const hassubsection = root.getAttribute('data-hassubsection') === '1';
        const cansection = root.getAttribute('data-cansection') === '1';
        const canmanage = root.getAttribute('data-canmanage') === '1';
        if (!sections.length) {
            host.innerHTML = '<p class="nxpro-aside__empty" data-region="nxpro-outline-empty"></p>';
            const empty = qs(host, '[data-region="nxpro-outline-empty"]');
            if (empty) {
                Str.get_string('emptycourse', 'format_nexcoursepro').then((s) => {
                    empty.textContent = s;
                }).catch(() => {
                    empty.textContent = 'No activities yet.';
                });
            }
            if (cansection) {
                host.innerHTML += '<div class="nxpro-between-sec" data-region="nxpro-between-sec">' +
                    '<button type="button" class="nxpro-btn nxpro-btn--ghost nxpro-btn--sm" ' +
                    'data-action="nxpro-add-section" data-aftersectionid="0">Add section</button></div>';
            }
            return;
        }

        const editing = root.classList.contains('is-editing');
        let html = '';
        const actListHtml = (sec, acts, nested) => {
            let block = '<ul class="nxpro-acts' + (nested ? ' nxpro-acts--nested' : '') +
                '" data-region="nxpro-acts" data-sectionid="' + sec.sectionid +
                '" data-section="' + sec.sectionnum + '">';
            if (canmanage && !nested) {
                block += insertSlotHtml(sec.sectionnum, sec.sectionid, acts.length ? acts[0].id : 0, editing);
            }
            acts.forEach((act, ai) => {
                block += activityRowHtml(act, sec, editing, canmanage);
                if (canmanage && !nested) {
                    const next = acts[ai + 1];
                    block += insertSlotHtml(sec.sectionnum, sec.sectionid, next ? next.id : 0, editing);
                }
            });
            block += '</ul>';
            return block;
        };
        sections.forEach((sec, idx) => {
            const acts = sec.activities || [];
            const subs = sec.subsections || [];
            html += '<details class="nxpro-sec" data-section="' + sec.sectionnum +
                '" data-sectionid="' + sec.sectionid + '"' + (sec.expanded || editing ? ' open' : '') + '>' +
                '<summary class="nxpro-sec__sum">' +
                '<span class="nxpro-sec__drag' + (editing ? '' : ' is-hidden') +
                '" data-region="nxpro-sec-drag" draggable="true" aria-hidden="true"></span>' +
                '<span class="nxpro-sec__chev" aria-hidden="true"></span>' +
                '<span class="nxpro-sec__title">' + escapeHtml(sec.title || sec.name) + '</span>';
            html += ringHtml(sec, false);
            html += '</summary>';
            html += actListHtml(sec, acts, false);
            subs.forEach((sub) => {
                const subacts = sub.activities || [];
                html += '<details class="nxpro-subsec" data-section="' + sub.sectionnum +
                    '" data-sectionid="' + sub.sectionid + '"' + (sub.expanded || editing ? ' open' : '') + '>' +
                    '<summary class="nxpro-subsec__sum">' +
                    '<span class="nxpro-sec__chev" aria-hidden="true"></span>' +
                    '<span class="nxpro-subsec__title">' + escapeHtml(sub.title || sub.name) + '</span>';
                html += ringHtml(sub, true);
                html += '</summary>' + actListHtml(sub, subacts, true) + '</details>';
            });
            if (!acts.length && !subs.length) {
                html += '<p class="nxpro-aside__empty" data-region="nxpro-sec-empty">No activities in this section yet.</p>';
            }

            const showDelete = !!(sec.candelete || (cansection && !acts.length && !subs.length));
            if ((canmanage && hassubsection) || showDelete) {
                html += '<div class="nxpro-sec__foot' + (editing ? '' : ' is-hidden') +
                    '" data-region="nxpro-sec-foot">';
                if (showDelete) {
                    html += '<button type="button" class="nxpro-btn nxpro-btn--ghost nxpro-btn--sm nxpro-btn--danger" ' +
                        'data-region="nxpro-sec-delete" data-action="nxpro-delete-section" ' +
                        'data-sectionid="' + sec.sectionid + '">Delete section</button>';
                }
                if (canmanage && hassubsection) {
                    html += '<button type="button" class="nxpro-btn nxpro-btn--ghost nxpro-btn--sm" ' +
                        'data-action="nxpro-add-nested" data-section="' + sec.sectionnum +
                        '" data-sectionid="' + sec.sectionid + '">Add subsection</button>';
                }
                html += '</div>';
            }
            html += '</details>';

            if (cansection) {
                html += '<div class="nxpro-between-sec' + (editing ? '' : ' is-hidden') +
                    '" data-region="nxpro-between-sec">' +
                    '<button type="button" class="nxpro-btn nxpro-btn--ghost nxpro-btn--sm" ' +
                    'data-action="nxpro-add-section" data-aftersectionid="' + sec.sectionid +
                    '">Add section</button></div>';
            }
            void idx;
        });
        if (cansection) {
            html += '<div class="nxpro-outline__addsec' + (editing ? '' : ' is-hidden') +
                '" data-region="nxpro-between-sec">' +
                '<button type="button" class="nxpro-btn nxpro-btn--ghost nxpro-btn--sm" ' +
                'data-action="nxpro-add-section" data-aftersectionid="0">Add section</button></div>';
        }
        host.innerHTML = html;
        bindDrag(root);
        localizeEditLabels(root);
    };

    const localizeEditLabels = (root) => {
        Str.get_strings([
            {key: 'addnestedsection', component: 'format_nexcoursepro'},
            {key: 'addsection', component: 'format_nexcoursepro'},
            {key: 'insertactivity', component: 'format_nexcoursepro'},
            {key: 'renameactivity', component: 'format_nexcoursepro'},
            {key: 'editactivity', component: 'format_nexcoursepro'},
            {key: 'deleteactivity', component: 'format_nexcoursepro'},
            {key: 'deletesection', component: 'format_nexcoursepro'},
        ]).then((strings) => {
            qsa(root, '[data-action="nxpro-add-nested"]').forEach((btn) => {
                btn.textContent = strings[0];
            });
            qsa(root, '[data-action="nxpro-add-section"]').forEach((btn) => {
                btn.textContent = strings[1];
            });
            qsa(root, '.nxpro-insert__btn').forEach((btn) => {
                btn.setAttribute('title', strings[2]);
                btn.setAttribute('aria-label', strings[2]);
            });
            qsa(root, '[data-action="nxpro-rename"]').forEach((btn) => {
                btn.setAttribute('title', strings[3]);
            });
            qsa(root, '[data-region="nxpro-act-edit"]').forEach((a) => {
                a.setAttribute('title', strings[4]);
            });
            qsa(root, '[data-action="nxpro-delete"]').forEach((btn) => {
                btn.setAttribute('title', strings[5]);
                btn.setAttribute('aria-label', strings[5]);
            });
            qsa(root, '[data-action="nxpro-delete-section"]').forEach((btn) => {
                btn.setAttribute('title', strings[6]);
                btn.setAttribute('aria-label', strings[6]);
            });
        }).catch(() => { /* keep fallback */ });
    };

    const refreshOutline = (root, preferCmid) => {
        const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
        let cmid = preferCmid;
        if (typeof cmid === 'undefined' || cmid === null) {
            const pane = qs(root, '[data-region="nxpro-pane"]');
            cmid = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
        }
        cmid = parseInt(cmid || '0', 10);
        return Ajax.call([{
            methodname: 'format_nexcoursepro_get_outline',
            args: {courseid: courseid, cmid: cmid},
        }])[0].then((data) => {
            renderOutline(root, data);
            setEditing(root, root.classList.contains('is-editing'));
            return data;
        }).catch((err) => {
            notifyError(err);
            throw err;
        });
    };

    const bindDrag = (root) => {
        let dragEl = null;
        let dragKind = '';

        qsa(root, '.nxpro-act').forEach((row) => {
            row.addEventListener('dragstart', (e) => {
                if (!root.classList.contains('is-editing')) {
                    return;
                }
                // Don't start drag from rename / settings / delete controls.
                if (e.target && e.target.closest &&
                        (e.target.closest('[data-action="nxpro-rename"]') ||
                            e.target.closest('[data-region="nxpro-act-edit"]') ||
                            e.target.closest('[data-action="nxpro-delete"]') ||
                            e.target.closest('input'))) {
                    e.preventDefault();
                    return;
                }
                dragEl = row;
                dragKind = 'cm';
                row.classList.add('is-dragging');
                try {
                    e.dataTransfer.setData('text/plain', row.getAttribute('data-cmid') || '');
                    e.dataTransfer.effectAllowed = 'move';
                } catch (err) { /* ignore */ }
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('is-dragging');
                qsa(root, '.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
                dragEl = null;
                dragKind = '';
            });
        });

        qsa(root, '.nxpro-sec').forEach((sec) => {
            const handle = qs(sec, '[data-region="nxpro-sec-drag"]');
            if (handle) {
                handle.addEventListener('dragstart', (e) => {
                    if (!root.classList.contains('is-editing')) {
                        return;
                    }
                    dragEl = sec;
                    dragKind = 'section';
                    sec.classList.add('is-dragging');
                    try {
                        e.dataTransfer.setData('text/plain', sec.getAttribute('data-sectionid') || '');
                        e.dataTransfer.effectAllowed = 'move';
                    } catch (err) { /* ignore */ }
                    e.stopPropagation();
                });
                handle.addEventListener('dragend', () => {
                    sec.classList.remove('is-dragging');
                    qsa(root, '.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
                    dragEl = null;
                    dragKind = '';
                });
            }
        });

        qsa(root, '.nxpro-acts, .nxpro-act, .nxpro-sec').forEach((el) => {
            el.addEventListener('dragover', (e) => {
                if (!dragEl) {
                    return;
                }
                e.preventDefault();
                el.classList.add('is-drop-target');
            });
            el.addEventListener('dragleave', () => el.classList.remove('is-drop-target'));
            el.addEventListener('drop', (e) => {
                e.preventDefault();
                el.classList.remove('is-drop-target');
                if (!dragEl || !root.classList.contains('is-editing')) {
                    return;
                }
                const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
                if (dragKind === 'cm') {
                    const cmid = parseInt(dragEl.getAttribute('data-cmid') || '0', 10);
                    let targetsectionid = 0;
                    let targetcmid = 0;
                    const targetAct = el.classList.contains('nxpro-act') ? el : el.closest('.nxpro-act');
                    if (targetAct && targetAct !== dragEl) {
                        targetcmid = parseInt(targetAct.getAttribute('data-cmid') || '0', 10);
                        targetsectionid = parseInt(targetAct.getAttribute('data-sectionid') || '0', 10);
                    } else {
                        const list = el.classList.contains('nxpro-acts') ? el : el.closest('.nxpro-acts');
                        const sec = el.classList.contains('nxpro-sec') ? el : el.closest('.nxpro-sec');
                        targetsectionid = parseInt(
                            (list && list.getAttribute('data-sectionid')) ||
                            (sec && sec.getAttribute('data-sectionid')) || '0', 10);
                    }
                    if (!cmid || (!targetsectionid && !targetcmid)) {
                        return;
                    }
                    callUpdate('cm_move', courseid, [cmid], targetsectionid || null, targetcmid || null)
                        .then(() => refreshOutline(root))
                        .catch(notifyError);
                } else if (dragKind === 'section') {
                    const sectionid = parseInt(dragEl.getAttribute('data-sectionid') || '0', 10);
                    const targetSec = el.classList.contains('nxpro-sec') ? el : el.closest('.nxpro-sec');
                    if (!sectionid || !targetSec || targetSec === dragEl) {
                        return;
                    }
                    const targetsectionid = parseInt(targetSec.getAttribute('data-sectionid') || '0', 10);
                    callUpdate('section_move_after', courseid, [sectionid], targetsectionid)
                        .then(() => refreshOutline(root))
                        .catch(notifyError);
                }
            });
        });
    };

    const applyActivityName = (root, cmid, name) => {
        const rows = qsa(root, '.nxpro-act[data-cmid="' + cmid + '"]');
        rows.forEach((row) => {
            const renameBtn = qs(row, '[data-region="nxpro-rename"]');
            const view = qs(row, '.nxpro-act__name--view');
            if (renameBtn && !renameBtn.querySelector('input') && !renameBtn.classList.contains('is-saving')) {
                renameBtn.textContent = name;
            }
            if (view) {
                view.textContent = name;
            }
            const modname = row.getAttribute('data-modname') || '';
            row.setAttribute('data-search', String(name || '').toLowerCase() + (modname ? ' ' + modname : ''));
        });
        const pane = qs(root, '[data-region="nxpro-pane"]');
        if (pane && parseInt(pane.getAttribute('data-cmid') || '0', 10) === cmid) {
            const title = qs(root, '[data-region="nxpro-title"]');
            if (title) {
                title.textContent = name;
            }
        }
    };

    const startRename = (root, btn) => {
        if (!btn || btn.querySelector('input') || btn.classList.contains('is-saving')) {
            return;
        }
        const cmid = parseInt(btn.getAttribute('data-cmid') || '0', 10);
        if (!cmid) {
            return;
        }
        const row = btn.closest('.nxpro-act');
        const current = (btn.textContent || '').trim();
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'nxpro-act__rename-input';
        input.value = current;
        input.setAttribute('aria-label', 'Rename');
        btn.textContent = '';
        btn.appendChild(input);
        input.focus();
        input.select();

        let finished = false;
        const restore = (name) => {
            btn.classList.remove('is-saving');
            if (row) {
                row.classList.remove('is-renaming');
            }
            btn.textContent = name;
        };

        const finish = (save) => {
            if (finished) {
                return;
            }
            finished = true;
            const next = (input.value || '').trim();
            // Detach blur so removing the input does not re-enter finish.
            input.onblur = null;
            input.onkeydown = null;

            if (!save || !next || next === current) {
                restore(current);
                return;
            }

            btn.classList.add('is-saving');
            if (row) {
                row.classList.add('is-renaming');
            }
            // Show optimistic name under a fading loader.
            btn.innerHTML = '<span class="nxpro-act__rename-text">' + escapeHtml(next) + '</span>' +
                '<span class="nxpro-act__rename-loader" aria-hidden="true"></span>';

            Ajax.call([{
                methodname: 'format_nexcoursepro_rename_cm',
                args: {cmid: cmid, name: next},
            }])[0].then((data) => {
                const name = (data && data.name) ? String(data.name) : next;
                restore(name);
                applyActivityName(root, cmid, name);
                // Brief fade confirmation.
                btn.classList.add('is-saved');
                window.setTimeout(() => btn.classList.remove('is-saved'), 700);
            }).catch((ex) => {
                restore(current);
                notifyError(ex);
            });
        };

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                finish(true);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                finish(false);
            }
        });
        input.addEventListener('blur', () => {
            // Defer so Enter keydown can mark finished first.
            window.setTimeout(() => finish(true), 0);
        });
        input.addEventListener('click', (e) => e.stopPropagation());
        input.addEventListener('mousedown', (e) => e.stopPropagation());
    };

    const deleteActivity = (root, btn) => {
        const cmid = parseInt(btn.getAttribute('data-cmid') || '0', 10);
        if (!cmid || btn.disabled) {
            return;
        }
        const row = btn.closest('.nxpro-act');
        let name = '';
        if (row) {
            const renameBtn = qs(row, '[data-region="nxpro-rename"]');
            const viewName = qs(row, '.nxpro-act__name--view');
            name = ((renameBtn && renameBtn.textContent) || (viewName && viewName.textContent) || '').trim();
        }

        const pickNeighborCmid = () => {
            if (!row) {
                return 0;
            }
            let el = row.nextElementSibling;
            while (el) {
                if (el.classList.contains('nxpro-act') && el.getAttribute('data-cmid')) {
                    return parseInt(el.getAttribute('data-cmid'), 10) || 0;
                }
                el = el.nextElementSibling;
            }
            el = row.previousElementSibling;
            while (el) {
                if (el.classList.contains('nxpro-act') && el.getAttribute('data-cmid')) {
                    return parseInt(el.getAttribute('data-cmid'), 10) || 0;
                }
                el = el.previousElementSibling;
            }
            return 0;
        };

        const removeRowOptimistic = () => {
            if (!row || !row.parentNode) {
                return;
            }
            // Structure is: [insert] act [insert] act … — drop the insert after this row.
            const after = row.nextElementSibling;
            if (after && after.classList.contains('nxpro-insert')) {
                after.remove();
            }
            row.remove();
        };

        const navigatePane = (nextCmid) => {
            nextCmid = parseInt(nextCmid || '0', 10);
            if (nextCmid > 0) {
                root.dispatchEvent(new CustomEvent('nxpro:navigate', {
                    detail: {cmid: nextCmid, force: true},
                }));
                return;
            }
            const pane = qs(root, '[data-region="nxpro-pane"]');
            if (pane) {
                pane.setAttribute('data-cmid', '0');
                pane.innerHTML = '<div class="nxpro-av"><p class="nxpro-empty">No activity selected.</p></div>';
            }
        };

        const runDelete = () => {
            const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
            const pane = qs(root, '[data-region="nxpro-pane"]');
            const paneCmid = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
            const wasActive = paneCmid === cmid;
            const nextCmid = wasActive ? pickNeighborCmid() : paneCmid;

            btn.disabled = true;
            if (row) {
                row.classList.add('is-deleting');
            }
            // Instant sidebar update, then confirm with server + full outline sync.
            removeRowOptimistic();

            return callUpdate('cm_delete', courseid, [cmid]).then(() => {
                // Never ask outline for the deleted cmid — that breaks the AJAX refresh.
                return refreshOutline(root, wasActive ? nextCmid : paneCmid);
            }).then((data) => {
                if (wasActive) {
                    let resolved = nextCmid;
                    if (!resolved && data && data.sections) {
                        data.sections.forEach((sec) => {
                            (sec.activities || []).forEach((act) => {
                                if (!resolved && act && act.id) {
                                    resolved = parseInt(act.id, 10);
                                }
                            });
                        });
                    }
                    navigatePane(resolved);
                }
                return data;
            }).catch((err) => {
                // Re-sync outline so a failed delete does not leave a hole.
                refreshOutline(root, paneCmid).catch(() => { /* ignore */ });
                notifyError(err);
            });
        };

        const ask = (title, question, deleteLabel) => {
            if (Notification && typeof Notification.deleteCancelPromise === 'function') {
                return Notification.deleteCancelPromise(title, question, deleteLabel || title)
                    .then(runDelete)
                    .catch(() => { /* cancelled */ });
            }
            if (window.confirm(question)) {
                return runDelete();
            }
            return null;
        };

        Str.get_strings([
            {key: 'deleteactivity', component: 'format_nexcoursepro'},
            {key: 'confirmdeleteactivity', component: 'format_nexcoursepro', param: name || 'activity'},
        ]).then((strings) => {
            ask(strings[0], strings[1], strings[0]);
        }).catch(() => {
            ask('Delete activity', 'Delete "' + (name || 'this activity') + '"? This cannot be undone.', 'Delete');
        });
    };

    const addSection = (root, nested, aftersectionid, sectionnum, triggerBtn) => {
        const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
        const pane = qs(root, '[data-region="nxpro-pane"]');
        const cmid = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
        const btn = triggerBtn || null;
        if (btn) {
            btn.disabled = true;
        }

        Ajax.call([{
            methodname: 'format_nexcoursepro_add_section',
            args: {
                courseid: courseid,
                cmid: cmid,
                aftersectionid: nested ? 0 : (aftersectionid || 0),
                parentsectionnum: nested ? (sectionnum || 0) : 0,
            },
        }])[0].then((data) => {
            renderOutline(root, data);
            setEditing(root, true);
        }).catch((err) => {
            if (btn) {
                btn.disabled = false;
            }
            notifyError(err);
        });
    };

    const deleteSection = (root, btn) => {
        const sectionid = parseInt(btn.getAttribute('data-sectionid') || '0', 10);
        if (!sectionid || btn.disabled) {
            return;
        }
        const sec = btn.closest('.nxpro-sec');
        const titleEl = sec ? qs(sec, '.nxpro-sec__title') : null;
        const name = titleEl ? (titleEl.textContent || '').trim() : '';

        const runDelete = () => {
            const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
            const pane = qs(root, '[data-region="nxpro-pane"]');
            const cmid = pane ? parseInt(pane.getAttribute('data-cmid') || '0', 10) : 0;
            btn.disabled = true;
            if (sec) {
                sec.classList.add('is-deleting');
            }
            return Ajax.call([{
                methodname: 'format_nexcoursepro_delete_section',
                args: {courseid: courseid, sectionid: sectionid, cmid: cmid},
            }])[0].then((data) => {
                renderOutline(root, data);
                setEditing(root, true);
                return data;
            }).catch((err) => {
                btn.disabled = false;
                if (sec) {
                    sec.classList.remove('is-deleting');
                }
                notifyError(err);
            });
        };

        const ask = (title, question, deleteLabel) => {
            if (Notification && typeof Notification.deleteCancelPromise === 'function') {
                return Notification.deleteCancelPromise(title, question, deleteLabel || title)
                    .then(runDelete)
                    .catch(() => { /* cancelled */ });
            }
            if (window.confirm(question)) {
                return runDelete();
            }
            return null;
        };

        Str.get_strings([
            {key: 'deletesection', component: 'format_nexcoursepro'},
            {key: 'confirmdeletesection', component: 'format_nexcoursepro', param: name || 'section'},
        ]).then((strings) => {
            ask(strings[0], strings[1], strings[0]);
        }).catch(() => {
            ask('Delete section', 'Delete empty section "' + (name || '') + '"?', 'Delete');
        });
    };

    const moodleEditingOn = () => {
        if (document.body.classList.contains('editing')) {
            return true;
        }
        const sw = document.querySelector('.editmode-switch-form input[type="checkbox"], ' +
            'input[name="setmode"], .editmode-switch input[type="checkbox"]');
        if (sw && (sw.checked || sw.getAttribute('aria-checked') === 'true')) {
            return true;
        }
        return false;
    };

    const watchMoodleEditToggle = (root) => {
        const sync = () => {
            const on = moodleEditingOn() || root.getAttribute('data-editing') === '1';
            setEditing(root, on);
        };

        document.addEventListener('change', (e) => {
            const t = e.target;
            if (!t) {
                return;
            }
            if (t.matches && (t.matches('.editmode-switch-form input') ||
                    t.matches('input[name="setmode"]') ||
                    t.closest('.editmode-switch-form') ||
                    t.closest('.editmode-switch'))) {
                window.setTimeout(sync, 50);
            }
        }, true);

        document.addEventListener('click', (e) => {
            const t = e.target && e.target.closest && e.target.closest(
                '.editmode-switch-form, .editmode-switch, [data-action="toggle-editmode"], .editmode-switch-form label'
            );
            if (!t) {
                return;
            }
            window.setTimeout(sync, 100);
        }, true);

        try {
            const obs = new MutationObserver(() => sync());
            obs.observe(document.body, {attributes: true, attributeFilter: ['class']});
        } catch (err) { /* ignore */ }
    };

    /**
     * Force the chooser to fit the viewport with a scrollable body.
     * CSS alone fails under RemUI / Bootstrap centered-dialog transforms.
     */
    const fitChooserLayout = (modal) => {
        const dialog = modal.querySelector('.modal-dialog');
        const content = modal.querySelector('.modal-content');
        const body = modal.querySelector('.modal-body');
        const header = modal.querySelector('.modal-header');
        const footer = modal.querySelector('.modal-footer');
        if (!dialog || !content || !body) {
            return;
        }

        const margin = 32;
        const maxH = Math.max(280, Math.floor(window.innerHeight - margin));

        dialog.classList.remove('modal-dialog-centered');
        dialog.style.setProperty('transform', 'none', 'important');
        dialog.style.setProperty('margin', '16px auto', 'important');
        dialog.style.setProperty('max-height', maxH + 'px', 'important');
        dialog.style.setProperty('height', maxH + 'px', 'important');
        dialog.style.setProperty('display', 'flex', 'important');

        content.style.setProperty('height', '100%', 'important');
        content.style.setProperty('max-height', '100%', 'important');
        content.style.setProperty('display', 'flex', 'important');
        content.style.setProperty('flex-direction', 'column', 'important');
        content.style.setProperty('overflow', 'hidden', 'important');

        const hh = header ? header.getBoundingClientRect().height : 0;
        const fh = footer ? footer.getBoundingClientRect().height : 0;
        const bodyH = Math.max(160, Math.floor(maxH - hh - fh));

        body.style.setProperty('flex', '1 1 auto', 'important');
        body.style.setProperty('min-height', '0', 'important');
        body.style.setProperty('height', bodyH + 'px', 'important');
        body.style.setProperty('max-height', bodyH + 'px', 'important');
        body.style.setProperty('overflow-x', 'hidden', 'important');
        body.style.setProperty('overflow-y', 'auto', 'important');
        body.style.setProperty('-webkit-overflow-scrolling', 'touch');
    };

    /**
     * Tag Moodle's activity chooser modal so Pro CSS can style it, force a
     * viewport-fit layout, and strip Marketplace / MoodleNet footer promos.
     */
    const enhanceChooserModal = (modal) => {
        if (!modal) {
            return;
        }
        const titleEl = modal.querySelector('.modal-title, .modal-header h5, .modal-header h4');
        const title = ((titleEl && titleEl.textContent) || '').toLowerCase();
        const isChooser = modal.classList.contains('modchooser') ||
            modal.classList.contains('nxpro-chooser') ||
            /activity or resource|add an activity|choose activity/.test(title) ||
            !!modal.querySelector(
                '.modchoosercontainer, .optionscontainer, [data-region="chooser-options-container"], ' +
                '[data-action="add-selected-chooser-option"], [data-region="active-footer-container"]'
            );
        if (!isChooser) {
            return;
        }

        modal.classList.add('modchooser', 'nxpro-chooser');

        const hidePromo = (el) => {
            if (!el) {
                return;
            }
            el.hidden = true;
            el.setAttribute('aria-hidden', 'true');
            el.style.setProperty('display', 'none', 'important');
        };

        modal.querySelectorAll('[data-region="active-footer-container"]').forEach(hidePromo);
        modal.querySelectorAll('.modal-footer a, [data-region="chooser-option-summary-actions-container"] a').forEach((a) => {
            const t = ((a.textContent || '') + ' ' + (a.getAttribute('aria-label') || '')).toLowerCase();
            if (/marketplace|moodlenet|browse more|browse for content/.test(t) ||
                    a.getAttribute('data-action') === 'show-moodlenet') {
                hidePromo(a);
                if (a.parentElement && a.parentElement !== modal.querySelector('.modal-footer')) {
                    const siblings = a.parentElement.querySelectorAll('a, button');
                    if (siblings.length <= 1) {
                        hidePromo(a.parentElement);
                    }
                }
            }
        });
        modal.querySelectorAll('.moodlenet-logo, [data-region="moodle-net"]').forEach(hidePromo);

        fitChooserLayout(modal);
        window.requestAnimationFrame(() => fitChooserLayout(modal));
    };

    const watchChooserModals = () => {
        if (document.documentElement.dataset.nxproChooserWatch === '1') {
            return;
        }
        document.documentElement.dataset.nxproChooserWatch = '1';
        const scan = () => {
            document.querySelectorAll('.modal, [role="dialog"]').forEach(enhanceChooserModal);
        };
        scan();
        try {
            const obs = new MutationObserver(() => scan());
            obs.observe(document.body, {childList: true, subtree: true});
        } catch (err) { /* ignore */ }
        document.addEventListener('click', () => {
            window.setTimeout(scan, 50);
            window.setTimeout(scan, 300);
            window.setTimeout(scan, 800);
        }, true);
        window.addEventListener('resize', () => {
            document.querySelectorAll('.modal.nxpro-chooser, .modal.modchooser').forEach(fitChooserLayout);
        });
    };

    const initChooser = (root) => {
        const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
        if (!courseid || root.dataset.nxproChooser === '1') {
            return;
        }
        watchChooserModals();
        // Moodle 5.0 template builder reads chooserConfig.tabmode; 5.1+ ignores it.
        const chooserConfig = {tabmode: 0};
        const done = (Chooser) => {
            try {
                if (Chooser && typeof Chooser.init === 'function') {
                    Chooser.init(courseid, chooserConfig);
                    root.dataset.nxproChooser = '1';
                    watchChooserModals();
                }
            } catch (err) {
                window.console && console.warn && console.warn('NexCoursePro chooser init', err);
            }
        };
        // Moodle 5.1+ moved the chooser into core_courseformat; 5.0 still uses core_course.
        require(['core_courseformat/activitychooser'], done, function() {
            require(['core_course/activitychooser'], done, function() {
                window.console && console.warn &&
                    console.warn('NexCoursePro: native activity chooser AMD not found');
            });
        });
    };

    const init = (root) => {
        if (!root || root.dataset.nxproEditor === '1') {
            return;
        }
        if (root.getAttribute('data-canedit') !== '1') {
            return;
        }
        root.dataset.nxproEditor = '1';

        bindDrag(root);
        watchMoodleEditToggle(root);
        initChooser(root);

        const startOn = root.getAttribute('data-editing') === '1' || moodleEditingOn();
        setEditing(root, startOn);

        root.addEventListener('click', (e) => {
            const renameBtn = e.target.closest('[data-action="nxpro-rename"]');
            if (renameBtn && root.classList.contains('is-editing')) {
                e.preventDefault();
                e.stopPropagation();
                startRename(root, renameBtn);
                return;
            }

            const deleteBtn = e.target.closest('[data-action="nxpro-delete"]');
            if (deleteBtn && root.classList.contains('is-editing')) {
                e.preventDefault();
                e.stopPropagation();
                deleteActivity(root, deleteBtn);
                return;
            }

            const delSec = e.target.closest('[data-action="nxpro-delete-section"]');
            if (delSec && root.classList.contains('is-editing')) {
                e.preventDefault();
                e.stopPropagation();
                deleteSection(root, delSec);
                return;
            }

            const addSec = e.target.closest('[data-action="nxpro-add-section"]');
            if (addSec) {
                e.preventDefault();
                e.stopPropagation();
                const after = parseInt(addSec.getAttribute('data-aftersectionid') || '0', 10);
                addSection(root, false, after, 0, addSec);
                return;
            }
            const addNested = e.target.closest('[data-action="nxpro-add-nested"]');
            if (addNested) {
                e.preventDefault();
                e.stopPropagation();
                const sectionnum = parseInt(addNested.getAttribute('data-section') || '1', 10);
                addSection(root, true, 0, sectionnum, addNested);
                return;
            }

            // In edit mode, don't navigate when clicking the activity row chrome.
            if (root.classList.contains('is-editing')) {
                const nav = e.target.closest('[data-action="nxpro-nav"]');
                if (nav && nav.closest('.nxpro-act')) {
                    if (!e.target.closest('[data-region="nxpro-act-edit"], [data-region="nxpro-act-delete"]')) {
                        e.preventDefault();
                    }
                }
            }

            // Native chooser: core listens for button.section-modchooser-link.
            const openChooser = e.target.closest('button.section-modchooser-link, [data-action="open-chooser"]');
            if (openChooser && root.dataset.nxproChooser !== '1') {
                e.preventDefault();
                const courseid = parseInt(root.getAttribute('data-courseid') || '0', 10);
                const sectionnum = parseInt(
                    openChooser.getAttribute('data-sectionnum') ||
                    openChooser.getAttribute('data-section-num') || '0', 10);
                window.location.href = (window.M && M.cfg ? M.cfg.wwwroot : '') +
                    '/course/view.php?id=' + courseid + '&section=' + sectionnum;
            }
        }, true); // Capture so summary/details does not swallow section actions.
    };

    return {
        init: init,
        refreshOutline: refreshOutline,
        setEditing: setEditing,
    };
});
