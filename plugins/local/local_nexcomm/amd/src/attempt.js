/**
 * NexComm attempt player — reading / listening (TTS) / writing / speaking (mic + transcript).
 *
 * @module local_nexcomm/attempt
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/config'], function($, Ajax, Notification, Config) {

    const esc = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    const wordCount = function(text) {
        const t = String(text || '').trim().replace(/\s+/g, ' ');
        return t ? t.split(' ').length : 0;
    };

    const listeningScript = function(body) {
        let text = String(body || '');
        text = text.replace(/\[Audio placeholder:[^\]]*\]/gi, '').trim();
        const m = text.match(/Script:\s*([\s\S]+)/i);
        if (m) {
            return m[1].trim();
        }
        return text;
    };

    const formatTime = function(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    };

    const init = function(config) {
        config = config || {};
        const root = $('[data-region="nc-attempt"]');
        if (!root.length) {
            return;
        }
        const el = root.get(0);
        const player = root.find('[data-region="player"]');
        const result = root.find('[data-region="result"]');
        const activityId = config.activityId || parseInt(root.attr('data-activityid'), 10);
        let draftItemId = config.draftItemId || parseInt(root.attr('data-file-itemid'), 10) || 0;
        let activity = null;
        let mediaRecorder = null;
        let chunks = [];
        let recordedBlob = null;
        let recognition = null;
        let transcriptFinal = '';
        let transcriptInterim = '';
        let recordStartedAt = 0;
        let timerIv = null;
        let elapsedSec = 0;

        const label = function(name) {
            return el.getAttribute('data-label-' + name) || name;
        };

        const uploadRecording = function(blob) {
            const fd = new FormData();
            const name = (blob && blob.type && blob.type.indexOf('mp4') >= 0) ? 'speech.mp4' : 'speech.webm';
            fd.append('speech', blob, name);
            fd.append('sesskey', config.sesskey || Config.sesskey);
            fd.append('itemid', String(draftItemId));
            return fetch(Config.wwwroot + '/local/nexcomm/upload_draft.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function(res) {
                return res.json();
            }).then(function(data) {
                if (!data || !data.ok) {
                    throw new Error((data && data.error) || 'Upload failed');
                }
                draftItemId = data.itemid || draftItemId;
                return data;
            });
        };

        const showResult = function(data) {
            const pass = data.status === 'passed' || data.status === 'submitted';
            const fail = data.status === 'failed';
            let analysis = null;
            try {
                analysis = data.analysisJson ? JSON.parse(data.analysisJson) : null;
            } catch (e) {
                analysis = null;
            }

            let html = '<h3>' + esc(pass && !fail ? label('passed') : label('failed')) + '</h3>';
            html += '<p>' + esc(label('score')) + ': ' + esc(Math.round(data.score)) +
                '% (pass mark ' + esc(data.passmark) + '%)</p>';
            if (data.xpAwarded > 0) {
                html += '<p><strong>+' + esc(data.xpAwarded) + ' XP</strong></p>';
            }
            if (data.dailyBonus > 0) {
                html += '<p>Daily target bonus: +' + esc(data.dailyBonus) + ' XP</p>';
            }
            if (data.weeklyBonus > 0) {
                html += '<p>Weekly target bonus: +' + esc(data.weeklyBonus) + ' XP</p>';
            }
            if (data.transcript) {
                html += '<div class="nc-transcript"><strong>' + esc(label('transcript')) + '</strong>' +
                    '<p>' + esc(data.transcript) + '</p></div>';
            }
            if (analysis) {
                html += '<div class="nc-analysis">' +
                    '<strong>' + esc(label('analysis')) + '</strong>' +
                    '<ul class="nc-analysis__metrics">' +
                    '<li>' + esc(analysis.wordCount || 0) + ' words</li>' +
                    '<li>' + esc(formatTime(analysis.durationSec || 0)) + '</li>' +
                    '<li>' + esc(analysis.wpm || 0) + ' WPM</li>' +
                    '<li>Prompt coverage ' + esc(analysis.promptCoverage || 0) + '%</li>' +
                    '<li>Fillers ' + esc(analysis.fillerRatio || 0) + '%</li>' +
                    '</ul>';
                if (analysis.feedback && analysis.feedback.length) {
                    html += '<ul class="nc-analysis__tips">' +
                        analysis.feedback.map(function(f) {
                            return '<li>' + esc(f) + '</li>';
                        }).join('') + '</ul>';
                }
                html += '</div>';
            }
            html += '<div class="nc-attempt__actions">' +
                '<a class="nc-btn nc-btn--primary" href="' + esc(config.catalogUrl || activity.catalogurl) +
                '">Back to practice</a></div>';
            result.html(html)
                .toggleClass('is-pass', pass && !fail)
                .toggleClass('is-fail', fail)
                .removeAttr('hidden');
            result.get(0).scrollIntoView({behavior: 'smooth', block: 'nearest'});
        };

        const submit = function(payload) {
            const btn = root.find('[data-action="submit"]');
            btn.prop('disabled', true).text(label('submitting'));
            return Ajax.call([{
                methodname: 'local_nexcomm_submit_attempt',
                args: {
                    activityid: activityId,
                    answersjson: JSON.stringify(payload.answers || {}),
                    text: payload.text || '',
                    draftitemid: payload.draftitemid || 0,
                    duration: payload.duration || 0
                }
            }])[0].then(function(data) {
                showResult(data);
                btn.prop('disabled', true).text(label('submit'));
                return data;
            }).catch(function(err) {
                btn.prop('disabled', false).text(label('submit'));
                Notification.exception(err);
            });
        };

        const stopRecognition = function() {
            if (recognition) {
                try {
                    recognition.onend = null;
                    recognition.stop();
                } catch (e) {
                    // ignore
                }
                recognition = null;
            }
        };

        const startRecognition = function() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            transcriptFinal = '';
            transcriptInterim = '';
            root.find('[data-region="live-transcript"]').text('');
            if (!SR) {
                root.find('[data-region="transcript-note"]').text(label('nospeechapi'));
                return;
            }
            recognition = new SR();
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.lang = 'en-IN';
            recognition.onresult = function(event) {
                let interim = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const piece = event.results[i][0].transcript;
                    if (event.results[i].isFinal) {
                        transcriptFinal += piece + ' ';
                    } else {
                        interim += piece;
                    }
                }
                transcriptInterim = interim;
                root.find('[data-region="live-transcript"]').text(
                    (transcriptFinal + ' ' + transcriptInterim).trim()
                );
            };
            recognition.onerror = function() {
                // Keep recording audio even if STT fails.
            };
            try {
                recognition.start();
                root.find('[data-region="transcript-note"]').text(label('livetranscript'));
            } catch (e) {
                root.find('[data-region="transcript-note"]').text(label('nospeechapi'));
            }
        };

        const clearTimer = function() {
            if (timerIv) {
                clearInterval(timerIv);
                timerIv = null;
            }
        };

        const renderListening = function() {
            const script = listeningScript(activity.body);
            let html = '<div class="nc-listen-player">' +
                '<div class="nc-listen-player__icon" aria-hidden="true">🎧</div>' +
                '<div class="nc-listen-player__body">' +
                '<strong>' + esc(label('audio')) + '</strong>' +
                '<p class="nc-muted">' + esc(label('listenhint')) + '</p>' +
                '<div class="nc-listen-player__controls">';
            if (activity.audiourl) {
                html += '<audio class="nc-audio" controls src="' + esc(activity.audiourl) + '"></audio>';
            } else {
                html += '<button type="button" class="nc-btn nc-btn--primary" data-action="tts-play">' +
                    esc(label('playaudio')) + '</button>' +
                    '<button type="button" class="nc-btn nc-btn--ghost" data-action="tts-stop">' +
                    esc(label('stop')) + '</button>';
            }
            html += '</div></div></div>';
            html += '<button type="button" class="nc-btn nc-btn--ghost nc-btn--small" data-action="toggle-script">' +
                esc(label('showscript')) + '</button>';
            html += '<div class="nc-passage" data-region="script" hidden>' + esc(script) + '</div>';

            (activity.questions || []).forEach(function(q, qi) {
                html += '<div class="nc-q" data-qid="' + esc(q.id) + '">' +
                    '<div class="nc-q__stem">' + (qi + 1) + '. ' + esc(q.stem) + '</div>';
                (q.choices || []).forEach(function(c) {
                    html += '<label class="nc-choice">' +
                        '<input type="radio" name="q' + esc(q.id) + '" value="' + esc(c.key) + '"/>' +
                        '<span>' + esc(c.label) + '</span></label>';
                });
                html += '</div>';
            });
            html += '<div class="nc-attempt__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="submit">' +
                esc(label('submit')) + '</button></div>';
            player.html(html);

            root.off('click.ncListen');
            root.on('click.ncListen', '[data-action="tts-play"]', function() {
                if (!window.speechSynthesis) {
                    Notification.addNotification({message: label('notts'), type: 'error'});
                    return;
                }
                window.speechSynthesis.cancel();
                const u = new SpeechSynthesisUtterance(script);
                u.lang = 'en-IN';
                u.rate = 0.95;
                window.speechSynthesis.speak(u);
            });
            root.on('click.ncListen', '[data-action="tts-stop"]', function() {
                if (window.speechSynthesis) {
                    window.speechSynthesis.cancel();
                }
            });
            root.on('click.ncListen', '[data-action="toggle-script"]', function() {
                const box = root.find('[data-region="script"]');
                const btn = $(this);
                if (box[0].hasAttribute('hidden')) {
                    box.removeAttr('hidden');
                    btn.text(label('hidescript'));
                } else {
                    box.attr('hidden', true);
                    btn.text(label('showscript'));
                }
            });
            root.on('click.ncListen', '[data-action="submit"]', function() {
                const answers = {};
                root.find('.nc-q').each(function() {
                    const qid = $(this).attr('data-qid');
                    const val = $(this).find('input:checked').val();
                    if (val) {
                        answers[qid] = val;
                    }
                });
                submit({answers: answers});
            });
        };

        const renderReading = function() {
            let html = '';
            if (activity.body) {
                html += '<div class="nc-passage">' + esc(activity.body) + '</div>';
            }
            (activity.questions || []).forEach(function(q, qi) {
                html += '<div class="nc-q" data-qid="' + esc(q.id) + '">' +
                    '<div class="nc-q__stem">' + (qi + 1) + '. ' + esc(q.stem) + '</div>';
                (q.choices || []).forEach(function(c) {
                    html += '<label class="nc-choice">' +
                        '<input type="radio" name="q' + esc(q.id) + '" value="' + esc(c.key) + '"/>' +
                        '<span>' + esc(c.label) + '</span></label>';
                });
                html += '</div>';
            });
            html += '<div class="nc-attempt__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="submit">' +
                esc(label('submit')) + '</button></div>';
            player.html(html);
            root.off('click.ncRead').on('click.ncRead', '[data-action="submit"]', function() {
                const answers = {};
                root.find('.nc-q').each(function() {
                    const qid = $(this).attr('data-qid');
                    const val = $(this).find('input:checked').val();
                    if (val) {
                        answers[qid] = val;
                    }
                });
                submit({answers: answers});
            });
        };

        const renderWriting = function() {
            const min = activity.minwords || 0;
            let html = '';
            if (activity.prompt) {
                html += '<div class="nc-prompt-box">' + esc(activity.prompt) + '</div>';
            }
            html += '<textarea class="nc-writearea" data-region="write" placeholder="Write your response…"></textarea>' +
                '<div class="nc-wordmeta"><span data-region="words">0 words</span>' +
                (min ? '<span>Minimum: ' + esc(min) + ' words</span>' : '') + '</div>' +
                '<div class="nc-attempt__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="submit">' +
                esc(label('submit')) + '</button></div>';
            player.html(html);
            root.off('input.ncWrite click.ncWrite');
            root.on('input.ncWrite', '[data-region="write"]', function() {
                root.find('[data-region="words"]').text(wordCount($(this).val()) + ' words');
            });
            root.on('click.ncWrite', '[data-action="submit"]', function() {
                submit({text: root.find('[data-region="write"]').val() || ''});
            });
        };

        const renderSpeaking = function() {
            let html = '';
            if (activity.prompt) {
                html += '<div class="nc-prompt-box">' + esc(activity.prompt) + '</div>';
            }
            html += '<div class="nc-recorder" data-region="recorder">' +
                '<div class="nc-recorder__orb" data-region="orb">' +
                '<button type="button" class="nc-recorder__mic" data-action="record" title="' +
                esc(label('record')) + '" aria-label="' + esc(label('record')) + '">' +
                '<span class="nc-recorder__mic-icon" aria-hidden="true"></span>' +
                '</button></div>' +
                '<div class="nc-recorder__timer" data-region="timer">0:00</div>' +
                '<p class="nc-recorder__hint">' + esc(label('speakhint')) + '</p>' +
                '<div class="nc-recorder__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="record">' +
                esc(label('record')) + '</button>' +
                '<button type="button" class="nc-btn nc-btn--danger" data-action="stop" disabled>' +
                esc(label('stop')) + '</button>' +
                '<button type="button" class="nc-btn nc-btn--ghost" data-action="rerecord" disabled>' +
                esc(label('rerecord')) + '</button>' +
                '</div>' +
                '<div class="nc-recorder__playback" data-region="playback" hidden></div>' +
                '</div>';

            html += '<div class="nc-transcript-live">' +
                '<div class="nc-transcript-live__head">' +
                '<strong>' + esc(label('transcript')) + '</strong>' +
                '<span class="nc-muted" data-region="transcript-note"></span></div>' +
                '<div class="nc-transcript-live__body" data-region="live-transcript">' +
                esc(label('transcriptplaceholder')) + '</div>' +
                '<label class="nc-field nc-field--block"><span>' + esc(label('edittranscript')) + '</span>' +
                '<textarea data-region="transcript-edit" rows="4" placeholder="' +
                esc(label('transcriptplaceholder')) + '"></textarea></label>' +
                '</div>';

            html += '<div class="nc-attempt__actions">' +
                '<button type="button" class="nc-btn nc-btn--primary" data-action="submit">' +
                esc(label('submit')) + '</button></div>';
            player.html(html);

            const setRecordingUi = function(on) {
                root.find('[data-region="orb"]').toggleClass('is-recording', on);
                root.find('[data-action="record"]').prop('disabled', on);
                root.find('[data-action="stop"]').prop('disabled', !on);
            };

            const resetRecording = function() {
                clearTimer();
                stopRecognition();
                recordedBlob = null;
                chunks = [];
                elapsedSec = 0;
                transcriptFinal = '';
                transcriptInterim = '';
                root.find('[data-region="timer"]').text('0:00');
                root.find('[data-region="playback"]').attr('hidden', true).empty();
                root.find('[data-region="live-transcript"]').text(label('transcriptplaceholder'));
                root.find('[data-region="transcript-edit"]').val('');
                root.find('[data-action="rerecord"]').prop('disabled', true);
                setRecordingUi(false);
            };

            const beginRecord = function() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    Notification.addNotification({message: label('micdenied'), type: 'error'});
                    return;
                }
                navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
                    chunks = [];
                    recordedBlob = null;
                    transcriptFinal = '';
                    transcriptInterim = '';
                    let mime = '';
                    if (window.MediaRecorder) {
                        if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                            mime = 'audio/webm;codecs=opus';
                        } else if (MediaRecorder.isTypeSupported('audio/webm')) {
                            mime = 'audio/webm';
                        } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                            mime = 'audio/mp4';
                        }
                    } else {
                        Notification.addNotification({message: label('norecorder'), type: 'error'});
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        return;
                    }
                    mediaRecorder = mime ? new MediaRecorder(stream, {mimeType: mime}) : new MediaRecorder(stream);
                    mediaRecorder.ondataavailable = function(e) {
                        if (e.data && e.data.size > 0) {
                            chunks.push(e.data);
                        }
                    };
                    mediaRecorder.onstop = function() {
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        clearTimer();
                        stopRecognition();
                        const type = (mediaRecorder && mediaRecorder.mimeType) || 'audio/webm';
                        recordedBlob = new Blob(chunks, {type: type});
                        const url = URL.createObjectURL(recordedBlob);
                        root.find('[data-region="playback"]')
                            .html('<audio class="nc-audio" controls src="' + url + '"></audio>')
                            .removeAttr('hidden');
                        const live = (transcriptFinal + ' ' + transcriptInterim).trim();
                        if (live) {
                            root.find('[data-region="transcript-edit"]').val(live);
                            root.find('[data-region="live-transcript"]').text(live);
                        }
                        root.find('[data-action="rerecord"]').prop('disabled', false);
                        setRecordingUi(false);
                    };
                    mediaRecorder.start(250);
                    recordStartedAt = Date.now();
                    elapsedSec = 0;
                    clearTimer();
                    timerIv = setInterval(function() {
                        elapsedSec = Math.floor((Date.now() - recordStartedAt) / 1000);
                        root.find('[data-region="timer"]').text(formatTime(elapsedSec));
                    }, 250);
                    startRecognition();
                    setRecordingUi(true);
                    root.find('[data-region="playback"]').attr('hidden', true).empty();
                }).catch(function() {
                    Notification.addNotification({message: label('micdenied'), type: 'error'});
                });
            };

            root.off('click.ncSpeak');
            root.on('click.ncSpeak', '[data-action="record"]', beginRecord);
            root.on('click.ncSpeak', '[data-action="stop"]', function() {
                if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                }
            });
            root.on('click.ncSpeak', '[data-action="rerecord"]', function() {
                resetRecording();
            });
            root.on('click.ncSpeak', '[data-action="submit"]', function() {
                if (!recordedBlob) {
                    Notification.addNotification({message: label('norecording'), type: 'error'});
                    return;
                }
                const transcript = String(root.find('[data-region="transcript-edit"]').val() ||
                    (transcriptFinal + ' ' + transcriptInterim).trim() || '').trim();
                const btn = root.find('[data-action="submit"]');
                btn.prop('disabled', true).text(label('submitting'));
                uploadRecording(recordedBlob).then(function() {
                    return submit({
                        draftitemid: draftItemId,
                        text: transcript,
                        duration: elapsedSec || Math.floor((Date.now() - recordStartedAt) / 1000)
                    });
                }).catch(function(err) {
                    btn.prop('disabled', false).text(label('submit'));
                    Notification.exception(err);
                });
            });
        };

        Ajax.call([{
            methodname: 'local_nexcomm_get_activity',
            args: {activityid: activityId}
        }])[0].then(function(data) {
            activity = data;
            try {
                activity.questions = JSON.parse(data.questionsJson || '[]');
            } catch (e) {
                activity.questions = [];
            }
            root.find('[data-region="title"]').text(data.title || '');
            root.find('[data-region="skill-badge"]')
                .text(data.skill || '')
                .attr('class', 'nc-badge nc-badge--' + (data.skill || ''));
            if (data.skill === 'writing') {
                renderWriting();
            } else if (data.skill === 'speaking') {
                renderSpeaking();
            } else if (data.skill === 'listening') {
                renderListening();
            } else {
                renderReading();
            }
            return null;
        }).catch(Notification.exception);
    };

    return {init: init};
});
