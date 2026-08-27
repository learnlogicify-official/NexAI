# NexDashboard (`local_nexdashboard`)

Student home dashboard for Moodle — MVP + Phase 2.

Aggregates **courses**, **NexPractice**, and **NexCodeLab** into one light, card-based page that **replaces Moodle’s default Dashboard** (`/my/`).

## Behaviour

- **Replace Moodle Dashboard** (default on): `/my/` opens NexDashboard
- **Dashboard stays the native navbar item** — leftover custom-menu “Dashboard → /local/nexdashboard” links are removed automatically
- **Leaderboard** is added as its own top-navbar link (`/local/nexdashboard/leaderboard.php`)
- Set **Appearance → Navigation → Default home page → Dashboard** so login lands on `/my/`

## What’s included

**MVP**
- Greeting, learning-time estimate, course count
- Next best action + continue cards
- Weekly XP chart, streak week, player stats

**Phase 2+ (0.3.0)**
- Skill focus, mission tracks, stuck list with Retry / Ask for help
- Weekly goal with 3 / 5 / 7 picker
- Upcoming deadlines (14 days), recent activity
- This month summary + copy-to-clipboard
- Peer leaderboard snippet (overall scores)
- Overall leaderboard: course grades + NexPractice XP + CodeLab XP + BattleGround XP, with pagination
- Skeleton loader while data loads

## Requirements

- Moodle **5.0+**
- Optional: `local_learnlogic` (NexPractice), `local_nexcodelab` (CodeLab) — dashboard degrades gracefully if missing

## Install

1. Unzip so the plugin lands at `moodle/local/nexdashboard/` (zip root folder is `nexdashboard/`).
2. Site administration → Notifications to install.
3. Purge caches.
4. Confirm Default home page is **Dashboard**, then open the site Dashboard / My home.

## Configure

**Site administration → Plugins → Local plugins → NexDashboard**

- Replace Moodle Dashboard (`/my/`) — default on

## Capabilities

- `local/nexdashboard:view` — see the dashboard (students/teachers/managers by default)
