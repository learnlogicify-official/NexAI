# NexProctor (`local_nexproctor` + `quizaccess_nexproctor`)

Moodle-native quiz proctoring for Moodle 5 / RemUI: forced webcam, microphone, screen share, fullscreen; face / multi-face / attention checks; noise clips; multi-monitor block; tab-switch screen capture; teacher trust reports.

## Install (two zips)

1. Install **`nexproctor.zip`** first (folder inside zip: `nexproctor/` → `local/nexproctor`)
2. Install **`quizaccess_nexproctor.zip`** (folder inside zip: `nexproctor/` → `mod/quiz/accessrule/nexproctor`)
3. Complete upgrade (access rule **0.1.1+** — must include root `rule.php`)
4. **Purge all caches**
5. Confirm under **Site administration → Plugins → Activity modules → Quiz → Access rules** that **NexProctor** is listed/enabled

## Enable on a quiz

1. Edit quiz → **Extra restrictions on attempts** / NexProctor section  
2. Set **Enable NexProctor** = Yes  
3. Toggle sensors as needed  
4. Save

Students must complete `/local/nexproctor/preflight.php` before attempting.

## Teacher report

`/local/nexproctor/report.php?cmid=<quiz_cmid>`

## Browser notes

- Chrome / Edge desktop recommended  
- Face detection uses **MediaPipe Face Detector** (BlazeFace), shipped in the plugin — runs in the browser  
- Multi-monitor uses Window Management API / `screen.isExtended` (not available everywhere)  
- Attention monitoring is **head/face-position based**, not lab-grade eye tracking  

## Works with LL Assessment arena

`local_llassessment` calls `local_nexproctor_bootstrap_on_attempt()` so the monitor HUD still loads under the arena shell.
