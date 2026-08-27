/**
 * NexBattleGround lobby — queue, challenge, recent battles.
 *
 * @module local_nexbattleground/lobby
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const PERPAGE = 8;

    let cfg = {};
    let timer = null;
    let state = {
        recentPage: 0,
        recentTotal: 0,
        recentPerpage: PERPAGE,
    };

    const call = function(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args || {}}])[0];
    };

    const battleHref = function(id) {
        const base = cfg.battleUrl || '';
        const sep = base.indexOf('?') >= 0 ? '&' : '?';
        return base + sep + 'id=' + encodeURIComponent(id);
    };

    const esc = function(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const initial = function(name) {
        const t = String(name || '').trim();
        return t ? t.charAt(0).toUpperCase() : '?';
    };

    const truncate = function(text, max) {
        const t = String(text || '').trim();
        if (t.length <= max) {
            return t;
        }
        return t.slice(0, max - 1).trim() + '…';
    };

    const relativeTime = function(ts) {
        const s = cfg.strings || {};
        const now = Math.floor(Date.now() / 1000);
        const t = ts | 0;
        if (!t) {
            return '';
        }
        const diff = Math.max(0, now - t);
        if (diff < 60) {
            return s.justnow || 'Just now';
        }
        if (diff < 3600) {
            const m = Math.floor(diff / 60);
            return (s.minutesago || '{n}m ago').replace('{n}', String(m));
        }
        if (diff < 86400) {
            const h = Math.floor(diff / 3600);
            return (s.hoursago || '{n}h ago').replace('{n}', String(h));
        }
        const d = Math.floor(diff / 86400);
        return (s.daysago || '{n}d ago').replace('{n}', String(d));
    };

    const difficultyLabel = function(diff) {
        const s = cfg.strings || {};
        const key = String(diff || '').toLowerCase();
        if (key === 'easy') {
            return s.easy || 'Easy';
        }
        if (key === 'medium') {
            return s.medium || 'Medium';
        }
        if (key === 'hard') {
            return s.hard || 'Hard';
        }
        if (key === 'veryhard') {
            return s.veryhard || 'Very hard';
        }
        return s.anydifficulty || 'Any';
    };

    const setQueued = function(root, queued, message) {
        const status = root.querySelector('[data-region="queue-status"]');
        const findBtn = root.querySelector('[data-action="find-match"]');
        const cancelBtn = root.querySelector('[data-action="cancel-queue"]');
        if (queued) {
            if (status) {
                status.hidden = false;
                status.textContent = message || (cfg.strings && cfg.strings.searching) || 'Searching…';
            }
            if (findBtn) {
                findBtn.disabled = true;
            }
            if (cancelBtn) {
                cancelBtn.hidden = false;
            }
        } else {
            if (status) {
                status.hidden = true;
                status.textContent = '';
            }
            if (findBtn) {
                findBtn.disabled = false;
            }
            if (cancelBtn) {
                cancelBtn.hidden = true;
            }
        }
    };

    const resultLabel = function(result) {
        const s = cfg.strings || {};
        if (result === 'win') {
            return s.win || 'Win';
        }
        if (result === 'loss') {
            return s.loss || 'Loss';
        }
        if (result === 'tie') {
            return s.tie || 'Tie';
        }
        return result || '';
    };

    const renderIncoming = function(root, items) {
        const wrap = root.querySelector('[data-region="incoming-wrap"]');
        const list = root.querySelector('[data-region="incoming"]');
        if (!wrap || !list) {
            return;
        }
        list.innerHTML = '';
        if (!items || !items.length) {
            wrap.hidden = true;
            return;
        }
        wrap.hidden = false;
        const s = cfg.strings || {};
        items.forEach(function(item) {
            const li = document.createElement('li');
            li.className = 'nbg-list__item nbg-incoming';
            const diff = String(item.difficulty || '').toLowerCase();
            li.innerHTML =
                '<div class="nbg-list__main">' +
                '<div class="nbg-recent__title">' +
                '<strong></strong>' +
                '<span class="nbg-diffchip' + (diff ? ' nbg-diffchip--' + esc(diff) : '') + '"></span>' +
                '</div>' +
                '<span class="nbg-muted"></span>' +
                '</div>' +
                '<div class="nbg-list__actions">' +
                '<button type="button" class="nbg-btn nbg-btn--primary" data-action="accept"></button>' +
                '<button type="button" class="nbg-btn nbg-btn--ghost" data-action="decline"></button>' +
                '</div>';
            li.querySelector('strong').textContent = item.from || '';
            li.querySelector('.nbg-diffchip').textContent = difficultyLabel(diff);
            li.querySelector('.nbg-muted').textContent =
                (s.challengedifficulty || 'Wants to battle · {diff}')
                    .replace('{diff}', difficultyLabel(diff));
            const accept = li.querySelector('[data-action="accept"]');
            const decline = li.querySelector('[data-action="decline"]');
            accept.textContent = s.accept || 'Accept';
            decline.textContent = s.decline || 'Decline';
            accept.addEventListener('click', function() {
                call('local_nexbattleground_respond_challenge', {
                    battleid: item.battleid,
                    accept: true
                }).then(function(res) {
                    if (res && res.battleid) {
                        window.location.href = battleHref(res.battleid);
                    }
                    return res;
                }).catch(Notification.exception);
            });
            decline.addEventListener('click', function() {
                call('local_nexbattleground_respond_challenge', {
                    battleid: item.battleid,
                    accept: false
                }).then(function() {
                    return poll(root);
                }).catch(Notification.exception);
            });
            list.appendChild(li);
        });
    };

    const pageWindow = function(current, pages) {
        if (pages <= 7) {
            const all = [];
            for (let i = 0; i < pages; i++) {
                all.push(i);
            }
            return all;
        }
        const set = {};
        [0, pages - 1, current, current - 1, current + 1].forEach(function(i) {
            if (i >= 0 && i < pages) {
                set[i] = true;
            }
        });
        return Object.keys(set).map(Number).sort(function(a, b) {
            return a - b;
        });
    };

    const renderPager = function(root, total, page, perpage) {
        const pager = root.querySelector('[data-region="recent-pager"]');
        const meta = root.querySelector('[data-region="recent-meta"]');
        const s = cfg.strings || {};
        if (!pager) {
            return;
        }
        const pages = Math.max(1, Math.ceil((total || 0) / perpage));
        if (meta) {
            if (!total) {
                meta.textContent = '';
            } else {
                const from = page * perpage + 1;
                const to = Math.min(total, (page + 1) * perpage);
                meta.textContent = (s.showingrange || 'Showing {from}–{to} of {total}')
                    .replace('{from}', String(from))
                    .replace('{to}', String(to))
                    .replace('{total}', String(total));
            }
        }
        if (!total || pages <= 1) {
            pager.hidden = true;
            pager.innerHTML = '';
            return;
        }
        const nums = pageWindow(page, pages);
        let controls = '<button type="button" class="nbg-pager__btn" data-page="' +
            (page - 1) + '" ' + (page <= 0 ? 'disabled' : '') + '>' +
            esc(s.prev || 'Prev') + '</button>';
        let prev = null;
        nums.forEach(function(n) {
            if (prev !== null && n > prev + 1) {
                controls += '<span class="nbg-pager__ellipsis" aria-hidden="true">…</span>';
            }
            controls += '<button type="button" class="nbg-pager__btn' +
                (n === page ? ' is-active' : '') + '" data-page="' + n + '"' +
                (n === page ? ' aria-current="page"' : '') + '>' + (n + 1) + '</button>';
            prev = n;
        });
        controls += '<button type="button" class="nbg-pager__btn" data-page="' +
            (page + 1) + '" ' + (page >= pages - 1 ? 'disabled' : '') + '>' +
            esc(s.next || 'Next') + '</button>';

        pager.hidden = false;
        pager.innerHTML =
            '<div class="nbg-pager__controls">' + controls + '</div>';
    };

    const renderRecent = function(root, items, total, page, perpage) {
        const list = root.querySelector('[data-region="recent"]');
        const empty = root.querySelector('[data-region="recent-empty"]');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        state.recentTotal = total | 0;
        state.recentPage = page | 0;
        state.recentPerpage = perpage || PERPAGE;

        if (!items || !items.length) {
            if (empty) {
                empty.hidden = false;
            }
            renderPager(root, 0, 0, state.recentPerpage);
            return;
        }
        if (empty) {
            empty.hidden = true;
        }
        items.forEach(function(item) {
            const li = document.createElement('li');
            li.className = 'nbg-list__item nbg-recent';
            const result = item.result || 'none';
            const opp = item.opponent || '';
            const problem = truncate(item.problemname || '', 72);
            const when = relativeTime(item.timefinish || item.timecreated);
            const diff = String(item.difficulty || '').toLowerCase();
            li.innerHTML =
                '<a class="nbg-list__link nbg-recent__link" href="' + esc(item.url || battleHref(item.battleid)) + '">' +
                '<span class="nbg-recent__avatar" aria-hidden="true"></span>' +
                '<div class="nbg-list__main">' +
                '<div class="nbg-recent__title">' +
                '<strong></strong>' +
                (diff ? '<span class="nbg-diffchip nbg-diffchip--' + esc(diff) + '"></span>' : '') +
                '</div>' +
                '<span class="nbg-muted nbg-recent__problem"></span>' +
                (when ? '<span class="nbg-recent__time"></span>' : '') +
                '</div>' +
                '<span class="nbg-badge nbg-badge--' + esc(result) + '"></span>' +
                '</a>';
            li.querySelector('.nbg-recent__avatar').textContent = initial(opp);
            li.querySelector('strong').textContent = 'vs ' + opp;
            const chip = li.querySelector('.nbg-diffchip');
            if (chip) {
                chip.textContent = difficultyLabel(diff);
            }
            li.querySelector('.nbg-recent__problem').textContent = problem;
            const timeEl = li.querySelector('.nbg-recent__time');
            if (timeEl) {
                timeEl.textContent = when;
            }
            li.querySelector('.nbg-badge').textContent = resultLabel(result);
            list.appendChild(li);
        });
        renderPager(root, state.recentTotal, state.recentPage, state.recentPerpage);
    };

    const showRoom = function(root, code, battleid, difficulty) {
        const panel = root.querySelector('[data-region="room-active"]');
        const codeEl = root.querySelector('[data-region="room-code"]');
        const diffEl = root.querySelector('[data-region="room-diff"]');
        if (!panel || !codeEl) {
            return;
        }
        if (code) {
            panel.hidden = false;
            codeEl.textContent = code;
            panel.dataset.battleid = battleid ? String(battleid) : '';
            if (diffEl) {
                const key = String(difficulty || '').toLowerCase();
                diffEl.hidden = false;
                diffEl.className = 'nbg-diffchip' + (key ? ' nbg-diffchip--' + key : '');
                diffEl.textContent = difficultyLabel(key);
            }
        } else {
            panel.hidden = true;
            codeEl.textContent = '';
            panel.dataset.battleid = '';
            if (diffEl) {
                diffEl.hidden = true;
                diffEl.textContent = '';
            }
        }
    };

    const showRoomPeek = function(root, info) {
        const peek = root.querySelector('[data-region="room-peek"]');
        if (!peek) {
            return;
        }
        const s = cfg.strings || {};
        if (!info || !info.found) {
            peek.hidden = true;
            peek.textContent = '';
            return;
        }
        const diff = difficultyLabel(info.difficulty);
        const host = info.host || (s.opponent || 'Opponent');
        peek.hidden = false;
        peek.textContent = (s.roompeek || '{host} · {diff}')
            .replace('{host}', host)
            .replace('{diff}', diff);
    };

    const poll = function(root) {
        return call('local_nexbattleground_poll_lobby', {
            page: state.recentPage,
            perpage: state.recentPerpage
        }).then(function(payload) {
            if (payload.battleid && payload.battlestatus === 'active') {
                window.location.href = battleHref(payload.battleid);
                return payload;
            }
            setQueued(root, !!payload.queued, cfg.strings && cfg.strings.searching);
            showRoom(
                root,
                payload.roomcode || '',
                payload.battleid || 0,
                payload.roomDifficulty || ''
            );
            renderIncoming(root, payload.incoming || []);
            renderRecent(
                root,
                payload.recent || [],
                payload.recentTotal | 0,
                payload.recentPage | 0,
                payload.recentPerpage || PERPAGE
            );
            return payload;
        }).catch(function(err) {
            Notification.exception(err);
        });
    };

    const init = function(config) {
        cfg = config || {};
        const root = document.querySelector('[data-region="nbg-lobby"]');
        if (!root) {
            return;
        }

        const diffSel = root.querySelector('[data-region="difficulty"]');
        const roomDiffSel = root.querySelector('[data-region="room-difficulty"]');
        const challengeDiffSel = root.querySelector('[data-region="challenge-difficulty"]');

        const pagerEl = root.querySelector('[data-region="recent-pager"]');
        if (pagerEl) {
            pagerEl.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-page]');
                if (!btn || btn.disabled) {
                    return;
                }
                const next = parseInt(btn.getAttribute('data-page'), 10);
                if (isNaN(next) || next === state.recentPage) {
                    return;
                }
                state.recentPage = Math.max(0, next);
                poll(root);
            });
        }

        root.querySelectorAll('[data-action="find-match"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const difficulty = diffSel ? diffSel.value : '';
                call('local_nexbattleground_join_queue', {difficulty: difficulty}).then(function(res) {
                    if (res.battleid && !res.queued) {
                        return call('local_nexbattleground_poll_lobby', {
                            page: state.recentPage,
                            perpage: state.recentPerpage
                        }).then(function(payload) {
                            if (payload.battleid && payload.battlestatus === 'active') {
                                window.location.href = battleHref(payload.battleid);
                            } else if (res.battleid) {
                                window.location.href = battleHref(res.battleid);
                            } else {
                                setQueued(root, !!res.queued, res.message);
                            }
                            return payload;
                        });
                    }
                    setQueued(root, !!res.queued, res.message);
                    return res;
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('[data-action="cancel-queue"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                call('local_nexbattleground_leave_queue', {}).then(function() {
                    setQueued(root, false, '');
                    return poll(root);
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('[data-action="challenge"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const input = root.querySelector('[data-region="challenge-user"]');
                const status = root.querySelector('[data-region="challenge-status"]');
                const username = input ? input.value.trim() : '';
                if (!username) {
                    return;
                }
                const difficulty = challengeDiffSel ? challengeDiffSel.value : '';
                call('local_nexbattleground_challenge_user', {
                    username: username,
                    difficulty: difficulty
                }).then(function(res) {
                    if (status) {
                        status.hidden = false;
                        const base = res.message || 'OK';
                        status.textContent = base + ' · ' + difficultyLabel(res.difficulty || difficulty);
                    }
                    return poll(root);
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('[data-action="create-room"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const difficulty = roomDiffSel ? roomDiffSel.value : '';
                const status = root.querySelector('[data-region="room-status"]');
                call('local_nexbattleground_create_room', {difficulty: difficulty}).then(function(res) {
                    if (status) {
                        status.hidden = false;
                        status.textContent = res.message || '';
                    }
                    showRoom(root, res.roomcode || '', res.battleid || 0, res.difficulty || difficulty);
                    return poll(root);
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('[data-action="join-room"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const input = root.querySelector('[data-region="join-code"]');
                const code = input ? input.value.trim() : '';
                if (!code) {
                    return;
                }
                call('local_nexbattleground_join_room', {code: code}).then(function(res) {
                    if (res.battleid) {
                        window.location.href = battleHref(res.battleid);
                    }
                    return res;
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('[data-action="cancel-room"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const panel = root.querySelector('[data-region="room-active"]');
                const battleid = panel && panel.dataset.battleid ? parseInt(panel.dataset.battleid, 10) : 0;
                call('local_nexbattleground_cancel_room', {battleid: battleid || 0}).then(function() {
                    showRoom(root, '', 0, '');
                    return poll(root);
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('[data-action="copy-code"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const codeEl = root.querySelector('[data-region="room-code"]');
                const code = codeEl ? codeEl.textContent.trim() : '';
                if (!code) {
                    return;
                }
                const done = function() {
                    const original = btn.textContent;
                    btn.textContent = (cfg.strings && cfg.strings.copied) || 'Copied';
                    window.setTimeout(function() {
                        btn.textContent = original;
                    }, 1200);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(done).catch(function() {
                        done();
                    });
                } else {
                    done();
                }
            });
        });

        const joinInput = root.querySelector('[data-region="join-code"]');
        let peekTimer = null;
        if (joinInput) {
            const runPeek = function() {
                const code = joinInput.value.replace(/\D+/g, '').slice(0, 6);
                if (code.length !== 6) {
                    showRoomPeek(root, null);
                    return;
                }
                call('local_nexbattleground_peek_room', {code: code}).then(function(info) {
                    showRoomPeek(root, info);
                    return info;
                }).catch(function() {
                    showRoomPeek(root, null);
                });
            };
            joinInput.addEventListener('input', function() {
                if (peekTimer) {
                    window.clearTimeout(peekTimer);
                }
                peekTimer = window.setTimeout(runPeek, 250);
            });
            joinInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const joinBtn = root.querySelector('[data-action="join-room"]');
                    if (joinBtn) {
                        joinBtn.click();
                    }
                }
            });
        }

        poll(root);
        timer = window.setInterval(function() {
            poll(root);
        }, 2500);
    };

    return {init: init};
});
