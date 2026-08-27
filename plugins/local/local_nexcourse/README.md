# NexCourse — My Courses hub

Student-facing replacement for Moodle `/my/courses.php`, styled like NexPractice and NexCodeLab.

## Install

1. Install `local_nexcourse.zip` as a **local** plugin (folder `nexcourse` → `local/nexcourse`).
2. Visit **Site administration → Notifications** to finish install.
3. Purge caches.
4. Open **My courses** in the navbar (or `/my/courses.php`) — redirects to `/local/nexcourse/index.php` when enabled.

> Do not confuse with `format_nexcourse` (in-course format). This plugin is the **course list hub**.

## Settings

- **Replace Moodle My Courses page** (default on)
- **Add NexCourse to the custom menu** (default off — use when redirect is disabled)

## Related

- `format_nexcourse` — course view / section UI
- `local_nexdashboard` — student home (`/my/`)
