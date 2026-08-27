/**
 * NexComm manage CRUD.
 *
 * @module local_nexcomm/manage
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    /**
     * Parse manage textarea into questions JSON.
     * Blocks separated by --- ; first line Q: stem; choices one per line; * marks correct.
     */
    const parseQuestions = function(raw) {
        const blocks = String(raw || '').split(/\n---\n/);
        const out = [];
        blocks.forEach(function(block) {
            const lines = block.split(/\r\n|\n|\r/).map(function(l) { return l.trim(); }).filter(Boolean);
            if (!lines.length) {
                return;
            }
            let stem = lines[0];
            if (/^Q:/i.test(stem)) {
                stem = stem.replace(/^Q:\s*/i, '');
            }
            const choiceLines = lines.slice(1);
            if (!choiceLines.length) {
                out.push({stem: stem, choices: {}, correctkey: 'A'});
                return;
            }
            const choices = {};
            let correct = 'A';
            choiceLines.forEach(function(line, idx) {
                const key = String.fromCharCode(65 + idx);
                let label = line;
                if (label.charAt(0) === '*') {
                    correct = key;
                    label = label.slice(1).trim();
                }
                choices[key] = label;
            });
            out.push({stem: stem, choices: choices, correctkey: correct});
        });
        return out;
    };

    const init = function() {
        const root = $('[data-region="nc-manage"]');
        if (!root.length) {
            return;
        }
        const el = root.get(0);
        const list = root.find('[data-region="list"]');
        const editor = root.find('[data-region="editor"]');
        const form = root.find('[data-region="form"]');
        const status = root.find('[data-region="form-status"]');
        let cache = [];

        const label = function(name) {
            return el.getAttribute('data-label-' + name) || name;
        };

        const renderList = function(items) {
            cache = items || [];
            if (!cache.length) {
                list.html('<p class="nc-empty">No activities yet.</p>');
                return;
            }
            list.html('<div class="nc-managelist">' + cache.map(function(item) {
                return '<div class="nc-manage-row" data-id="' + esc(item.id) + '">' +
                    '<span class="nc-badge nc-badge--' + esc(item.skill) + '">' + esc(item.skill) + '</span>' +
                    '<span class="nc-badge nc-badge--' + esc(item.difficulty) + '">' + esc(item.difficulty) + '</span>' +
                    '<span class="nc-badge">' + esc(item.status) + '</span>' +
                    '<span class="nc-manage-row__title">' + esc(item.title) + '</span>' +
                    '<span class="nc-muted">' + esc(item.qcount) + ' Q</span>' +
                    '<div class="nc-manage-row__actions">' +
                        '<button type="button" class="nc-btn nc-btn--ghost" data-action="edit">Edit</button>' +
                        '<button type="button" class="nc-btn nc-btn--danger" data-action="delete">Delete</button>' +
                    '</div></div>';
            }).join('') + '</div>');
        };

        const openEditor = function(item) {
            editor.removeAttr('hidden');
            root.find('[data-region="editor-title"]').text(item ? 'Edit activity' : 'Add activity');
            form.find('[name="id"]').val(item ? item.id : 0);
            form.find('[name="skill"]').val(item ? item.skill : 'reading');
            form.find('[name="difficulty"]').val(item ? item.difficulty : 'easy');
            form.find('[name="title"]').val(item ? item.title : '');
            form.find('[name="body"]').val(item ? item.body : '');
            form.find('[name="prompt"]').val(item ? item.prompt : '');
            form.find('[name="audiourl"]').val(item ? item.audiourl : '');
            form.find('[name="passmark"]').val(item ? item.passmark : 70);
            form.find('[name="minwords"]').val(item ? item.minwords : 40);
            form.find('[name="tags"]').val(item ? item.tags : '');
            form.find('[name="status"]').val(item ? item.status : 'ready');
            form.find('[name="questionsraw"]').val(item ? (item.questionsraw || '') : '');
            status.attr('hidden', true);
            editor.get(0).scrollIntoView({behavior: 'smooth', block: 'start'});
        };

        const load = function() {
            Ajax.call([{
                methodname: 'local_nexcomm_list_manage',
                args: {}
            }])[0].then(function(data) {
                renderList(data.items || []);
                return null;
            }).catch(Notification.exception);
        };

        root.on('click', '[data-action="new"]', function() {
            openEditor(null);
        });

        root.on('click', '[data-action="cancel"]', function() {
            editor.attr('hidden', true);
        });

        root.on('click', '[data-action="edit"]', function() {
            const id = parseInt($(this).closest('[data-id]').attr('data-id'), 10);
            const item = cache.find(function(x) { return x.id === id; });
            openEditor(item || null);
        });

        root.on('click', '[data-action="delete"]', function() {
            if (!window.confirm(label('confirm'))) {
                return;
            }
            const id = parseInt($(this).closest('[data-id]').attr('data-id'), 10);
            Ajax.call([{
                methodname: 'local_nexcomm_delete_activity',
                args: {id: id}
            }])[0].then(function() {
                load();
                return null;
            }).catch(Notification.exception);
        });

        form.on('submit', function(e) {
            e.preventDefault();
            const questions = parseQuestions(form.find('[name="questionsraw"]').val());
            Ajax.call([{
                methodname: 'local_nexcomm_save_activity',
                args: {
                    id: parseInt(form.find('[name="id"]').val(), 10) || 0,
                    skill: form.find('[name="skill"]').val(),
                    difficulty: form.find('[name="difficulty"]').val(),
                    title: form.find('[name="title"]').val(),
                    body: form.find('[name="body"]').val(),
                    prompt: form.find('[name="prompt"]').val(),
                    audiourl: form.find('[name="audiourl"]').val(),
                    status: form.find('[name="status"]').val(),
                    passmark: parseInt(form.find('[name="passmark"]').val(), 10) || 70,
                    minwords: parseInt(form.find('[name="minwords"]').val(), 10) || 0,
                    tags: form.find('[name="tags"]').val(),
                    questionsjson: JSON.stringify(questions)
                }
            }])[0].then(function(res) {
                status.text(res.message || 'Saved').removeAttr('hidden');
                editor.attr('hidden', true);
                load();
                return null;
            }).catch(Notification.exception);
        });

        load();
    };

    return {init: init};
});
