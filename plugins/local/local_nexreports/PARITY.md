# NexReports ↔ Edwiser Reports Pro 2.6.3 parity map

Functional parity target. NexReports keeps its current blue UI (tabs + cards + charts).
No Edwiser license code. Original implementation; calculations matched to observed Pro behaviour.

## Dashboard blocks → NexReports surface

| Edwiser block | NexReports home | Status |
|---|---|---|
| Site Overview Status | Overview → KPIs + overview chart | Done (0.3.1, counts aligned) |
| Visits On Site | Overview → visits chart (+ user filter / export) | Done (0.3.19) |
| Time Spent On Site | Overview → time spent on site | Done (heartbeat) |
| Time Spent On Course | Overview → time spent on course | Done (heartbeat) |
| Course Activity Status | Overview → activity status chart | Done (0.3.27) |
| Popular Courses | Overview → popular courses | Partial → complete |
| Real Time Users | Overview → realtime users | Partial → complete |
| Daily Activities | Overview → daily activities | Done (0.3.1) |
| Inactive Users List | Overview → inactive users (+ CSV) | Done (0.3.1) |
| Site Access Information | Overview → site access heatmap | Todo |
| Certificates Stats | Overview → certificates (needs `mod_customcert`) | Todo |
| Course Progress | Overview → course progress donut | Done (0.3.18) |
| Course Engagement | Courses → engagement | Todo |
| Grades | Courses → Full course grades | Done (0.4.0) |
| My Course Progress | Learner → my progress | Todo |
| My Time Spent On Site | Learner → my time | Done (0.3.28) |
| Custom Reports instances | Custom → builder + saved reports | Todo |

## Detailed reports → NexReports pages

| Edwiser report | NexReports route | Status |
|---|---|---|
| All Courses Summary | Courses tab + `/local/nexreports/courses.php` | Done (0.3.30 — Edwiser columns/filters) |
| Course Activities Summary | Courses → `/local/nexreports/course_activities.php` | Done (0.3.29) |
| Course Activity Completion | Courses → `/local/nexreports/course_activity_completion.php` | Done (0.3.29) |
| Course Completion | Courses → `/local/nexreports/course_completion.php` | Done (0.3.29) |
| Full course grades | Courses → `/local/nexreports/course_grades.php` | Done (0.4.0 — sections × activities + total + CSV) |
| All Learner Summary | Students tab | Done (0.3.0) |
| Learner Course Progress | Students / Learner | Todo |
| Learner Course Activities | Students / Courses | Todo |
| Site Overview detail | Overview drill-down | Todo |
| Certificates detail | Overview certificates | Todo |

## KPI / insight calculation parity (0.3.1)

| Metric | Edwiser source | NexReports source |
|---|---|---|
| Window | day buckets `floor(ts/86400)`, ends yesterday | same |
| New registrations | all `{user}` rows by `timecreated` | same |
| Course enrolments | distinct `courseid-relateduserid` from `user_enrolment_created` log + student role | same (log fallback: `{user_enrolments}`) |
| Course completions | `edwreports_course_progress.completiontime`: core progress = 100%, stamped by last COMPLETE/COMPLETE_PASS activity, labels skipped | same rule in `nexreports_course_progress.completiontime` |
| Completion after a course changes | recomputed, so adding an activity clears an earlier 100% — but only for rows Edwiser has flagged with `pchange`, so its table can keep showing a completion that no longer holds | same rule, applied to every row on each refresh |
| Course progress % | `core_completion\progress::get_course_progress_percentage()` — numerator counts only COMPLETE/COMPLETE_PASS on visible modules, denominator is the learner's visible non-group-restricted completion activities, 100% when `{course_completions}.timecompleted` is set | same rule computed in bulk from `get_progress_all()`, with the per-learner availability pass only for courses carrying group/grouping restrictions |
| Active users | distinct `userid > 1`, `action = 'viewed'`, restricted to student-archetype users | same (EXISTS on `role_assignments` at course context instead of a temp table) |
| Visits | `action = 'viewed'` where `target = 'course'`, or `target = 'course_module'` with a non-null `objecttable`; `userid > 2` and the user not deleted | same |
| Visits shown on the Edwiser dashboard | with `precalculated` on, the block reads `edwreports_summary_detailed`, which its task fills by counting **every** log row for the course regardless of action or target — logins, submissions and grade changes included. Its own previous-period comparison still uses the strict query above, so the percentage change is not meaningful | deliberately not matched; NexReports reports views only, so expect roughly a 9x lower total |
| Time spent | `edwreports_activity_log` sum (tracker only) | `nexreports_tracking` + log-gap for pre-tracking days (ACS uses the same; never reads Edwiser tables) |
| Daily activities — registrations / enrolments | all `{user}` by `timecreated`; `{user_enrolments}` for student-archetype users | same |
| Daily activities — course / activity completions | `edwreports_course_progress` at 100%; `{course_modules_completion}` with `completionstate <> 0`, student-scoped | `nexreports_course_progress`; same activity rule |
| Daily activities — learners / teachers | distinct users with any log that day who hold learner/teacher capability in a course | same via student / teacher archetypes at course context |
| Daily activities — visits + hourly chart | events Visits query uses `userid < 1` (under-counts); hourly chart = distinct users per hour from all logs | distinct users that day + distinct users per hour; we do not copy the broken events Visits query |
| Course progress (overview) | enrolled with `moodle/course:isincompletionreports` via `get_enrolled_sql(..., onlyactive=false)`; missing `edwreports_course_progress` row → 0–20% and omitted from average sum; average = sum / enrolled count (0 if sum is 0); UI `toPrecision(2)`; buckets ≤20 / ≤40 / ≤60 / ≤80 / else | same enrolment + bucket rules via `nexreports_course_progress` (0.3.18) |
| Course activity status | `assign_submission` status=submitted; `course_modules_completion` with `completionstate <> 0`; average = mean daily completions; filters course/group/user | same live path (0.3.27); no Edwiser precalc summary table |

## Insights

Admin: new registrations, enrolments, completions, active users, activity completions, time on courses.
Learner: courses enrolled, courses completed, activities completed, time on site.
Status: Overview KPIs cover 4 admin insights; remaining Todo.

## Platform features

| Feature | NexReports plan | Status |
|---|---|---|
| Heartbeat time tracking | `nexreports_tracking` | Done |
| Course progress cache | `nexreports_course_progress` + 5‑min task | Done (0.3.0) |
| Daily engagement rollups | `nexreports_summary` / `_detailed` | Todo |
| Site access aggregate | config + daily task | Todo |
| Snapshot cache | `nexreports_snapshot` | Done (overview defaults) |
| Custom reports | `nexreports_custom_reports` + builder UI | Todo |
| Scheduled emails | `nexreports_schedemails` + cron | Todo |
| CSV / Excel / PDF export | `classes/local/export.php` | CSV done (0.3.0); Excel/PDF todo |
| Graph image download | `nexreports_graph_data` + download.php | Todo |
| Cohort / course / group filters | `classes/local/filters.php` | Done (cohort on Courses) |
| Per-block capabilities | expand `db/access.php` | Done (role caps) |
| Privacy provider | tracking done; expand for new tables | Partial |
| NexPractice reports | soft-depend `local_learnlogic` | Done (0.3.94 — leaderboard + KPIs + CSV) |
| NexBattleGround reports | soft-depend `local_nexbattleground` | Done (0.3.98 — wins/XP leaderboard + CSV) |
| NexCodeLab reports | soft-depend `local_nexcodelab` | Done (0.3.98 — missions/XP leaderboard + CSV) |
| NexInterview reports | soft-depend `local_nexinterview` | Done (0.4.1 — attempt ledger + CSV) |
| Portfolio reports | soft-depend `local_nexportfolio` | Done (0.3.99 — Platforms + GitHub leaderboards + CSV) |

## Roles

- Manager / course creator: full site reports
- Teacher / editing teacher: course-scoped reports
- Student: Learner tab only (own progress + time)

## Out of scope (not copied)

- Edwiser license activation / RemUI theme lock-in
- Proprietary secret-key AJAX auth (`loginrequired=false` keep_alive)
- Exact RemUI markup / Chart.js skin (NexReports SVG / card system stays)
