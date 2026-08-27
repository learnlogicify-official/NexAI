# NexStack (`local_nexstack`)

**Full-stack practice studio** for Moodle — multi-file editor, live preview, mission steps, and a **remote Docker sandbox** for Node/Vite (and later DB) missions.

## Install (Moodle plugin)

1. Prefer uninstalling any older NexStack, then install fresh.
2. Unzip so the plugin is `{moodle}/local/nexstack/` (zip root folder `nexstack/`).
3. **Site administration → Notifications**
4. **Purge all caches**
5. Open `/local/nexstack/index.php`

Pack:

```bash
python3 pack_nexstack.py
```

## Sandbox server (required for Node/Vite)

In-browser WebContainers need COOP/COEP headers that many Moodle hosts strip. NexStack now prefers a **separate sandbox service**:

```bash
cd sandbox-server
cp .env.example .env   # set TOKEN
npm install
npm start              # :7077
```

Then in Moodle: **Site admin → Plugins → NexStack**

- Enable remote sandbox server
- Sandbox URL: `http://127.0.0.1:7077` (from the Moodle server’s view)
- Sandbox token: same as `.env` `TOKEN`

Studio opens → sandbox boots automatically (`npm install` + preview) → preview URL loads in the IDE.

See `sandbox-server/README.md` for API and nginx notes.

## Missions (seeded)

| Slug | Runtime | Focus |
|------|---------|--------|
| `campus-landing` | static | HTML/CSS/JS landing + CTA |
| `event-rsvp` | static | Form + validation + localStorage |
| `vite-counter` | sandbox (Node) | Vite + React via Docker |

## Architecture

```
Browser (NexStack studio)
    │  Moodle AJAX
    ▼
Moodle (local_nexstack) ──Bearer token──► sandbox-server
                                              │
                                              ▼
                                         Docker (node:20)
                                         npm install / npm run dev
                                         published preview port
```

Static missions stay fully client-side (blob preview).  
Full-stack + DB: extend sandbox with compose templates (Postgres + API + web) — same session API.

## Legacy WebContainers

Still available behind a setting, but not recommended. Prefer the sandbox server.

## Capabilities

| Cap | Default |
|-----|---------|
| `local/nexstack:view` | authenticated |
| `local/nexstack:attempt` | authenticated |
| `local/nexstack:manage` | manager |
