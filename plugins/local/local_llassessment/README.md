# LL Assessment Arena (`local_llassessment`)

## Important: console error you saw

`Could not establish connection. Receiving end does not exist` is from a **browser extension** (not Moodle). Ignore it.

If **View Source has no `ll-arena-boot`**, Moodle never ran our boot code on that page — usually an old install / caches / wrong zip layout.

## Install with the correct zip

Use the ready-made zip in the repo root: **`llassessment.zip`**

Or zip so the **root folder inside the archive is named `llassessment`**:

```text
llassessment/
  version.php
  lib.php
  check.php
  db/
  amd/
  ...
```

Do **not** zip as `local_llassessment/...`.

1. Site administration → Plugins → Install plugins → upload `llassessment.zip`
2. Complete upgrade (release should show **1.9.26**)
3. **Purge all caches**
4. Open diagnostic: `https://YOUR-SITE/local/llassessment/check.php` (admin login)
5. Open a quiz attempt → View Source → search `ll-arena-boot`

## What 1.9.29 changes

- **Quiz landing page** (`mod/quiz/view.php`): themed to match NexCourse + arena — Hind Siliguri, blue tokens, back chip, assessment hero, restyled info/attempts table, primary Attempt CTA

## What 1.9.26 changes

- **Mobile scroll fix**: main workspace scrolls again (question → editor); desktop `overflow:hidden` / flex height chain no longer traps touch scroll

## What 1.9.25 changes

- **Mobile arena layout**: question stem stacks above the editor (no side-by-side split)
- **Left drawer**: hamburger opens a collapsible left navbar with header actions + Question Navigator (closed by default)
- Slim mobile top bar keeps LIVE + timer; backdrop / Escape / question pick closes the drawer

## What 1.9.24 changes

- Review: force `showall=1` so every question loads; section tabs show all questions in the selected section

## What 1.9.23 changes

- Remove RemUI light-blue plate behind all MCQ options; blue only on the selected choice

## What 1.9.22 changes

- Fix Moodle 5.2 MCQ: remove blue background wrapping all options (fieldset.ablock / answer plate)

## What 1.9.21 changes

- Custom Test UI matched to Sample Tests / Results (same headings, pills, IO cards, Run button, banners)

## What 1.9.20 changes

- Fix custom test crash: use `quiz_attempt::get_question_attempt()` instead of unit-test-only `get_question_usage()`

## What 1.9.19 changes

- Custom Test works for **template/function** CodeRunner questions (runs through the question Twig harness)
- New fields: Test code + stdin + expected; sample chips fill both styles
- Server AJAX `local_llassessment_run_custom_test` (does not affect grades)

## What 1.9.18 changes

- Custom Test tab: run student code with custom stdin via CodeRunner sandbox web service
- Optional expected-output compare; fill-from-sample chips; clear error if WS disabled

## What 1.9.17 changes

- Review coding: remove left/right split; normal full-width problem + testcases + code

## What 1.9.16 changes

- Review coding: force problem statement to full width (override split %)

## What 1.9.15 changes

- Review coding: full-width problem statement with each testcase listed below

## What 1.9.14 changes

- Review coding questions: attempt-style code editor + test case tabs (in-page)

## What 1.9.13 changes

- Review: hide empty feedback boxes above the correct-answer panel

## What 1.9.12 changes

- Review: section-wise marks, attempted/total, and score visualizations

## What 1.9.11 changes

- Review: section tabs above questions (synced with navigator tabs)

## What 1.9.10 changes

- Review navigator: section name tabs; only attempted questions appear selected

## What 1.9.9 changes

- Style quiz review page to match arena UI (in-page, not fullscreen): review bar, sectioned navigator, MCQ cards, summary card

## What 1.9.8 changes

- Fix Back button to open the current quiz view page (not another quiz)

## What 1.9.7 changes

- Add Back button on the left side of the top header

## What 1.9.6 changes

- Show the current question section name in the top header badge

## What 1.9.5 changes

- Visible thin modern panel splitter; persist split width across question changes
- Persist Question Navigator collapsed/open state across reload

## What 1.9.4 changes

- Question Navigator groups questions under Moodle section headings
- MCQ: Select one / Select all that apply; full-row selectable options (no visible radios)

## What 1.9.3 changes

- Use Hind Siliguri as the quiz arena UI font

## What 1.9.2 changes

- Full dark mode coverage across topbar, panels, navigator, footer, modal, and IDE chrome

## What 1.9.1 changes

- Fix Flag for review (Moodle AJAX flag toggle); add light/dark mode switcher in top bar

## What 1.9.0 changes

- Submit Assessment opens a Review & Submit popup (answered / unanswered / flagged) instead of the Moodle summary page

## What 1.8.4 changes

- Hide timer host on untimed quizzes; never show Moodle timer Hide/Show toggle

## What 1.8.3 changes

- Disable Previous on first / Next on last question; problem statement card + sample formatting

## What 1.8.2 changes

- Fix footer Previous/Next: discover Moodle controls reliably and soft-navigate on click

## What 1.8.1 changes

- Question number, tags, marks, and Flag for review sit inside the left problem panel

## What 1.8.0 changes

- Visual match pass: white surfaces, LIVE topbar, progress, Flag for review, footer Previous/Next, navigator polish

## What 1.7.2 changes

- Hide Moodle Flag-question checkbox that appeared in the left problem pane after soft-nav

## What 1.7.1 changes

- Question Navigator stays clickable after soft-nav (no longer depends on Moodle’s stale form handler)

## What 1.7.0 changes

- Soft navigation: Run, Submit, next/previous, and Question Navigator load in-place with a loader (no full page refresh)

## What 1.6.1 changes

- Run switches to Sample Tests; Submit switches to Hidden Tests (survives page reload)

## What 1.6.0 changes

- Question Navigator matches reference: header, circular %, number grid, overall progress bar

## What 1.5.6 changes

- Test Cases tab shows the same Sample Test Cases cards as the left panel
- Reload keeps **Code** as the default tab (Precheck/Check still auto-switch)

## What 1.5.5 changes

- Restored Sample Test Cases card styles on the left problem panel

## What 1.5.4 changes

- Fixed Ace gutter overlay (line numbers / fold arrows no longer sit on top of code)

## What 1.5.3 changes

- Restored Ace gutter/line numbers (absolute fill was collapsing Ace into a plain-text look)

## What 1.5.2 changes

- Default editor is light Ace (textmate); removed CSS that painted over Ace gutter/colors

## What 1.5.1 changes

- Exact NexEditor chrome: Your Solution header, Run/Submit, pill tabs, dark full-bleed Ace (100% width/height), status bar

## What 1.5.0 changes

- NexEditor-style code editor: rounded shell, status bar (Ln/Col, lang, spaces, font, theme), settings panel (Light/Dark, font size, tab size)

## What 1.4.4 changes

- Honour CodeRunner Display per testcase (Show / Hide / Hide if fail / Hide if succeed) — only visible cases in Sample Tests & Results

## What 1.4.3 changes

- **Check** opens Sample Tests on the first failed case (Results overview if all pass)

## What 1.4.2 changes

- Fixed Input / Expected / Your Output shift when empty tick/cross table headers are missing from the DOM

## What 1.4.1 changes

- Precheck correctly opens **Sample Tests** (not Results)
- Fixed Input / Expected / Your Output column mapping (was shifting because "Test" was treated as Input)

## What 1.4.0 changes

- **Precheck** opens **Sample Tests** with Test 1/2 pills, Input / Expected / Your Output blocks
- **Check** opens **Results** with success banner, overview stats, and per-test grid
- IDE tabs: Code | Sample Tests | Results

## What 1.2.1 changes

- Fixes empty Problem pane / missing Ace editor (split was dropping CodeRunner nodes)
- Full-viewport arena (hides `#page-wrapper` / RemUI navbar)
- Taller editor area + flex height chain so panes fill the screen

## Keep RemUI selected

This local plugin works while RemUI stays the active theme.
