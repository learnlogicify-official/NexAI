/**
 * Multichoice / true-false option chrome:
 * - Prompt: "Select one" vs "Select all that apply"
 * - Full-row selectable options (radios/checkboxes visually hidden)
 *
 * @module     local_llassessment/mcq
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    const PROMPT_SINGLE = 'Select one';
    const PROMPT_MULTI = 'Select all that apply';

    /**
     * @param {Element} answer
     * @return {{multi: boolean, inputs: Element[]}|null}
     */
    const detectMode = function(answer) {
        const checks = Array.prototype.slice.call(
            answer.querySelectorAll('input[type="checkbox"]')
        ).filter(function(i) {
            return !i.closest('.questionflag');
        });
        const radios = Array.prototype.slice.call(
            answer.querySelectorAll('input[type="radio"]')
        );
        if (checks.length) {
            return {multi: true, inputs: checks};
        }
        if (radios.length) {
            return {multi: false, inputs: radios};
        }
        return null;
    };

    /**
     * @param {Element} answer
     * @param {boolean} multi
     */
    const ensurePrompt = function(answer, multi) {
        const host = answer.closest('.ablock')
            || answer.closest('.formulation')
            || answer.parentElement;
        if (!host) {
            return;
        }
        let prompt = host.querySelector(':scope > .prompt, .prompt');
        // Prefer a prompt that sits with this answer block.
        const siblings = host.querySelectorAll('.prompt');
        if (siblings.length) {
            prompt = siblings[0];
        }
        if (!prompt) {
            prompt = document.createElement('div');
            prompt.className = 'prompt ll-mcq-prompt';
            host.insertBefore(prompt, answer);
        }
        prompt.classList.add('ll-mcq-prompt');
        prompt.textContent = multi ? PROMPT_MULTI : PROMPT_SINGLE;
        prompt.setAttribute('data-ll-mcq-mode', multi ? 'multi' : 'single');
    };

    /**
     * @param {Element} row
     * @param {HTMLInputElement} input
     */
    const syncRow = function(row, input) {
        row.classList.toggle('is-selected', !!input.checked);
        row.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    };

    /**
     * @param {Element} answer
     * @param {HTMLInputElement[]} inputs
     * @param {boolean} multi
     * @param {boolean} [readonly]
     */
    const enhanceOptions = function(answer, inputs, multi, readonly) {
        answer.classList.add('ll-mcq-answer');
        answer.classList.toggle('ll-mcq-answer--multi', multi);
        answer.classList.toggle('ll-mcq-answer--single', !multi);

        inputs.forEach(function(input) {
            let row = input.closest('.r0, .r1');
            if (!row) {
                row = input.parentElement;
                // Walk up until we find a single-choice row (not the .answer wrapper).
                while (row && row !== answer) {
                    const radios = row.querySelectorAll('input[type="radio"], input[type="checkbox"]');
                    const choiceInputs = Array.prototype.filter.call(radios, function(i) {
                        return !i.closest('.questionflag')
                            && !i.classList.contains('visually-hidden')
                            && i.type !== 'hidden';
                    });
                    if (choiceInputs.length === 1 && choiceInputs[0] === input) {
                        break;
                    }
                    row = row.parentElement;
                }
            }
            if (!row || row === answer || answer.contains(row) === false) {
                return;
            }
            // Never style a node that wraps multiple choices.
            const nested = row.querySelectorAll('input[type="radio"], input[type="checkbox"]');
            const nestedChoices = Array.prototype.filter.call(nested, function(i) {
                return !i.closest('.questionflag')
                    && !i.classList.contains('visually-hidden')
                    && i.getAttribute('type') !== 'hidden'
                    && Number(i.value) !== -1;
            });
            if (nestedChoices.length !== 1) {
                return;
            }
            if (row.classList.contains('ll-mcq-option')) {
                syncRow(row, input);
                return;
            }

            row.classList.add('ll-mcq-option');
            if (readonly || input.disabled || input.readOnly) {
                row.classList.add('ll-mcq-option--readonly');
                row.setAttribute('role', multi ? 'checkbox' : 'radio');
                row.setAttribute('aria-disabled', 'true');
                syncRow(row, input);
                return;
            }

            row.setAttribute('role', multi ? 'checkbox' : 'radio');
            row.setAttribute('tabindex', '0');
            if (!multi) {
                row.setAttribute('aria-checked', input.checked ? 'true' : 'false');
            }

            const activate = function(fromLabel) {
                if (multi) {
                    if (!fromLabel) {
                        input.checked = !input.checked;
                    }
                } else if (!input.checked) {
                    input.checked = true;
                }
                try {
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                } catch (e) {
                    // Ignore.
                }
                inputs.forEach(function(inp) {
                    const r = inp.closest('.ll-mcq-option') || inp.closest('.r0, .r1, div');
                    if (r) {
                        syncRow(r, inp);
                    }
                });
            };

            row.addEventListener('click', function(ev) {
                if (ev.target.closest('a, button, textarea, select')) {
                    return;
                }
                ev.preventDefault();
                activate(false);
            });

            row.addEventListener('keydown', function(ev) {
                if (ev.key === ' ' || ev.key === 'Enter') {
                    ev.preventDefault();
                    activate(false);
                }
            });

            input.addEventListener('change', function() {
                inputs.forEach(function(inp) {
                    const r = inp.closest('.ll-mcq-option') || inp.closest('.r0, .r1, div');
                    if (r) {
                        syncRow(r, inp);
                    }
                });
            });

            syncRow(row, input);
        });
    };

    /**
     * @param {Element} [root]
     * @param {Object} [opts]
     */
    const enhance = function(root, opts) {
        opts = opts || {};
        root = root || document.getElementById('ll-arena') || document;
        const readonly = !!opts.readonly || !!document.body.classList.contains('ll-arena-review');
        const answers = Array.prototype.slice.call(root.querySelectorAll('.answer'));
        answers.forEach(function(answer) {
            // Skip CodeRunner / non-choice answer blocks.
            if (answer.closest('.ll-arena-split--coderunner, .coderunner, .que.coderunner')) {
                return;
            }
            const mode = detectMode(answer);
            if (!mode) {
                return;
            }
            const que = answer.closest('.que, .ll-arena-question-wrap, [data-qtype]');
            if (que) {
                que.classList.add('ll-mcq');
                que.classList.toggle('ll-mcq--multi', mode.multi);
                que.classList.toggle('ll-mcq--single', !mode.multi);
            }
            ensurePrompt(answer, mode.multi);
            enhanceOptions(answer, mode.inputs, mode.multi, readonly);

            // Strip RemUI/Moodle blue plate from wrappers around the choices.
            const wipe = [answer,
                answer.closest('.ablock'),
                answer.closest('fieldset'),
                answer.closest('.formulation'),
                answer.closest('.content'),
                answer.closest('.ll-arena-split__response')];
            wipe.forEach(function(el) {
                if (!el) {
                    return;
                }
                el.style.setProperty('background', '#ffffff', 'important');
                el.style.setProperty('background-color', '#ffffff', 'important');
                el.style.setProperty('background-image', 'none', 'important');
                el.style.setProperty('box-shadow', 'none', 'important');
                if (el.classList.contains('ablock')
                    || el.classList.contains('answer')
                    || el.tagName === 'FIELDSET') {
                    el.style.setProperty('border', '0', 'important');
                    el.style.setProperty('padding', '0', 'important');
                }
            });
        });
    };

    return {
        enhance: enhance
    };
});
