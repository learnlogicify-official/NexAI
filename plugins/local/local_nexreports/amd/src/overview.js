/**
 * NexReports site overview dashboard.
 *
 * @module     local_nexreports/overview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'local_nexreports/table_export'], function(Ajax, TableExport) {

    /** @type {Object|null} */
    let headcountCache = null;

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

    const kpiLabel = function(root, key) {
        const map = {
            registrations: 'registrations',
            enrolments: 'enrolments',
            completions: 'completions',
            activeusers: 'activeusers',
            totalyears: 'totalyears',
            totaldepartments: 'totaldepartments',
            totalstudents: 'totalstudents',
            timespent: 'timespent',
        };
        return label(root, map[key] || key);
    };

    /**
     * Format whole minutes as compact duration (e.g. 45m, 2h, 2h 15m).
     *
     * @param {number} minutes
     * @return {string}
     */
    const formatMinutes = function(minutes) {
        let mins = Math.max(0, Math.round(Number(minutes) || 0));
        if (mins < 60) {
            return mins + 'm';
        }
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return m ? (h + 'h ' + m + 'm') : (h + 'h');
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

    const DEFAULT_KPI_KEYS = ['registrations', 'enrolments', 'completions', 'activeusers', 'timespent'];
    const STATIC_KPI_KEYS = {
        totalyears: true,
        totaldepartments: true,
        totalstudents: true,
    };

    const buildKpiSkeleton = function(root, keys) {
        const host = root.querySelector('[data-region="kpis"]');
        if (!host) {
            return;
        }
        const list = (keys && keys.length) ? keys.slice() : DEFAULT_KPI_KEYS.slice();
        if (list.indexOf('timespent') === -1) {
            list.push('timespent');
        }
        host.innerHTML = list.map(function(key) {
            return '<article class="nxr-kpi" data-region="kpi-' + key + '">' +
                '<span class="nxr-kpi__icon" aria-hidden="true">' +
                    '<span class="nxr-kpi__glyph nxr-kpi__glyph--' + key + '"></span>' +
                '</span>' +
                '<div class="nxr-kpi__body">' +
                    '<p class="nxr-kpi__label">' + esc(kpiLabel(root, key)) + '</p>' +
                    '<div class="nxr-kpi__metrics">' +
                        '<span class="nxr-skeleton nxr-skeleton--value"></span>' +
                    '</div>' +
                '</div>' +
                '</article>';
        }).join('');
    };

    const setKpiCard = function(root, key, valueHtml, changeHtml) {
        const card = root.querySelector('[data-region="kpi-' + key + '"]');
        if (!card) {
            return;
        }
        const metrics = card.querySelector('.nxr-kpi__metrics');
        if (metrics) {
            metrics.innerHTML = '<p class="nxr-kpi__value">' + valueHtml + '</p>' + (changeHtml || '');
        }
    };

    const renderKpis = function(root, kpis) {
        const keys = (kpis || []).map(function(kpi) {
            return kpi.key;
        });
        buildKpiSkeleton(root, keys);
        (kpis || []).forEach(function(kpi) {
            const changeHtml = STATIC_KPI_KEYS[kpi.key] ? '' : changePill(root, kpi.change);
            setKpiCard(root, kpi.key, esc(String(kpi.value)), changeHtml);
        });
    };

    const CHART_W = 560;
    const CHART_H = 220;
    const CHART_PAD = 28;

    /**
     * Largest value across every series, so all lines share one scale.
     *
     * @param {Array} series
     * @return {number}
     */
    const sharedMax = function(series) {
        let max = 1;
        series.forEach(function(s) {
            (s.values || []).forEach(function(v) {
                const num = Number(v) || 0;
                if (num > max) {
                    max = num;
                }
            });
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
        const step = n > 8 ? Math.ceil(n / 6) : 1;
        let html = '';
        for (let i = 0; i < n; i += step) {
            html += '<text x="' + pointX(i, n).toFixed(1) + '" y="' + (CHART_H - 4) +
                '" text-anchor="middle" fill="#94a3b8" font-size="10">' + esc(labels[i]) + '</text>';
        }
        return html;
    };

    /**
     * Wire pointer tracking: guide line, markers, and tooltip.
     *
     * Marker and tooltip positions are computed in pixels rather than SVG units
     * because the chart is stretched with preserveAspectRatio="none".
     *
     * @param {Element} host
     * @param {Array} labels
     * @param {Array} series
     * @param {number} max
     */
    const attachChartHover = function(host, labels, series, max) {
        const n = labels.length;
        const guide = host.querySelector('.nxr-chart__guide');
        const tip = host.querySelector('.nxr-chart__tip');
        const markers = host.querySelectorAll('.nxr-chart__marker');
        if (!n || !guide || !tip) {
            return;
        }

        const show = function(event) {
            const rect = host.getBoundingClientRect();
            if (!rect.width) {
                return;
            }
            const innerW = CHART_W - (CHART_PAD * 2);
            const viewX = ((event.clientX - rect.left) / rect.width) * CHART_W;
            let index = n <= 1 ? 0 : Math.round(((viewX - CHART_PAD) / innerW) * (n - 1));
            index = Math.max(0, Math.min(n - 1, index));

            const xPx = (pointX(index, n) / CHART_W) * rect.width;
            guide.style.left = xPx + 'px';

            let topPx = rect.height;
            series.forEach(function(s, i) {
                const marker = markers[i];
                if (!marker) {
                    return;
                }
                const value = (s.values || [])[index];
                if (value === undefined) {
                    marker.style.display = 'none';
                    return;
                }
                const yPx = (pointY(value, max) / CHART_H) * rect.height;
                marker.style.display = '';
                marker.style.left = xPx + 'px';
                marker.style.top = yPx + 'px';
                topPx = Math.min(topPx, yPx);
            });

            tip.innerHTML = '<p class="nxr-chart__tip-title">' + esc(labels[index]) + '</p>' +
                series.map(function(s) {
                    const raw = (s.values || [])[index] || 0;
                    const shown = s.format === 'minutes' ? formatMinutes(raw) : raw;
                    return '<span class="nxr-chart__tip-row" style="color:' + s.color + '">' +
                        '<span class="nxr-chart__tip-dot"></span>' +
                        '<span class="nxr-chart__tip-name">' + esc(s.label) + '</span>' +
                        '<span class="nxr-chart__tip-value">' +
                        esc(shown) + '</span></span>';
                }).join('');

            const half = tip.offsetWidth / 2;
            const left = Math.max(half + 4, Math.min(rect.width - half - 4, xPx));
            tip.style.left = left + 'px';
            tip.style.top = Math.max(tip.offsetHeight + 6, topPx - 10) + 'px';
            host.classList.add('is-hovering');
        };

        const hide = function() {
            host.classList.remove('is-hovering');
        };

        host.addEventListener('pointermove', show);
        host.addEventListener('pointerdown', show);
        host.addEventListener('pointerleave', hide);
        host.addEventListener('pointercancel', hide);
    };

    /**
     * @param {Element} host
     * @param {Array} labels
     * @param {Array} series each {key, label, color, values}
     */
    const renderChart = function(host, labels, series) {
        const max = sharedMax(series);
        let paths = '';
        let markers = '';
        series.forEach(function(s) {
            const pts = polyline(s.values || [], max);
            if (pts) {
                paths += '<polyline fill="none" stroke="' + s.color +
                    '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="' +
                    pts + '"></polyline>';
            }
            markers += '<span class="nxr-chart__marker" style="color:' + s.color + '"></span>';
        });

        host.innerHTML = '<svg class="nxr-chart__svg" viewBox="0 0 ' + CHART_W + ' ' + CHART_H +
            '" preserveAspectRatio="none" role="img" focusable="false">' +
            '<line x1="' + CHART_PAD + '" y1="' + (CHART_H - CHART_PAD) + '" x2="' + (CHART_W - CHART_PAD) +
            '" y2="' + (CHART_H - CHART_PAD) + '" stroke="#e5e7eb"></line>' +
            paths + axisLabels(labels || []) + '</svg>' +
            '<div class="nxr-chart__overlay">' +
                '<span class="nxr-chart__guide"></span>' + markers +
                '<div class="nxr-chart__tip" role="status"></div>' +
            '</div>';

        attachChartHover(host, labels || [], series, max);
    };

    const renderOverviewSummary = function(root, overview) {
        const host = root.querySelector('[data-region="overview-summary"]');
        if (!host || !overview) {
            return;
        }
        host.innerHTML =
            '<div class="nxr-summary__primary">' +
                '<p class="nxr-summary__value">' + esc(overview.averageactive) + '</p>' +
                changePill(root, overview.activechange) +
                '<p class="nxr-summary__label">' + esc(label(root, 'avg-active')) + '</p>' +
            '</div>' +
            '<div class="nxr-summary__side">' +
                esc(label(root, 'total-active')) + ': <strong>' + esc(overview.totalactive) + '</strong><br>' +
                esc(label(root, 'total-enrolments')) + ': <strong>' + esc(overview.totalenrolments) + '</strong><br>' +
                esc(label(root, 'total-completions')) + ': <strong>' + esc(overview.totalcompletions) + '</strong>' +
            '</div>';

        const chart = root.querySelector('[data-region="overview-chart"]');
        if (!chart) {
            return;
        }
        const mount = document.createElement('div');
        mount.innerHTML =
            '<div class="nxr-legend">' +
                '<span class="nxr-legend__item" style="color:#2563eb"><span class="nxr-legend__dot"></span>' +
                esc(label(root, 'series-active')) + '</span>' +
                '<span class="nxr-legend__item" style="color:#1e3a8a"><span class="nxr-legend__dot"></span>' +
                esc(label(root, 'series-enrolments')) + '</span>' +
                '<span class="nxr-legend__item" style="color:#60a5fa"><span class="nxr-legend__dot"></span>' +
                esc(label(root, 'series-completions')) + '</span>' +
            '</div>';
        const svgHost = document.createElement('div');
        svgHost.className = 'nxr-chart';
        chart.innerHTML = '';
        chart.appendChild(mount.firstChild);
        chart.appendChild(svgHost);
        renderChart(svgHost, overview.labels || [], [
            {key: 'active', label: label(root, 'series-active'), color: '#2563eb', values: overview.active || []},
            {
                key: 'enrolments',
                label: label(root, 'series-enrolments'),
                color: '#1e3a8a',
                values: overview.enrolments || []
            },
            {
                key: 'completions',
                label: label(root, 'series-completions'),
                color: '#60a5fa',
                values: overview.completions || []
            },
        ]);
    };

    const renderVisitsSummary = function(root, visits) {
        const host = root.querySelector('[data-region="visits-summary"]');
        if (!host || !visits) {
            return;
        }
        host.innerHTML =
            '<div class="nxr-summary__primary">' +
                '<p class="nxr-summary__value">' + esc(visits.average) + '</p>' +
                changePill(root, visits.change) +
                '<p class="nxr-summary__label">' + esc(label(root, 'avg-visits')) + '</p>' +
            '</div>' +
            '<div class="nxr-summary__side">' +
                esc(label(root, 'total-visits')) + ': <strong>' + esc(visits.total) + '</strong>' +
            '</div>';
        const chart = root.querySelector('[data-region="visits-chart"]');
        if (chart) {
            renderChart(chart, visits.labels || [], [
                {key: 'visits', label: label(root, 'series-visits'), color: '#2563eb', values: visits.values || []},
            ]);
        }
    };

    /**
     * Download a Blob as a named file.
     *
     * @param {Blob} blob
     * @param {string} filename
     */
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

    /**
     * Minimal single-page PDF wrapping a JPEG payload.
     *
     * @param {Uint8Array} jpeg
     * @param {number} width
     * @param {number} height
     * @return {Blob}
     */
    const jpegToPdfBlob = function(jpeg, width, height) {
        const encoder = new TextEncoder();
        const parts = [];
        const push = function(chunk) {
            if (typeof chunk === 'string') {
                parts.push(encoder.encode(chunk));
            } else {
                parts.push(chunk);
            }
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

    /**
     * Export the first SVG inside a chart host as svg/png/jpeg/pdf.
     *
     * @param {Element} host
     * @param {string} format
     * @param {string} basename
     * @return {Promise}
     */
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
                            downloadBlob(
                                jpegToPdfBlob(new Uint8Array(buffer), width, height),
                                safe + '.pdf'
                            );
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

    /**
     * Wire ellipsis export menus on chart cards.
     *
     * @param {Element} root
     */
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
                root.querySelectorAll('.nxr-chart-export__toggle').forEach(function(btn) {
                    btn.setAttribute('aria-expanded', 'false');
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
            root.querySelectorAll('.nxr-chart-export__toggle').forEach(function(btn) {
                btn.setAttribute('aria-expanded', 'false');
            });
        });
    };

    const renderBarChart = function(host, labels, values, options) {
        if (!host) {
            return;
        }
        options = options || {};
        const tipLabel = options.tipLabel || 'series-timespent';
        const asMinutes = options.format !== 'count';
        const width = 560;
        const height = 250;
        const left = 24;
        const right = 12;
        const top = 18;
        const bottom = 72;
        const max = Math.max.apply(null, (values || []).concat([1]));
        const count = labels.length;
        const slot = count ? (width - left - right) / count : 0;
        const barWidth = Math.max(5, Math.min(30, slot * 0.58));
        let bars = '';
        let captions = '';
        labels.forEach(function(text, i) {
            const value = Number(values[i]) || 0;
            const barHeight = (value / max) * (height - top - bottom);
            const x = left + (i * slot) + ((slot - barWidth) / 2);
            const y = height - bottom - barHeight;
            const short = String(text).length > 16 ? String(text).slice(0, 15) + '…' : String(text);
            bars += '<rect class="nxr-chart__bar" data-index="' + i + '" x="' + x.toFixed(1) +
                '" y="' + y.toFixed(1) + '" width="' + barWidth.toFixed(1) +
                '" height="' + Math.max(1, barHeight).toFixed(1) + '" rx="3"></rect>';
            captions += '<text x="' + (x + barWidth / 2).toFixed(1) + '" y="' + (height - bottom + 13) +
                '" transform="rotate(-38 ' + (x + barWidth / 2).toFixed(1) + ' ' + (height - bottom + 13) +
                ')" text-anchor="end" fill="#64748b" font-size="9">' + esc(short) + '</text>';
        });
        host.innerHTML = '<svg viewBox="0 0 ' + width + ' ' + height +
            '" preserveAspectRatio="none" role="img">' +
            '<line x1="' + left + '" y1="' + (height - bottom) + '" x2="' + (width - right) +
            '" y2="' + (height - bottom) + '" stroke="#e5e7eb"></line>' + bars + captions + '</svg>' +
            '<div class="nxr-chart__overlay"><div class="nxr-chart__tip" role="status"></div></div>';

        const tip = host.querySelector('.nxr-chart__tip');
        const overview = host.closest('[data-region="nxr-overview"]');
        host.querySelectorAll('.nxr-chart__bar').forEach(function(bar) {
            const show = function(event) {
                const index = parseInt(bar.getAttribute('data-index'), 10);
                const rect = host.getBoundingClientRect();
                const raw = values[index] || 0;
                const shown = asMinutes ? formatMinutes(raw) : String(raw);
                tip.innerHTML = '<p class="nxr-chart__tip-title">' + esc(labels[index]) + '</p>' +
                    '<span class="nxr-chart__tip-row"><span class="nxr-chart__tip-name">' +
                    esc(label(overview, tipLabel)) +
                    '</span><span class="nxr-chart__tip-value">' +
                    esc(shown) + '</span></span>';
                tip.style.left = Math.max(65, Math.min(rect.width - 65, event.clientX - rect.left)) + 'px';
                tip.style.top = Math.max(tip.offsetHeight + 8, event.clientY - rect.top - 8) + 'px';
                host.classList.add('is-hovering');
            };
            bar.addEventListener('pointermove', show);
            bar.addEventListener('pointerenter', show);
        });
        host.addEventListener('pointerleave', function() {
            host.classList.remove('is-hovering');
        });
    };

    /**
     * Searchable dropdown backed by a server-side query.
     *
     * Options are never preloaded with the page: the list is fetched when the panel
     * opens and again (debounced) as the user types, so every account is reachable
     * without shipping the whole user table to the browser.
     *
     * @param {Element} root Dashboard root, used for labels
     * @param {Element} container Element carrying data-combo
     * @param {Function} search Called with (query) returning a Promise of options
     * @param {Function} onSelect Called with (id, name) when the selection changes
     * @return {Object} Controller with a setSelected method
     */
    const createCombo = function(root, container, search, onSelect, guard) {
        const toggle = container.querySelector('.nxr-combo__toggle');
        const valueEl = container.querySelector('.nxr-combo__value');
        const panel = container.querySelector('.nxr-combo__panel');
        const input = container.querySelector('.nxr-combo__search');
        const list = container.querySelector('.nxr-combo__list');
        const placeholder = container.getAttribute('data-placeholder') || '';
        const type = container.getAttribute('data-type') || '';
        const stringIds = (type === 'year' || type === 'department');
        let options = [];
        let activeIndex = -1;
        let selectedId = stringIds ? '' : 0;
        let timer = null;
        let seq = 0;

        const isEmpty = function(id) {
            return stringIds ? (id === '' || id == null) : !Number(id);
        };

        const rows = function() {
            return list.querySelectorAll('.nxr-combo__option');
        };

        const highlight = function(index) {
            const items = rows();
            activeIndex = Math.max(-1, Math.min(items.length - 1, index));
            items.forEach(function(item, i) {
                item.classList.toggle('is-active', i === activeIndex);
            });
            if (activeIndex >= 0 && items[activeIndex]) {
                items[activeIndex].scrollIntoView({block: 'nearest'});
            }
        };

        const paint = function(message) {
            if (message) {
                list.innerHTML = '<li class="nxr-combo__msg">' + esc(message) + '</li>';
                return;
            }
            const emptyId = stringIds ? '' : '0';
            list.innerHTML =
                '<li class="nxr-combo__option' + (isEmpty(selectedId) ? ' is-selected' : '') +
                    '" role="option" data-id="' + emptyId + '">' + esc(placeholder) + '</li>' +
                options.map(function(option) {
                    return '<li class="nxr-combo__option' +
                        (String(option.id) === String(selectedId) ? ' is-selected' : '') +
                        '" role="option" data-id="' + esc(option.id) + '">' +
                        esc(option.name) + '</li>';
                }).join('');
            highlight(-1);
        };

        const fetch = function(query) {
            // A dependent filter stays empty until its parent is chosen, so there is nothing
            // to search for yet and the panel explains what to pick first.
            const blocked = guard ? guard() : '';
            if (blocked) {
                options = [];
                seq++;
                list.innerHTML = '<li class="nxr-combo__msg">' + esc(blocked) + '</li>';
                return;
            }
            const id = ++seq;
            paint(label(root, 'searching'));
            search(query).then(function(result) {
                if (id !== seq) {
                    return null;
                }
                options = result || [];
                if (!options.length && query) {
                    paint(label(root, 'nomatches'));
                    return null;
                }
                paint(null);
                return null;
            }).catch(function() {
                if (id === seq) {
                    paint(label(root, 'loaderror'));
                }
            });
        };

        const close = function() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            container.classList.remove('is-open');
        };

        const open = function() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            container.classList.add('is-open');
            input.value = '';
            input.focus();
            fetch('');
        };

        const choose = function(id, name) {
            if (stringIds) {
                selectedId = (id == null || id === '') ? '' : String(id);
            } else {
                selectedId = Number(id) || 0;
            }
            valueEl.textContent = isEmpty(selectedId) ? placeholder : name;
            container.classList.toggle('is-filtered', !isEmpty(selectedId));
            close();
            onSelect(selectedId, name);
        };

        toggle.addEventListener('click', function() {
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });

        input.addEventListener('input', function() {
            window.clearTimeout(timer);
            const query = input.value;
            timer = window.setTimeout(function() {
                fetch(query);
            }, 250);
        });

        list.addEventListener('click', function(event) {
            const option = event.target.closest('.nxr-combo__option');
            if (option) {
                choose(option.getAttribute('data-id'), option.textContent);
            }
        });

        input.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const item = rows()[activeIndex];
                if (item) {
                    choose(item.getAttribute('data-id'), item.textContent);
                }
            } else if (event.key === 'Escape') {
                close();
                toggle.focus();
            }
        });

        document.addEventListener('click', function(event) {
            if (!panel.hidden && !container.contains(event.target)) {
                close();
            }
        });

        return {
            setSelected: function(id, name) {
                if (stringIds) {
                    selectedId = (id == null || id === '') ? '' : String(id);
                } else {
                    selectedId = Number(id) || 0;
                }
                valueEl.textContent = (!isEmpty(selectedId) && name) ? name : placeholder;
                container.classList.toggle('is-filtered', !isEmpty(selectedId));
            },
        };
    };

    const renderSiteTimespent = function(root, data) {
        const host = root.querySelector('[data-region="site-timespent-summary"]');
        const chart = root.querySelector('[data-region="site-timespent-chart"]');
        if (!host) {
            return;
        }
        if (!data || data.available === false) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'timespent-unavailable')) + '</p>';
            if (chart) {
                chart.innerHTML = '';
            }
            setKpiCard(root, 'timespent', formatMinutes(0), '');
            return;
        }
        host.innerHTML =
            '<div class="nxr-summary__primary">' +
                '<p class="nxr-summary__value">' + esc(formatMinutes(data.average)) + '</p>' +
                changePill(root, data.change) +
                '<p class="nxr-summary__label">' + esc(label(root, 'avg-timespent')) + '</p>' +
            '</div>' +
            '<div class="nxr-summary__side">' +
                esc(label(root, 'total-timespent')) + ': <strong>' +
                esc(formatMinutes(data.total)) + '</strong>' +
            '</div>';
        renderChart(chart, data.labels || [], [{
            key: 'timespent',
            label: label(root, 'series-timespent'),
            color: '#1d4ed8',
            values: data.values || [],
            format: 'minutes',
        }]);
        setKpiCard(root, 'timespent', esc(formatMinutes(data.total)), changePill(root, data.change));
    };

    const renderCourseTimespent = function(root, data) {
        const host = root.querySelector('[data-region="course-timespent-summary"]');
        const chart = root.querySelector('[data-region="course-timespent-chart"]');
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
                '<p class="nxr-summary__value">' + esc(formatMinutes(data.courseaverage)) + '</p>' +
                changePill(root, data.coursechange) +
                '<p class="nxr-summary__label">' + esc(label(root, 'average-course-time')) + '</p>' +
            '</div>' +
            '<div class="nxr-summary__side">' +
                esc(label(root, 'total-timespent')) + ': <strong>' +
                esc(formatMinutes(data.coursetotal)) + '</strong>' +
            '</div>';
        renderBarChart(chart, data.courselabels || [], data.coursevalues || []);
    };

    const renderActivityStatus = function(root, data) {
        const host = root.querySelector('[data-region="activity-status-summary"]');
        const chart = root.querySelector('[data-region="activity-status-chart"]');
        if (!host || !data) {
            return;
        }
        host.innerHTML =
            '<div class="nxr-summary__primary">' +
                '<p class="nxr-summary__value">' + esc(String(data.average)) + '</p>' +
                changePill(root, data.change) +
                '<p class="nxr-summary__label">' + esc(label(root, 'avg-activity')) + '</p>' +
            '</div>' +
            '<div class="nxr-summary__side">' +
                esc(label(root, 'total-assignments')) + ': <strong>' +
                esc(String(data.totalsubmissions)) + '</strong><br>' +
                esc(label(root, 'total-activity-completed')) + ': <strong>' +
                esc(String(data.totalcompletions)) + '</strong>' +
            '</div>';
        if (!chart) {
            return;
        }
        const mount = document.createElement('div');
        mount.innerHTML =
            '<div class="nxr-legend">' +
                '<span class="nxr-legend__item" style="color:#2563eb"><span class="nxr-legend__dot"></span>' +
                esc(label(root, 'series-assignments')) + '</span>' +
                '<span class="nxr-legend__item" style="color:#1e3a8a"><span class="nxr-legend__dot"></span>' +
                esc(label(root, 'series-activities-completed')) + '</span>' +
            '</div>';
        const svgHost = document.createElement('div');
        svgHost.className = 'nxr-chart';
        chart.innerHTML = '';
        chart.appendChild(mount.firstChild);
        chart.appendChild(svgHost);
        renderChart(svgHost, data.labels || [], [
            {
                key: 'submissions',
                label: label(root, 'series-assignments'),
                color: '#2563eb',
                values: data.submissions || [],
            },
            {
                key: 'completions',
                label: label(root, 'series-activities-completed'),
                color: '#1e3a8a',
                values: data.completions || [],
            },
        ]);
    };

    const renderPopular = function(root, courses, query) {
        const host = root.querySelector('[data-region="popular-courses"]');
        if (!host) {
            return;
        }
        const q = (query || '').toLowerCase();
        const rows = (courses || []).filter(function(c) {
            return !q || String(c.name || '').toLowerCase().indexOf(q) !== -1;
        });
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'nodata')) + '</p>';
            return;
        }
        host.innerHTML =
            '<div class="nxr-table-wrap"><table class="nxr-table"><thead><tr>' +
            '<th>' + esc(label(root, 'rank')) + '</th>' +
            '<th>' + esc(label(root, 'coursename')) + '</th>' +
            '<th>' + esc(label(root, 'enrolments-col')) + '</th>' +
            '</tr></thead><tbody>' +
            rows.map(function(c) {
                const trophy = c.rank <= 3 ?
                    '<span class="nxr-trophy nxr-trophy--' + c.rank + '" aria-hidden="true"></span>' : '';
                return '<tr><td><span class="nxr-rank">' + trophy + esc(c.rank) +
                    '</span></td><td><a href="' + esc(c.url) + '">' + esc(c.name) +
                    '</a></td><td>' + esc(c.enrolments) + '</td></tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    const renderRealtime = function(root, users, query) {
        const host = root.querySelector('[data-region="realtime-users"]');
        if (!host) {
            return;
        }
        const q = (query || '').toLowerCase();
        const rows = (users || []).filter(function(u) {
            return !q || String(u.fullname || '').toLowerCase().indexOf(q) !== -1;
        });
        if (!rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'nodata')) + '</p>';
            return;
        }
        host.innerHTML =
            '<div class="nxr-table-wrap"><table class="nxr-table"><thead><tr>' +
            '<th>' + esc(label(root, 'fullname')) + '</th>' +
            '<th>' + esc(label(root, 'onlinesince')) + '</th>' +
            '<th>' + esc(label(root, 'status')) + '</th>' +
            '</tr></thead><tbody>' +
            rows.map(function(u) {
                const statusClass = u.active ? 'is-active' : 'is-inactive';
                const statusText = u.active ? label(root, 'active') : label(root, 'inactive');
                return '<tr><td><a href="' + esc(u.url) + '">' + esc(u.fullname) +
                    '</a></td><td>' + esc(u.onlinesince) +
                    '</td><td><span class="nxr-status ' + statusClass + '">' +
                    esc(statusText) + '</span></td></tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    const DONUT_COLORS = ['#1d4ed8', '#2563eb', '#38bdf8', '#64748b', '#94a3b8'];

    /**
     * SVG donut with legend for course progress buckets.
     *
     * @param {Element} host
     * @param {Array} buckets
     */
    const renderDonut = function(host, buckets) {
        if (!host) {
            return;
        }
        const total = (buckets || []).reduce(function(sum, b) {
            return sum + (Number(b.count) || 0);
        }, 0);
        if (!total) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(host.closest('[data-region="nxr-overview"]'), 'nodata')) + '</p>';
            return;
        }

        const size = 200;
        const cx = size / 2;
        const cy = size / 2;
        const radius = 72;
        const stroke = 28;
        const circumference = 2 * Math.PI * radius;
        let offset = 0;
        let arcs = '';

        buckets.forEach(function(bucket, i) {
            const value = Number(bucket.count) || 0;
            if (!value) {
                return;
            }
            const length = (value / total) * circumference;
            const color = DONUT_COLORS[i % DONUT_COLORS.length];
            arcs += '<circle class="nxr-donut__arc" data-index="' + i + '" cx="' + cx + '" cy="' + cy +
                '" r="' + radius + '" fill="none" stroke="' + color + '" stroke-width="' + stroke +
                '" stroke-dasharray="' + length.toFixed(2) + ' ' + (circumference - length).toFixed(2) +
                '" stroke-dashoffset="' + (-offset).toFixed(2) +
                '" transform="rotate(-90 ' + cx + ' ' + cy + ')"></circle>';
            offset += length;
        });

        const overview = host.closest('[data-region="nxr-overview"]');
        const legend = buckets.map(function(bucket, i) {
            return '<li class="nxr-donut__legend-item">' +
                '<span class="nxr-donut__swatch" style="background:' + DONUT_COLORS[i % DONUT_COLORS.length] +
                '"></span><span class="nxr-donut__legend-label">' + esc(bucket.label) +
                '</span><span class="nxr-donut__legend-count">' + esc(String(bucket.count)) + '</span></li>';
        }).join('');

        host.innerHTML =
            '<div class="nxr-donut__chart">' +
                '<svg viewBox="0 0 ' + size + ' ' + size + '" role="img">' + arcs + '</svg>' +
                '<div class="nxr-donut__tip" role="status"></div>' +
            '</div>' +
            '<ul class="nxr-donut__legend">' + legend + '</ul>';

        const tip = host.querySelector('.nxr-donut__tip');
        host.querySelectorAll('.nxr-donut__arc').forEach(function(arc) {
            const show = function() {
                const index = parseInt(arc.getAttribute('data-index'), 10);
                const bucket = buckets[index];
                if (!bucket) {
                    return;
                }
                const noun = Number(bucket.count) === 1
                    ? label(overview, 'progress-student')
                    : label(overview, 'progress-students');
                tip.innerHTML = '<strong>' + esc(bucket.label) + '</strong>' +
                    esc(String(bucket.count) + ' ' + noun);
                host.classList.add('is-hovering');
            };
            arc.addEventListener('pointerenter', show);
            arc.addEventListener('pointermove', show);
        });
        host.onpointerleave = function() {
            host.classList.remove('is-hovering');
        };
    };

    const renderCourseProgress = function(root, data) {
        const summary = root.querySelector('[data-region="course-progress-summary"]');
        const chart = root.querySelector('[data-region="course-progress-chart"]');
        if (!summary || !chart) {
            return;
        }
        if (!data || !data.selectedcourseid) {
            summary.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'no-course-progress')) + '</p>';
            chart.innerHTML = '';
            return;
        }
        // Match Edwiser: Number#toPrecision(2) significant figures (e.g. 9.523 → "9.5").
        let averageText = '0';
        const averageNum = Number(data.average);
        if (isFinite(averageNum) && averageNum !== 0) {
            averageText = averageNum.toPrecision(2);
        }
        summary.innerHTML =
            '<div class="nxr-summary__primary">' +
                '<p class="nxr-summary__value">' + esc(averageText) + '%</p>' +
                '<p class="nxr-summary__label">' + esc(label(root, 'average-course-progress')) + '</p>' +
            '</div>';
        renderDonut(chart, data.buckets || []);
    };

    const renderDailyActivity = function(root, data) {
        const summary = root.querySelector('[data-region="daily-summary"]');
        const chart = root.querySelector('[data-region="daily-chart"]');
        if (!summary || !chart) {
            return;
        }
        const cards = [
            ['daily-registrations', data.registrations],
            ['daily-enrolments', data.enrolments],
            ['daily-completions', data.coursecompletions],
            ['daily-activitycompletions', data.activitycompletions],
            ['daily-visits', data.visits],
            ['daily-onlinelearners', data.onlinelearners],
            ['daily-onlineteachers', data.onlineteachers],
        ];
        summary.innerHTML =
            '<div class="nxr-summary__side nxr-summary__side--grid">' +
            cards.map(function(item) {
                return '<div class="nxr-stat">' +
                    '<span class="nxr-stat__value">' + esc(String(item[1] || 0)) + '</span>' +
                    '<span class="nxr-stat__label">' + esc(label(root, item[0])) + '</span>' +
                    '</div>';
            }).join('') +
            '</div>';
        renderBarChart(chart, data.labels || [], data.visitsbyhour || [], {
            tipLabel: 'series-visits',
            format: 'count',
        });
    };

    const renderInactiveUsers = function(root, rows) {
        const host = root.querySelector('[data-region="inactive-users"]');
        if (!host) {
            return;
        }
        if (!rows || !rows.length) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'nodata')) + '</p>';
            return;
        }
        host.innerHTML =
            '<div class="nxr-table-wrap"><table class="nxr-table"><thead><tr>' +
            '<th>' + esc(label(root, 'rank')) + '</th>' +
            '<th>' + esc(label(root, 'fullname')) + '</th>' +
            '<th>' + esc(label(root, 'email')) + '</th>' +
            '<th>' + esc(label(root, 'lastaccess')) + '</th>' +
            '</tr></thead><tbody>' +
            rows.map(function(u) {
                return '<tr><td>' + esc(u.rank) + '</td><td><a href="' + esc(u.url) + '">' +
                    esc(u.fullname) + '</a></td><td>' + esc(u.email) +
                    '</td><td>' + esc(u.lastaccess) + '</td></tr>';
            }).join('') +
            '</tbody></table></div>';
    };

    /**
     * Colleges list + department breakdown (dropdown).
     *
     * @param {Element} root
     * @param {Object} data
     */
    const renderLearnerHeadcount = function(root, data) {
        if (!data) {
            return;
        }
        headcountCache = data;
        const institutions = data.institutions || [];

        const totalEl = root.querySelector('[data-region="headcount-total"]');
        if (totalEl) {
            totalEl.textContent = String(data.totalstudents || 0);
        }

        const listHost = root.querySelector('[data-region="colleges-list"]');
        if (listHost) {
            if (!institutions.length) {
                listHost.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'nodata')) + '</p>';
            } else {
                listHost.innerHTML = '<ul class="nxr-college-list">' + institutions.map(function(inst) {
                    return '<li class="nxr-college-list__item">' +
                        '<span class="nxr-college-list__name">' + esc(inst.name) + '</span>' +
                        '<span class="nxr-college-list__count">' + esc(String(inst.count)) + '</span>' +
                        '</li>';
                }).join('') + '</ul>';
            }
        }

        const select = root.querySelector('[data-region="college-select"]');
        if (select) {
            const prev = select.value;
            select.innerHTML = '<option value="">' + esc(label(root, 'select-college')) + '</option>' +
                institutions.map(function(inst) {
                    return '<option value="' + esc(inst.name) + '">' +
                        esc(inst.name) + ' (' + esc(String(inst.count)) + ')</option>';
                }).join('');
            if (prev && institutions.some(function(i) { return i.name === prev; })) {
                select.value = prev;
            } else if (institutions.length) {
                select.value = institutions[0].name;
            }
            renderDepartmentsForCollege(root, select.value);
        }
    };

    /**
     * @param {Element} root
     * @param {string} collegeName
     */
    const renderDepartmentsForCollege = function(root, collegeName) {
        const host = root.querySelector('[data-region="departments-list"]');
        if (!host) {
            return;
        }
        const data = headcountCache;
        if (!collegeName || !data || !data.institutions) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'select-college-hint')) + '</p>';
            return;
        }
        const inst = (data.institutions || []).find(function(i) {
            return i.name === collegeName;
        });
        if (!inst) {
            host.innerHTML = '<p class="nxr-empty">' + esc(label(root, 'nodata')) + '</p>';
            return;
        }
        const depts = inst.departments || [];
        const years = inst.years || [];
        let html = '';

        html += '<h3 class="nxr-headcount__subtitle">' + esc(label(root, 'department')) + '</h3>';
        if (!depts.length) {
            html += '<p class="nxr-empty">' + esc(label(root, 'no-departments')) + '</p>';
        } else {
            html += '<table class="nxr-dept-table"><thead><tr>' +
                '<th>' + esc(label(root, 'department')) + '</th>' +
                '<th class="nxr-num">' + esc(label(root, 'students-count')) + '</th>' +
                '</tr></thead><tbody>' +
                depts.map(function(dept) {
                    return '<tr><td>' + esc(dept.name) + '</td>' +
                        '<td class="nxr-num">' + esc(String(dept.count)) + '</td></tr>';
                }).join('') +
                '</tbody></table>';
        }

        html += '<h3 class="nxr-headcount__subtitle">' + esc(label(root, 'year-of-passing')) + '</h3>';
        if (!years.length) {
            html += '<p class="nxr-empty">' + esc(label(root, 'no-years')) + '</p>';
        } else {
            html += '<table class="nxr-dept-table"><thead><tr>' +
                '<th>' + esc(label(root, 'year-of-passing')) + '</th>' +
                '<th class="nxr-num">' + esc(label(root, 'students-count')) + '</th>' +
                '</tr></thead><tbody>' +
                years.map(function(year) {
                    return '<tr><td>' + esc(year.name) + '</td>' +
                        '<td class="nxr-num">' + esc(String(year.count)) + '</td></tr>';
                }).join('') +
                '</tbody></table>';
        }

        // Optional detail: department × year when useful.
        const nested = [];
        depts.forEach(function(dept) {
            (dept.years || []).forEach(function(year) {
                nested.push({
                    department: dept.name,
                    year: year.name,
                    count: year.count
                });
            });
        });
        if (nested.length > 1 && depts.length > 1) {
            html += '<h3 class="nxr-headcount__subtitle">' + esc(label(root, 'dept-by-year')) + '</h3>';
            html += '<table class="nxr-dept-table"><thead><tr>' +
                '<th>' + esc(label(root, 'department')) + '</th>' +
                '<th>' + esc(label(root, 'year-of-passing')) + '</th>' +
                '<th class="nxr-num">' + esc(label(root, 'students-count')) + '</th>' +
                '</tr></thead><tbody>' +
                nested.map(function(row) {
                    return '<tr><td>' + esc(row.department) + '</td><td>' + esc(row.year) +
                        '</td><td class="nxr-num">' + esc(String(row.count)) + '</td></tr>';
                }).join('') +
                '</tbody></table>';
        }

        host.innerHTML = html;
    };

    const syncInactiveExport = function(root, months, search) {
        const wrap = root.querySelector('[data-region="table-export"][data-region-inactive-export], [data-region-inactive-export="1"]')
            || root.querySelector('[data-region="table-export"]');
        const link = root.querySelector('[data-region="inactive-export"]');
        const base = link ? link.href : (wrap && wrap.querySelector('[data-export-format]')
            ? wrap.querySelector('[data-export-format]').href : '');
        if (!base) {
            return;
        }
        const url = new URL(base, window.location.origin);
        url.searchParams.set('report', 'inactive_users');
        url.searchParams.set('months', String(months));
        url.searchParams.set('search', search || '');
        const scope = wrap || root;
        scope.querySelectorAll('[data-export-format]').forEach(function(a) {
            const u = new URL(url.toString());
            u.searchParams.set('format', a.getAttribute('data-export-format') || 'csv');
            a.href = u.pathname + u.search;
        });
    };

    const localDateValue = function(uts) {
        const d = uts ? new Date(uts * 1000) : new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    };

    const dayStartFromInput = function(value) {
        if (!value) {
            return 0;
        }
        const parts = String(value).split('-');
        if (parts.length !== 3) {
            return 0;
        }
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]), 0, 0, 0, 0);
        return Math.floor(d.getTime() / 1000);
    };

    const valOf = function(root, selector) {
        const el = root.querySelector(selector);
        return el ? el.value : '';
    };

    const putSkeleton = function(root, selector, modifier) {
        const el = root.querySelector(selector);
        if (el) {
            el.innerHTML = '<div class="nxr-skeleton ' + (modifier || 'nxr-skeleton--block') + '"></div>';
        }
    };

    const showBlockError = function(root, selectors) {
        selectors.forEach(function(selector) {
            const el = root.querySelector(selector);
            if (el) {
                el.innerHTML = '<p class="nxr-error">' + esc(label(root, 'loaderror')) + '</p>';
            }
        });
    };

    const init = function(config) {
        const root = document.querySelector('[data-region="nxr-overview"]');
        if (!root) {
            return;
        }

        const institutionAdmin = !!(config && config.institutionAdmin);
        let days = (config && config.defaultDays === 30) ? 30 : 7;
        const req = {
            summary: 0,
            site: 0,
            course: 0,
            daily: 0,
            inactive: 0,
            progress: 0,
            visits: 0,
            activity: 0,
            headcount: 0,
        };
        const combos = {};
        const state = {
            popularcourses: [],
            realtimeusers: [],
            siteuserid: 0,
            siteyear: '',
            sitedepartment: '',
            visitsuserid: 0,
            courseuserid: 0,
            courseid: 0,
            coursegroupid: 0,
            courseyear: '',
            coursedepartment: '',
            activitycourseid: 0,
            activitygroupid: 0,
            activityuserid: 0,
            activityyear: '',
            activitydepartment: '',
            progresscourseid: 0,
            progressgroupid: 0,
            progressyear: '',
            progressdepartment: '',
            inactivemonths: 1,
            inactivesearch: '',
            daystart: 0,
        };

        const call = function(method, args) {
            return Ajax.call([{methodname: method, args: args}])[0];
        };

        const loadSummary = function() {
            const id = ++req.summary;
            buildKpiSkeleton(root);
            putSkeleton(root, '[data-region="overview-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="overview-chart"]', 'nxr-skeleton--chart');
            putSkeleton(root, '[data-region="popular-courses"]', 'nxr-skeleton--table');
            putSkeleton(root, '[data-region="realtime-users"]', 'nxr-skeleton--table');
            call('local_nexreports_get_summary', {days: days}).then(function(data) {
                if (id !== req.summary) {
                    return null;
                }
                state.popularcourses = data.popularcourses || [];
                state.realtimeusers = data.realtimeusers || [];
                renderKpis(root, data.kpis || []);
                renderOverviewSummary(root, data.overview);
                renderPopular(root, state.popularcourses, valOf(root, '[data-filter="courses"]'));
                renderRealtime(root, state.realtimeusers, valOf(root, '[data-filter="users"]'));
                return null;
            }).catch(function() {
                if (id !== req.summary) {
                    return;
                }
                ['registrations', 'enrolments', 'completions', 'activeusers',
                    'totalyears', 'totaldepartments', 'totalstudents'].forEach(function(key) {
                    setKpiCard(root, key, '&mdash;', '');
                });
                showBlockError(root, [
                    '[data-region="overview-summary"]',
                    '[data-region="overview-chart"]',
                    '[data-region="popular-courses"]',
                    '[data-region="realtime-users"]',
                ]);
            });
        };

        const loadVisits = function() {
            const id = ++req.visits;
            putSkeleton(root, '[data-region="visits-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="visits-chart"]', 'nxr-skeleton--chart');
            call('local_nexreports_get_visits_site', {
                days: days,
                userid: state.visitsuserid,
            }).then(function(data) {
                if (id !== req.visits) {
                    return null;
                }
                state.visitsuserid = data.selecteduserid || 0;
                renderVisitsSummary(root, data);
                if (combos.visitsuser) {
                    combos.visitsuser.setSelected(data.selecteduserid, data.selectedusername);
                }
                return null;
            }).catch(function() {
                if (id !== req.visits) {
                    return;
                }
                showBlockError(root, [
                    '[data-region="visits-summary"]',
                    '[data-region="visits-chart"]',
                ]);
            });
        };

        const loadSite = function() {
            const id = ++req.site;
            setKpiCard(root, 'timespent', '<span class="nxr-skeleton nxr-skeleton--value"></span>', '');
            putSkeleton(root, '[data-region="site-timespent-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="site-timespent-chart"]', 'nxr-skeleton--chart');
            call('local_nexreports_get_timespent_site', {
                days: days,
                userid: state.siteuserid,
                year: state.siteyear || '',
                department: state.sitedepartment || '',
            }).then(function(data) {
                if (id !== req.site) {
                    return null;
                }
                state.siteyear = data.selectedyear || '';
                state.sitedepartment = data.selecteddepartment || '';
                renderSiteTimespent(root, data);
                if (combos.siteyear) {
                    combos.siteyear.setSelected(data.selectedyear, data.selectedyear);
                }
                if (combos.sitedepartment) {
                    combos.sitedepartment.setSelected(data.selecteddepartment, data.selecteddepartment);
                }
                if (combos.siteuser) {
                    combos.siteuser.setSelected(data.selecteduserid, data.selectedusername);
                }
                return null;
            }).catch(function() {
                if (id !== req.site) {
                    return;
                }
                setKpiCard(root, 'timespent', '&mdash;', '');
                showBlockError(root, [
                    '[data-region="site-timespent-summary"]',
                    '[data-region="site-timespent-chart"]',
                ]);
            });
        };

        const loadCourse = function() {
            const id = ++req.course;
            putSkeleton(root, '[data-region="course-timespent-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="course-timespent-chart"]', 'nxr-skeleton--chart');
            call('local_nexreports_get_timespent_course', {
                days: days,
                courseid: state.courseid,
                groupid: institutionAdmin ? 0 : state.coursegroupid,
                userid: state.courseuserid,
                year: state.courseyear || '',
                department: state.coursedepartment || '',
            }).then(function(data) {
                if (id !== req.course) {
                    return null;
                }
                state.courseyear = data.selectedyear || '';
                state.coursedepartment = data.selecteddepartment || '';
                renderCourseTimespent(root, data);
                if (combos.course) {
                    combos.course.setSelected(data.selectedcourseid, data.selectedcoursename);
                }
                if (combos.coursegroup) {
                    combos.coursegroup.setSelected(data.selectedgroupid, data.selectedgroupname);
                }
                if (combos.courseyear) {
                    combos.courseyear.setSelected(data.selectedyear, data.selectedyear);
                }
                if (combos.coursedepartment) {
                    combos.coursedepartment.setSelected(data.selecteddepartment, data.selecteddepartment);
                }
                if (combos.courseuser) {
                    combos.courseuser.setSelected(data.selecteduserid, data.selectedusername);
                }
                return null;
            }).catch(function() {
                if (id !== req.course) {
                    return;
                }
                showBlockError(root, [
                    '[data-region="course-timespent-summary"]',
                    '[data-region="course-timespent-chart"]',
                ]);
            });
        };

        const loadDaily = function() {
            const id = ++req.daily;
            putSkeleton(root, '[data-region="daily-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="daily-chart"]', 'nxr-skeleton--chart');
            call('local_nexreports_get_daily_activity', {
                daystart: state.daystart,
            }).then(function(data) {
                if (id !== req.daily) {
                    return null;
                }
                if (data.daystart) {
                    state.daystart = data.daystart;
                    const dayInput = root.querySelector('[data-filter="activity-day"]');
                    if (dayInput && !dayInput.value) {
                        dayInput.value = localDateValue(data.daystart);
                    }
                }
                renderDailyActivity(root, data);
                return null;
            }).catch(function() {
                if (id !== req.daily) {
                    return;
                }
                showBlockError(root, [
                    '[data-region="daily-summary"]',
                    '[data-region="daily-chart"]',
                ]);
            });
        };

        const loadInactive = function() {
            const id = ++req.inactive;
            putSkeleton(root, '[data-region="inactive-users"]', 'nxr-skeleton--table');
            syncInactiveExport(root, state.inactivemonths, state.inactivesearch);
            call('local_nexreports_get_inactive_users', {
                months: state.inactivemonths,
                search: state.inactivesearch,
                limit: 100,
            }).then(function(data) {
                if (id !== req.inactive) {
                    return null;
                }
                renderInactiveUsers(root, data.rows || []);
                return null;
            }).catch(function() {
                if (id !== req.inactive) {
                    return;
                }
                showBlockError(root, ['[data-region="inactive-users"]']);
            });
        };

        const loadProgress = function() {
            const id = ++req.progress;
            putSkeleton(root, '[data-region="course-progress-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="course-progress-chart"]', 'nxr-skeleton--chart');
            call('local_nexreports_get_course_progress', {
                courseid: state.progresscourseid,
                groupid: institutionAdmin ? 0 : state.progressgroupid,
                year: state.progressyear || '',
                department: state.progressdepartment || '',
            }).then(function(data) {
                if (id !== req.progress) {
                    return null;
                }
                state.progresscourseid = data.selectedcourseid || 0;
                state.progressgroupid = data.selectedgroupid || 0;
                state.progressyear = data.selectedyear || '';
                state.progressdepartment = data.selecteddepartment || '';
                renderCourseProgress(root, data);
                if (combos.progresscourse) {
                    combos.progresscourse.setSelected(data.selectedcourseid, data.selectedcoursename);
                }
                if (combos.progressgroup) {
                    combos.progressgroup.setSelected(data.selectedgroupid, data.selectedgroupname);
                }
                if (combos.progressyear) {
                    combos.progressyear.setSelected(data.selectedyear, data.selectedyear);
                }
                if (combos.progressdepartment) {
                    combos.progressdepartment.setSelected(data.selecteddepartment, data.selecteddepartment);
                }
                return null;
            }).catch(function() {
                if (id !== req.progress) {
                    return;
                }
                showBlockError(root, [
                    '[data-region="course-progress-summary"]',
                    '[data-region="course-progress-chart"]',
                ]);
            });
        };

        const loadActivity = function() {
            const id = ++req.activity;
            putSkeleton(root, '[data-region="activity-status-summary"]', 'nxr-skeleton--strip');
            putSkeleton(root, '[data-region="activity-status-chart"]', 'nxr-skeleton--chart');
            call('local_nexreports_get_activity_status', {
                days: days,
                courseid: state.activitycourseid,
                groupid: institutionAdmin ? 0 : state.activitygroupid,
                userid: state.activityuserid,
                year: state.activityyear || '',
                department: state.activitydepartment || '',
            }).then(function(data) {
                if (id !== req.activity) {
                    return null;
                }
                state.activitycourseid = data.selectedcourseid || 0;
                state.activitygroupid = data.selectedgroupid || 0;
                state.activityuserid = data.selecteduserid || 0;
                state.activityyear = data.selectedyear || '';
                state.activitydepartment = data.selecteddepartment || '';
                renderActivityStatus(root, data);
                if (combos.activitycourse) {
                    combos.activitycourse.setSelected(data.selectedcourseid, data.selectedcoursename);
                }
                if (combos.activitygroup) {
                    combos.activitygroup.setSelected(data.selectedgroupid, data.selectedgroupname);
                }
                if (combos.activityyear) {
                    combos.activityyear.setSelected(data.selectedyear, data.selectedyear);
                }
                if (combos.activitydepartment) {
                    combos.activitydepartment.setSelected(data.selecteddepartment, data.selecteddepartment);
                }
                if (combos.activityuser) {
                    combos.activityuser.setSelected(data.selecteduserid, data.selectedusername);
                }
                return null;
            }).catch(function() {
                if (id !== req.activity) {
                    return;
                }
                showBlockError(root, [
                    '[data-region="activity-status-summary"]',
                    '[data-region="activity-status-chart"]',
                ]);
            });
        };

        const loadHeadcount = function() {
            const id = ++req.headcount;
            const listHost = root.querySelector('[data-region="colleges-list"]');
            if (listHost && !listHost.querySelector('.nxr-college-list, .nxr-empty')) {
                putSkeleton(root, '[data-region="colleges-list"]', 'nxr-skeleton--table');
            }
            call('local_nexreports_get_learner_headcount', {}).then(function(data) {
                if (id !== req.headcount) {
                    return null;
                }
                renderLearnerHeadcount(root, data);
                return null;
            }).catch(function() {
                if (id !== req.headcount) {
                    return;
                }
                if (listHost && listHost.querySelector('.nxr-college-list')) {
                    return;
                }
                showBlockError(root, ['[data-region="colleges-list"]', '[data-region="departments-list"]']);
            });
        };

        const loadAll = function() {
            loadSummary();
            loadHeadcount();
            loadVisits();
            loadSite();
            loadCourse();
            loadActivity();
            loadDaily();
            loadInactive();
            loadProgress();
        };

        root.querySelectorAll('[data-days]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                days = parseInt(btn.getAttribute('data-days'), 10) === 30 ? 30 : 7;
                root.querySelectorAll('[data-days]').forEach(function(el) {
                    el.classList.toggle('is-active', el === btn);
                });
                loadAll();
            });
        });

        const courseFilter = root.querySelector('[data-filter="courses"]');
        const userFilter = root.querySelector('[data-filter="users"]');
        if (courseFilter) {
            courseFilter.addEventListener('input', function() {
                renderPopular(root, state.popularcourses, courseFilter.value);
            });
        }
        if (userFilter) {
            userFilter.addEventListener('input', function() {
                renderRealtime(root, state.realtimeusers, userFilter.value);
            });
        }

        const dayInput = root.querySelector('[data-filter="activity-day"]');
        if (dayInput) {
            if (!dayInput.value) {
                dayInput.value = localDateValue();
            }
            state.daystart = dayStartFromInput(dayInput.value);
            dayInput.addEventListener('change', function() {
                state.daystart = dayStartFromInput(dayInput.value);
                loadDaily();
            });
        }

        const collegeSelect = root.querySelector('[data-filter="college-select"]');
        if (collegeSelect) {
            // Bootstrap cache from server-rendered JSON so dropdown works before AJAX.
            const deptBlock = root.querySelector('[data-region="departments-block"]');
            const raw = deptBlock && deptBlock.getAttribute('data-headcount-json');
            if (raw && !headcountCache) {
                try {
                    headcountCache = JSON.parse(raw);
                } catch (e) {
                    headcountCache = null;
                }
            }
            collegeSelect.addEventListener('change', function() {
                renderDepartmentsForCollege(root, collegeSelect.value || '');
            });
        }

        const inactiveMonths = root.querySelector('[data-filter="inactive-months"]');
        if (inactiveMonths) {
            state.inactivemonths = parseInt(inactiveMonths.value, 10) || 1;
            inactiveMonths.addEventListener('change', function() {
                state.inactivemonths = parseInt(inactiveMonths.value, 10) || 1;
                loadInactive();
            });
        }

        let inactiveTimer = null;
        const inactiveSearch = root.querySelector('[data-filter="inactive-search"]');
        if (inactiveSearch) {
            inactiveSearch.addEventListener('input', function() {
                window.clearTimeout(inactiveTimer);
                inactiveTimer = window.setTimeout(function() {
                    state.inactivesearch = inactiveSearch.value || '';
                    loadInactive();
                }, 300);
            });
        }

        const searchOptions = function(type, query, courseid, groupid, year, department) {
            return call('local_nexreports_search_options', {
                type: type,
                query: query || '',
                limit: 20,
                courseid: courseid || 0,
                groupid: groupid || 0,
                year: year || '',
                department: department || '',
            }).then(function(response) {
                return response.options || [];
            });
        };

        const combo = function(name, onSelect, context, guard) {
            const container = root.querySelector('[data-combo="' + name + '"]');
            if (!container) {
                return null;
            }
            const type = container.getAttribute('data-type') || 'user';
            return createCombo(root, container, function(query) {
                const scope = context ? context() : {};
                return searchOptions(
                    type,
                    query,
                    scope.courseid,
                    scope.groupid,
                    scope.year,
                    scope.department
                );
            }, onSelect, guard);
        };

        const needsCourse = function() {
            return state.courseid ? '' : label(root, 'select-course-first');
        };

        const needsProgressCourse = function() {
            return state.progresscourseid ? '' : label(root, 'select-course-first');
        };

        const needsActivityCourse = function() {
            return state.activitycourseid ? '' : label(root, 'select-course-first');
        };

        const needsYear = function(year) {
            return year ? '' : label(root, 'select-year-first');
        };

        if (institutionAdmin) {
            combos.siteyear = combo('site-year', function(id) {
                state.siteyear = id;
                state.sitedepartment = '';
                state.siteuserid = 0;
                if (combos.sitedepartment) {
                    combos.sitedepartment.setSelected('', '');
                }
                if (combos.siteuser) {
                    combos.siteuser.setSelected(0, '');
                }
                loadSite();
            });
            combos.sitedepartment = combo('site-department', function(id) {
                state.sitedepartment = id;
                state.siteuserid = 0;
                if (combos.siteuser) {
                    combos.siteuser.setSelected(0, '');
                }
                loadSite();
            }, function() {
                return {year: state.siteyear};
            }, function() {
                return needsYear(state.siteyear);
            });
            combos.siteuser = combo('timespent-user', function(id) {
                state.siteuserid = id;
                loadSite();
            }, function() {
                return {year: state.siteyear, department: state.sitedepartment};
            });
        } else {
            combos.siteuser = combo('timespent-user', function(id) {
                state.siteuserid = id;
                loadSite();
            });
        }
        combos.visitsuser = combo('visits-user', function(id) {
            state.visitsuserid = id;
            loadVisits();
        });
        combos.course = combo('timespent-course', function(id) {
            state.courseid = id;
            state.courseuserid = 0;
            if (institutionAdmin) {
                state.courseyear = '';
                state.coursedepartment = '';
                if (combos.courseyear) {
                    combos.courseyear.setSelected('', '');
                }
                if (combos.coursedepartment) {
                    combos.coursedepartment.setSelected('', '');
                }
            } else {
                state.coursegroupid = 0;
                if (combos.coursegroup) {
                    combos.coursegroup.setSelected(0, '');
                }
            }
            if (combos.courseuser) {
                combos.courseuser.setSelected(0, '');
            }
            loadCourse();
        });
        if (institutionAdmin) {
            combos.courseyear = combo('course-year', function(id) {
                state.courseyear = id;
                state.coursedepartment = '';
                state.courseuserid = 0;
                if (combos.coursedepartment) {
                    combos.coursedepartment.setSelected('', '');
                }
                if (combos.courseuser) {
                    combos.courseuser.setSelected(0, '');
                }
                loadCourse();
            }, function() {
                return {courseid: state.courseid};
            }, needsCourse);
            combos.coursedepartment = combo('course-department', function(id) {
                state.coursedepartment = id;
                state.courseuserid = 0;
                if (combos.courseuser) {
                    combos.courseuser.setSelected(0, '');
                }
                loadCourse();
            }, function() {
                return {courseid: state.courseid, year: state.courseyear};
            }, function() {
                return needsCourse() || needsYear(state.courseyear);
            });
            combos.courseuser = combo('course-user', function(id) {
                state.courseuserid = id;
                loadCourse();
            }, function() {
                return {
                    courseid: state.courseid,
                    year: state.courseyear,
                    department: state.coursedepartment,
                };
            }, needsCourse);
        } else {
            combos.coursegroup = combo('course-group', function(id) {
                state.coursegroupid = id;
                state.courseuserid = 0;
                if (combos.courseuser) {
                    combos.courseuser.setSelected(0, '');
                }
                loadCourse();
            }, function() {
                return {courseid: state.courseid};
            }, needsCourse);
            combos.courseuser = combo('course-user', function(id) {
                state.courseuserid = id;
                loadCourse();
            }, function() {
                return {courseid: state.courseid, groupid: state.coursegroupid};
            }, needsCourse);
        }

        combos.progresscourse = combo('progress-course', function(id) {
            state.progresscourseid = id;
            if (institutionAdmin) {
                state.progressyear = '';
                state.progressdepartment = '';
                if (combos.progressyear) {
                    combos.progressyear.setSelected('', '');
                }
                if (combos.progressdepartment) {
                    combos.progressdepartment.setSelected('', '');
                }
            } else {
                state.progressgroupid = 0;
                if (combos.progressgroup) {
                    combos.progressgroup.setSelected(0, '');
                }
            }
            loadProgress();
        });
        if (institutionAdmin) {
            combos.progressyear = combo('progress-year', function(id) {
                state.progressyear = id;
                state.progressdepartment = '';
                if (combos.progressdepartment) {
                    combos.progressdepartment.setSelected('', '');
                }
                loadProgress();
            }, function() {
                return {courseid: state.progresscourseid};
            }, needsProgressCourse);
            combos.progressdepartment = combo('progress-department', function(id) {
                state.progressdepartment = id;
                loadProgress();
            }, function() {
                return {courseid: state.progresscourseid, year: state.progressyear};
            }, function() {
                return needsProgressCourse() || needsYear(state.progressyear);
            });
        } else {
            combos.progressgroup = combo('progress-group', function(id) {
                state.progressgroupid = id;
                loadProgress();
            }, function() {
                return {courseid: state.progresscourseid};
            }, needsProgressCourse);
        }

        combos.activitycourse = combo('activity-course', function(id) {
            state.activitycourseid = id;
            state.activityuserid = 0;
            if (institutionAdmin) {
                state.activityyear = '';
                state.activitydepartment = '';
                if (combos.activityyear) {
                    combos.activityyear.setSelected('', '');
                }
                if (combos.activitydepartment) {
                    combos.activitydepartment.setSelected('', '');
                }
            } else {
                state.activitygroupid = 0;
                if (combos.activitygroup) {
                    combos.activitygroup.setSelected(0, '');
                }
            }
            if (combos.activityuser) {
                combos.activityuser.setSelected(0, '');
            }
            loadActivity();
        });
        if (institutionAdmin) {
            combos.activityyear = combo('activity-year', function(id) {
                state.activityyear = id;
                state.activitydepartment = '';
                state.activityuserid = 0;
                if (combos.activitydepartment) {
                    combos.activitydepartment.setSelected('', '');
                }
                if (combos.activityuser) {
                    combos.activityuser.setSelected(0, '');
                }
                loadActivity();
            }, function() {
                return {courseid: state.activitycourseid};
            }, needsActivityCourse);
            combos.activitydepartment = combo('activity-department', function(id) {
                state.activitydepartment = id;
                state.activityuserid = 0;
                if (combos.activityuser) {
                    combos.activityuser.setSelected(0, '');
                }
                loadActivity();
            }, function() {
                return {courseid: state.activitycourseid, year: state.activityyear};
            }, function() {
                return needsActivityCourse() || needsYear(state.activityyear);
            });
            combos.activityuser = combo('activity-user', function(id) {
                state.activityuserid = id;
                loadActivity();
            }, function() {
                return {
                    courseid: state.activitycourseid,
                    year: state.activityyear,
                    department: state.activitydepartment,
                };
            });
        } else {
            combos.activitygroup = combo('activity-group', function(id) {
                state.activitygroupid = id;
                state.activityuserid = 0;
                if (combos.activityuser) {
                    combos.activityuser.setSelected(0, '');
                }
                loadActivity();
            }, function() {
                return {courseid: state.activitycourseid};
            }, needsActivityCourse);
            combos.activityuser = combo('activity-user', function(id) {
                state.activityuserid = id;
                loadActivity();
            }, function() {
                return {courseid: state.activitycourseid, groupid: state.activitygroupid};
            });
        }

        wireChartExports(root);
        TableExport.bind(root);
        loadAll();
    };

    return {init: init};
});
