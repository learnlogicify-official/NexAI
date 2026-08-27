/**
 * NexBattleGround arena chrome — wraps the NexPractice problem IDE.
 *
 * @module local_nexbattleground/battle
 */
define(['core/ajax', 'core/notification', 'local_learnlogic/problem'], function(Ajax, Notification, Problem) {
    const REDIRECT_MS = 5000;

    let cfg = {};
    let state = {
        battle: null,
        deadline: 0,
        pollTimer: null,
        clockTimer: null,
        ideReady: false,
        finishShown: false,
        redirectTimer: null,
    };

    const call = function(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args || {}}])[0];
    };

    const parseJson = function(raw) {
        try {
            return JSON.parse(raw || '{}');
        } catch (e) {
            return {};
        }
    };

    const formatClock = function(secs) {
        secs = Math.max(0, secs | 0);
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        const pad = function(n) {
            return (n < 10 ? '0' : '') + n;
        };
        return pad(m) + ':' + pad(s);
    };

    const tickClock = function(root) {
        const el = root.querySelector('[data-region="clock"]');
        if (!el) {
            return;
        }
        const left = Math.max(0, state.deadline - Math.floor(Date.now() / 1000));
        el.textContent = formatClock(left);
        if (left <= 0 && state.battle && state.battle.status === 'active') {
            refresh(root);
        }
    };

    const setIcon = function(overlay, kind) {
        overlay.querySelectorAll('.nbg-overlay__svg').forEach(function(svg) {
            svg.hidden = true;
        });
        const target = overlay.querySelector('.nbg-overlay__svg--' + kind);
        if (target) {
            target.hidden = false;
        }
    };

    const setCardTone = function(card, tone) {
        if (!card) {
            return;
        }
        card.classList.remove(
            'nbg-overlay__card--win',
            'nbg-overlay__card--lose',
            'nbg-overlay__card--tie',
            'nbg-overlay__card--wait'
        );
        if (tone) {
            card.classList.add('nbg-overlay__card--' + tone);
        }
    };

    const showOverlay = function(root, opts) {
        opts = opts || {};
        const overlay = root.querySelector('[data-region="overlay"]');
        if (!overlay) {
            return;
        }
        overlay.hidden = false;
        const card = overlay.querySelector('[data-region="overlay-card"]');
        const t = overlay.querySelector('[data-region="overlay-title"]');
        const m = overlay.querySelector('[data-region="overlay-msg"]');
        const c = overlay.querySelector('[data-region="overlay-code"]');
        const xp = overlay.querySelector('[data-region="overlay-xp"]');
        const xpVal = overlay.querySelector('[data-region="overlay-xp-value"]');
        const redirect = overlay.querySelector('[data-region="overlay-redirect"]');
        const bar = overlay.querySelector('[data-region="overlay-loader-bar"]');

        setCardTone(card, opts.tone || '');
        setIcon(overlay, opts.icon || 'wait');

        if (t) {
            t.textContent = opts.title || '';
        }
        if (m) {
            m.textContent = opts.msg || '';
        }
        if (c) {
            if (opts.code) {
                c.hidden = false;
                c.textContent = opts.code;
            } else {
                c.hidden = true;
                c.textContent = '';
            }
        }
        if (xp) {
            const amount = opts.xp | 0;
            if (amount > 0) {
                xp.hidden = false;
                if (xpVal) {
                    xpVal.textContent = '+' + amount + ' XP';
                }
            } else {
                xp.hidden = true;
            }
        }
        if (redirect) {
            if (opts.showRedirect) {
                redirect.hidden = false;
                if (bar) {
                    bar.style.transition = 'none';
                    bar.style.width = '0%';
                    // Force reflow so the 5s transition always restarts.
                    void bar.offsetWidth;
                    bar.style.transition = 'width ' + (REDIRECT_MS / 1000) + 's linear';
                    bar.style.width = '100%';
                }
            } else {
                redirect.hidden = true;
                if (bar) {
                    bar.style.transition = 'none';
                    bar.style.width = '0%';
                }
            }
        }
    };

    const hideOverlay = function(root) {
        const overlay = root.querySelector('[data-region="overlay"]');
        if (overlay) {
            overlay.hidden = true;
        }
    };

    const goLobby = function() {
        if (cfg.lobbyUrl) {
            window.location.href = cfg.lobbyUrl;
        }
    };

    const startLobbyRedirect = function() {
        if (state.redirectTimer) {
            window.clearTimeout(state.redirectTimer);
        }
        state.redirectTimer = window.setTimeout(goLobby, REDIRECT_MS);
    };

    const applyFinished = function(root, battle) {
        root.querySelectorAll('[data-action="forfeit"]').forEach(function(btn) {
            btn.disabled = true;
        });
        if (state.pollTimer) {
            window.clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
        if (state.finishShown) {
            return;
        }
        state.finishShown = true;

        const s = cfg.strings || {};
        const result = battle.result || '';
        let title = s.battleover || 'Battle over';
        let tone = 'tie';
        let icon = 'tie';
        let msg = '';
        let xp = 0;

        if (result === 'win') {
            title = s.youwin || 'You win!';
            tone = 'win';
            icon = 'win';
            xp = battle.xpAwarded | 0;
            msg = xp > 0 ? '' : (s.victory || 'Victory!');
        } else if (result === 'loss') {
            title = s.youlose || 'You lose';
            tone = 'lose';
            icon = 'lose';
            msg = s.opponentsolved || 'Better luck next time';
        } else if (result === 'tie' || battle.outcome === 'tie') {
            title = s.itsatie || "It's a tie";
            tone = 'tie';
            icon = 'tie';
            msg = s.timeouttie || 'Time expired — neither solved';
        }

        showOverlay(root, {
            title: title,
            msg: msg,
            tone: tone,
            icon: icon,
            xp: xp,
            showRedirect: true,
        });
        startLobbyRedirect();
    };

    const ensureIde = function(root, flat) {
        if (state.ideReady || !flat.problemid) {
            return;
        }
        const host = root.querySelector('[data-region="ide-host"]');
        const ide = root.querySelector('[data-region="ll-ide"]');
        if (!host || !ide) {
            return;
        }
        host.hidden = false;
        ide.setAttribute('data-problemid', String(flat.problemid));
        ide.classList.add('ll-np--battle');
        document.body.classList.add('ll-np-battle');

        // Point brand / back links at the lobby.
        ide.querySelectorAll('[data-action="go-list"]').forEach(function(a) {
            a.setAttribute('href', cfg.lobbyUrl || '#');
        });
        const brand = ide.querySelector('.ll-np__brand');
        if (brand) {
            brand.textContent = 'NexBattleGround';
        }

        Problem.init({
            problemId: flat.problemid,
            listUrl: cfg.lobbyUrl,
            canAttempt: !!cfg.canBattle,
            aceBaseUrl: cfg.aceBaseUrl || '',
            strings: cfg.practiceStrings || {},
            battle: {
                battleId: cfg.battleId,
                onResult: function(payload) {
                    if (!payload) {
                        return;
                    }
                    // Refresh chrome from submit response (already flattened).
                    applyBattle(root, payload);
                }
            }
        });
        state.ideReady = true;
    };

    const applyBattle = function(root, flat) {
        const you = parseJson(flat.youJson);
        const opp = parseJson(flat.opponentJson);
        state.battle = flat;
        state.deadline = (flat.deadline | 0) || 0;

        const youName = root.querySelector('[data-region="you-name"]');
        const oppName = root.querySelector('[data-region="opp-name"]');
        const s = cfg.strings || {};
        if (youName) {
            youName.textContent = you.displayname || s.you || 'You';
        }
        if (oppName) {
            oppName.textContent = opp.displayname || s.opponent || 'Opponent';
        }

        tickClock(root);

        if (flat.status === 'waiting') {
            const code = flat.roomcode || '';
            const diffKey = String(flat.difficulty || '').toLowerCase();
            const diffMap = {
                easy: s.easy || 'Easy',
                medium: s.medium || 'Medium',
                hard: s.hard || 'Hard',
                veryhard: s.veryhard || 'Very hard'
            };
            const diffLabel = diffMap[diffKey] || (s.anydifficulty || 'Any');
            let msg = code
                ? ((s.sharecode || 'Share this code') + ' — ' + (s.waitingforopponent || 'Waiting…'))
                : (s.waitingforopponent || 'Waiting for opponent…');
            msg += ' · ' + ((s.difficulty || 'Difficulty') + ': ' + diffLabel);
            showOverlay(root, {
                title: s.waitingtitle || 'Waiting',
                msg: msg,
                code: code,
                tone: 'wait',
                icon: 'wait',
                showRedirect: false,
            });
            root.querySelectorAll('[data-action="forfeit"]').forEach(function(btn) {
                btn.disabled = true;
            });
            const host = root.querySelector('[data-region="ide-host"]');
            if (host) {
                host.hidden = true;
            }
            return;
        }

        if (flat.status === 'finished') {
            applyFinished(root, flat);
            return;
        }

        // Active battle.
        hideOverlay(root);
        root.querySelectorAll('[data-action="forfeit"]').forEach(function(btn) {
            btn.disabled = false;
        });
        ensureIde(root, flat);
    };

    const refresh = function(root) {
        return call('local_nexbattleground_get_battle', {battleid: cfg.battleId}).then(function(flat) {
            applyBattle(root, flat);
            return flat;
        }).catch(Notification.exception);
    };

    const init = function(config) {
        cfg = config || {};
        const root = document.querySelector('[data-region="nbg-battle"]');
        if (!root) {
            return;
        }

        root.querySelectorAll('[data-action="forfeit"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const msg = (cfg.strings && cfg.strings.forfeitconfirm) || 'Forfeit?';
                if (!window.confirm(msg)) {
                    return;
                }
                call('local_nexbattleground_forfeit', {battleid: cfg.battleId}).then(function(res) {
                    applyBattle(root, res);
                    return res;
                }).catch(Notification.exception);
            });
        });

        refresh(root);
        state.pollTimer = window.setInterval(function() {
            refresh(root);
        }, 3000);
        state.clockTimer = window.setInterval(function() {
            tickClock(root);
        }, 1000);
    };

    return {init: init};
});
