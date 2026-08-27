/**
 * NexComm catalog.
 *
 * @module local_nexcomm/catalog
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const init = function(config) {
        config = config || {};
        const root = $('[data-region="nc-catalog"]');
        if (!root.length) {
            return;
        }
        const el = root.get(0);
        const grid = root.find('[data-region="grid"]');
        const empty = root.find('[data-region="empty"]');
        let timer = null;
        let requestId = 0;

        const label = function(name) {
            return el.getAttribute('data-label-' + name) || name;
        };

        if (config.initialSkill) {
            root.find('[data-filter="skill"]').val(config.initialSkill);
            root.find('[data-region="skill-pills"] .nc-pill').removeClass('is-active');
            const pill = root.find('[data-region="skill-pills"] .nc-pill[data-skill="' + config.initialSkill + '"]');
            if (pill.length) {
                pill.addClass('is-active');
            } else {
                root.find('[data-region="skill-pills"] .nc-pill[data-skill=""]').addClass('is-active');
            }
        }

        const actionLabel = function(status) {
            if (status === 'completed') {
                return label('retry');
            }
            if (status === 'inprogress' || status === 'failed') {
                return label('continue');
            }
            return label('start');
        };

        const statusLabel = function(status) {
            if (status === 'completed') {
                return label('completed');
            }
            if (status === 'failed') {
                return 'Try again';
            }
            if (status === 'inprogress') {
                return 'In progress';
            }
            return 'Not started';
        };

        const render = function(items) {
            if (!items || !items.length) {
                grid.empty();
                empty.removeAttr('hidden');
                return;
            }
            empty.attr('hidden', true);
            grid.html(items.map(function(item) {
                return '<article class="nc-card nc-card--' + esc(item.userstatus) + '">' +
                    '<div class="nc-card__meta">' +
                        '<span class="nc-badge nc-badge--' + esc(item.skill) + '">' + esc(item.skill) + '</span>' +
                        '<span class="nc-badge nc-badge--' + esc(item.difficulty) + '">' + esc(item.difficulty) + '</span>' +
                        '<span class="nc-statuspill nc-statuspill--' + esc(item.userstatus) + '">' +
                            esc(statusLabel(item.userstatus)) + '</span>' +
                    '</div>' +
                    '<h3 class="nc-card__title"><a href="' + esc(item.url) + '">' + esc(item.title) + '</a></h3>' +
                    '<div class="nc-card__foot">' +
                        '<a class="nc-btn nc-btn--primary" href="' + esc(item.url) + '">' +
                            esc(actionLabel(item.userstatus)) + '</a>' +
                    '</div>' +
                    '</article>';
            }).join(''));
        };

        const load = function() {
            const id = ++requestId;
            Ajax.call([{
                methodname: 'local_nexcomm_get_activities',
                args: {
                    skill: String(root.find('[data-filter="skill"]').val() || ''),
                    difficulty: String(root.find('[data-filter="difficulty"]').val() || ''),
                    search: String(root.find('[data-filter="search"]').val() || ''),
                    page: 0,
                    perpage: 48
                }
            }])[0].then(function(data) {
                if (id !== requestId) {
                    return null;
                }
                render(data.items || []);
                return null;
            }).catch(Notification.exception);
        };

        root.on('click', '[data-region="skill-pills"] .nc-pill', function() {
            root.find('[data-region="skill-pills"] .nc-pill').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-filter="skill"]').val($(this).attr('data-skill') || '');
            load();
        });

        root.on('click', '[data-region="diff-pills"] .nc-diffpill', function() {
            root.find('[data-region="diff-pills"] .nc-diffpill').removeClass('is-active');
            $(this).addClass('is-active');
            root.find('[data-filter="difficulty"]').val($(this).attr('data-diff') || '');
            load();
        });

        root.on('input', '[data-filter="search"]', function() {
            clearTimeout(timer);
            timer = setTimeout(load, 250);
        });

        load();
    };

    return {init: init};
});
