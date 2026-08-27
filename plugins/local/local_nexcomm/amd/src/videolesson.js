/**
 * English Central–style Watch / Learn / Speak lesson player.
 *
 * @module local_nexcomm/videolesson
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const init = function(config) {
        config = config || {};
        const root = $('[data-region="nc-lesson"]');
        if (!root.length) {
            return;
        }
        const el = root.get(0);
        const stage = root.find('[data-region="stage"]');
        const lessonId = config.lessonId || parseInt(root.attr('data-lessonid'), 10);
        let lesson = null;
        let lines = [];
        let words = [];
        let speakMap = {};
        let tab = 'watch';
        let mediaRecorder = null;
        let chunks = [];
        let recognition = null;
        let activeLineId = 0;

        const label = function(n) {
            return el.getAttribute('data-label-' + n) || n;
        };

        const save = function(args) {
            return Ajax.call([{
                methodname: 'local_nexcomm_save_lesson_progress',
                args: Object.assign({
                    lessonid: lessonId,
                    answersjson: '{}',
                    lineid: 0,
                    transcript: ''
                }, args)
            }])[0];
        };

        const renderProgress = function() {
            const html = '<div class="nc-ec-progress__pills">' +
                '<span class="' + (lesson.watched ? 'is-on' : '') + '">' + esc(label('watch')) +
                (lesson.watched ? ' ✓' : '') + '</span>' +
                '<span class="' + (lesson.learnScore >= 70 ? 'is-on' : '') + '">' + esc(label('learn')) +
                ' ' + esc(Math.round(lesson.learnScore || 0)) + '%</span>' +
                '<span class="' + (lesson.linesSpoken > 0 ? 'is-on' : '') + '">' + esc(label('speak')) +
                ' ' + esc(lesson.linesSpoken || 0) + '/' + esc(lines.length) + '</span>' +
                (lesson.complete ? '<span class="is-on">' + esc(label('complete')) + '</span>' : '') +
                '</div>';
            root.find('[data-region="progress"]').html(html);
        };

        const speakLineTts = function(text) {
            if (!window.speechSynthesis) {
                return;
            }
            window.speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(text);
            u.lang = 'en-IN';
            u.rate = 0.92;
            window.speechSynthesis.speak(u);
        };

        const stopRec = function() {
            if (recognition) {
                try {
                    recognition.onend = null;
                    recognition.stop();
                } catch (e) { /* ignore */ }
                recognition = null;
            }
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                try {
                    mediaRecorder.stop();
                } catch (e) { /* ignore */ }
            }
        };

        const renderWatch = function() {
            let html = '<div class="nc-ec-watch">';
            if (lesson.videourl) {
                const url = lesson.videourl;
                if (/youtube\.com|youtu\.be/.test(url)) {
                    let id = '';
                    const m = url.match(/(?:v=|youtu\.be\/)([\w-]{6,})/);
                    id = m ? m[1] : '';
                    if (id) {
                        html += '<div class="nc-ec-video"><iframe src="https://www.youtube.com/embed/' +
                            esc(id) + '" title="lesson" allowfullscreen></iframe></div>';
                    }
                } else {
                    html += '<video class="nc-ec-videoel" controls src="' + esc(url) + '"></video>';
                }
            } else {
                html += '<p class="nc-muted">Dialogue mode — play the conversation, then mark Watch complete.</p>';
            }
            html += '<div class="nc-ec-script" data-region="script">';
            lines.forEach(function(line, i) {
                html += '<div class="nc-ec-line" data-line-index="' + i + '">' +
                    '<span class="nc-ec-line__speaker">' + esc(line.speaker) + '</span>' +
                    '<span class="nc-ec-line__text">' + esc(line.text) + '</span></div>';
            });
            html += '</div>';
            html += '<div class="nc-attempt__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="play-all">' +
                esc(label('playall')) + '</button>' +
                '<button type="button" class="nc-btn nc-btn--ghost" data-action="mark-watched"' +
                (lesson.watched ? ' disabled' : '') + '>' +
                esc(lesson.watched ? label('watched') : label('markwatched')) + '</button>' +
                '</div></div>';
            stage.html(html);

            root.off('click.ncWatch');
            root.on('click.ncWatch', '[data-action="play-all"]', function() {
                if (!window.speechSynthesis) {
                    return;
                }
                window.speechSynthesis.cancel();
                let i = 0;
                const next = function() {
                    root.find('.nc-ec-line').removeClass('is-active');
                    if (i >= lines.length) {
                        return;
                    }
                    root.find('.nc-ec-line[data-line-index="' + i + '"]').addClass('is-active');
                    const u = new SpeechSynthesisUtterance(lines[i].text);
                    u.lang = 'en-IN';
                    u.rate = 0.92;
                    u.onend = function() {
                        i++;
                        setTimeout(next, 280);
                    };
                    window.speechSynthesis.speak(u);
                };
                next();
            });
            root.on('click.ncWatch', '[data-action="mark-watched"]', function() {
                const btn = $(this);
                btn.prop('disabled', true);
                save({mode: 'watch'}).then(function(res) {
                    lesson.watched = res.watched;
                    lesson.complete = res.complete;
                    btn.text(label('watched'));
                    renderProgress();
                    if (res.xpAwarded) {
                        Notification.addNotification({
                            message: '+' + res.xpAwarded + ' XP',
                            type: 'success'
                        });
                    }
                    return null;
                }).catch(function(err) {
                    btn.prop('disabled', false);
                    Notification.exception(err);
                });
            });
        };

        const renderLearn = function() {
            let html = '<div class="nc-ec-learn"><p class="nc-muted">Fill in the missing words from the dialogue.</p>';
            words.forEach(function(w) {
                html += '<div class="nc-ec-word" data-wordid="' + esc(w.id) + '">' +
                    '<div class="nc-ec-word__sentence">' + esc(w.sentence) + '</div>' +
                    '<input type="text" class="nc-ec-word__input" autocomplete="off" ' +
                    'placeholder="' + esc(label('hint')) + ': ' + esc(w.hint) + '"/>' +
                    '</div>';
            });
            html += '<div class="nc-attempt__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="check-learn">' +
                esc(label('checklearn')) + '</button></div>' +
                '<p class="nc-status" data-region="learn-result" hidden></p></div>';
            stage.html(html);

            root.off('click.ncLearn');
            root.on('click.ncLearn', '[data-action="check-learn"]', function() {
                const answers = {};
                root.find('.nc-ec-word').each(function() {
                    const id = $(this).attr('data-wordid');
                    answers[id] = $(this).find('input').val() || '';
                });
                save({mode: 'learn', answersjson: JSON.stringify(answers)}).then(function(res) {
                    lesson.wordsLearned = res.wordsLearned;
                    lesson.learnScore = res.learnScore;
                    lesson.complete = res.complete;
                    root.find('[data-region="learn-result"]')
                        .text('Score: ' + Math.round(res.learnScore) + '% · Words learned: ' + res.wordsLearned)
                        .removeAttr('hidden');
                    renderProgress();
                    if (res.xpAwarded) {
                        Notification.addNotification({
                            message: '+' + res.xpAwarded + ' XP — lesson complete',
                            type: 'success'
                        });
                    }
                    return null;
                }).catch(Notification.exception);
            });
        };

        const renderSpeak = function() {
            let html = '<div class="nc-ec-speak"><p class="nc-muted">' +
                'Hear the model line, then record yourself. Aim for 60%+ match.</p>';
            lines.forEach(function(line) {
                const prev = speakMap[String(line.id)] || {};
                const score = prev.score != null ? Math.round(prev.score) : null;
                html += '<div class="nc-ec-speakline' + (prev.passed ? ' is-passed' : '') +
                    '" data-lineid="' + esc(line.id) + '">' +
                    '<div class="nc-ec-speakline__meta">' +
                    '<span class="nc-ec-line__speaker">' + esc(line.speaker) + '</span>' +
                    (score != null ? '<span class="nc-badge">' + esc(score) + '%</span>' : '') +
                    '</div>' +
                    '<div class="nc-ec-speakline__text">' + esc(line.text) + '</div>' +
                    '<div class="nc-ec-speakline__actions">' +
                    '<button type="button" class="nc-btn nc-btn--ghost" data-action="hear">' +
                    esc(label('hearline')) + '</button>' +
                    '<button type="button" class="nc-btn nc-btn--primary" data-action="rec">' +
                    esc(label('recordline')) + '</button>' +
                    '<button type="button" class="nc-btn nc-btn--danger" data-action="stop" disabled>' +
                    esc(label('stopline')) + '</button>' +
                    '</div>' +
                    '<div class="nc-ec-speakline__live" data-region="live" hidden></div>' +
                    '</div>';
            });
            html += '</div>';
            stage.html(html);

            root.off('click.ncSpeak');
            root.on('click.ncSpeak', '[data-action="hear"]', function() {
                const row = $(this).closest('[data-lineid]');
                const id = parseInt(row.attr('data-lineid'), 10);
                const line = lines.find(function(l) { return l.id === id; });
                if (line) {
                    speakLineTts(line.text);
                }
            });

            root.on('click.ncSpeak', '[data-action="rec"]', function() {
                const row = $(this).closest('[data-lineid]');
                activeLineId = parseInt(row.attr('data-lineid'), 10);
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    Notification.addNotification({message: label('micdenied'), type: 'error'});
                    return;
                }
                stopRec();
                navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
                    chunks = [];
                    let transcript = '';
                    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
                    if (SR) {
                        recognition = new SR();
                        recognition.lang = 'en-IN';
                        recognition.continuous = true;
                        recognition.interimResults = true;
                        recognition.onresult = function(ev) {
                            let t = '';
                            for (let i = 0; i < ev.results.length; i++) {
                                t += ev.results[i][0].transcript + ' ';
                            }
                            transcript = t.trim();
                            row.find('[data-region="live"]').text(transcript).removeAttr('hidden');
                        };
                        try {
                            recognition.start();
                        } catch (e) { /* ignore */ }
                    }
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.ondataavailable = function(e) {
                        if (e.data && e.data.size) {
                            chunks.push(e.data);
                        }
                    };
                    mediaRecorder.onstop = function() {
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        if (recognition) {
                            try {
                                recognition.stop();
                            } catch (e) { /* ignore */ }
                        }
                        const live = row.find('[data-region="live"]').text().trim();
                        const said = live || transcript || '';
                        row.find('[data-action="rec"]').prop('disabled', false);
                        row.find('[data-action="stop"]').prop('disabled', true);
                        save({
                            mode: 'speak',
                            lineid: activeLineId,
                            transcript: said
                        }).then(function(res) {
                            try {
                                speakMap = JSON.parse(res.speakJson || '{}');
                            } catch (e) {
                                speakMap = {};
                            }
                            lesson.linesSpoken = res.linesSpoken;
                            lesson.speakScore = res.speakScore;
                            lesson.complete = res.complete;
                            renderProgress();
                            renderSpeak();
                            Notification.addNotification({
                                message: label('linescore') + ': ' + Math.round(res.lineScore) + '%',
                                type: res.lineScore >= 60 ? 'success' : 'info'
                            });
                            if (res.xpAwarded) {
                                Notification.addNotification({
                                    message: '+' + res.xpAwarded + ' XP — lesson complete',
                                    type: 'success'
                                });
                            }
                            return null;
                        }).catch(Notification.exception);
                    };
                    mediaRecorder.start();
                    row.find('[data-action="rec"]').prop('disabled', true);
                    row.find('[data-action="stop"]').prop('disabled', false);
                    row.find('[data-region="live"]').text('Listening…').removeAttr('hidden');
                }).catch(function() {
                    Notification.addNotification({message: label('micdenied'), type: 'error'});
                });
            });

            root.on('click.ncSpeak', '[data-action="stop"]', function() {
                if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                }
            });
        };

        const showTab = function(name) {
            tab = name;
            root.find('.nc-ec-tab').removeClass('is-active');
            root.find('.nc-ec-tab[data-tab="' + name + '"]').addClass('is-active');
            if (name === 'watch') {
                renderWatch();
            } else if (name === 'learn') {
                renderLearn();
            } else {
                renderSpeak();
            }
        };

        root.on('click', '.nc-ec-tab', function() {
            showTab($(this).attr('data-tab'));
        });

        Ajax.call([{
            methodname: 'local_nexcomm_get_lesson',
            args: {lessonid: lessonId}
        }])[0].then(function(data) {
            lesson = data;
            try {
                lines = JSON.parse(data.linesJson || '[]');
            } catch (e) {
                lines = [];
            }
            try {
                words = JSON.parse(data.wordsJson || '[]');
            } catch (e) {
                words = [];
            }
            try {
                speakMap = JSON.parse(data.speakJson || '{}');
            } catch (e) {
                speakMap = {};
            }
            root.find('[data-region="title"]').text(data.title || '');
            root.find('[data-region="diff-badge"]')
                .text(data.difficulty || '')
                .attr('class', 'nc-badge nc-badge--' + (data.difficulty || ''));
            renderProgress();
            showTab('watch');
            return null;
        }).catch(Notification.exception);
    };

    return {init: init};
});
