/**
 * NexPractice manage / import UI.
 *
 * @module     local_learnlogic/manage
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const normalize = (s) => String(s || '').toLowerCase().trim();

    const rowTagTokens = (el, attr) => {
        return normalize(el.attr(attr)).split(/\s+/).filter(Boolean);
    };

    const initChipTagFilter = (root, options) => {
        const {
            rows,
            rowTagsAttr = 'data-tags',
            searchInput,
            countEl,
            formatCount,
            onApply,
        } = options;

        const selectedTags = new Set();
        const chips = root.find('[data-action="toggle-tag-filter"]');
        const clearBtn = root.find('[data-action="clear-filters"]');

        const updateChips = () => {
            chips.each(function() {
                const tag = normalize($(this).attr('data-tag'));
                $(this).toggleClass('is-active', selectedTags.has(tag));
            });
        };

        const hasActiveFilters = () => {
            if (selectedTags.size > 0) {
                return true;
            }
            return searchInput && normalize(searchInput.val()) !== '';
        };

        const apply = () => {
            const q = searchInput ? normalize(searchInput.val()) : '';
            let visible = 0;

            rows.each(function() {
                const el = $(this);
                const nameHay = normalize(el.attr('data-name'));
                const tagHay = normalize(el.attr(rowTagsAttr));
                const hay = (nameHay + ' ' + tagHay).trim();
                const textMatch = !q || hay.indexOf(q) !== -1;
                const tokens = rowTagTokens(el, rowTagsAttr);
                const tagMatch = selectedTags.size === 0 ||
                    [...selectedTags].some((tag) => tokens.includes(tag));
                const show = textMatch && tagMatch;
                el.toggle(show);
                if (show) {
                    visible++;
                }
            });

            if (countEl && countEl.length) {
                countEl.text(formatCount(visible, rows.length));
            }
            if (clearBtn.length) {
                clearBtn.prop('hidden', !hasActiveFilters());
            }
            updateChips();
            if (typeof onApply === 'function') {
                onApply();
            }
        };

        chips.on('click', function(e) {
            e.preventDefault();
            const tag = normalize($(this).attr('data-tag'));
            if (selectedTags.has(tag)) {
                selectedTags.delete(tag);
            } else {
                selectedTags.add(tag);
            }
            apply();
        });

        clearBtn.on('click', function(e) {
            e.preventDefault();
            selectedTags.clear();
            if (searchInput) {
                searchInput.val('');
            }
            apply();
        });

        if (searchInput) {
            searchInput.on('input', apply);
        }

        apply();
    };

    const pageWindow = (current, pages) => {
        if (pages <= 7) {
            return Array.from({length: pages}, (_, i) => i);
        }
        const set = new Set([0, pages - 1, current]);
        for (let i = current - 1; i <= current + 1; i++) {
            if (i >= 0 && i < pages) {
                set.add(i);
            }
        }
        return Array.from(set).sort((a, b) => a - b);
    };

    const renderPager = (pager, total, page, perpage) => {
        const pages = Math.max(1, Math.ceil((total || 0) / perpage));
        if (!pager || !pager.length) {
            return;
        }
        if (!total || pages <= 1) {
            pager.attr('hidden', true).empty();
            return;
        }
        const from = page * perpage + 1;
        const to = Math.min(total, (page + 1) * perpage);
        const nums = pageWindow(page, pages);
        let controls = '<button type="button" class="ll-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>Prev</button>';
        let prev = null;
        nums.forEach((n) => {
            if (prev !== null && n > prev + 1) {
                controls += '<span class="ll-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="ll-pager__btn' +
                (n === page ? ' is-active' : '') + '" data-page="' + n + '"' +
                (n === page ? ' aria-current="page"' : '') + '>' + (n + 1) + '</button>';
            prev = n;
        });
        controls += '<button type="button" class="ll-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>Next</button>';

        pager.removeAttr('hidden').html(
            '<div class="ll-pager__meta">Showing ' + from + '–' + to + ' of ' + total + '</div>' +
            '<div class="ll-pager__controls">' + controls + '</div>'
        );
    };

    const initManageList = (root) => {
        const form = root.find('[data-region="bulk-companies-form"]');
        const rows = root.find('[data-region="manage-list"] .ll-manage-tr');
        if (!rows.length) {
            return;
        }

        const PERPAGE = 25;
        let page = 0;
        const selectedTags = new Set();
        const cbs = rows.find('.ll-manage-tr__cb');
        const checkAll = root.find('[data-action="check-all"]');
        const selEl = root.find('[data-region="bulk-selected"]');
        const companiesInput = root.find('[data-region="bulk-companies-input"]');
        const modeSelect = root.find('[data-region="bulk-companies-mode"]');
        const submitBtn = root.find('[data-action="bulk-companies-submit"]');
        const searchInput = root.find('[data-action="filter-manage"]');
        const countEl = root.find('[data-region="manage-count"]');
        const chips = root.find('[data-action="toggle-tag-filter"]');
        const clearBtn = root.find('[data-action="clear-filters"]');
        const pager = root.find('[data-region="pager"]');
        const selectedLabel = String(selEl.text() || '').replace(/^\d+\s*/, '') || 'selected';

        const updateChips = () => {
            chips.each(function() {
                const tag = normalize($(this).attr('data-tag'));
                $(this).toggleClass('is-active', selectedTags.has(tag));
            });
        };

        const hasActiveFilters = () => {
            if (selectedTags.size > 0) {
                return true;
            }
            return normalize(searchInput.val()) !== '';
        };

        const updateSel = () => {
            const visible = cbs.filter(function() {
                return $(this).closest('tr').is(':visible');
            });
            const n = visible.filter(':checked').length;
            selEl.text(n + ' ' + selectedLabel);
            const companies = normalize(companiesInput.val());
            const mode = String(modeSelect.val() || 'add');
            const canSubmit = n > 0 && (mode === 'replace' || companies !== '');
            submitBtn.prop('disabled', !canSubmit);
            if (checkAll.length) {
                checkAll.prop('checked', visible.length > 0 && n === visible.length);
                checkAll.prop('indeterminate', n > 0 && n < visible.length);
            }
        };

        const apply = (resetPage) => {
            if (resetPage) {
                page = 0;
            }
            const q = normalize(searchInput.val());
            const matches = [];

            rows.each(function() {
                const el = $(this);
                const nameHay = normalize(el.attr('data-name'));
                const tagHay = normalize(el.attr('data-tags'));
                const hay = (nameHay + ' ' + tagHay).trim();
                const textMatch = !q || hay.indexOf(q) !== -1;
                const tokens = rowTagTokens(el, 'data-tags');
                const tagMatch = selectedTags.size === 0 ||
                    [...selectedTags].some((tag) => tokens.includes(tag));
                if (textMatch && tagMatch) {
                    matches.push(el);
                }
            });

            const total = matches.length;
            const pages = Math.max(1, Math.ceil(total / PERPAGE) || 1);
            if (page > pages - 1) {
                page = Math.max(0, pages - 1);
            }
            const start = page * PERPAGE;
            const end = start + PERPAGE;

            rows.hide();
            matches.forEach((el, idx) => {
                if (idx >= start && idx < end) {
                    el.show();
                }
            });

            if (countEl.length) {
                countEl.text(total + ' / ' + rows.length);
            }
            if (clearBtn.length) {
                clearBtn.prop('hidden', !hasActiveFilters());
            }
            updateChips();
            renderPager(pager, total, page, PERPAGE);
            updateSel();
        };

        chips.on('click', function(e) {
            e.preventDefault();
            const tag = normalize($(this).attr('data-tag'));
            if (selectedTags.has(tag)) {
                selectedTags.delete(tag);
            } else {
                selectedTags.add(tag);
            }
            apply(true);
        });

        clearBtn.on('click', function(e) {
            e.preventDefault();
            selectedTags.clear();
            searchInput.val('');
            apply(true);
        });

        searchInput.on('input', function() {
            apply(true);
        });

        root.on('click', '[data-region="pager"] [data-page]', function(e) {
            e.preventDefault();
            if ($(this).is(':disabled') || $(this).hasClass('is-active')) {
                return;
            }
            const next = parseInt($(this).attr('data-page'), 10);
            if (isNaN(next) || next < 0) {
                return;
            }
            page = next;
            apply(false);
        });

        cbs.on('change', updateSel);
        companiesInput.on('input', updateSel);
        modeSelect.on('change', updateSel);

        checkAll.on('change', function() {
            const on = checkAll.prop('checked');
            rows.filter(':visible').find('.ll-manage-tr__cb').prop('checked', on);
            updateSel();
        });

        root.on('click', '[data-action="select-visible"]', function(e) {
            e.preventDefault();
            rows.filter(':visible').find('.ll-manage-tr__cb').prop('checked', true);
            updateSel();
        });

        root.on('click', '[data-action="select-none"]', function(e) {
            e.preventDefault();
            cbs.prop('checked', false);
            if (checkAll.length) {
                checkAll.prop('checked', false).prop('indeterminate', false);
            }
            updateSel();
        });

        rows.on('click', function(e) {
            if ($(e.target).is('a, input, button, label, select')) {
                return;
            }
            const cb = $(this).find('.ll-manage-tr__cb');
            if (!cb.length || !$(this).is(':visible')) {
                return;
            }
            cb.prop('checked', !cb.prop('checked')).trigger('change');
        });

        if (form.length) {
            form.on('submit', function(e) {
                const checked = cbs.filter(':checked').length;
                const companies = normalize(companiesInput.val());
                const mode = String(modeSelect.val() || 'add');
                if (checked < 1) {
                    e.preventDefault();
                    return;
                }
                if (mode === 'add' && !companies) {
                    e.preventDefault();
                    return;
                }
                if (mode === 'replace') {
                    const msg = companies
                        ? ('Replace company tags on ' + checked + ' problem(s)?')
                        : ('Clear all company tags on ' + checked + ' problem(s)?');
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                    }
                }
            });
        }

        apply(true);
    };

    const initImport = (root) => {
        const form = root.find('[data-region="import-form"]');
        if (!form.length) {
            return;
        }

        const rows = form.find('.ll-import-tr');
        const cbs = form.find('.ll-import-tr__cb');
        const selEl = form.find('[data-region="import-selected"]');
        const filterInput = root.find('[data-action="filter-import"]');
        const checkAll = form.find('[data-action="check-all"]');

        const updateSel = () => {
            const visible = cbs.filter(function() {
                return $(this).closest('tr').is(':visible');
            });
            const n = visible.filter(':checked').length;
            selEl.text(n + ' selected');
            form.find('[data-action="import-submit"]').prop('disabled', n < 1);
            if (checkAll.length) {
                checkAll.prop('checked', visible.length > 0 && n === visible.length);
            }
        };

        cbs.on('change', updateSel);

        checkAll.on('change', function() {
            const on = checkAll.prop('checked');
            rows.filter(':visible').find('.ll-import-tr__cb').prop('checked', on);
            updateSel();
        });

        form.on('click', '[data-action="select-all"]', function(e) {
            e.preventDefault();
            rows.filter(':visible').find('.ll-import-tr__cb').prop('checked', true);
            updateSel();
        });
        form.on('click', '[data-action="select-new"]', function(e) {
            e.preventDefault();
            cbs.prop('checked', false);
            rows.filter(':visible').not('.is-imported').find('.ll-import-tr__cb').prop('checked', true);
            updateSel();
        });
        form.on('click', '[data-action="select-none"]', function(e) {
            e.preventDefault();
            cbs.prop('checked', false);
            if (checkAll.length) {
                checkAll.prop('checked', false);
            }
            updateSel();
        });

        rows.on('click', function(e) {
            if ($(e.target).is('a, input, button, label')) {
                return;
            }
            const cb = $(this).find('.ll-import-tr__cb');
            cb.prop('checked', !cb.prop('checked')).trigger('change');
        });

        filterInput.on('input', function() {
            const q = normalize(filterInput.val());
            rows.each(function() {
                const el = $(this);
                const hay = normalize(el.attr('data-search'));
                el.toggle(!q || hay.indexOf(q) !== -1);
            });
            updateSel();
        });

        updateSel();
    };

    const initManageTags = (root) => {
        const rows = root.find('[data-region="tags-list"] .ll-manage-tags-tr');
        if (!rows.length) {
            return;
        }

        root.on('click', '[data-action="start-rename"]', function(e) {
            e.preventDefault();
            const row = $(this).closest('tr');
            root.find('[data-region="tag-rename-form"]').attr('hidden', true);
            root.find('[data-region="tag-label"]').removeAttr('hidden');
            row.find('[data-region="tag-label"]').attr('hidden', true);
            const form = row.find('[data-region="tag-rename-form"]');
            form.removeAttr('hidden');
            form.find('input[name="tagname"]').trigger('focus').select();
        });

        root.on('click', '[data-action="cancel-rename"]', function(e) {
            e.preventDefault();
            const row = $(this).closest('tr');
            row.find('[data-region="tag-rename-form"]').attr('hidden', true);
            row.find('[data-region="tag-label"]').removeAttr('hidden');
        });

        initChipTagFilter(root, {
            rows: rows,
            searchInput: root.find('[data-action="filter-tags"]'),
            countEl: root.find('[data-region="tags-count"]'),
            formatCount: (visible, total) => visible + ' / ' + total + ' tags',
        });
    };

    const aceModeFor = (lang) => {
        const map = {
            python3: 'ace/mode/python',
            python: 'ace/mode/python',
            java: 'ace/mode/java',
            cpp: 'ace/mode/c_cpp',
            c: 'ace/mode/c_cpp',
            javascript: 'ace/mode/javascript',
            php: 'ace/mode/php',
        };
        return map[String(lang || '').toLowerCase()] || 'ace/mode/text';
    };

    const initCodeEditors = () => {
        if (typeof window.ace === 'undefined' || !window.ace || !window.ace.edit) {
            return;
        }
        const fields = $('textarea[name^="solution_code_"]');
        if (!fields.length) {
            return;
        }

        fields.each(function() {
            const ta = $(this);
            if (ta.data('ll-ace-ready')) {
                return;
            }
            ta.data('ll-ace-ready', true);

            const name = String(ta.attr('name') || '');
            const lang = name.replace(/^solution_code_/, '') ||
                String(ta.attr('data-ll-code-lang') || 'text');

            const host = $('<div class="ll-manage-ace-host" aria-hidden="true"></div>');
            ta.addClass('ll-manage-code-source is-ace').attr('hidden', true);
            ta.after(host);

            const editor = window.ace.edit(host[0]);
            editor.setOptions({
                fontSize: '13px',
                showPrintMargin: false,
                wrap: true,
                useSoftTabs: true,
                tabSize: 4,
                minLines: 12,
                maxLines: 28,
            });
            editor.$blockScrolling = Infinity;
            try {
                editor.setTheme('ace/theme/tomorrow_night');
            } catch (e) { /* theme optional */ }
            try {
                editor.session.setMode(aceModeFor(lang));
            } catch (e) { /* mode optional */ }
            editor.session.setValue(ta.val() || '');
            editor.session.on('change', function() {
                ta.val(editor.getValue());
            });

            const form = ta.closest('form');
            form.on('submit.llAce', function() {
                ta.val(editor.getValue());
            });
        });
    };

    const init = () => {
        const manage = $('[data-region="ll-manage"]');
        if (manage.length) {
            initManageList(manage);
        }
        const tagmanage = $('[data-region="ll-manage-tags"]');
        if (tagmanage.length) {
            initManageTags(tagmanage);
        }
        const imp = $('[data-region="ll-import"]');
        if (imp.length) {
            initImport(imp);
        }
        initCodeEditors();
        // Ace may finish loading after AMD boot — retry briefly.
        let tries = 0;
        const waitAce = window.setInterval(function() {
            tries += 1;
            if (typeof window.ace !== 'undefined' && window.ace && window.ace.edit) {
                initCodeEditors();
                window.clearInterval(waitAce);
            } else if (tries >= 40) {
                window.clearInterval(waitAce);
            }
        }, 100);
    };

    return {init: init};
});
