// NexResume builder — auto-fill from platform + student edits.
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    const MAX_PROJECTS = 3;

    const escapeHtml = (s) => String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const linesToArray = (text) => String(text || '')
        .split(/\r?\n/)
        .map((l) => l.trim())
        .filter(Boolean);

    const arrayToLines = (arr) => (arr || []).join('\n');

    const sectionToggle = (key, label, checked, strings) =>
        '<label class="nr-toggle"><input type="checkbox" data-section="' + key + '"' +
        (checked ? ' checked' : '') + '> ' + escapeHtml(label) + '</label>';

    const badge = (kind, strings) => {
        const text = kind === 'auto'
            ? (strings.badge_auto || 'Platform')
            : (strings.badge_yours || 'Yours');
        return '<span class="nr-badge nr-badge--' + kind + '">' + escapeHtml(text) + '</span>';
    };

    const blockOpen = (titleHtml, extraHtml, open) =>
        '<details class="nr-block"' + (open ? ' open' : '') + '>' +
        '<summary><span class="nr-block__label">' + titleHtml + extraHtml + '</span></summary>' +
        '<div class="nr-block__body">';

    const blockClose = () => '</div></details>';

    const field = (label, id, value, multiline) => {
        const val = escapeHtml(value || '');
        if (multiline) {
            return '<label class="nr-field"><span>' + escapeHtml(label) + '</span>' +
                '<textarea data-field="' + id + '" rows="4">' + val + '</textarea></label>';
        }
        return '<label class="nr-field"><span>' + escapeHtml(label) + '</span>' +
            '<input type="text" data-field="' + id + '" value="' + val + '"></label>';
    };

    const renderTemplatePicker = (doc, strings, templates, canManage) => {
        const current = doc.template || 'professional';
        const disabled = canManage ? '' : ' disabled';
        let html = '<div class="nr-templates">';
        html += '<h3 class="nr-templates__title">' + escapeHtml(strings.templates) + '</h3>';
        html += '<p class="nr-hint">' + escapeHtml(strings.templates_help) + '</p>';
        html += '<div class="nr-templates__grid" role="radiogroup" aria-label="' + escapeHtml(strings.templates) + '">';
        (templates || []).forEach((t) => {
            const active = current === t.id;
            html += '<label class="nr-template-card' + (active ? ' is-active' : '') + '">';
            html += '<input type="radio" name="nr-template" value="' + escapeHtml(t.id) + '"' +
                (active ? ' checked' : '') + disabled + ' data-action="pick-template">';
            html += '<span class="nr-template-card__mock nr-template-card__mock--' + escapeHtml(t.id) + '" aria-hidden="true"></span>';
            html += '<span class="nr-template-card__body">';
            html += '<strong>' + escapeHtml(t.name) + '</strong>';
            html += '<span>' + escapeHtml(t.desc) + '</span>';
            html += '</span></label>';
        });
        html += '</div></div>';
        return html;
    };

    const renderEditor = (doc, strings, canManage, templates) => {
        const c = doc.contact || {};
        const sk = doc.skills || {};
        const secs = doc.sections || {};
        const disabled = canManage ? '' : ' disabled';

        let html = renderTemplatePicker(doc, strings, templates, canManage);

        html += blockOpen(escapeHtml(strings.contact), '', true);
        html += '<div class="nr-fields nr-fields--2">';
        html += field('Full name', 'contact.fullname', c.fullname);
        html += field('Email', 'contact.email', c.email);
        html += field('Phone', 'contact.phone', c.phone);
        html += field('Location', 'contact.location', c.location);
        html += field('LinkedIn', 'contact.linkedin', c.linkedin);
        html += field('GitHub', 'contact.github', c.github);
        html += field('Portfolio URL', 'contact.portfolio', c.portfolio);
        html += '</div>';
        html += blockClose();

        html += blockOpen(escapeHtml(strings.objective), badge('yours', strings), false);
        html += sectionToggle('objective', strings.includesection, !!secs.objective, strings);
        html += field(strings.objective, 'objective', doc.objective, true);
        html += blockClose();

        html += blockOpen(escapeHtml(strings.education), badge('yours', strings), true);
        html += sectionToggle('education', strings.includesection, secs.education !== false, strings);
        const education = Array.isArray(doc.education)
            ? doc.education
            : (doc.education && (doc.education.school || doc.education.degree) ? [doc.education] : [{}]);
        education.forEach((ed, idx) => {
            html += '<div class="nr-edu" data-edu-idx="' + idx + '">';
            html += '<div class="nr-edu__head"><strong>' + escapeHtml(strings.education) + ' ' + (idx + 1) + '</strong>';
            if (education.length > 1) {
                html += '<button type="button" class="nxf-btn nxf-btn--sm" data-action="remove-edu" data-edu-idx="' + idx + '">' +
                    escapeHtml(strings.removeeducation || 'Remove') + '</button>';
            }
            html += '</div>';
            html += field('Institution', 'education.' + idx + '.school', ed.school);
            html += field('Degree / program', 'education.' + idx + '.degree', ed.degree);
            html += '<div class="nr-fields nr-fields--2">';
            html += field('Dates', 'education.' + idx + '.dates', ed.dates);
            html += field('CGPA / GPA', 'education.' + idx + '.gpa', ed.gpa);
            html += '</div>';
            html += field('Coursework', 'education.' + idx + '.coursework', ed.coursework, true);
            html += '</div>';
        });
        html += '<button type="button" class="nxf-btn nxf-btn--sm" data-action="add-edu">' +
            escapeHtml(strings.addeducation || 'Add education') + '</button>';
        html += blockClose();

        html += blockOpen(escapeHtml(strings.projects), badge('auto', strings), true);
        html += sectionToggle('projects', strings.includesection, !!secs.projects, strings);
        html += '<p class="nr-hint">' + escapeHtml(strings.selectprojects) + '</p>';
        const projects = doc.projects || [];
        const includedCount = projects.filter((p) => p.included).length;
        if (!projects.length) {
            html += '<p class="nr-empty">' + escapeHtml(strings.noprojects) + '</p>';
        } else {
            html += '<p class="nr-hint nr-hint--count">' + includedCount + ' / ' + MAX_PROJECTS + ' selected</p>';
            projects.forEach((p, idx) => {
                html += '<div class="nr-project" data-project-idx="' + idx + '">';
                html += '<label class="nr-check"><input type="checkbox" data-project-include="' + idx + '"' +
                    (p.included ? ' checked' : '') + disabled + '> ' + escapeHtml(p.name) + '</label>';
                html += field('Project name', 'project.name.' + idx, p.name);
                html += field(strings.projecturl || 'Repository URL', 'project.url.' + idx, p.url);
                html += field('Tech stack', 'project.stack.' + idx, p.stack);
                html += field('Date', 'project.date.' + idx, p.date);
                html += field(strings.bullets, 'project.bullets.' + idx, arrayToLines(p.bullets), true);
                html += '</div>';
            });
        }
        html += blockClose();

        html += blockOpen(escapeHtml(strings.skills), badge('auto', strings), true);
        html += sectionToggle('skills', strings.includesection, secs.skills !== false, strings);
        html += '<div class="nr-fields nr-fields--2">';
        html += field('Languages', 'skills.languages', sk.languages);
        html += field('Frameworks', 'skills.frameworks', sk.frameworks);
        html += field('Tools & cloud', 'skills.tools', sk.tools);
        html += '</div>';
        html += field('Fundamentals', 'skills.fundamentals', sk.fundamentals, true);
        html += blockClose();

        html += blockOpen(escapeHtml(strings.certifications), badge('yours', strings), false);
        html += sectionToggle('certifications', strings.includesection, !!secs.certifications, strings);
        html += field('One per line: Name | Link | Year', 'certifications.lines',
            (doc.certifications || []).map((c) =>
                [c.name, c.link, c.year].filter(Boolean).join(' | ')
            ).join('\n'), true);
        html += blockClose();

        html += blockOpen(escapeHtml(strings.competitive), badge('auto', strings), true);
        html += sectionToggle('competitive', strings.includesection, !!secs.competitive, strings);
        html += field(strings.lines, 'platforms.lines', arrayToLines(doc.platforms), true);
        html += blockClose();

        html += blockOpen(escapeHtml(strings.achievements), badge('yours', strings), false);
        html += sectionToggle('achievements', strings.includesection, !!secs.achievements, strings);
        html += field(strings.lines, 'achievements.lines', arrayToLines(doc.achievements), true);
        html += blockClose();

        html += blockOpen(escapeHtml(strings.volunteering), badge('yours', strings), false);
        html += sectionToggle('volunteering', strings.includesection, !!secs.volunteering, strings);
        html += field(strings.lines, 'volunteering.lines', arrayToLines(doc.volunteering), true);
        html += blockClose();

        return html;
    };

    const readEditor = (root, doc) => {
        const get = (sel) => {
            const el = root.querySelector(sel);
            return el ? el.value : '';
        };
        const out = JSON.parse(JSON.stringify(doc));

        out.contact = out.contact || {};
        out.skills = out.skills || {};
        out.sections = out.sections || {};

        out.contact.fullname = get('[data-field="contact.fullname"]');
        out.contact.email = get('[data-field="contact.email"]');
        out.contact.phone = get('[data-field="contact.phone"]');
        out.contact.location = get('[data-field="contact.location"]');
        out.contact.linkedin = get('[data-field="contact.linkedin"]');
        out.contact.github = get('[data-field="contact.github"]');
        out.contact.portfolio = get('[data-field="contact.portfolio"]');

        out.objective = get('[data-field="objective"]');
        out.education = [];
        root.querySelectorAll('.nr-edu[data-edu-idx]').forEach((block) => {
            const idx = block.getAttribute('data-edu-idx');
            out.education.push({
                school: get('[data-field="education.' + idx + '.school"]'),
                degree: get('[data-field="education.' + idx + '.degree"]'),
                dates: get('[data-field="education.' + idx + '.dates"]'),
                gpa: get('[data-field="education.' + idx + '.gpa"]'),
                coursework: get('[data-field="education.' + idx + '.coursework"]')
            });
        });
        if (!out.education.length) {
            out.education = [{school: '', degree: '', dates: '', gpa: '', coursework: ''}];
        }

        out.skills.languages = get('[data-field="skills.languages"]');
        out.skills.frameworks = get('[data-field="skills.frameworks"]');
        out.skills.tools = get('[data-field="skills.tools"]');
        out.skills.fundamentals = get('[data-field="skills.fundamentals"]');

        root.querySelectorAll('[data-section]').forEach((el) => {
            out.sections[el.getAttribute('data-section')] = el.checked;
        });

        (out.projects || []).forEach((p, idx) => {
            const inc = root.querySelector('[data-project-include="' + idx + '"]');
            p.included = inc ? inc.checked : p.included;
            p.name = get('[data-field="project.name.' + idx + '"]') || p.name;
            p.url = get('[data-field="project.url.' + idx + '"]') || p.url;
            p.stack = get('[data-field="project.stack.' + idx + '"]') || p.stack;
            p.date = get('[data-field="project.date.' + idx + '"]') || p.date;
            p.bullets = linesToArray(get('[data-field="project.bullets.' + idx + '"]'));
        });

        let selected = 0;
        (out.projects || []).forEach((p) => {
            if (p.included) {
                selected++;
                if (selected > MAX_PROJECTS) {
                    p.included = false;
                }
            }
        });

        out.platforms = linesToArray(get('[data-field="platforms.lines"]'));
        out.achievements = linesToArray(get('[data-field="achievements.lines"]'));
        out.volunteering = linesToArray(get('[data-field="volunteering.lines"]'));

        out.certifications = linesToArray(get('[data-field="certifications.lines"]')).map((line) => {
            const parts = line.split('|').map((x) => x.trim());
            return {name: parts[0] || '', link: parts[1] || '', year: parts[2] || ''};
        });

        const picked = root.querySelector('[name="nr-template"]:checked');
        out.template = picked ? picked.value : (doc.template || 'professional');

        return out;
    };

    const updateHeaderStats = (root, doc) => {
        const meta = doc.meta || {};
        const sources = doc.sources || {};
        const pct = meta.completeness || 0;
        const donut = root.querySelector('[data-region="donut"]');
        if (donut) {
            donut.style.setProperty('--nxf-donut-pct', String(pct));
        }
        const set = (sel, val) => {
            const el = root.querySelector(sel);
            if (el) {
                el.textContent = String(val);
            }
        };
        set('[data-region="donut-value"]', pct + '%');
        set('[data-stat="completeness"]', pct + '%');
        set('[data-hstat="completeness"]', pct + '%');
        set('[data-stat="projects"]', sources.projectcount || 0);
        set('[data-hstat="projects"]', sources.projectcount || 0);
        set('[data-stat="platforms"]', sources.platformcount || 0);
        set('[data-hstat="platforms"]', sources.platformcount || 0);
        set('[data-stat="skills"]', sources.skillcount || 0);
        set('[data-hstat="skills"]', sources.skillcount || 0);
    };

    const setStatus = (root, msg, ok) => {
        const el = root.querySelector('[data-region="status"]');
        if (el) {
            el.textContent = msg || '';
            el.classList.toggle('nr-status--ok', !!ok);
            el.classList.toggle('is-visible', !!(msg || '').trim());
        }
    };

    const setPreview = (root, html) => {
        const preview = root.querySelector('[data-region="preview"]');
        if (preview) {
            preview.innerHTML = html || '';
        }
    };

    const load = (root, cfg, refresh) => {
        cfg.ready = false;
        return Ajax.call([{
            methodname: 'local_nexresume_get_resume',
            args: {refresh: !!refresh}
        }])[0].then((data) => {
            let doc = {};
            try {
                doc = JSON.parse(data.resumejson || '{}');
            } catch (e) {
                doc = {};
            }
            cfg.doc = doc;
            try {
                if (data.templatesjson) {
                    cfg.templates = JSON.parse(data.templatesjson);
                }
            } catch (e) {
                // Keep init templates.
            }
            const editor = root.querySelector('[data-region="editor-body"]');
            if (editor) {
                editor.innerHTML = renderEditor(doc, cfg.strings, cfg.canManage, cfg.templates);
            }
            setPreview(root, data.previewhtml);
            updateHeaderStats(root, doc);
            cfg.ready = true;
            return doc;
        }).catch(Notification.exception);
    };

    const setPath = (obj, path, value) => {
        const parts = String(path).split('.');
        let cur = obj;
        for (let i = 0; i < parts.length - 1; i++) {
            const key = parts[i];
            const next = parts[i + 1];
            if (cur[key] == null) {
                cur[key] = /^\d+$/.test(next) ? [] : {};
            }
            cur = cur[key];
        }
        cur[parts[parts.length - 1]] = value;
    };

    const saveDoc = (root, cfg, options) => {
        options = options || {};
        const doc = options.doc || readEditor(root, cfg.doc || {});
        cfg.doc = doc;
        return Ajax.call([{
            methodname: 'local_nexresume_save_resume',
            args: {resumejson: JSON.stringify(doc)}
        }])[0].then((data) => {
            let saved = doc;
            try {
                saved = JSON.parse(data.resumejson || '{}');
            } catch (e) {
                saved = doc;
            }
            cfg.doc = saved;
            const previewFocused = !!root.querySelector('[data-region="preview"] [data-edit]:focus');
            if (options.refreshPreview !== false && !previewFocused) {
                setPreview(root, data.previewhtml);
            }
            if (options.refreshEditor) {
                const editor = root.querySelector('[data-region="editor-body"]');
                if (editor) {
                    editor.innerHTML = renderEditor(saved, cfg.strings, cfg.canManage, cfg.templates);
                }
            }
            updateHeaderStats(root, saved);
            return data;
        }).catch(Notification.exception);
    };

    const exportPdf = (root, cfg) => {
        const doc = readEditor(root, cfg.doc || {});
        return Ajax.call([{
            methodname: 'local_nexresume_save_resume',
            args: {resumejson: JSON.stringify(doc)}
        }])[0].then((data) => {
            const win = window.open('', '_blank');
            if (win) {
                win.document.write(data.printhtml || data.previewhtml || '');
                win.document.close();
                win.focus();
                setTimeout(() => win.print(), 400);
            }
        }).catch(Notification.exception);
    };

    const init = (cfg) => {
        const root = document.getElementById('nr-builder');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        cfg.strings = cfg.strings || {};
        cfg.templates = cfg.templates || [];

        root.addEventListener('click', (e) => {
            if (e.target.closest('.nr-preview a') && e.target.closest('[data-edit]')) {
                e.preventDefault();
            }
            const saveBtn = e.target.closest('[data-action="save"]');
            if (saveBtn) {
                setStatus(root, cfg.strings.saving);
                saveDoc(root, cfg, {refreshPreview: true, refreshEditor: true}).then(() => {
                    setStatus(root, cfg.strings.saved, true);
                });
                return;
            }
            const exportBtn = e.target.closest('[data-action="export"]');
            if (exportBtn) {
                exportPdf(root, cfg);
                return;
            }
            const refreshBtn = e.target.closest('[data-action="refresh"]');
            if (refreshBtn) {
                refreshBtn.disabled = true;
                load(root, cfg, true).finally(() => {
                    refreshBtn.disabled = false;
                });
                return;
            }
            const addEdu = e.target.closest('[data-action="add-edu"]');
            if (addEdu && cfg.canManage) {
                const doc = readEditor(root, cfg.doc || {});
                doc.education = doc.education || [];
                doc.education.push({school: '', degree: '', dates: '', gpa: '', coursework: ''});
                cfg.doc = doc;
                const editor = root.querySelector('[data-region="editor-body"]');
                if (editor) {
                    editor.innerHTML = renderEditor(doc, cfg.strings, cfg.canManage, cfg.templates);
                }
                saveDoc(root, cfg, {refreshPreview: true});
                return;
            }
            const removeEdu = e.target.closest('[data-action="remove-edu"]');
            if (removeEdu && cfg.canManage) {
                const idx = parseInt(removeEdu.getAttribute('data-edu-idx'), 10);
                const doc = readEditor(root, cfg.doc || {});
                doc.education = (doc.education || []).filter((_, i) => i !== idx);
                if (!doc.education.length) {
                    doc.education = [{school: '', degree: '', dates: '', gpa: '', coursework: ''}];
                }
                cfg.doc = doc;
                const editor = root.querySelector('[data-region="editor-body"]');
                if (editor) {
                    editor.innerHTML = renderEditor(doc, cfg.strings, cfg.canManage, cfg.templates);
                }
                saveDoc(root, cfg, {refreshPreview: true});
            }
        });

        root.addEventListener('change', (e) => {
            const box = e.target.closest('[data-project-include]');
            if (box && cfg.canManage) {
                if (box.checked) {
                    const count = root.querySelectorAll('[data-project-include]:checked').length;
                    if (count > MAX_PROJECTS) {
                        box.checked = false;
                        setStatus(root, cfg.strings.projectlimit || 'Maximum 3 projects.');
                    }
                }
                return;
            }
            const templatePick = e.target.closest('[data-action="pick-template"]');
            if (templatePick && cfg.canManage && cfg.ready) {
                root.querySelectorAll('.nr-template-card').forEach((card) => {
                    card.classList.toggle('is-active', card.contains(templatePick));
                });
                const doc = readEditor(root, cfg.doc || {});
                cfg.doc = doc;
                Ajax.call([{
                    methodname: 'local_nexresume_save_resume',
                    args: {resumejson: JSON.stringify(doc)}
                }])[0].then((data) => {
                    setPreview(root, data.previewhtml);
                }).catch(Notification.exception);
            }
        });

        const applyLiveEdit = (live, persist) => {
            const path = live.getAttribute('data-edit');
            if (!path) {
                return;
            }
            const value = (live.innerText || '').replace(/\u00a0/g, ' ');
            const doc = JSON.parse(JSON.stringify(cfg.doc || {}));
            setPath(doc, path, persist ? value.trim() : value);
            cfg.doc = doc;
            const field = root.querySelector('[data-field="' + path.replace(/"/g, '') + '"]');
            if (field) {
                field.value = persist ? value.trim() : value;
            }
            return doc;
        };

        root.addEventListener('input', (e) => {
            if (!cfg.canManage || !cfg.ready) {
                return;
            }
            const live = e.target.closest('[data-edit]');
            if (live) {
                applyLiveEdit(live, false);
                return;
            }
            const doc = readEditor(root, cfg.doc || {});
            cfg.doc = doc;
            if (cfg.previewTimer) {
                clearTimeout(cfg.previewTimer);
            }
            cfg.previewTimer = setTimeout(() => {
                saveDoc(root, cfg, {refreshPreview: true});
            }, 600);
        });

        root.addEventListener('focusout', (e) => {
            const live = e.target.closest('[data-edit]');
            if (!live || !cfg.canManage || !cfg.ready) {
                return;
            }
            const next = e.relatedTarget;
            if (next && next.closest && next.closest('[data-region="preview"]')) {
                applyLiveEdit(live, false);
                return;
            }
            const doc = applyLiveEdit(live, true);
            saveDoc(root, cfg, {refreshPreview: false, doc: doc});
        });

        load(root, cfg);
    };

    return {init: init};
});
