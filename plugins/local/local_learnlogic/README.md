# NexPractice (`local_learnlogic`)

LeetCode-style coding practice for Moodle. Students pick problems, run sample tests, and submit against the full suite. Execution uses **CodeRunner** (`qtype_coderunner`) — not Judge0.

## Requirements

- Moodle **5.0+**
- [`qtype_coderunner`](https://moodle.org/plugins/qtype_coderunner) installed and working (Jobe sandbox or equivalent)
- At least one CodeRunner question per language you enable (used as an execution **prototype**)

## Install

1. Unzip so the plugin lands at `moodle/local/learnlogic/` (the zip’s inner folder is `learnlogic/`).
2. Visit **Site administration → Notifications** to install.
3. Purge caches if the NexPractice menu item or styles do not appear.

## Configure CodeRunner prototypes

**Site administration → Plugins → Local plugins → NexPractice**

Set **Prototype question id** for each language (`python3`, `java`, `cpp`, …) to the Moodle question id of a working CodeRunner question. NexPractice clones that question’s template/sandbox settings at run time.

Without prototype ids, Run/Submit will show a configuration error.

## Features (v1)

- Navbar **NexPractice** entry (RemUI / custom menu)
- Problem list with search, difficulty, tags, and All / Completed / In progress / Not started
- Split-pane IDE: description, samples, custom stdin, Run / Submit
- Submissions history, drafts autosave
- XP, daily streaks, leaderboard
- Teacher **Manage problems** CRUD (capability `local/learnlogic:manageproblems`)

## Import from CodeRunner

**Manage problems → Import from CodeRunner**

1. Choose a **question bank**, then select CodeRunner questions.
2. NexPractice **links** to the live CodeRunner question (does not copy templates/tests).
3. The IDE always loads the **latest question-bank version** of that link — edit tests in CodeRunner and they appear in NexPractice without re-import.
4. Difficulty / tags / Ready status are NexPractice metadata only.

No separate per-language prototype setup is required for linked questions — CodeRunner’s own template is used.

## Capabilities

| Capability | Default |
|------------|---------|
| `local/learnlogic:view` | Authenticated users |
| `local/learnlogic:attempt` | Authenticated users |
| `local/learnlogic:manageproblems` | Editing teachers, managers |

## Zip layout

```
learnlogic.zip
└── learnlogic/          → extracts to local/learnlogic
    ├── version.php
    ├── …
```
