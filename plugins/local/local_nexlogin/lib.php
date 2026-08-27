<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library for local_nexlogin.
 *
 * @package    local_nexlogin
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Whether NexLogin chrome is enabled.
 */
function local_nexlogin_enabled(): bool {
    // Default ON when setting has never been saved.
    $enabled = get_config('local_nexlogin', 'enable');
    return $enabled === false || $enabled === '' || (bool) $enabled;
}

/**
 * Cache-busted plugin file URL.
 */
function local_nexlogin_file_url(string $path): moodle_url {
    $rev = (int) (get_config('local_nexlogin', 'version') ?: time());
    return new moodle_url($path, ['v' => $rev]);
}

/**
 * Raw request path used when $PAGE is not ready yet.
 */
function local_nexlogin_request_haystack(): string {
    global $SCRIPT, $PAGE;

    $parts = [
        $SCRIPT ?? '',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SERVER['SCRIPT_NAME'] ?? '',
        $_SERVER['PHP_SELF'] ?? '',
        $_SERVER['PATH_INFO'] ?? '',
        !empty($PAGE) ? (string) ($PAGE->pagetype ?? '') : '',
        !empty($PAGE) ? (string) ($PAGE->pagelayout ?? '') : '',
        !empty($PAGE) ? (string) ($PAGE->bodyid ?? '') : '',
    ];
    return strtolower(implode(' ', $parts));
}

/**
 * Detect Moodle login / signup / forgot-password pages (RemUI-safe).
 */
function local_nexlogin_is_auth_page(): bool {
    global $PAGE;

    if (!empty($PAGE)) {
        $pagetype = (string) ($PAGE->pagetype ?? '');
        if (strpos($pagetype, 'login-') === 0) {
            return true;
        }
        $layout = (string) ($PAGE->pagelayout ?? '');
        if ($layout === 'login') {
            return true;
        }
        $bodyid = (string) ($PAGE->bodyid ?? '');
        if (strpos($bodyid, 'page-login') === 0) {
            return true;
        }
    }

    $hay = local_nexlogin_request_haystack();
    $needles = [
        '/login/',
        'login/index',
        'login/signup',
        'login/forgot',
        'login-index',
        'login-signup',
        'pagelayout-login',
        'page-login',
    ];
    foreach ($needles as $n) {
        if (strpos($hay, $n) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * @return string login|signup|forgot
 */
function local_nexlogin_page_mode(): string {
    $hay = local_nexlogin_request_haystack();
    if (strpos($hay, 'signup') !== false) {
        return 'signup';
    }
    if (strpos($hay, 'forgot') !== false || strpos($hay, 'forgotten') !== false) {
        return 'forgot';
    }
    return 'login';
}

/**
 * Resolve the site logo URL from RemUI settings, then core.
 */
function local_nexlogin_resolve_logo_url(): string {
    global $OUTPUT, $PAGE;

    $keys = [
        // RemUI login / branding uploads (Site branding / Login page).
        'loginlogo',
        'loginpagelogo',
        'login_page_logo',
        'logo',
        'logomini',
        'logoimage',
        'headerlogo',
        'site_logo',
        'darklogo',
        'darklogomini',
    ];

    $themes = [];
    // Prefer RemUI explicitly (user asked for RemUI-set logo).
    $themes[] = 'remui';
    if (!empty($PAGE->theme) && !empty($PAGE->theme->name)) {
        $themes[] = $PAGE->theme->name;
    }
    $themes = array_values(array_unique($themes));

    // 1) Theme file settings (RemUI customizer / Site branding).
    foreach ($themes as $themename) {
        try {
            $theme = \theme_config::load($themename);
        } catch (\Throwable $e) {
            continue;
        }
        if (!$theme || !method_exists($theme, 'setting_file_url')) {
            continue;
        }
        foreach ($keys as $key) {
            try {
                if (empty($theme->settings->$key)) {
                    continue;
                }
                $url = $theme->setting_file_url($key, $key);
                if ($url) {
                    return is_object($url) && method_exists($url, 'out')
                        ? $url->out(false)
                        : (string) $url;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    // 2) File storage scan (covers RemUI areas when setting_file_url misses).
    try {
        $fs = get_file_storage();
        $ctx = \context_system::instance();
        foreach (['theme_remui', 'theme_remui_child'] as $component) {
            foreach ($keys as $area) {
                // itemid false = all itemids (RemUI sometimes uses non-zero).
                $files = $fs->get_area_files($ctx->id, $component, $area, false, 'timemodified DESC', false);
                foreach ($files as $file) {
                    if ($file->is_directory() || !$file->get_filename() || $file->get_filename() === '.') {
                        continue;
                    }
                    $url = \moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    );
                    return $url->out(false);
                }
            }
        }
    } catch (\Throwable $e) {
        // continue
    }

    // 3) Moodle core logo (Appearance → Logos) as fallback.
    try {
        if (!empty($OUTPUT) && method_exists($OUTPUT, 'get_logo_url')) {
            $core = $OUTPUT->get_logo_url(null, 240);
            if ($core instanceof moodle_url) {
                return $core->out(false);
            }
        }
    } catch (\Throwable $e) {
        // continue
    }

    return '';
}

/**
 * Config array passed to the browser.
 */
function local_nexlogin_js_config(): array {
    global $CFG;

    $brand = trim((string) (get_config('local_nexlogin', 'brandname')
        ?: get_string('brandname_default', 'local_nexlogin')));

    return [
        'mode' => local_nexlogin_page_mode(),
        'brand' => $brand,
        'logoUrl' => local_nexlogin_resolve_logo_url(),
        'wwwroot' => rtrim($CFG->wwwroot, '/'),
        'heroUrl' => local_nexlogin_file_url('/local/nexlogin/pix/hero.jpg')->out(false),
        'cssUrl' => local_nexlogin_file_url('/local/nexlogin/styles.css')->out(false),
        'homeUrl' => (new moodle_url('/'))->out(false),
        'loginUrl' => (new moodle_url('/login/index.php'))->out(false),
        'signupUrl' => (new moodle_url('/login/signup.php'))->out(false),
        'strings' => [
            'eyebrowLogin' => get_string('eyebrow_login', 'local_nexlogin'),
            'eyebrowSignup' => get_string('eyebrow_signup', 'local_nexlogin'),
            'headingLogin' => get_string('heading_login', 'local_nexlogin'),
            'headingSignup' => get_string('heading_signup', 'local_nexlogin'),
            'alreadyMember' => get_string('already_member', 'local_nexlogin'),
            'notMember' => get_string('not_member', 'local_nexlogin'),
            'loginLink' => get_string('login_link', 'local_nexlogin'),
            'signupLink' => get_string('signup_link', 'local_nexlogin'),
            'navHome' => get_string('nav_home', 'local_nexlogin'),
            'navJoin' => get_string('nav_join', 'local_nexlogin'),
            'changeMethod' => get_string('change_method', 'local_nexlogin'),
            'createAccount' => get_string('create_account', 'local_nexlogin'),
            'signIn' => get_string('sign_in', 'local_nexlogin'),
        ],
    ];
}

/**
 * Attach CSS/JS via Moodle requires API (best effort).
 */
function local_nexlogin_bootstrap(): void {
    global $PAGE;

    static $done = false;
    if ($done || !local_nexlogin_enabled() || !local_nexlogin_is_auth_page()) {
        return;
    }

    if (empty($PAGE) || !method_exists($PAGE, 'requires')) {
        return;
    }

    $done = true;
    $PAGE->add_body_class('nxl-active');

    try {
        // fonts.css is not auto-included site-wide (only styles.css is).
        $PAGE->requires->css(local_nexlogin_file_url('/local/nexlogin/fonts.css'));
        $PAGE->requires->css(local_nexlogin_file_url('/local/nexlogin/styles.css'));
    } catch (\Throwable $e) {
        // Fall through — head HTML injection still loads CSS.
    }

    try {
        $PAGE->requires->js_call_amd('local_nexlogin/login', 'init', [local_nexlogin_js_config()]);
    } catch (\Throwable $e) {
        // Footer inline script is the fallback.
    }
}

/**
 * Extra <head> tags: critical anti-FOUC CSS + config + early script.
 */
function local_nexlogin_head_html(): string {
    if (!local_nexlogin_enabled() || !local_nexlogin_is_auth_page()) {
        return '';
    }

    $cfg = local_nexlogin_js_config();
    $css = htmlspecialchars($cfg['cssUrl'], ENT_QUOTES);
    // Webfonts live outside styles.css so they never load site-wide.
    $fonts = htmlspecialchars(
        local_nexlogin_file_url('/local/nexlogin/fonts.css')->out(false),
        ENT_QUOTES
    );
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{}';
    }
    $inlinejs = local_nexlogin_file_url('/local/nexlogin/amd/src/login-inline.js')->out(false);
    $inlinejsesc = htmlspecialchars($inlinejs, ENT_QUOTES);

    // Critical CSS paints the light page immediately and hides Moodle chrome
    // before RemUI CSS / our full stylesheet finish loading.
    $critical = <<<'CSS'
html.nxl-pending,html.nxl-pending body{background:#e3eaf5!important}
html.nxl-pending body.pagelayout-login #page,
html.nxl-pending body.pagelayout-login #page-wrapper,
html.nxl-pending body.pagelayout-login #region-main,
html.nxl-pending body.pagelayout-login .login-wrapper,
html.nxl-pending body.pagelayout-login .login-container,
html.nxl-pending body.pagelayout-login .navbar,
html.nxl-pending body.pagelayout-login .edw-header,
html.nxl-pending body.pagelayout-login #page-header,
html.nxl-pending body.pagelayout-login #page-footer,
html.nxl-pending body.path-login #page,
html.nxl-pending body.path-login #page-wrapper,
html.nxl-pending body.path-login #region-main,
html.nxl-pending body.path-login .login-wrapper,
html.nxl-pending body.path-login .login-container,
html.nxl-pending body.path-login .navbar,
html.nxl-pending body.path-login .edw-header,
html.nxl-pending body#page-login-index #page,
html.nxl-pending body#page-login-signup #page{opacity:0!important;visibility:hidden!important;pointer-events:none!important}
#nxl-splash{position:fixed;inset:0;z-index:4500;background:radial-gradient(ellipse 70% 55% at 12% 18%,rgba(47,107,255,.16),transparent 58%),radial-gradient(ellipse 55% 50% at 88% 82%,rgba(56,189,248,.12),transparent 55%),linear-gradient(155deg,#e3eaf5 0%,#eef2f8 42%,#e6edf6 100%)}
body.nxl-shell-ready #nxl-splash{display:none!important}
CSS;

    return '<script>document.documentElement.classList.add("nxl-pending");</script>' .
        '<style id="nxl-critical">' . $critical . '</style>' .
        '<link rel="stylesheet" href="' . $fonts . '">' .
        '<link rel="stylesheet" href="' . $css . '">' .
        '<script>window.NEXLOGIN_CFG = ' . $json . ';</script>' .
        '<script src="' . $inlinejsesc . '" defer></script>' .
        '<script defer>(function(){function b(){try{var c=window.NEXLOGIN_CFG||{};if(window.NexLoginInline){window.NexLoginInline(c);return;}if(window.require){window.require(["local_nexlogin/login"],function(m){m&&m.init&&m.init(c);},function(){window.NexLoginInline&&window.NexLoginInline(c);});}}catch(e){}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",b);}else{b();}})();</script>';
}

/**
 * Immediate splash overlay at top of body (covers RemUI flash).
 */
function local_nexlogin_top_html(): string {
    if (!local_nexlogin_enabled() || !local_nexlogin_is_auth_page()) {
        return '';
    }
    return '<div id="nxl-splash" aria-hidden="true"></div>';
}

/**
 * Footer bootstrap: require AMD if present, else run inline shell builder.
 */
function local_nexlogin_footer_html(): string {
    if (!local_nexlogin_enabled() || !local_nexlogin_is_auth_page()) {
        return '';
    }

    $cfg = local_nexlogin_js_config();
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{}';
    }

    // Inline fallback — RemUI sometimes never fires the AMD call on login.
    $inline = <<<'JS'
(function () {
  if (window.__nexloginBooted) { return; }
  window.__nexloginBooted = true;
  var cfg = window.NEXLOGIN_CFG || __CFG__;
  function boot() {
    try {
      if (window.NexLoginInline) {
        window.NexLoginInline(cfg);
        return;
      }
      if (window.require) {
        window.require(['local_nexlogin/login'], function (m) {
          if (m && m.init) { m.init(cfg); }
          else { window.NexLoginInline && window.NexLoginInline(cfg); }
        }, function () {
          window.NexLoginInline && window.NexLoginInline(cfg);
        });
        return;
      }
    } catch (e) {}
    window.NexLoginInline && window.NexLoginInline(cfg);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
JS;
    $inline = str_replace('__CFG__', $json, $inline);

    return '<script>' . $inline . '</script>';
}

/**
 * Legacy callback.
 */
function local_nexlogin_before_http_headers(): void {
    local_nexlogin_bootstrap();
}

/**
 * Legacy footer callback.
 */
function local_nexlogin_before_footer(): void {
    local_nexlogin_bootstrap();
}
