<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Isolated WebContainer host (COOP/COEP).
 *
 * Served without Moodle chrome. Can run as a hidden iframe (needs
 * allow="cross-origin-isolated") or as a popup window.
 *
 * @package    local_nexstack
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

/**
 * Apply cross-origin isolation headers.
 */
function local_nexstack_wcframe_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('Cross-Origin-Opener-Policy: same-origin', true);
    header('Cross-Origin-Embedder-Policy: credentialless', true);
    header('Permissions-Policy: cross-origin-isolated=*', true);
    header('Content-Type: text/html; charset=utf-8', true);
    header('Cache-Control: no-store, no-cache, must-revalidate', true);
    header_remove('X-Frame-Options');
    header('X-Frame-Options: SAMEORIGIN', true);
}

local_nexstack_wcframe_headers();

require_once(__DIR__ . '/lib.php');

// Avoid Moodle login redirect inside iframe/popup (that kills the bridge script).
if (!isloggedin() || isguestuser()) {
    local_nexstack_wcframe_headers();
    http_response_code(401);
    echo '<!DOCTYPE html><html><body style="font:14px system-ui;padding:1rem">' .
        '<strong>NexStack WC:</strong> Log into Moodle first, then Boot again.' .
        '</body></html>';
    exit;
}

$context = context_system::instance();
if (!has_capability('local/nexstack:attempt', $context)) {
    local_nexstack_wcframe_headers();
    http_response_code(403);
    echo 'No permission';
    exit;
}

local_nexstack_wcframe_headers();

if (!(int) (get_config('local_nexstack', 'webcontainers') ?: 1)) {
    http_response_code(403);
    echo 'WebContainers disabled';
    exit;
}

$ispoup = optional_param('popup', 0, PARAM_BOOL);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>NexStack WebContainer</title>
<style>
  html, body { margin: 0; background: #0f172a; color: #94a3b8; font: 12px/1.45 ui-monospace, Menlo, monospace; }
  #st { padding: 10px 12px; white-space: pre-wrap; }
  #hint { padding: 0 12px 12px; color: #64748b; font-size: 11px; white-space: pre-wrap; }
  .ok { color: #4ade80; }
  .bad { color: #f87171; }
</style>
</head>
<body>
<div id="st">NexStack WC frame…</div>
<div id="hint"></div>
<script>
(function () {
  'use strict';
  // Always use the browser origin — $CFG->wwwroot can disagree (www / https / port).
  var PAGE_ORIGIN = window.location.origin;
  var IS_POPUP = <?php echo $ispoup ? 'true' : 'false'; ?>;
  var statusEl = document.getElementById('st');
  var hintEl = document.getElementById('hint');
  var wc = null;
  var booting = null;

  function isolationInfo() {
    return {
      sab: typeof SharedArrayBuffer !== 'undefined',
      coi: !!(window.crossOriginIsolated),
      mode: IS_POPUP ? 'popup' : 'iframe',
      origin: PAGE_ORIGIN
    };
  }

  function setStatus(msg, ok) {
    if (!statusEl) { return; }
    statusEl.className = ok === true ? 'ok' : (ok === false ? 'bad' : '');
    statusEl.textContent = msg;
  }

  function post(msg) {
    var target = IS_POPUP ? (window.opener || null) : (parent !== window ? parent : null);
    if (!target) { return; }
    try {
      // '*' so a wwwroot mismatch cannot drop the ready handshake.
      target.postMessage(Object.assign({channel: 'nxs-wc'}, msg, {iso: isolationInfo()}), '*');
    } catch (e) { /* ignore */ }
  }

  function sameSite(origin) {
    try {
      return origin === PAGE_ORIGIN;
    } catch (e) {
      return false;
    }
  }

  function filesToTree(files) {
    var tree = {};
    Object.keys(files || {}).forEach(function (path) {
      var parts = String(path).split('/').filter(Boolean);
      var cur = tree;
      parts.forEach(function (part, i) {
        if (i === parts.length - 1) {
          cur[part] = { file: { contents: String(files[path] == null ? '' : files[path]) } };
        } else {
          cur[part] = cur[part] || { directory: {} };
          cur = cur[part].directory;
        }
      });
    });
    return tree;
  }

  function loadApi() {
    return new Promise(function (resolve, reject) {
      if (window.WebContainer && window.WebContainer.boot) {
        resolve(window.WebContainer);
        return;
      }
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/@webcontainer/api@1.5.1/dist/index.js';
      s.crossOrigin = 'anonymous';
      s.onload = function () {
        if (window.WebContainer && window.WebContainer.boot) {
          resolve(window.WebContainer);
        } else {
          reject(new Error('WebContainer API missing after load'));
        }
      };
      s.onerror = function () { reject(new Error('Failed to load @webcontainer/api (CDN / COEP)')); };
      document.head.appendChild(s);
    });
  }

  function pipeOutput(proc, reqId) {
    if (!proc || !proc.output) { return; }
    proc.output.pipeTo(new WritableStream({
      write: function (chunk) {
        post({type: 'spawn-out', id: reqId, text: String(chunk)});
      }
    })).catch(function () {});
  }

  function boot(reqId, files) {
    if (booting) { return booting; }
    var iso = isolationInfo();
    if (!iso.sab || !iso.coi) {
      post({
        type: 'boot-err',
        id: reqId,
        message: 'Not cross-origin isolated (crossOriginIsolated=' + iso.coi +
          ', SharedArrayBuffer=' + iso.sab + '). Server may be stripping COOP/COEP headers.'
      });
      return Promise.resolve();
    }
    setStatus('Loading WebContainer API…');
    booting = loadApi().then(function (WC) {
      setStatus('Booting…');
      return WC.boot({ coep: 'credentialless' });
    }).then(function (instance) {
      wc = instance;
      setStatus('Mounting…');
      return wc.mount(filesToTree(files)).then(function () { return instance; });
    }).then(function (instance) {
      instance.on('server-ready', function (port, url) {
        post({type: 'server-ready', port: port, url: url});
        setStatus('Server ' + port, true);
      });
      post({type: 'boot-ok', id: reqId});
      setStatus('Ready', true);
      return instance;
    }).catch(function (err) {
      booting = null;
      wc = null;
      var msg = err && err.message ? err.message : String(err);
      post({type: 'boot-err', id: reqId, message: msg});
      setStatus('Error: ' + msg, false);
    });
    return booting;
  }

  function spawn(reqId, cmd, args, cwd) {
    if (!wc) {
      post({type: 'spawn-exit', id: reqId, code: 1, message: 'WebContainer not booted'});
      return;
    }
    wc.spawn(cmd, args || [], {cwd: cwd || '/'}).then(function (proc) {
      pipeOutput(proc, reqId);
      return proc.exit;
    }).then(function (code) {
      post({type: 'spawn-exit', id: reqId, code: code});
    }).catch(function (err) {
      post({type: 'spawn-exit', id: reqId, code: 1, message: err && err.message ? err.message : String(err)});
    });
  }

  window.addEventListener('message', function (ev) {
    if (!sameSite(ev.origin)) { return; }
    var data = ev.data || {};
    if (data.channel !== 'nxs-wc') { return; }
    if (data.type === 'ping') {
      post({type: 'pong'});
      return;
    }
    if (data.type === 'boot') {
      boot(data.id, data.files || {});
      return;
    }
    if (data.type === 'spawn') {
      spawn(data.id, data.cmd, data.args || [], data.cwd || '/');
      return;
    }
    if (data.type === 'write' && wc && data.path) {
      wc.fs.writeFile(data.path, String(data.contents == null ? '' : data.contents)).then(function () {
        post({type: 'write-ok', id: data.id, path: data.path});
      }).catch(function (err) {
        post({type: 'write-err', id: data.id, message: err && err.message ? err.message : String(err)});
      });
    }
  });

  var iso = isolationInfo();
  if (iso.coi && iso.sab) {
    setStatus('Isolated OK (SharedArrayBuffer available) · ' + iso.mode, true);
  } else {
    setStatus('NOT isolated · crossOriginIsolated=' + iso.coi + ' · SAB=' + iso.sab + ' · ' + iso.mode, false);
    hintEl.textContent =
      'Response headers must include:\n' +
      '  Cross-Origin-Opener-Policy: same-origin\n' +
      '  Cross-Origin-Embedder-Policy: credentialless\n\n' +
      'Check DevTools → Network → wcframe.php → Response Headers.\n' +
      'If missing, your proxy/CDN is stripping them (see plugin .htaccess / README).';
  }
  post({type: 'frame-ready'});
})();
</script>
</body>
</html>
