/**
 * Connect platforms form + GitHub import.
 *
 * @module     local_nexportfolio/connect
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    const setStatus = (el, text) => {
        if (el) {
            el.textContent = text || '';
        }
    };

    const init = (cfg) => {
        const root = document.getElementById('np-connect');
        if (!root) {
            return;
        }
        cfg = cfg || {};
        const form = root.querySelector('[data-region="connect-form"]');
        const status = root.querySelector('[data-region="status"]');
        const btn = root.querySelector('[data-action="save"]');
        const ghUser = root.querySelector('[data-region="github-username"]');

        const importBtn = root.querySelector('[data-action="github-import"]');
        if (importBtn) {
            importBtn.addEventListener('click', () => {
                const username = ghUser ? (ghUser.value || '').trim() : '';
                if (!username) {
                    setStatus(status, cfg.strings.githubusernamerequired || 'Enter your GitHub username.');
                    if (ghUser) {
                        ghUser.focus();
                    }
                    return;
                }
                importBtn.disabled = true;
                setStatus(status, cfg.strings.githubimporting || 'Importing repositories and README files…');
                Ajax.call([{
                    methodname: 'local_nexportfolio_import_github',
                    args: {
                        username: username,
                        fetchlanguages: true,
                        fetchreadmes: true
                    }
                }])[0].then((res) => {
                    setStatus(status, (res && res.message) || 'Imported');
                    if (cfg.dashboardUrl) {
                        window.location.href = cfg.dashboardUrl;
                    }
                }).catch(Notification.exception).finally(() => {
                    importBtn.disabled = false;
                });
            });
        }

        const disconnectBtn = root.querySelector('[data-action="github-disconnect"]');
        if (disconnectBtn) {
            disconnectBtn.addEventListener('click', () => {
                disconnectBtn.disabled = true;
                Ajax.call([{
                    methodname: 'local_nexportfolio_disconnect_github',
                    args: {}
                }])[0].then(() => {
                    window.location.reload();
                }).catch(Notification.exception).finally(() => {
                    disconnectBtn.disabled = false;
                });
            });
        }

        if (!form) {
            return;
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const inputs = form.querySelectorAll('[data-platform]');
            const handles = [];
            inputs.forEach((input) => {
                handles.push({
                    platform: input.getAttribute('data-platform'),
                    handle: (input.value || '').trim()
                });
            });

            if (btn) {
                btn.disabled = true;
                btn.textContent = cfg.strings.saving || 'Saving…';
            }
            setStatus(status, '');

            Ajax.call([{
                methodname: 'local_nexportfolio_save_handles',
                args: {handles: handles}
            }])[0].then((res) => {
                setStatus(status, (res && res.message) || (cfg.strings.handlesaved || 'Saved'));
                const toFetch = handles.filter((h) => h.handle);
                if (!toFetch.length) {
                    if (cfg.dashboardUrl) {
                        window.location.href = cfg.dashboardUrl;
                    }
                    return null;
                }
                setStatus(status, cfg.strings.refreshing || 'Fetching…');
                const calls = toFetch.map((h) => ({
                    methodname: 'local_nexportfolio_refresh_platform',
                    args: {platform: h.platform, force: true}
                }));
                return Promise.all(Ajax.call(calls)).then(() => {
                    if (cfg.dashboardUrl) {
                        window.location.href = cfg.dashboardUrl;
                    }
                    return null;
                });
            }).catch(Notification.exception).finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = cfg.strings.savehandles || 'Save';
                }
            });
        });
    };

    return {init: init};
});
