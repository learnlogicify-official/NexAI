# NexReports (`local_nexreports`)

Site analytics dashboard for Nex Academy Moodle sites.

## Install

1. Copy this folder to `{moodle}/local/nexreports/`, or install from `nexreports.zip`.
2. Visit **Site administration → Notifications** to install.
3. Open **Site administration → Reports → NexReports**, or use the custom menu link when enabled.

## Access

For now the reports UI (menu link, pages, downloads, and report AJAX) is limited to **site administrators** (`is_siteadmin()`). That covers every account listed under Site administration → Users → Site administrators — not Moodle user id 1, which is the guest account on a standard install. Capability definitions remain in the plugin so role-based access can be restored later without another schema change. The dwell-time tracker still runs for every logged-in user so learner activity continues to be collected.

## Phase 1 — Super Admin Overview

Site administrators see the Overview tab and the rest of the NexReports shell. Teachers and students do not see the menu or pages while this temporary gate is in place. Custom reports and remaining Edwiser Pro reports are scaffolded and tracked in `PARITY.md`.

Users with site-admin access see:

- KPI cards: registrations, enrolments, completions, active users, time spent (7 / 30 day periods)
- Charts: site overview status, visits (with searchable user filter for per-user visits), user-wise time spent on site, course-wise time spent, and **course activity status** (assignment submissions + activity completions, filtered by course → group → user)
- Chart cards include an export menu (PDF, PNG, JPEG, SVG)
- **Course progress** — average completion percent and a donut of learners in the Edwiser buckets (81–100 … 0–20), filtered by course then group. Group stays empty until a course is chosen. Progress comes from `nexreports_course_progress`; learners without a cache row count in 0–20%. Enrolment set matches Edwiser (includes inactive/suspended enrolments).
- Tables: popular courses, real-time / recent users
- **Courses** tab — Edwiser-style sub-nav with **All Courses Summary**, **Course Activities Summary**, **Course Activity Completion**, **Course Completion (Without Pass Grade Condition)**, **Full course grades** (sections as column groups, activities as sub-columns, marks + course total, college/year/department filters, CSV), and **Course Completion**
- **My learning** tab — **My time spent on site** for the signed-in account (7 / 30 day periods, HH:MM:SS display, chart export)

**Time spent** is *measured* by a lightweight browser heartbeat (same approach as Edwiser Reports Pro): a tracker on every page counts seconds while the tab is visible and posts them every 5 minutes (configurable) into `nexreports_tracking`. Reports sum this table per day / per course. Days **before** tracking was installed fall back to an estimate that sums gaps between consecutive `logstore_standard_log` events within the configured session gap (default 20 minutes). Each day is served by exactly one source, so nothing is double counted.

- **Time spent on site** shows a daily trend and can be filtered by user.
- Filter dropdowns are search-driven: options are not preloaded with the page. Opening a dropdown shows a search box and fetches matches from the database as you type, so every user and course is reachable regardless of site size.
- **Time spent on course** shows the top 12 courses by engaged time and is filtered in order by course, then group, then user. The group and user lists stay empty until a course is chosen, then offer only that course's groups and enrolled users; picking a group narrows the users to its members. Changing the course or group resets the filters below it.
- Course time is counted only when two consecutive events are in the same course, preventing navigation between courses from being attributed to either course.

### How figures are counted

KPI definitions follow Edwiser Reports Pro so both dashboards report the same totals on the same site.

- **Window.** Periods are whole day buckets (`floor(timestamp / 86400)`) ending with yesterday. Today is excluded, so a period never contains a partial day and the previous-period comparison is like for like.
- **New registrations** — every `{user}` row created in the window, including administrators and accounts deleted since.
- **Course enrolments** — `\core\event\user_enrolment_created` log events, counted once per course/user pair, and only for users who hold a student-archetype role in that context. Re-enrolments of the same learner in the same course count once, and teacher or manager enrolments are not counted. Falls back to `{user_enrolments}` if the standard log store is unavailable.
- **Course completions** — rows in `nexreports_course_progress` whose completion time falls in the window. A learner completes a course when course progress reaches 100%, timestamped by the last activity finished. Only `COMPLETION_COMPLETE` and `COMPLETION_COMPLETE_PASS` count, so a failed attempt leaves the activity incomplete, and labels are ignored. Courses that never configured Moodle completion criteria are still counted; reading `{course_completions}` instead would report zero for them.
- **Active users** — distinct learners (`userid > 1` holding a student-archetype role in some course) with an `action = 'viewed'` log event in the window. Teacher, manager, and admin browsing does not count.
- **Visits** — count of `action = 'viewed'` log rows for a course page or an activity, so viewing a dashboard, profile, grade report or calendar is not a visit. Excludes the guest and primary admin accounts (`userid > 2`) and anyone since deleted. Note that a site running Edwiser Reports alongside will show a far larger figure: with its precalculation setting enabled, that block counts every log row, including logins, submissions and grade changes.
- **Time spent** — measured dwell time, not a log-derived count; see above. Days before tracking was installed use the log-gap estimate, so those days read differently from a plugin that only ever reports measured time.
- Time-spent, inactive-user, and real-time-user panels still exclude the guest account and site administrators.

### Performance

Default overview views (no user/course filter, 7- and 30-day periods) are **precomputed by a scheduled task** every 10 minutes into the `nexreports_snapshot` table. The dashboard reads those rows first, so opening the report does not scan the log store. Real-time users are always refreshed on load. Filtered user/course views stay lazy-cached on demand.

If cron has not run yet or a snapshot is older than the configured max age, the report falls back to a live calculate-and-cache path so the UI still works.

Daily activity and visits are aggregated in SQL, and the time-spent session-gap scan uses numeric day bucketing.

Later phases (course, NexPractice, portfolio, student) are reserved in the UI tabs but not built yet.

## Settings

- **Show in navigation menu** — adds a top custom-menu link
- **Enable time tracking** — turns the browser heartbeat on/off (default on). When off, time spent is estimated from log gaps only.
- **Tracking flush frequency** — seconds between heartbeat posts (default 300, range 30–900)
- **Overview cache lifetime** — seconds for the in-memory application cache layer (default 600)
- **Time spent session gap** — minutes of inactivity that still count as continuous time (default 20, range 1–120). Lower it to bring totals closer to trackers that measure real dwell time.
- **Snapshot max age** — minutes after which a precomputed snapshot is ignored and a live calculate runs (default 30)

The refresh schedule itself is controlled under **Site administration → Server → Tasks → Scheduled tasks → Refresh NexReports overview snapshots** (default every 10 minutes). After install, run cron once (or click Run now on that task) so the first snapshots exist.

If **Refresh NexReports course progress cache** shows `Cannot obtain task lock`, a previous run is still holding the lock (or cron is already executing it). Bypass the lock from the Moodle root:

```bash
php local/nexreports/cli/refresh_progress.php --unlock
```

That rebuilds the progress cache outside the task manager, clears a stuck lock, and prints how many completions fall in the 7- and 30-day KPI windows. Then run the overview snapshot task and reload the dashboard.

To see how one learner's percentage was reached, activity by activity:

```bash
php local/nexreports/cli/refresh_progress.php --explain=82:3112
```

Each activity is listed with whether it counted towards progress, why it did not when it was skipped, and the learner's completion state, followed by the resulting figures next to the cached row.

Completion is always recomputed from the current course. Adding an activity therefore takes a learner back below 100% and clears their completion time, so a completion counted yesterday can drop out of the KPI today. This is Moodle's own rule, but it makes completion counts move on courses that gain activities regularly, such as a daily assessment course.

From 0.3.6, progress refreshes use Moodle's bulk completion API in pages of 1,000 learners and replace each course's cache with batched inserts. This avoids the previous per-learner/per-activity query loop and makes full rebuilds substantially faster.

From 0.3.7, a learner's course progress follows Moodle core exactly: only visible activities count, and only `Complete` and `Complete (passed)` states count as done. Activities a learner attempted but failed no longer count towards progress, so they no longer push a learner to 100%. Group and grouping restrictions shrink the denominator for the learners they exclude, matching core's per-user activity list.
