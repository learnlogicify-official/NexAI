# NexCourse format

Moodle **course format** that shows sections as module cards (grid). Progress lives in the theme course header; Moodle’s own Course / Settings / Participants / Grades nav is styled with icons (no duplicate custom tabs).

## Install

1. Upload **`nexcourse.zip`** (folder inside zip: `nexcourse/`) into `course/format/nexcourse`
2. Visit **Site administration → Notifications**
3. Edit a course → **Course format** → choose **NexCourse format**

## Behaviour

- **Course home:** module cards (General / section 0 hidden — reserved for a future Announcements tab)
- **Hierarchy:** section → activities and/or subsections (`mod_subsection`); subsections can nest further
- **Start module / Continue:** opens that section (`course/view.php?id=…&section=N` or Moodle’s `course/section.php?id=…`)
- **Editing on:** standard Moodle section/activity UI (add/move subsections & activities)
- **Header progress:** completed / total activities → percentage; course marks obtained / total when graded items exist
- **Module cards:** activity progress respects completion (including **require passing grade**); quiz marks follow quiz **grading method** (highest / average / first / last)
- **Tabs:** enhances Moodle secondary navigation; subsection tabs on the module/section page

## Requirements

Moodle 5.0+ (`2025041400`)
