/**
 * Three-dot CSV / Excel / PDF export menu for report tables.
 *
 * @module     local_nexreports/table_export
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const closeAll = function(scope) {
        const root = scope || document;
        root.querySelectorAll('.nxr-table-export__menu').forEach(function(menu) {
            menu.hidden = true;
        });
        root.querySelectorAll('.nxr-table-export__toggle').forEach(function(btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    /**
     * Wire toggle open/close for export menus under root (or document).
     *
     * @param {Element=} root
     */
    const bind = function(root) {
        const scope = root || document;
        if (scope.getAttribute && scope.getAttribute('data-nxr-export-bound') === '1') {
            return;
        }
        if (scope.setAttribute) {
            scope.setAttribute('data-nxr-export-bound', '1');
        }

        scope.querySelectorAll('[data-region="table-export"]').forEach(function(wrap) {
            const toggle = wrap.querySelector('.nxr-table-export__toggle');
            const menu = wrap.querySelector('.nxr-table-export__menu');
            if (!toggle || !menu) {
                return;
            }
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const open = menu.hidden;
                closeAll(scope);
                if (open) {
                    menu.hidden = false;
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest || !e.target.closest('[data-region="table-export"]')) {
                closeAll(scope);
            }
        });
    };

    /**
     * Apply filter query params to all format links under root.
     *
     * @param {Element} root
     * @param {URL} url Base URL with report filters already set (format overwritten per link)
     */
    const sync = function(root, url) {
        if (!root || !url) {
            return;
        }
        root.querySelectorAll('[data-region="table-export"] [data-export-format]').forEach(function(a) {
            const u = new URL(url.toString());
            u.searchParams.set('format', a.getAttribute('data-export-format') || 'csv');
            a.href = u.toString();
        });
    };

    return {
        bind: bind,
        sync: sync,
    };
});
