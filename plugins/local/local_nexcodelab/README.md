# NexCodeLab (`local_nexcodelab`)

**Mission Labs** for Data Science / ML on Moodle — sibling to **NexPractice** (DSA), not a clone.

NexCodeLab teaches applied analytics through short workplace **missions**. Each mission is a scenario with:

- a story (why the work matters),
- a multi-file lab workspace (`BRIEF.md`, `main.py`, `data.csv`),
- several **ordered steps** the learner must implement and **Check**,
- XP for first-time step passes, plus a mission-complete bonus and streak updates.

Learners write Python (pandas / scikit-learn) in the browser. Checking a step runs against a site **CodeRunner** prototype on **Jobe**.

---

## Product idea

| | NexPractice | NexCodeLab |
|---|---|---|
| Focus | DSA problems | DS / ML mission labs |
| Unit of work | Single problem | Multi-step mission |
| Workspace | Code editor + tests | Brief + code + CSV table |
| Grading | Run / Submit tests | Per-step **Check** |
| Tracks | Topics/tags | wrangling · EDA · ML · NLP |

---

## Requirements

- Moodle **5.0+**
- Question type **`qtype_coderunner`** + **Jobe** with **pandas / numpy / scikit-learn**
- Site admin setting: **Plugins → NexCodeLab → Prototype question id (python3)**

---

## Install

1. Deploy as `moodle/local/nexcodelab/` (zip root folder must be `nexcodelab/`).
2. **Site administration → Notifications** (install/upgrade seeds the 6 starter missions).
3. Configure the CodeRunner **python3 prototype** question id under Plugins → NexCodeLab.
4. Purge caches.

Package layout:

```
nexcodelab.zip
└── nexcodelab/
```

---

## How a mission works (learner flow)

1. Open the **catalog** (`/local/nexcodelab/index.php`) and pick a mission.
2. Open the **lab bench** (`mission.php?id=…`):
   - **Step rail** — steps unlock in order (complete previous to unlock next).
   - **File tabs** — `BRIEF.md` (readonly), `main.py` (editable), `data.csv` (readonly table + Raw toggle).
   - Ace editor for Python; workspace autosaves.
3. Implement the function(s) for the current step, then click **Check step**.
4. On pass: XP awarded (first pass only), progress advances, next step unlocks.
5. When all steps pass: mission marked complete, bonus XP, streak bump.

### Step grading kinds

Each step has a grader payload (`frame` or `metric`):

- **`frame`** — call a named function (optionally after a preprocess function) and compare the returned DataFrame to an expected CSV.
- **`metric`** — call a named function and check a numeric result (exact expect and/or minimum floor).

Execution uses the configured CodeRunner prototype when available.

---

## Seeded content: 6 missions

Content is defined in `classes/local/mission_seed.php` and inserted on install/upgrade (idempotent by slug). Totals: **6 missions · 15 steps**.

### Track overview

| Track | Missions |
|--------|----------|
| **Wrangling** | Titanic triage · Messy sales cleanup |
| **ML** | Churn clinic · House prices desk |
| **NLP** | Review sentiment lab |
| **EDA** | Outlier watch |

---

### 1. Titanic triage

**Slug:** `titanic-triage` · **Track:** wrangling · **~35 min** · **4 steps · 100 XP**

**Scenario:** You joined a maritime analytics desk. Leadership wants a clean passenger table before any survival model.

**Workspace:** Titanic-style extract with some missing ages.

| Column | Meaning |
|--------|---------|
| `PassengerId` | Unique passenger id |
| `Survived` | `1` = survived, `0` = did not |
| `Sex` | `male` / `female` |
| `Age` | Age in years (blank = missing) |
| `SibSp` | Siblings + spouses aboard |
| `Parch` | Parents + children aboard |

| # | Step | What to implement | Check | XP |
|---|------|-------------------|-------|----|
| 1 | Load the extract | `load_df` — safe independent copy of the extract | frame | 20 |
| 2 | Drop missing ages | `drop_missing_age` — remove unknown ages | frame | 25 |
| 3 | Family size feature | `add_family_size` — total family party including passenger (derive from data dictionary) | frame | 25 |
| 4 | Survival rate insight | `survival_rate` — survival share among age-known passengers | metric | 30 |

**Skills:** DataFrame copy, `dropna`, feature engineering, simple aggregate.

---

### 2. Messy sales cleanup

**Slug:** `messy-sales-cleanup` · **Track:** wrangling · **~30 min** · **3 steps · 85 XP**

**Scenario:** Finance dumped a sales export with currency strings. Ops needs clean numbers and regional totals before Friday standup.

**Workspace:** Orders with messy `amount` values like `$1,200.00`.

| # | Step | What to implement | Check | XP |
|---|------|-------------------|-------|----|
| 1 | Parse currency | `clean_amounts` — strip `$`/commas → float | frame | 30 |
| 2 | Regional totals | `region_totals` — `region,total` summed & sorted by region | frame | 30 |
| 3 | West share | `west_share` — West total / grand total after cleaning | metric (~0.5792) | 25 |

**Skills:** String cleaning, `groupby` / sum, ratio metrics.

---

### 3. Churn clinic

**Slug:** `churn-clinic` · **Track:** ml · **~40 min** · **2 steps · 65 XP**

**Scenario:** A junior left a leaky churn notebook in prod. Rebuild an honest split and hit a logistic baseline.

**Workspace:** Small churn table (`tenure`, `monthly`, `support_tickets`, `churn`).

| # | Step | What to implement | Check | XP |
|---|------|-------------------|-------|----|
| 1 | Honest split sizes | `split_shapes` — `test_size=0.25`, `random_state=0` → `n_train,n_test` (6 / 2) | frame | 25 |
| 2 | Logistic baseline | `logistic_accuracy` — train `LogisticRegression`, return test accuracy (≥ 0.5) | metric | 40 |

**Skills:** Train/test split hygiene, logistic regression baseline.

---

### 4. House prices desk

**Slug:** `house-prices-desk` · **Track:** ml · **~25 min** · **2 steps · 55 XP**

**Scenario:** A brokerage wants a quick sqft→price linear sanity check before buying a larger valuation model.

**Workspace:** Toy `sqft` / `price` pairs (perfectly correlated).

| # | Step | What to implement | Check | XP |
|---|------|-------------------|-------|----|
| 1 | Correlation check | `abs_corr` — abs Pearson corr(sqft, price) (≥ 0.99) | metric | 20 |
| 2 | Linear R² gate | `r2_score_lin` — `LinearRegression` train R² (≥ 0.90) | metric | 35 |

**Skills:** Correlation, simple linear regression / R².

---

### 5. Review sentiment lab

**Slug:** `review-sentiment-lab` · **Track:** nlp · **~25 min** · **2 steps · 55 XP**

**Scenario:** Content ops wants a cheap lexicon score before wiring a heavier NLP model.

**Workspace:** Short review texts; starter code defines small `POS` / `NEG` word sets.

| # | Step | What to implement | Check | XP |
|---|------|-------------------|-------|----|
| 1 | Lexicon scores | `lexicon_scores` — +1 POS / −1 NEG per token → `id,score` | frame | 30 |
| 2 | Bag-of-words size | `vocab_size` — `CountVectorizer` vocabulary size (≥ 8) | metric | 25 |

**Skills:** Rule-based sentiment, bag-of-words vocabulary.

---

### 6. Outlier watch

**Slug:** `outlier-watch` · **Track:** eda · **~20 min** · **2 steps · 50 XP**

**Scenario:** Sensor ops flagged a spike. Confirm with IQR fences and report how many points survive.

**Workspace:** Values mostly ~10–14 with one extreme spike (`1000`).

| # | Step | What to implement | Check | XP |
|---|------|-------------------|-------|----|
| 1 | IQR filter | `iqr_filter` — keep values in `[Q1−1.5·IQR, Q3+1.5·IQR]` | frame | 30 |
| 2 | Kept count | `kept_count` — rows remaining after filter (expect 6) | metric | 20 |

**Skills:** Tukey / IQR outlier fences, row counting after filter.

---

## XP & progress

- Each step has its own XP (see tables above); first successful Check awards it.
- Completing all steps marks the mission done and can award a **mission complete** bonus (site setting).
- Daily streak updates on mission completion.
- Progress / leaderboard pages surface totals for the learner.

Dashboard (NexDashboard) can show CodeLab continue cards, mission-track progress, and recent step activity from these tables.

---

## Admin / manage

- Capability-gated manage UI under `/local/nexcodelab/manage/`
- Seed is idempotent by mission **slug** (re-running won’t duplicate existing seeds)
- Plugin also retains older single-problem tables/APIs; the primary learner product is **missions**

---

## Key pages

| URL | Purpose |
|-----|---------|
| `/local/nexcodelab/index.php` | Mission catalog |
| `/local/nexcodelab/mission.php?id=` | Lab bench |
| `/local/nexcodelab/progress.php` | My progress |
| `/local/nexcodelab/leaderboard.php` | XP leaderboard |
| `/local/nexcodelab/manage/` | Admin manage |

---

## Technical sketch

- Moodle **local plugin** with Mustache + AMD front ends
- AJAX via `db/services.php` (`get_missions`, `get_mission`, `check_step`, `save_workspace`, …)
- Domain logic in `classes/local/` (`missions`, `mission_runner`, `mission_seed`, `gamification`, `runner`)
- Step Checks go through `mission_runner` → CodeRunner job runner when configured

Deferred (by design for MVP): Jupyter, editable CSV write-back, LLM tutor, multi-code-file execution, portfolio sync.


## XML mission packs

Manage → **Import missions (XML)** accepts a pack shaped like `content/missions_pack_50.xml` (50 scenario-led Mission Labs with datasets, briefs, and graded steps).

- Starter `main.py` is minimal (`import pandas as pd`).
- Each step injects its function signature into the editor when opened.
- Hints are conceptual — they do not paste Pandas APIs.
