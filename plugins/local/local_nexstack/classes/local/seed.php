<?php
namespace local_nexstack\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Seed starter Full Stack missions.
 */
class seed {

    public static function install_defaults(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexstack_mission')) {
            return;
        }
        foreach (self::definitions() as $def) {
            if ($DB->record_exists('local_nexstack_mission', ['slug' => $def['slug']])) {
                continue;
            }
            $now = time();
            $DB->insert_record('local_nexstack_mission', (object) [
                'name' => $def['name'],
                'slug' => $def['slug'],
                'track' => $def['track'],
                'difficulty' => $def['difficulty'],
                'runtime' => $def['runtime'],
                'summary' => $def['summary'],
                'briefmd' => $def['briefmd'],
                'scaffoldjson' => json_encode($def['files'], JSON_UNESCAPED_SLASHES),
                'stepsjson' => json_encode($def['steps'], JSON_UNESCAPED_SLASHES),
                'status' => 'ready',
                'sortorder' => (int) $def['sortorder'],
                'estimatedmins' => (int) $def['estimatedmins'],
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => 0,
            ]);
        }
    }

    /**
     * @return array<int,array>
     */
    public static function definitions(): array {
        return [
            self::mission_campus_landing(),
            self::mission_rsvp(),
            self::mission_vite_counter(),
        ];
    }

    private static function mission_campus_landing(): array {
        return [
            'name' => 'Campus Landing Page',
            'slug' => 'campus-landing',
            'track' => 'web',
            'difficulty' => 'easy',
            'runtime' => 'static',
            'sortorder' => 10,
            'estimatedmins' => 30,
            'summary' => 'Build a responsive landing page with HTML, CSS, and a little JS.',
            'briefmd' => "# Campus Landing Page\n\nCreate a simple marketing page for **Nex Academy**.\n\n## Goals\n- Semantic HTML structure\n- Styled hero + CTA\n- A working \"Join waitlist\" button that shows a thank-you message\n",
            'files' => [
                'index.html' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Nex Academy</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <!-- TODO: hero with id="hero", h1, CTA button id="cta" -->
  <main>
    <p>Start building your landing page.</p>
  </main>
  <script src="app.js"></script>
</body>
</html>
HTML,
                'styles.css' => <<<'CSS'
:root {
  --bg: #0b1220;
  --text: #e5e7eb;
  --accent: #22c55e;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: system-ui, sans-serif;
  background: var(--bg);
  color: var(--text);
}
/* TODO: style #hero and #cta */
CSS,
                'app.js' => <<<'JS'
// TODO: when #cta is clicked, show #thanks (create it if missing)
console.log('Campus landing ready');
JS,
            ],
            'steps' => [
                [
                    'id' => 0,
                    'title' => 'Hero structure',
                    'instructions' => <<<'MD'
Build the page’s first impression in HTML.

### What to add
- A `<section id="hero">` that wraps the headline and call-to-action
- An `<h1>` inside the hero with a clear campus / product message
- A `<button id="cta">` whose label is exactly **Join waitlist**

### Why it matters
The hero is what learners see first. Stable ids (`hero`, `cta`) let later CSS and JS hook in without brittle selectors.

### Tip
Replace the placeholder `<main>` content in `index.html` — keep the page semantic and simple.
MD,
                    'checks' => [
                        ['type' => 'dom', 'selector' => '#hero', 'assert' => 'exists'],
                        ['type' => 'dom', 'selector' => '#hero h1', 'assert' => 'exists'],
                        ['type' => 'dom', 'selector' => '#cta', 'assert' => 'exists'],
                    ],
                ],
                [
                    'id' => 1,
                    'title' => 'Style the hero',
                    'instructions' => <<<'MD'
Make the hero feel intentional with CSS — not just default browser styles.

### What to style
- `#hero` — add comfortable padding and spacing so the block breathes
- `#cta` — give the button a **green** background (use your CSS variables if you defined any)

### Acceptance
Open Preview: the hero should look like a marketing block, and the CTA should clearly stand out as a clickable action.

### Tip
Edit `styles.css`. Selectors `#hero` and `#cta` must appear in that file.
MD,
                    'checks' => [
                        ['type' => 'file_includes', 'path' => 'styles.css', 'needle' => '#hero'],
                        ['type' => 'file_includes', 'path' => 'styles.css', 'needle' => '#cta'],
                    ],
                ],
                [
                    'id' => 2,
                    'title' => 'CTA interaction',
                    'instructions' => <<<'MD'
Wire the waitlist button so it feels interactive.

### Behaviour
When `#cta` is clicked:
1. Create (or reveal) an element with `id="thanks"`
2. Show a short thank-you message inside it

### Acceptance
Click **Join waitlist** in Preview — a thanks message should appear without a page reload.

### Tip
Listen for the click in `app.js`. You can `document.createElement` the thanks node if it is not already in the HTML.
MD,
                    'checks' => [
                        ['type' => 'file_includes', 'path' => 'app.js', 'needle' => 'cta'],
                        ['type' => 'file_includes', 'path' => 'app.js', 'needle' => 'thanks'],
                    ],
                ],
            ],
        ];
    }

    private static function mission_rsvp(): array {
        return [
            'name' => 'Campus Event RSVP',
            'slug' => 'event-rsvp',
            'track' => 'web',
            'difficulty' => 'easy',
            'runtime' => 'static',
            'sortorder' => 20,
            'estimatedmins' => 45,
            'summary' => 'Form validation + localStorage list for event RSVPs.',
            'briefmd' => "# Campus Event RSVP\n\nCollect RSVPs for a campus tech talk.\n\n## Goals\n- Form with name + email\n- Client-side validation\n- Persist RSVPs in localStorage and render a list\n",
            'files' => [
                'index.html' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Event RSVP</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <main class="wrap">
    <h1 id="event-title">Campus Tech Talk</h1>
    <form id="rsvp-form">
      <!-- TODO: name, email inputs + submit -->
    </form>
    <p id="form-error" hidden></p>
    <ul id="rsvp-list"></ul>
  </main>
  <script src="app.js"></script>
</body>
</html>
HTML,
                'styles.css' => <<<'CSS'
body { font-family: system-ui, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
.wrap { max-width: 480px; margin: 2rem auto; padding: 0 1rem; }
#form-error { color: #b91c1c; }
CSS,
                'app.js' => <<<'JS'
const KEY = 'nexstack_rsvp_v1';
// TODO:
// 1) Build form fields name + email
// 2) Validate email contains @
// 3) Save to localStorage and render #rsvp-list
JS,
            ],
            'steps' => [
                [
                    'id' => 0,
                    'title' => 'Form fields',
                    'instructions' => <<<'MD'
Build the RSVP form so guests can enter their details.

### Inside `#rsvp-form`, add
- A text input with `name="name"`
- An email input with `name="email"`
- A submit control (`button type="submit"` or `input type="submit"`)

### Why
These names match what your JS will read on submit. Keep labels or placeholders so the form is usable.

### Tip
You can leave `#form-error` and `#rsvp-list` as they are for the next steps.
MD,
                    'checks' => [
                        ['type' => 'dom', 'selector' => '#rsvp-form [name="name"]', 'assert' => 'exists'],
                        ['type' => 'dom', 'selector' => '#rsvp-form [name="email"]', 'assert' => 'exists'],
                        ['type' => 'dom', 'selector' => '#rsvp-form button[type="submit"], #rsvp-form input[type="submit"]', 'assert' => 'exists'],
                    ],
                ],
                [
                    'id' => 1,
                    'title' => 'Validate email',
                    'instructions' => <<<'MD'
Stop bad emails before anything is saved.

### On form submit
1. Read the email value
2. If it does **not** contain `@`, show `#form-error` with a clear message
3. Prevent saving / further handling when invalid

### Acceptance
Submitting `not-an-email` should show the error; a value like `ada@nex.academy` should pass validation.

### Tip
Use `event.preventDefault()` and toggle the `hidden` attribute (or text) on `#form-error`.
MD,
                    'checks' => [
                        ['type' => 'file_includes', 'path' => 'app.js', 'needle' => 'form-error'],
                        ['type' => 'file_includes', 'path' => 'app.js', 'needle' => '@'],
                    ],
                ],
                [
                    'id' => 2,
                    'title' => 'Persist list',
                    'instructions' => <<<'MD'
Remember RSVPs across refreshes and show them on the page.

### Requirements
- Save each valid RSVP with `localStorage` (key is already hinted in `app.js`)
- Render the saved entries into `#rsvp-list` (for example as `<li>` items)

### Acceptance
Add a guest, refresh Preview — the name/email should still appear in the list.

### Tip
`JSON.parse` / `JSON.stringify` around an array works well for this small dataset.
MD,
                    'checks' => [
                        ['type' => 'file_includes', 'path' => 'app.js', 'needle' => 'localStorage'],
                        ['type' => 'file_includes', 'path' => 'app.js', 'needle' => 'rsvp-list'],
                    ],
                ],
            ],
        ];
    }

    private static function mission_vite_counter(): array {
        return [
            'name' => 'Vite Counter (WebContainer)',
            'slug' => 'vite-counter',
            'track' => 'frontend',
            'difficulty' => 'medium',
            'runtime' => 'webcontainer',
            'sortorder' => 30,
            'estimatedmins' => 40,
            'summary' => 'Boot a real Vite app in-browser with WebContainers — npm install + live preview.',
            'briefmd' => "# Vite Counter\n\nThis mission runs **inside WebContainers** (StackBlitz-style):\n\n1. Studio boots Node in your browser\n2. Runs `npm install` + `npm run dev`\n3. You edit React and see HMR preview\n\n## Goals\n- Counter increments on click\n- Display count in `#count`\n",
            'files' => [
                'package.json' => json_encode([
                    'name' => 'nexstack-vite-counter',
                    'private' => true,
                    'type' => 'module',
                    'scripts' => [
                        'dev' => 'vite --host',
                        'build' => 'vite build',
                    ],
                    'dependencies' => [
                        'react' => '^18.3.1',
                        'react-dom' => '^18.3.1',
                    ],
                    'devDependencies' => [
                        '@vitejs/plugin-react' => '^4.3.4',
                        'vite' => '^5.4.11',
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'vite.config.js' => <<<'JS'
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: { host: true, port: 5173, strictPort: true },
});
JS,
                'index.html' => <<<'HTML'
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NexStack Counter</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.jsx"></script>
  </body>
</html>
HTML,
                'src/main.jsx' => <<<'JSX'
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';

createRoot(document.getElementById('root')).render(<App />);
JSX,
                'src/App.jsx' => <<<'JSX'
import React, { useState } from 'react';

export default function App() {
  const [count, setCount] = useState(0);
  // TODO: wire button#inc to increment and show value in #count
  return (
    <main className="wrap">
      <h1>Vite Counter</h1>
      <p>Count: <strong id="count">{count}</strong></p>
      <button id="inc" type="button">Increment</button>
    </main>
  );
}
JSX,
                'src/styles.css' => <<<'CSS'
body { margin: 0; font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }
.wrap { max-width: 28rem; margin: 3rem auto; padding: 1rem; }
button { background: #22c55e; border: 0; padding: .6rem 1rem; border-radius: 8px; font-weight: 600; cursor: pointer; }
CSS,
            ],
            'steps' => [
                [
                    'id' => 0,
                    'title' => 'Boot & preview',
                    'instructions' => <<<'MD'
Bring the Vite app to life inside WebContainers.

### Do this
1. Click **Boot WebContainer** in the studio toolbar
2. Wait until `npm install` and `npm run dev` finish in the terminal
3. Confirm Preview shows **Vite Counter** with a count display

### Acceptance
Preview is live (not a blank/static fallback), and `src/App.jsx` still contains a `count` value in state.

### Tip
If boot fails, check that SharedArrayBuffer / COOP-COEP is available for this Moodle page.
MD,
                    'checks' => [
                        ['type' => 'file_includes', 'path' => 'src/App.jsx', 'needle' => 'count'],
                        ['type' => 'runtime', 'assert' => 'webcontainer_ready'],
                    ],
                ],
                [
                    'id' => 1,
                    'title' => 'Increment handler',
                    'instructions' => <<<'MD'
Make the counter interactive with React state.

### Behaviour
When the `#inc` button is clicked, update state so `#count` shows the new number.

### What to implement
- Use `setCount` (or equivalent) to increment
- Attach an `onClick` handler on the increment control

### Acceptance
Each click increases the displayed count by one without reloading.

### Tip
Edit `src/App.jsx`. Keep the `id="count"` and `id="inc"` hooks so checks and Preview stay aligned.
MD,
                    'checks' => [
                        ['type' => 'file_includes', 'path' => 'src/App.jsx', 'needle' => 'setCount'],
                        ['type' => 'file_includes', 'path' => 'src/App.jsx', 'needle' => 'onClick'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Refresh seeded mission copy (steps/brief/summary) without wiping workspaces.
     */
    public static function refresh_mission_copy(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexstack_mission')) {
            return;
        }
        foreach (self::definitions() as $def) {
            $rec = $DB->get_record('local_nexstack_mission', ['slug' => $def['slug']]);
            if (!$rec) {
                continue;
            }
            $rec->name = $def['name'];
            $rec->summary = $def['summary'];
            $rec->briefmd = $def['briefmd'];
            $rec->stepsjson = json_encode($def['steps'], JSON_UNESCAPED_SLASHES);
            $rec->scaffoldjson = json_encode($def['files'], JSON_UNESCAPED_SLASHES);
            $rec->timemodified = time();
            $DB->update_record('local_nexstack_mission', $rec);
        }
    }
}
