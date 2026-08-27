# qbank_leetcodeimport

Question bank plugin: paste LeetCode problem IDs → fetch problem → OpenAI builds CodeRunner question + test cases → import Moodle XML into the current category.

## Requirements

- Moodle 5.0+ (adjust `version.php` if needed)
- [CodeRunner](https://moodle.org/plugins/qtype_coderunner) (`qtype_coderunner`) + working Jobe / sandbox
- OpenAI API key (or compatible `/v1/chat/completions` proxy)

## Install

1. Zip so the root folder is `leetcodeimport/` (use `python3 pack_leetcodeimport.py`).
2. Site administration → Plugins → Install plugins (or copy to `question/bank/leetcodeimport`).
3. Upgrade Moodle DB.
4. **Site administration → Plugins → Question bank plugins → LeetCode → CodeRunner**
   - Set **OpenAI API key**
   - Set **OpenAI API key** and model (**gpt-4o** recommended)
   - Optionally set CodeRunner type, LeetCode session for paid problems

## Use

1. Open a course **Question bank**.
2. Open the **LeetCode import** tab.
3. Choose a **CodeRunner type** from the dropdown (all site prototypes listed).
4. Keep **Use stdin / stdout tests** on for `multilanguage` / paper-style I/O.
5. Paste IDs (one per line), e.g.:

```
1
two-sum
https://leetcode.com/problems/add-two-numbers/
3, 4, 5
```

6. Submit — problems are rewritten into **Paper Generation** layout (title, difficulty, tags, Problem Statement, Constraints, Input/Output Format, Examples) with hidden large tests aimed at efficient solutions.

### Duplicate prototype error

If CodeRunner says a prototype is **non-unique**, your `CR_PROTOTYPES` category has two copies of the same built-in (e.g. two `BUILT_IN_PROTOTYPE_python3`). Delete the extras so each type exists once, purge caches, then re-import. The import tab warns about duplicates and blocks importing that type until fixed.

## Bulk options

| Option | Purpose |
|--------|---------|
| CodeRunner type | Prototype name (`python3`, `java_method`, …) |
| Language hint | Snippet + OpenAI language |
| Default grade / penalty / all-or-nothing | Shared CodeRunner marking |
| Extra hidden tests | OpenAI adds HIDE tests beyond examples |
| Include sample answer | Stores reference solution in the question |
| Dry run | Build XML only (download), no bank import |
| Stop on error | Abort remaining IDs after first failure |

## Pipeline

1. **LeetCode** — GraphQL `questionData` (slug) or `/api/problems/all/` (numeric frontend ID)
2. **OpenAI** — JSON: `questiontext_html`, `answerpreload`, `testcases[]` (`stdin`/`testcode`/`expected`)
3. **XML** — CodeRunner Moodle XML
4. **Import** — core `qformat_xml` into the selected category

## Notes

- Free problems work without cookies; locked/premium content needs `LEETCODE_SESSION` (+ CSRF) in settings.
- Generated tests should be reviewed before high-stakes quizzes.
- `idnumber` is set to `lc{id}-{slug}` for deduping / tracking.
- Outbound HTTPS must be allowed from the Moodle server to `leetcode.com` and the OpenAI endpoint.
