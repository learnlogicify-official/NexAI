# NexInterview

Site-wide AI technical interview hub (navbar), sibling to NexPractice.

## Install

1. Ensure **NexPractice** (`local_learnlogic`) and CodeRunner Ace are installed.
2. Install `nexinterview.zip` (root folder `nexinterview/`).
3. Set **Site administration → Plugins → Local plugins → NexInterview**:
   - Service URL (Railway)
   - Shared secret (match `SHARED_SECRET`)
4. Optionally map tracks to NexPractice problem ids in **Problem map**.
5. Purge caches.

## Flow

Hub → standard tracks **or custom interviewers** → resume → devices → voice interview → optional NexPractice coding.

### Custom interviewers

Managers / editing teachers: hub → **Custom interviewers** (or `/local/nexinterview/manage.php`).

Configure name, description, base role, topics, duration (10–45), style (friendly / strict / brief), briefing notes, and whether coding is included. Enabled profiles appear on the student hub and are injected into the interview-service prompt.

## Interview engine (Railway `interview-service`)

- **Question graph** — curated competency nodes with follow-ups / deep probes (not free-form chat only)
- **Skill state** — per-session skill tree drives next-topic selection (weakest first)
- **Evidence + H0–H3 hints** — scored evidence ledger; independence affects overall report
- **Report** — dimensions, independence, skill confidence, recruiter-style timeline
- **Gladia live STT** — set `GLADIA_API_KEY` from [app.gladia.io](https://app.gladia.io); browser streams PCM to Gladia WebSocket (Whisper remains fallback)

Deploy the updated `interview-service` to Railway whenever you change Python under `interview-service/app/`.
