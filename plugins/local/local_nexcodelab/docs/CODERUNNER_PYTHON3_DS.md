# CodeRunner prototype: `python3-DS`

NexCodeLab grades Data Science challenges through a dedicated CodeRunner question type (or a cloned `python3` question renamed to **python3-DS**). Configure its Moodle question id under **Site administration → Plugins → Local plugins → NexCodeLab → Prototype question id (python3)**.

## Required libraries (Jobe / sandbox image)

Install these in the CodeRunner sandbox (Jobe Docker image or host Python):

| Package | Purpose |
|---------|---------|
| `pandas` | DataFrame wrangling |
| `numpy` | Arrays / numeric checks |
| `scikit-learn` | Classical ML metrics & models |
| `matplotlib` | Viz challenges (use **Agg** backend only) |

Optional (phase 2): `joblib`, `scipy`, `nltk` / light NLP.

Pin a known-good set, e.g.:

```text
pandas>=2.0,<3
numpy>=1.24,<3
scikit-learn>=1.3,<2
matplotlib>=3.7,<4
```

Verify on Jobe:

```bash
python3 -c "import pandas, numpy, sklearn, matplotlib; print('ok')"
```

## Prototype question settings

| Setting | Value |
|---------|--------|
| Question type | CodeRunner |
| Coderunner type | `python3` (or custom `python3-DS` prototype) |
| Answer type | Python3 |
| Precheck | Examples (sample tests) |
| Grading | Exact match **or** custom template (recommended for DataFrames) |
| Memory / time | Raise vs DSA defaults (e.g. 256–512 MB, 10–20 s) |
| Sandbox | Jobe |

Use the Twig template in [`../coderunner/python3_ds_template.twig`](../coderunner/python3_ds_template.twig) for assertion-style grading (pandas equality, shapes, metric floors).

## Fixture delivery

Each challenge may ship a CSV under `local/nexcodelab/fixtures/<slug>/`. At run time the grading template expects fixtures either:

1. **Embedded in TEST.extra** as a path hint / inline CSV, or
2. **Copied into the sandbox working directory** via CodeRunner support files on the question.

For MVP, starter challenges use **inline CSV in stdin / TEST.extra** so no Jobe file sync is required. Larger datasets should use CodeRunner support files linked from the question.

## Grading patterns

1. **Function stub** — student implements `clean_df(df) -> DataFrame`; template loads fixture, calls function, asserts with `pd.testing.assert_frame_equal`.
2. **Metric floor** — student trains a model; template checks `accuracy_score >= 0.80` (or similar).
3. **Stdout report** — student prints a single number / JSON summary; exact or float-tolerant compare.

Keep grading **deterministic**. Do not call external LLMs in the grader.

## Checklist before go-live

- [ ] Jobe image has pandas / numpy / sklearn / matplotlib
- [ ] Prototype question id saved in NexCodeLab settings
- [ ] One smoke challenge (e.g. `drop-missing-rows`) Run + Submit pass end-to-end
- [ ] Matplotlib uses `matplotlib.use('Agg')` in template so headless jobs do not fail
- [ ] Time/memory limits tuned for sklearn fits on small fixtures
