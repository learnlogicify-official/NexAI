define([], function() {
    'use strict';

    const qs = (sel, root) => (root || document).querySelector(sel);
    const qsa = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

    const ICONS = {
        mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 7 9-7"/></svg>',
        lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
        user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="3.5"/><path d="M5 19a7 7 0 0 1 14 0"/></svg>'
    };

    const findLoginForm = () => {
        return qs('#login') ||
            qs('form#login') ||
            qs('form[action*="login/index.php"]') ||
            qs('form[action*="login/index"]') ||
            qs('.login-form form') ||
            qs('.loginform form') ||
            qs('.login-container form') ||
            qs('.login-wrapper form') ||
            qs('#region-main form') ||
            qs('form.mform') ||
            qs('#region-main .card form') ||
            qs('main form') ||
            qs('form');
    };

    const findSignupForm = () => {
        return qs('#signupform') ||
            qs('form[data-region="signup"]') ||
            qs('form[action*="signup.php"]') ||
            qs('form[action*="signup"]') ||
            qs('.signupform') ||
            qs('#region-main form.mform') ||
            qs('form.mform') ||
            qs('#region-main form');
    };

    const moveAlerts = (host) => {
        qsa('.alert, .notifysuccess, .notifyproblem, .loginerrors, [data-region="login-error"]')
            .filter(function(el) { return !host.contains(el); })
            .forEach(function(el) { host.insertBefore(el, host.firstChild); });
    };

    const wrapFields = (root) => {
        qsa('input[type="text"], input[type="email"], input[type="password"]', root).forEach(function(input) {
            if (input.closest('.nxl-field')) {
                return;
            }
            const wrap = document.createElement('div');
            wrap.className = 'nxl-field';
            const icon = document.createElement('span');
            icon.className = 'nxl-field__icon';
            const name = (input.name || input.id || input.type || '').toLowerCase();
            let kind = 'user';
            if (input.type === 'password' || name.indexOf('pass') >= 0) {
                kind = 'lock';
            } else if (input.type === 'email' || name.indexOf('email') >= 0 || name.indexOf('user') >= 0) {
                kind = 'mail';
            }
            icon.innerHTML = ICONS[kind] || ICONS.user;
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(icon);
            wrap.appendChild(input);

            if (!input.getAttribute('placeholder')) {
                if (kind === 'lock') {
                    input.setAttribute('placeholder', 'Password');
                } else if (kind === 'mail') {
                    input.setAttribute('placeholder', 'Email address');
                } else {
                    input.setAttribute('placeholder', 'Username');
                }
            }
        });
    };

    const pairNameFields = (root) => {
        if (qs('.nxl-name-row', root)) {
            return;
        }
        const first = qs('#fitem_id_firstname, .fitem_id_firstname, [data-fieldtype] #id_firstname', root);
        const last = qs('#fitem_id_lastname, .fitem_id_lastname, [data-fieldtype] #id_lastname', root);
        let firstItem = first;
        let lastItem = last;
        if (first && first.id === 'id_firstname') {
            firstItem = first.closest('.fitem, .form-group, .mb-3, .row') || first.parentElement;
        }
        if (last && last.id === 'id_lastname') {
            lastItem = last.closest('.fitem, .form-group, .mb-3, .row') || last.parentElement;
        }
        // Also try by input name.
        if (!firstItem) {
            const inp = qs('input[name="firstname"]', root);
            firstItem = inp ? (inp.closest('.fitem, .form-group, .mb-3, .row') || inp.parentElement) : null;
        }
        if (!lastItem) {
            const inp = qs('input[name="lastname"]', root);
            lastItem = inp ? (inp.closest('.fitem, .form-group, .mb-3, .row') || inp.parentElement) : null;
        }
        if (!firstItem || !lastItem || firstItem === lastItem) {
            return;
        }
        const row = document.createElement('div');
        row.className = 'nxl-name-row';
        firstItem.parentNode.insertBefore(row, firstItem);
        row.appendChild(firstItem);
        row.appendChild(lastItem);
    };

    const moveIdps = (shell, host) => {
        const idpHost = qs('[data-region="nxl-idp"]', shell);
        const or = qs('[data-region="nxl-or"]', shell);
        if (!idpHost) {
            return;
        }
        const idps = qs('.potentialidps, .login-identityproviders', host) ||
            qs('.potentialidps, .login-identityproviders');
        if (idps && !idpHost.contains(idps)) {
            const block = idps.closest('.potentialidps, .login-identityproviders') || idps;
            idpHost.appendChild(block);
            if (or) {
                or.hidden = false;
            }
        } else if (!idpHost.childElementCount && or) {
            or.hidden = true;
        }
    };

    const hideGeoFields = (root) => {
        const names = ['city', 'country'];
        names.forEach(function(name) {
            const input = qs('#' + 'id_' + name + ', [name="' + name + '"], select[name="' + name + '"]', root);
            if (!input) {
                return;
            }
            // Keep values valid for Moodle required checks while hiding UI.
            if (input.tagName === 'SELECT') {
                if (!input.value || input.value === '0' || input.value === '') {
                    // Prefer India if present (site locale), else first real option.
                    const preferred = qs('option[value="IN"]', input) ||
                        qs('option[value="in"]', input) ||
                        Array.prototype.find.call(input.options || [], function(o) {
                            return o.value && o.value !== '0' && o.value !== '';
                        });
                    if (preferred) {
                        input.value = preferred.value;
                    }
                }
            } else if (!String(input.value || '').trim()) {
                input.value = '-';
            }
            input.removeAttribute('required');
            input.setAttribute('aria-required', 'false');
            const item = input.closest('.fitem, .form-group, .mb-3, .row, [data-groupname]') || input.parentElement;
            if (item) {
                item.classList.add('nxl-hide-geo');
                item.style.display = 'none';
            }
        });
        // Also hide by id wrappers Moodle uses.
        qsa('#fitem_id_city, #fitem_id_country, .fitem_id_city, .fitem_id_country', root).forEach(function(el) {
            el.classList.add('nxl-hide-geo');
            el.style.display = 'none';
        });
    };

    const markFullWidthRows = (root) => {
        const selectors = [
            '#fitem_id_password',
            '#fitem_id_password2',
            '#fitem_id_email',
            '#fitem_id_email2',
            '#fitem_id_username',
            '#fitem_id_policyagreed',
            '#fitem_id_passwordpolicy',
            '.fitem_actionbuttons',
            '#fgroup_id_buttonar'
        ];
        // Keep email/username in columns; only force policy + buttons + static help full width.
        qsa('#fitem_id_policyagreed, #fitem_id_passwordpolicy, .fitem_actionbuttons, #fgroup_id_buttonar, .fstatic, .checkbox, .form-check', root)
            .forEach(function(el) {
                el.classList.add('nxl-span-2');
            });
        void selectors;
    };

    const tidySignupChrome = (host) => {
        // Remove duplicate Moodle page titles that sit inside the moved form block.
        qsa('h1, h2, .login-heading', host).forEach(function(el) {
            if (el.closest('.nxl-card')) {
                return;
            }
            el.style.display = 'none';
        });
        // Collapse empty fieldsets / headers that only add noise.
        qsa('fieldset', host).forEach(function(fs) {
            fs.style.border = '0';
            fs.style.margin = '0';
            fs.style.padding = '0';
        });
        hideGeoFields(host);
        markFullWidthRows(host);
    };

    const buildShell = (cfg) => {
        if (qs('.nxl-shell')) {
            return qs('.nxl-shell');
        }

        const mode = cfg.mode || 'login';
        const s = cfg.strings || {};
        const isSignup = mode === 'signup';
        const shell = document.createElement('div');
        shell.className = 'nxl-shell ' + (isSignup ? 'nxl-mode-signup' : 'nxl-mode-login');
        shell.setAttribute('data-region', 'nxl-shell');
        shell.setAttribute('data-mode', mode);

        const title = isSignup
            ? (s.headingSignup || 'Create account')
            : (s.headingLogin || 'Welcome Back');
        const sub = isSignup
            ? ((s.alreadyMember || 'Already have an account?') +
                ' <a href="' + (cfg.loginUrl || '/login/index.php') + '">' + (s.loginLink || 'Log in') + '</a>')
            : ((s.notMember || "Don't have an account yet?") +
                ' <a href="' + (cfg.signupUrl || '/login/signup.php') + '">' + (s.signupLink || 'Sign up') + '</a>');

        const logoHtml = cfg.logoUrl
            ? ('<div class="nxl-card__logo nxl-card__logo--img">' +
                '<img src="' + cfg.logoUrl + '" alt="' + (cfg.brand || 'Logo') + '">' +
               '</div>')
            : ('<div class="nxl-card__logo" aria-hidden="true"><div class="nxl-card__logo-ring"></div></div>');

        shell.innerHTML =
            '<div class="nxl-bg" aria-hidden="true">' +
                '<div class="nxl-bg__orb nxl-bg__orb--a"></div>' +
                '<div class="nxl-bg__orb nxl-bg__orb--b"></div>' +
                '<div class="nxl-bg__orb nxl-bg__orb--c"></div>' +
                '<div class="nxl-bg__beam"></div>' +
                '<div class="nxl-bg__mesh"></div>' +
                '<div class="nxl-bg__wash"></div>' +
            '</div>' +
            '<section class="nxl-card">' +
                logoHtml +
                '<h1 class="nxl-card__title">' + title + '</h1>' +
                '<p class="nxl-card__sub">' + sub + '</p>' +
                '<div class="nxl-form-host" data-region="nxl-form-host"></div>' +
                (isSignup ? '' :
                    '<div class="nxl-or" data-region="nxl-or" hidden>OR</div>' +
                    '<div class="nxl-idp-host" data-region="nxl-idp"></div>') +
            '</section>';

        document.body.appendChild(shell);
        document.body.classList.add('nxl-shell-ready', 'nxl-active', isSignup ? 'nxl-body-signup' : 'nxl-body-login');
        document.documentElement.classList.remove('nxl-pending');
        const splash = document.getElementById('nxl-splash');
        if (splash && splash.parentNode) {
            splash.parentNode.removeChild(splash);
        }
        return shell;
    };

    const relocateForm = (cfg, shell) => {
        const host = qs('[data-region="nxl-form-host"]', shell);
        if (!host) {
            return;
        }
        const isSignup = (cfg.mode || 'login') === 'signup';

        if (!qs('form', host)) {
            const form = isSignup ? findSignupForm() : findLoginForm();
            if (!form) {
                host.innerHTML = '<p class="nxl-card__sub">Could not find the Moodle auth form.</p>';
                return;
            }

            let block = form;
            if (!isSignup) {
                block = form.closest('.login-form') ||
                    form.closest('.login-container') ||
                    form.closest('.card') ||
                    form;
                if (block && (block.id === 'region-main' || block === document.body)) {
                    block = form;
                }
            }
            // Signup: always move the form only — never the page card (avoids nested mess).
            host.appendChild(block);
            moveAlerts(host);
        }

        if (isSignup) {
            tidySignupChrome(host);
            pairNameFields(host);
        } else {
            wrapFields(host);
            moveIdps(shell, host);
        }
    };

    const init = (cfg) => {
        cfg = cfg || {};
        if (!document.body) {
            return;
        }
        // Infer signup from URL if mode missing / wrong.
        if (!cfg.mode || cfg.mode === 'login') {
            const path = (window.location.pathname || '') + (window.location.search || '');
            if (/signup/i.test(path)) {
                cfg.mode = 'signup';
            }
        }
        document.body.classList.add('nxl-active');
        const shell = buildShell(cfg);
        const run = function() { relocateForm(cfg, shell); };
        run();
        setTimeout(run, 50);
        setTimeout(run, 300);
        setTimeout(run, 1000);
    };

    return {init: init};
});
