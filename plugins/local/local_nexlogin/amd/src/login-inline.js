/* global window, document */
(function (global) {
    'use strict';

    var qs = function (sel, root) {
        return (root || document).querySelector(sel);
    };
    var qsa = function (sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    };

    var ICONS = {
        mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 7 9-7"/></svg>',
        lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
        user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="3.5"/><path d="M5 19a7 7 0 0 1 14 0"/></svg>'
    };

    var findLoginForm = function () {
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

    var findSignupForm = function () {
        return qs('#signupform') ||
            qs('form[action*="signup.php"]') ||
            qs('form[action*="signup"]') ||
            qs('.signupform') ||
            qs('#region-main form.mform') ||
            qs('form.mform') ||
            qs('#region-main form');
    };

    var moveAlerts = function (host) {
        qsa('.alert, .notifysuccess, .notifyproblem, .loginerrors, [data-region="login-error"]')
            .filter(function (el) { return !host.contains(el); })
            .forEach(function (el) { host.insertBefore(el, host.firstChild); });
    };

    var wrapFields = function (root) {
        qsa('input[type="text"], input[type="email"], input[type="password"]', root).forEach(function (input) {
            if (input.closest('.nxl-field')) {
                return;
            }
            var wrap = document.createElement('div');
            wrap.className = 'nxl-field';
            var icon = document.createElement('span');
            icon.className = 'nxl-field__icon';
            var name = (input.name || input.id || input.type || '').toLowerCase();
            var kind = 'user';
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

    var pairNameFields = function (root) {
        if (qs('.nxl-name-row', root)) {
            return;
        }
        var firstInp = qs('input[name="firstname"], #id_firstname', root);
        var lastInp = qs('input[name="lastname"], #id_lastname', root);
        var firstItem = firstInp ? (firstInp.closest('.fitem, .form-group, .mb-3, .row') || firstInp.parentElement) : null;
        var lastItem = lastInp ? (lastInp.closest('.fitem, .form-group, .mb-3, .row') || lastInp.parentElement) : null;
        if (!firstItem || !lastItem || firstItem === lastItem) {
            return;
        }
        var row = document.createElement('div');
        row.className = 'nxl-name-row';
        firstItem.parentNode.insertBefore(row, firstItem);
        row.appendChild(firstItem);
        row.appendChild(lastItem);
    };

    var moveIdps = function (shell, host) {
        var idpHost = qs('[data-region="nxl-idp"]', shell);
        var or = qs('[data-region="nxl-or"]', shell);
        if (!idpHost) {
            return;
        }
        var idps = qs('.potentialidps, .login-identityproviders', host) ||
            qs('.potentialidps, .login-identityproviders');
        if (idps && !idpHost.contains(idps)) {
            var block = idps.closest('.potentialidps, .login-identityproviders') || idps;
            idpHost.appendChild(block);
            if (or) {
                or.hidden = false;
            }
        } else if (!idpHost.childElementCount && or) {
            or.hidden = true;
        }
    };

    var hideGeoFields = function (root) {
        ['city', 'country'].forEach(function (name) {
            var input = qs('#id_' + name + ', [name="' + name + '"], select[name="' + name + '"]', root);
            if (!input) {
                return;
            }
            if (input.tagName === 'SELECT') {
                if (!input.value || input.value === '0' || input.value === '') {
                    var preferred = qs('option[value="IN"]', input) || qs('option[value="in"]', input);
                    if (!preferred && input.options) {
                        for (var i = 0; i < input.options.length; i++) {
                            if (input.options[i].value && input.options[i].value !== '0') {
                                preferred = input.options[i];
                                break;
                            }
                        }
                    }
                    if (preferred) {
                        input.value = preferred.value;
                    }
                }
            } else if (!String(input.value || '').trim()) {
                input.value = '-';
            }
            input.removeAttribute('required');
            input.setAttribute('aria-required', 'false');
            var item = input.closest('.fitem, .form-group, .mb-3, .row, [data-groupname]') || input.parentElement;
            if (item) {
                item.classList.add('nxl-hide-geo');
                item.style.display = 'none';
            }
        });
        qsa('#fitem_id_city, #fitem_id_country, .fitem_id_city, .fitem_id_country', root).forEach(function (el) {
            el.classList.add('nxl-hide-geo');
            el.style.display = 'none';
        });
    };

    var markFullWidthRows = function (root) {
        qsa('#fitem_id_policyagreed, #fitem_id_passwordpolicy, .fitem_actionbuttons, #fgroup_id_buttonar, .fstatic, .checkbox, .form-check', root)
            .forEach(function (el) {
                el.classList.add('nxl-span-2');
            });
    };

    var tidySignupChrome = function (host) {
        qsa('h1, h2, .login-heading', host).forEach(function (el) {
            el.style.display = 'none';
        });
        qsa('fieldset', host).forEach(function (fs) {
            fs.style.border = '0';
            fs.style.margin = '0';
            fs.style.padding = '0';
        });
        hideGeoFields(host);
        markFullWidthRows(host);
    };

    var buildShell = function (cfg) {
        var existing = qs('.nxl-shell');
        if (existing) {
            return existing;
        }
        var mode = cfg.mode || 'login';
        var s = cfg.strings || {};
        var isSignup = mode === 'signup';
        var shell = document.createElement('div');
        shell.className = 'nxl-shell ' + (isSignup ? 'nxl-mode-signup' : 'nxl-mode-login');
        shell.setAttribute('data-region', 'nxl-shell');

        var title = isSignup ? (s.headingSignup || 'Create account') : (s.headingLogin || 'Welcome Back');
        var sub = isSignup
            ? ((s.alreadyMember || 'Already have an account?') + ' <a href="' + (cfg.loginUrl || '/login/index.php') + '">' + (s.loginLink || 'Log in') + '</a>')
            : ((s.notMember || "Don't have an account yet?") + ' <a href="' + (cfg.signupUrl || '/login/signup.php') + '">' + (s.signupLink || 'Sign up') + '</a>');

        var logoHtml = cfg.logoUrl
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
        var splash = document.getElementById('nxl-splash');
        if (splash && splash.parentNode) {
            splash.parentNode.removeChild(splash);
        }
        return shell;
    };

    var relocateForm = function (cfg, shell) {
        var host = qs('[data-region="nxl-form-host"]', shell);
        if (!host) {
            return;
        }
        var isSignup = (cfg.mode || 'login') === 'signup';
        if (!qs('form', host)) {
            var form = isSignup ? findSignupForm() : findLoginForm();
            if (!form) {
                host.innerHTML = '<p class="nxl-card__sub">Could not find the Moodle auth form.</p>';
                return;
            }
            var block = form;
            if (!isSignup) {
                block = form.closest('.login-form') ||
                    form.closest('.login-container') ||
                    form.closest('.card') ||
                    form;
                if (block && (block.id === 'region-main' || block === document.body)) {
                    block = form;
                }
            }
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

    var run = function (cfg) {
        cfg = cfg || global.NEXLOGIN_CFG || {};
        if (!document.body) {
            return;
        }
        if (!cfg.mode || cfg.mode === 'login') {
            var path = (window.location.pathname || '') + (window.location.search || '');
            if (/signup/i.test(path)) {
                cfg.mode = 'signup';
            }
        }
        document.body.classList.add('nxl-active');
        if (cfg.cssUrl && !document.querySelector('link[data-nxl-css]')) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = cfg.cssUrl;
            link.setAttribute('data-nxl-css', '1');
            document.head.appendChild(link);
        }
        var shell = buildShell(cfg);
        var tryMove = function () { relocateForm(cfg, shell); };
        tryMove();
        setTimeout(tryMove, 50);
        setTimeout(tryMove, 300);
        setTimeout(tryMove, 1000);
    };

    global.NexLoginInline = run;
})(window);
