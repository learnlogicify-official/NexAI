/**
 * NexCourse My Courses — filters + paginated AJAX fetch.
 *
 * @module     local_nexcourse/courses
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const PERPAGE = 12;

    let strings = {};
    let state = {
        page: 0,
        status: 'all',
        category: 0,
        search: '',
        total: 0,
        loading: false
    };

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const markPrimaryNav = () => {
        const pathOf = (href) => {
            try {
                return new URL(href, window.location.origin).pathname;
            } catch (e) {
                return '';
            }
        };
        const isMatch = (href) => {
            const p = pathOf(href);
            return p.indexOf('/my/courses') !== -1 || p.indexOf('/local/nexcourse') !== -1;
        };
        document.querySelectorAll(
            '.primary-navigation a, .moremenu a, .navbar .nav-link, .edw-nav a, [role="menubar"] a'
        ).forEach((a) => {
            if (!isMatch(a.getAttribute('href') || '')) {
                return;
            }
            a.classList.add('active');
            a.setAttribute('aria-current', 'page');
            const item = a.closest('.nav-item, li');
            if (item) {
                item.classList.add('active');
            }
        });
    };

    const setLoading = (root, on) => {
        state.loading = !!on;
        const skel = root.find('[data-region="skeleton"]');
        const grid = root.find('[data-region="grid"]');
        const pager = root.find('[data-region="pager"]');
        if (on) {
            grid.attr('hidden', true);
            pager.attr('hidden', true);
            skel.removeAttr('hidden').attr('aria-hidden', 'false');
            root.find('[data-region="empty-filtered"]').attr('hidden', true);
        } else {
            skel.attr('hidden', true).attr('aria-hidden', 'true');
            grid.removeAttr('hidden');
        }
    };

    const renderCards = (root, courses) => {
        const grid = root.find('[data-region="grid"]');
        const emptyFiltered = root.find('[data-region="empty-filtered"]');
        const emptyNone = root.find('[data-region="empty-none"]');
        if (emptyNone.length) {
            emptyNone.attr('hidden', true);
        }
        if (!courses || !courses.length) {
            grid.empty();
            emptyFiltered.removeAttr('hidden');
            return;
        }
        emptyFiltered.attr('hidden', true);
        const progressLabel = strings.progress || 'Progress';
        grid.html(courses.map((c) => {
            const sections = c.hassections
                ? '<span class="nxc-ccard__dot" aria-hidden="true"></span>' +
                  '<span class="nxc-ccard__meta-item">' +
                  '<i class="nxc-mini nxc-mini--section" aria-hidden="true"></i> ' +
                  esc(c.sectionslabel) + '</span>'
                : '';
            return '<article class="nxc-ccard nxc-ccard--' + esc(c.tone) + '">' +
                '<div class="nxc-ccard__main">' +
                    '<div class="nxc-ccard__content">' +
                        '<span class="nxc-ccard__badge">' + esc(c.badge) + '</span>' +
                        '<h3 class="nxc-ccard__title"><a href="' + esc(c.url) + '">' + esc(c.name) + '</a></h3>' +
                        '<p class="nxc-ccard__summary">' + esc(c.summary) + '</p>' +
                        '<div class="nxc-ccard__meta">' +
                            '<span class="nxc-ccard__meta-item">' +
                                '<i class="nxc-mini nxc-mini--task" aria-hidden="true"></i> ' +
                                esc(c.activitieslabel) +
                            '</span>' + sections +
                        '</div>' +
                        '<div class="nxc-ccard__progress">' +
                            '<div class="nxc-ccard__progress-top">' +
                                '<span>' + esc(progressLabel) + '</span>' +
                                '<strong>' + esc(c.progress) + '%</strong>' +
                            '</div>' +
                            '<div class="nxc-ccard__bar" role="progressbar" aria-valuenow="' + esc(c.progress) +
                                '" aria-valuemin="0" aria-valuemax="100">' +
                                '<span style="width:' + esc(c.progress) + '%"></span>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="nxc-ccard__art" aria-hidden="true">' +
                        '<span class="nxc-ccard__avatar">' + esc(c.initials) + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="nxc-ccard__foot">' +
                    '<div class="nxc-ccard__foot-text">' + esc(c.footlabel) +
                        ': <strong>' + esc(c.footvalue) + '</strong></div>' +
                    '<a class="nxc-ccard__btn" href="' + esc(c.url) + '">' + esc(c.cta) + '</a>' +
                '</div>' +
                '</article>';
        }).join(''));
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

    const renderPager = (root, total, page, perpage) => {
        const pager = root.find('[data-region="pager"]');
        const pages = Math.max(1, Math.ceil((total || 0) / perpage));
        if (!total || pages <= 1) {
            pager.attr('hidden', true).empty();
            return;
        }
        const from = page * perpage + 1;
        const to = Math.min(total, (page + 1) * perpage);
        const nums = pageWindow(page, pages);
        const prevLabel = strings.prev || 'Prev';
        const nextLabel = strings.next || 'Next';
        const showing = (strings.showing || 'Showing {$a->from}–{$a->to} of {$a->total}')
            .replace('{$a->from}', String(from))
            .replace('{$a->to}', String(to))
            .replace('{$a->total}', String(total));

        let controls = '<button type="button" class="nxc-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>' + esc(prevLabel) + '</button>';
        let prev = null;
        nums.forEach((n) => {
            if (prev !== null && n > prev + 1) {
                controls += '<span class="nxc-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="nxc-pager__btn' +
                (n === page ? ' is-active' : '') + '" data-page="' + n + '"' +
                (n === page ? ' aria-current="page"' : '') + '>' + (n + 1) + '</button>';
            prev = n;
        });
        controls += '<button type="button" class="nxc-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>' + esc(nextLabel) + '</button>';

        pager.removeAttr('hidden').html(
            '<div class="nxc-pager__meta">' + esc(showing) + '</div>' +
            '<div class="nxc-pager__controls">' + controls + '</div>'
        );
    };

    const updateHeader = (root, header) => {
        if (!header) {
            return;
        }
        const pct = parseInt(header.contentpct, 10) || 0;
        const donut = root.find('[data-region="header-donut"]');
        if (donut.length) {
            donut.css('--nxc-donut-pct', String(pct));
            donut.attr('aria-label', pct + '%');
        }
        root.find('[data-region="header-pct"]').text(pct + '%');
        (header.contentitems || []).forEach((item) => {
            root.find('[data-header-item="' + item.key + '"]').text(item.display);
        });
        (header.stats || []).forEach((stat) => {
            root.find('[data-header-stat="' + stat.key + '"]').text(stat.value);
        });
    };

    const load = (root, opts) => {
        if (state.loading) {
            return;
        }
        const resetPage = opts && opts.resetPage;
        if (resetPage) {
            state.page = 0;
        }
        setLoading(root, true);
        Ajax.call([{
            methodname: 'local_nexcourse_get_courses',
            args: {
                page: state.page,
                perpage: PERPAGE,
                search: state.search,
                status: state.status,
                categoryid: state.category
            }
        }])[0].then((data) => {
            const total = data.total || 0;
            state.total = total;
            state.page = data.page || 0;
            const counts = data.counts || {};
            root.find('[data-count="all"]').text('(' + (counts.all || 0) + ')');
            root.find('[data-count="completed"]').text('(' + (counts.completed || 0) + ')');
            root.find('[data-count="inprogress"]').text('(' + (counts.inprogress || 0) + ')');
            root.find('[data-count="notstarted"]').text('(' + (counts.notstarted || 0) + ')');
            root.find('[data-region="found-count"]').text(
                total + ' ' + (strings.coursesfound || 'Courses Found')
            );
            updateHeader(root, data.header || null);
            renderCards(root, data.courses || []);
            renderPager(root, total, state.page, data.perpage || PERPAGE);
            setLoading(root, false);
            return null;
        }).catch((err) => {
            setLoading(root, false);
            Notification.exception(err);
        });
    };

    const init = function(cfg) {
        strings = (cfg && cfg.strings) || {};
        state.page = (cfg && cfg.page) || 0;
        state.total = (cfg && cfg.total) || 0;
        const root = $('[data-region="nxc-courses"]');
        if (!root.length) {
            return;
        }
        markPrimaryNav();

        if (cfg && cfg.autoload) {
            load(root, {resetPage: true});
        } else if (state.total > PERPAGE) {
            renderPager(root, state.total, state.page, PERPAGE);
        }

        let timer = null;
        root.on('input', '[data-filter="search"]', function() {
            state.search = String($(this).val() || '').trim();
            clearTimeout(timer);
            timer = setTimeout(() => load(root, {resetPage: true}), 220);
        });

        root.on('click', '.nxc-statustab[data-status]', function(e) {
            e.preventDefault();
            const btn = $(this);
            state.status = btn.attr('data-status') || 'all';
            root.find('.nxc-statustab').removeClass('is-active');
            btn.addClass('is-active');
            root.find('[data-filter="userstatus"]').val(state.status);
            load(root, {resetPage: true});
        });

        root.on('click', '.nxc-catpill[data-category]', function(e) {
            e.preventDefault();
            const btn = $(this);
            state.category = parseInt(btn.attr('data-category') || '0', 10) || 0;
            root.find('.nxc-catpill').removeClass('is-active');
            btn.addClass('is-active');
            root.find('[data-filter="category"]').val(String(state.category));
            load(root, {resetPage: true});
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
            state.page = next;
            load(root, {resetPage: false});
            const top = root.find('.nxc-results')[0];
            if (top && top.scrollIntoView) {
                top.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });

        $(document).on('keydown.nxcsearch', function(e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                const input = root.find('[data-filter="search"]');
                if (input.length) {
                    e.preventDefault();
                    input.trigger('focus');
                }
            }
        });
    };

    return {init};
});
