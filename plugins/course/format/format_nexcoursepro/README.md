# NexCoursePro format

Full-screen Moodle **course format** inspired by modern learn UIs: main lesson pane + right syllabus rail (Content / Discussion / File), search, completion checks, and Back / Next Chapter.

## Install

1. Upload **`nexcoursepro.zip`** (folder inside zip: `nexcoursepro/`) into `course/format/nexcoursepro`
2. Visit **Site administration → Notifications**
3. Edit a course → **Course format** → **NexCoursePro format**

## Behaviour

- **Learn view (editing off):** full-width Pro shell
  - **Stats strip:** progress donut + completed / remaining / sections (NexCourse-style)
  - **Left:** native quiz / video / lesson view (no iframe), full-bleed width of the panel
  - **Right:** Content outline only (collapsible rail, left-aligned rows, search)
  - Click outline items or Back / Next Chapter to swap the left pane without reloading
  - Sidebar toggle remembered in the browser
- **Editing on:** Moodle’s **native course editor** (sections, chooser, drag/drop). Turn Edit mode off to return to the Pro learner shell
- **Activity CTAs:** Start assessment / Watch video open the real Moodle activity when needed

## Tips

- Mark section activities with completion tracking so checkmarks appear
- Use **Page** activities for in-shell reading content (best match to the screenshot)
- Section 0 (General) is hidden from the outline

## Requirements

Moodle 5.0+ (`2025041400`)
