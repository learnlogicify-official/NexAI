# local_nexqbank

Site-admin-only **Question Bank** overview: lists every Moodle question bank context in one place.

## What you get

- Nav item **Question Bank** (flat nav + custom menu) — **site administrators only**
- Also under **Site administration** root as an external page
- Unified list of banks at:
  - **System**
  - **Course category**
  - **Course**
  - **Activity** (quiz, qbank, etc.)
- Filters, search, category/question counts
- **Open bank** → standard `/question/edit.php` for that context

## Install

```bash
python3 pack_nexqbank.py
```

Install `nexqbank.zip` (root folder `nexqbank/`), then visit **Notifications** / upgrade.

## Access

- Page and nav require `is_siteadmin()`
- Capability `local/nexqbank:viewall` is reserved for future delegation (not granted to archetypes by default)

## Notes

- Empty banks (categories present, 0 questions) still appear and are dimmed.
- Activity rows show module name (e.g. `quiz`, `qbank`) and course path.
