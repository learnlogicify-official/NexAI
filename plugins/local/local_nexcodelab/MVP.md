# NexCodeLab — MVP confirmation

Locked product decisions for `local_nexcodelab`:

| Decision | Choice |
|----------|--------|
| Host | Moodle **local plugin** `local_nexcodelab` |
| Brand | **NexCodeLab** (menu: **CodeLab**) |
| Product | **Mission Labs** (multi-step scenarios) — not NexPractice clone |
| Workspace | Multi-file: BRIEF.md + main.py + data.csv (table grid) |
| Grading | Per-step **Check** via CodeRunner site prototype |
| Runtime | python3 + pandas/numpy/sklearn on Jobe |

## Surface

1. Mission catalog (scenario tiles)
2. Lab bench (`mission.php`) with step rail + file tabs + CSV grid
3. My progress / leaderboard / slim manage
4. Six seeded missions

Deferred: Jupyter, editable CSV write-back, LLM tutor, multi-code-file execution, NexPortfolio sync.
