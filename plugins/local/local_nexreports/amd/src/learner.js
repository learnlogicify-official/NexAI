/**
 * NexReports learner dashboard (My Time Spent On Site).
 *
 * @module     local_nexreports/learner
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const label = function(root, name) {
        return root.getAttribute('data-label-' + name) || '';
    };

    /**
     * Format minutes as HH:MM:SS (Edwiser learner chart style).
     *
     * @param {number} minutes
     * @return {string}
     */
    const formatHMS = function(minutes) {
        const secs = Math.max(0, Math.round(Number(minutes) || 0) * 60);
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        const s = secs % 60;
        const pad = function(n) {
            return String(n).padStart(2, '0');
        };
        return pad(h) + ':' + pad(m) + ':' + pad(s);
    };

    const changePill = function(root, change) {
        const abs = Math.abs(Number(change) || 0).toFixed(Math.abs(change) % 1 ? 1 : 0);
        if (Number(change) > 0) {
            return '<span class="nxr-kpi__change is-up">' + esc(abs) + '% ' + esc(label(root, 'pct-up')) + '</span>';
        }
        if (Number(change) < 0) {
            return '<span class="nxr-kpi__change is-down">' + esc(abs) + '% ' + esc(label(root, 'pct-down')) + '</span>';
        }
        return '<span class="nxr-kpi__change is-flat">' + esc(label(root, 'pct-flat')) + '</span>';
    };

    const CHART_W = 560;
    const CHART_H = 220;
    const CHART_PAD = 28;

    const sharedMax = function(values) {
        let max = 1;
        (values || []).forEach(function(v) {
            const num = Number(v) || 0;
            if (num > max) {
                max = num;
            }
        });
        return max;
    };

    const pointX = function(i, n) {
        const innerW = CHART_W - (CHART_PAD * 2);
        return CHART_PAD + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
    };

    const pointY = function(value, max) {
        const innerH = CHART_H - (CHART_PAD * 2);
        return CHART_PAD + innerH - (((Number(value) || 0) / max) * innerH);
    };

    const polyline = function(values, max) {
        const n = values.length;
        if (!n) {
            return '';
        }
        return values.map(function(v, i) {
            return pointX(i, n).toFixed(1) + ',' + pointY(v, max).toFixed(1);
        }).join(' ');
    };

    const axisLabels = function(labels) {
        const n = labels.length;
        if (!n) {
            return '';
        }
        return labels.map(function(text, i) {
            const x = pointX(i, n);
            const short = String(text).length > 8 ? String(text).slice(0, 7) + '…' : String(text);
            return '<text x="' + x.toFixed(1) + '" y="' + (CHART_H - 8) +
                '" text-anchor="middle" fill="#64748b" font-size="10">' + esc(short) + '</text>';
        }).join('');
    };

    const renderChart = function(host, labels, values) {
        const max = sharedMax(values);
        const pts = polyline(values || [], max);
        const path = pts
            ? '<polyline fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" ' +
                'stroke-linejoin="round" points="' + pts + '"></polyline>'
            : '';
        host.innerHTML = '<svg class="nxr-chart__svg" viewBox="0 0 ' + CHART_W + ' ' + CHART_H +
            '" preserveAspectRatio="none" role="img" focusable="false">' +
            '<line x1="' + CHART_PAD + '" y1="' + (CHART_H - CHART_PAD) + '" x2="' + (CHART_W - CHART_PAD) +
            '" y2="' + (CHART_H - CHART_PAD) + '" stroke="#e5e7eb"></line>' +
            path + axisLabels(labels || []) + '</svg>' +
            '<div class="nxr-chart__overlay">' +
                '<span class="nxr-chart__guide"></span>' +
                '<span class="nxr-chart__marker" style="color:#2563eb"></span>' +
                '<div class="nxr-chart__tip" role="status"></div>' +
            '</div>';

        const tip = host.querySelector('.nxr-chart__tip');
        const guide = host.querySelector('.nxr-chart__guide');
        const marker = host.querySelector('.nxr-chart__marker');
        const seriesLabel = label(host.closest('[data-region="nxr-learner"]'), 'series-timespent');
        const show = function(event) {
            const rect = host.getBoundingClientRect();
            const n = (labels || []).length || 1;
            const ratio = rect.width ? (event.clientX - rect.left) / rect.width : 0;
            const index = Math.max(0, Math.min(n - 1, Math.round(ratio * (n - 1))));
            const x = pointX(index, n);
            const y = pointY(values[index], max);
            const leftPct = (x / CHART_W) * 100;
            const topPct = (y / CHART_H) * 100;
            guide.style.left = leftPct + '%';
            marker.style.left = leftPct + '%';
            marker.style.top = topPct + '%';
            tip.style.left = leftPct + '%';
            tip.style.top = Math.max(8, topPct - 12) + '%';
            tip.innerHTML = '<div class="nxr-chart__tip-title">' + esc(labels[index] || '') + '</div>' +
                '<div class="nxr-chart__tip-row"><span class="nxr-chart__tip-dot" style="background:#2563eb"></span>' +
                '<span class="nxr-chart__tip-name">' + esc(seriesLabel) + '</span>' +
                '<span class="nxr-chart__tip-value">' + esc(formatHMS(values[index])) + '</span></div>';
            host.classList.add('is-hovering');
        };
        const hide = function() {
            host.classList.remove('is-hovering');
        };
        host.addEventListener('pointermove', show);
        host.addEventListener('pointerleave', hide);
        host.addEventListener('pointercancel', hide);
    };

    const downloadBlob = function(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function() {
            URL.revokeObjectURL(url);
        }, 1000);
    };

    const jpegToPdfBlob = function(jpeg, width, height) {
        const encoder = new TextEncoder();
        const parts = [];
        const push = function(chunk) {
            parts.push(typeof chunk === 'string' ? encoder.encode(chunk) : chunk);
        };
        const offsets = [];
        const startObj = function() {
            let len = 0;
            parts.forEach(function(p) {
                len += p.length;
            });
            offsets.push(len);
        };
        push('%PDF-1.4\n');
        startObj();
        push('1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n');
        startObj();
        push('2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n');
        startObj();
        push('3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + width + ' ' + height +
            '] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>endobj\n');
        const content = 'q ' + width + ' 0 0 ' + height + ' 0 0 cm /Im0 Do Q\n';
        startObj();
        push('4 0 obj<< /Length ' + content.length + ' >>stream\n' + content + 'endstream\nendobj\n');
        startObj();
        push('5 0 obj<< /Type /XObject /Subtype /Image /Width ' + width + ' /Height ' + height +
            ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' +
            jpeg.length + ' >>stream\n');
        push(jpeg);
        push('\nendstream\nendobj\n');
        let xrefAt = 0;
        parts.forEach(function(p) {
            xrefAt += p.length;
        });
        push('xref\n0 6\n0000000000 65535 f \n');
        offsets.forEach(function(off) {
            push(String(off).padStart(10, '0') + ' 00000 n \n');
        });
        push('trailer<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xrefAt + '\n%%EOF');
        let total = 0;
        parts.forEach(function(p) {
            total += p.length;
        });
        const out = new Uint8Array(total);
        let offset = 0;
        parts.forEach(function(p) {
            out.set(p, offset);
            offset += p.length;
        });
        return new Blob([out], {type: 'application/pdf'});
    };

    const exportChartHost = function(host, format, basename) {
        const svg = host ? host.querySelector('svg') : null;
        if (!svg) {
            return Promise.reject(new Error('empty'));
        }
        const clone = svg.cloneNode(true);
        if (!clone.getAttribute('xmlns')) {
            clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }
        const rect = svg.getBoundingClientRect();
        const width = Math.max(320, Math.round(rect.width) || 640);
        const height = Math.max(180, Math.round(rect.height) || 240);
        clone.setAttribute('width', String(width));
        clone.setAttribute('height', String(height));
        const xml = new XMLSerializer().serializeToString(clone);
        const safe = (basename || 'chart').replace(/[^\w\-]+/g, '_');
        if (format === 'svg') {
            downloadBlob(new Blob([xml], {type: 'image/svg+xml;charset=utf-8'}), safe + '.svg');
            return Promise.resolve();
        }
        return new Promise(function(resolve, reject) {
            const url = URL.createObjectURL(new Blob([xml], {type: 'image/svg+xml;charset=utf-8'}));
            const img = new Image();
            img.onload = function() {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, width, height);
                    ctx.drawImage(img, 0, 0, width, height);
                    URL.revokeObjectURL(url);
                    if (format === 'png') {
                        canvas.toBlob(function(blob) {
                            if (!blob) {
                                reject(new Error('blob'));
                                return;
                            }
                            downloadBlob(blob, safe + '.png');
                            resolve();
                        }, 'image/png');
                        return;
                    }
                    canvas.toBlob(function(blob) {
                        if (!blob) {
                            reject(new Error('blob'));
                            return;
                        }
                        if (format === 'jpeg') {
                            downloadBlob(blob, safe + '.jpeg');
                            resolve();
                            return;
                        }
                        blob.arrayBuffer().then(function(buffer) {
                            downloadBlob(jpegToPdfBlob(new Uint8Array(buffer), width, height), safe + '.pdf');
                            resolve();
                        }).catch(reject);
                    }, 'image/jpeg', 0.92);
                } catch (err) {
                    URL.revokeObjectURL(url);
                    reject(err);
                }
            };
            img.onerror = function() {
                URL.revokeObjectURL(url);
                reject(new Error('image'));
            };
            img.src = url;
        });
    };

    const wireChartExports = function(root) {
        root.querySelectorAll('.nxr-chart-export').forEach(function(wrap) {
            const toggle = wrap.querySelector('.nxr-chart-export__toggle');
            const menu = wrap.querySelector('.nxr-chart-export__menu');
            if (!toggle || !menu) {
                return;
            }
            const close = function() {
                menu.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            };
            toggle.addEventListener('click', function(event) {
                event.stopPropagation();
                const open = menu.hidden;
                root.querySelectorAll('.nxr-chart-export__menu').forEach(function(other) {
                    other.hidden = true;
                });
                if (open) {
                    menu.hidden = false;
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });
            menu.querySelectorAll('[data-chart-format]').forEach(function(btn) {
                btn.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const format = btn.getAttribute('data-chart-format');
                    const card = wrap.closest('[data-chart-card]') || wrap.closest('.nxr-card');
                    const host = card ? card.querySelector('[data-export-chart]') : null;
                    const titleEl = card ? card.querySelector('.nxr-card__title') : null;
                    const title = titleEl ? titleEl.textContent.trim() : 'chart';
                    close();
                    exportChartHost(host, format, 'nexreports-' + title).catch(function() {
                        window.alert(label(root, 'export-error') || label(root, 'no-chart-export'));
                    });
                });
            });
        });
        document.addEventListener('click', function() {
            root.querySelectorAll('.nxr-chart-export__menu').forEach(function(menu) {
                menu.hidden = true;
            });
        });
    };

    const renderMyTimespent = function(root, data) {
        const host = root.querySelector('[data-region="my-timespent-summary"]');
        const chart = root.querySelector('[data-region="my-timespent-chart"]');
        if (!host) {
            return;
        }
        if (!data || data.available === false) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'timespent-unavailable')) + '</p>';
            if (chart) {
                chart.innerHTML = '';
            }
            return;
        }
        host.innerHTML =
            '<div class="nxr-summary__primary">' +
                '<p class="nxr-summary__value">' + esc(formatHMS(data.average)) + '</p>' +
                changePill(root, data.change) +
                '<p class="nxr-summary__label">' + esc(label(root, 'avg-timespent')) + '</p>' +
            '</div>' +
            '<div class="nxr-summary__side">' +
                esc(label(root, 'total-timespent')) + ': <strong>' + esc(formatHMS(data.total)) + '</strong>' +
            '</div>';
        if (chart) {
            renderChart(chart, data.labels || [], data.values || []);
        }
    };

    const init = function(config) {
        const root = document.querySelector('[data-region="nxr-learner"]');
        if (!root) {
            return;
        }

        let days = (config && config.defaultDays === 30) ? 30 : 7;
        let reqId = 0;

        const call = function(method, args) {
            return Ajax.call([{methodname: method, args: args}])[0];
        };

        const load = function() {
            const id = ++reqId;
            const summary = root.querySelector('[data-region="my-timespent-summary"]');
            const chart = root.querySelector('[data-region="my-timespent-chart"]');
            if (summary) {
                summary.innerHTML = '<div class="nxr-skeleton nxr-skeleton--strip"></div>';
            }
            if (chart) {
                chart.innerHTML = '<div class="nxr-skeleton nxr-skeleton--chart"></div>';
            }
            call('local_nexreports_get_my_timespent', {days: days}).then(function(data) {
                if (id !== reqId) {
                    return null;
                }
                renderMyTimespent(root, data);
                return null;
            }).catch(function() {
                if (id !== reqId) {
                    return;
                }
                if (summary) {
                    summary.innerHTML = '<p class="nxr-error">' + esc(label(root, 'loaderror')) + '</p>';
                }
                if (chart) {
                    chart.innerHTML = '';
                }
            });
        };

        document.querySelectorAll('.nxr-period [data-days]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                days = parseInt(btn.getAttribute('data-days'), 10) === 30 ? 30 : 7;
                document.querySelectorAll('.nxr-period [data-days]').forEach(function(el) {
                    el.classList.toggle('is-active', el === btn);
                });
                load();
            });
        });

        wireChartExports(root);
        load();
    };

    return {init: init};
});
