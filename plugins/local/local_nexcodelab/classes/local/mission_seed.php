<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Seed Mission Labs content.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Install / upgrade seeder for missions.
 */
class mission_seed {

    /**
     * Seed all starter missions (idempotent by slug).
     */
    public static function seed(): void {
        global $DB, $USER;

        $now = time();
        $uid = !empty($USER->id) ? (int) $USER->id : 0;

        foreach (self::definitions() as $def) {
            if ($DB->record_exists('local_nexcodelab_mission', ['slug' => $def['slug']])) {
                continue;
            }
            $mid = (int) $DB->insert_record('local_nexcodelab_mission', (object) [
                'name' => $def['name'],
                'slug' => $def['slug'],
                'scenario' => $def['scenario'],
                'track' => $def['track'],
                'status' => 'ready',
                'estimateminutes' => $def['estimateminutes'],
                'coverkey' => $def['coverkey'],
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $uid,
            ]);

            $forder = 0;
            foreach ($def['files'] as $file) {
                $DB->insert_record('local_nexcodelab_mission_file', (object) [
                    'missionid' => $mid,
                    'path' => $file['path'],
                    'role' => $file['role'],
                    'content' => $file['content'],
                    'readonly' => !empty($file['readonly']) ? 1 : 0,
                    'sortorder' => $forder++,
                ]);
            }

            $sorder = 0;
            foreach ($def['steps'] as $step) {
                $DB->insert_record('local_nexcodelab_mission_step', (object) [
                    'missionid' => $mid,
                    'sortorder' => $sorder++,
                    'title' => $step['title'],
                    'instructions' => $step['instructions'],
                    'hint' => $step['hint'] ?? '',
                    'checkkind' => $step['checkkind'],
                    'graderpayload' => json_encode($step['grader']),
                    'xp' => $step['xp'] ?? 25,
                    'unlockprev' => 1,
                ]);
            }
        }
    }

    /**
     * Refresh brief / step copy (and grader payloads) for existing seeded missions.
     * Does not delete missions or wipe learner workspace / progress.
     *
     * @param string[]|null $slugs Limit to these slugs, or null for all definitions.
     */
    public static function refresh_copy(?array $slugs = null): void {
        global $DB;

        $now = time();
        foreach (self::definitions() as $def) {
            if ($slugs !== null && !in_array($def['slug'], $slugs, true)) {
                continue;
            }
            $mission = $DB->get_record('local_nexcodelab_mission', ['slug' => $def['slug']]);
            if (!$mission) {
                continue;
            }
            $mid = (int) $mission->id;
            $mission->name = $def['name'];
            $mission->scenario = $def['scenario'];
            $mission->track = $def['track'];
            $mission->estimateminutes = $def['estimateminutes'];
            $mission->coverkey = $def['coverkey'];
            $mission->timemodified = $now;
            $DB->update_record('local_nexcodelab_mission', $mission);

            foreach ($def['files'] as $file) {
                $existing = $DB->get_record('local_nexcodelab_mission_file', [
                    'missionid' => $mid,
                    'path' => $file['path'],
                ]);
                if ($existing) {
                    // Refresh brief/data always; refresh main.py starter only (learners keep workspace).
                    if ($file['path'] === 'main.py') {
                        continue;
                    }
                    $existing->content = $file['content'];
                    $existing->role = $file['role'];
                    $existing->readonly = !empty($file['readonly']) ? 1 : 0;
                    $DB->update_record('local_nexcodelab_mission_file', $existing);
                } else {
                    $DB->insert_record('local_nexcodelab_mission_file', (object) [
                        'missionid' => $mid,
                        'path' => $file['path'],
                        'role' => $file['role'],
                        'content' => $file['content'],
                        'readonly' => !empty($file['readonly']) ? 1 : 0,
                        'sortorder' => 0,
                    ]);
                }
            }

            $steps = array_values($DB->get_records(
                'local_nexcodelab_mission_step',
                ['missionid' => $mid],
                'sortorder ASC'
            ));
            foreach ($def['steps'] as $i => $step) {
                if (!isset($steps[$i])) {
                    continue;
                }
                $row = $steps[$i];
                $row->title = $step['title'];
                $row->instructions = $step['instructions'];
                $row->hint = $step['hint'] ?? '';
                $row->checkkind = $step['checkkind'];
                $row->graderpayload = json_encode($step['grader']);
                $row->xp = $step['xp'] ?? 25;
                $DB->update_record('local_nexcodelab_mission_step', $row);
            }
        }
    }

    /**
     * @return array[]
     */
    public static function definitions(): array {
        return [
            self::titanic(),
            self::sales(),
            self::churn(),
            self::houses(),
            self::sentiment(),
            self::outliers(),
        ];
    }


    /** Minimal starter — step signatures are appended in the editor as learners unlock steps. */
    private static function starter_code(string $extra = ''): string {
        $code = "import pandas as pd\n";
        if ($extra !== '') {
            $code .= $extra;
        }
        return $code;
    }

    private static function sig_frame(string $fn): string {
        return "def {$fn}(df: pd.DataFrame) -> pd.DataFrame:\n"
            . "    \"\"\"Implement this step.\"\"\"\n"
            . "    return df\n";
    }

    private static function sig_metric(string $fn): string {
        return "def {$fn}(df: pd.DataFrame) -> float:\n"
            . "    \"\"\"Implement this step.\"\"\"\n"
            . "    return 0.0\n";
    }

    private static function step_html(string $body, string $signature): string {
        return $body
            . '<p><strong>Function to implement:</strong></p>'
            . '<pre class="ncl-bench__sig">' . s($signature) . '</pre>';
    }


    private static function titanic(): array {
        $csv = 'PassengerId,Survived,Sex,Age,SibSp,Parch
1,0,male,22.0,1,0
2,1,female,38.0,1,0
3,1,female,,0,0
4,1,female,35.0,1,0
5,0,male,,0,0
6,0,male,54.0,0,0
7,0,male,2.0,3,1
8,1,female,27.0,0,2
';
        $expect1 = 'PassengerId,Survived,Sex,Age,SibSp,Parch
1,0,male,22.0,1,0
2,1,female,38.0,1,0
3,1,female,,0,0
4,1,female,35.0,1,0
5,0,male,,0,0
6,0,male,54.0,0,0
7,0,male,2.0,3,1
8,1,female,27.0,0,2
';
        $expect2 = 'PassengerId,Survived,Sex,Age,SibSp,Parch
1,0,male,22.0,1,0
2,1,female,38.0,1,0
4,1,female,35.0,1,0
6,0,male,54.0,0,0
7,0,male,2.0,3,1
8,1,female,27.0,0,2
';
        $expect3 = 'PassengerId,Survived,Sex,Age,SibSp,Parch,family_size
1,0,male,22.0,1,0,2
2,1,female,38.0,1,0,2
4,1,female,35.0,1,0,2
6,0,male,54.0,0,0,1
7,0,male,2.0,3,1,5
8,1,female,27.0,0,2,3
';
        $sig1 = self::sig_frame('load_df');
        $sig2 = self::sig_frame('drop_missing_age');
        $sig3 = self::sig_frame('add_family_size');
        $sig4 = self::sig_metric('survival_rate');

        return [
            'name' => 'Titanic triage',
            'slug' => 'titanic-triage',
            'scenario' => 'A maritime analytics desk needs a clean passenger extract before any survival model.',
            'track' => 'wrangling',
            'estimateminutes' => 35,
            'coverkey' => 'ship',
            'files' => [
                [
                    'path' => 'BRIEF.md',
                    'role' => 'brief',
                    'readonly' => 1,
                    'content' => <<<'MD'
# Titanic triage

## Situation
You joined a maritime analytics desk. Leadership wants a **clean passenger extract** before anyone fits a survival model. The raw dump in `data.csv` still has gaps and is missing a basic family-size feature.

## Your workspace
| File | Role |
|------|------|
| `BRIEF.md` | This brief (read-only) |
| `main.py` | Grow helpers one step at a time |
| `data.csv` | Working passenger table (read-only) |

## Data dictionary (`data.csv`)
| Column | Meaning |
|--------|---------|
| `PassengerId` | Unique passenger id |
| `Survived` | `1` survived, `0` did not |
| `Sex` | `male` / `female` |
| `Age` | Age in years; blank means missing |
| `SibSp` | Siblings + spouses aboard |
| `Parch` | Parents + children aboard |

## What “done” looks like
1. Keep a safe working copy of the extract  
2. Remove passengers with unknown age  
3. Add a family-party size that includes the passenger  
4. Report the survival share among age-known passengers  

Each Check grades **one** helper. Later steps may reuse an earlier helper as a preprocess.
MD
                ],
                [
                    'path' => 'main.py',
                    'role' => 'code',
                    'readonly' => 0,
                    'content' => self::starter_code(),
                ],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $csv],
            ],
            'steps' => [
                [
                    'title' => 'Load the extract',
                    'instructions' => self::step_html(
                        '<p><strong>Goal:</strong> Start from a safe working copy so later cleaning never mutates the grader’s input by accident.</p>'
                        . '<p>Implement <code>load_df</code> so the returned table matches the extract row-for-row and column-for-column (including blank ages), without sharing the same object as the input.</p>',
                        $sig1
                    ),
                    'hint' => 'You need an independent table that still looks identical to the input.',
                    'checkkind' => 'frame',
                    'xp' => 20,
                    'grader' => ['kind' => 'frame', 'fn' => 'load_df', 'signature' => $sig1, 'expect_csv' => $expect1],
                ],
                [
                    'title' => 'Drop missing ages',
                    'instructions' => self::step_html(
                        '<p><strong>Goal:</strong> Age is incomplete. Before family features that assume a known age, remove passengers with a missing <code>Age</code>.</p>'
                        . '<p>Implement <code>drop_missing_age</code> so every remaining row has a known age. Keep other columns unchanged.</p>',
                        $sig2
                    ),
                    'hint' => 'Blank age means unknown — those passengers should not remain in the cleaned extract.',
                    'checkkind' => 'frame',
                    'xp' => 25,
                    'grader' => ['kind' => 'frame', 'fn' => 'drop_missing_age', 'signature' => $sig2, 'expect_csv' => $expect2],
                ],
                [
                    'title' => 'Family size feature',
                    'instructions' => self::step_html(
                        '<p><strong>Family profile</strong></p>'
                        . '<p>Leadership wants to know whether passengers travelled alone or with family.</p>'
                        . '<p><code>SibSp</code> is siblings/spouses travelling with the passenger; <code>Parch</code> is parents/children.</p>'
                        . '<p>Implement <code>add_family_size</code> with a <code>family_size</code> column for the <strong>total family party including the passenger</strong>.</p>'
                        . '<p>The grader applies <code>drop_missing_age</code> first.</p>'
                        . '<p><strong>Examples to reason from:</strong></p>'
                        . '<table class="ncl-bench__mdtable"><thead><tr><th>SibSp</th><th>Parch</th><th>family_size</th></tr></thead><tbody>'
                        . '<tr><td>0</td><td>0</td><td>1</td></tr><tr><td>1</td><td>0</td><td>2</td></tr>'
                        . '<tr><td>2</td><td>1</td><td>4</td></tr><tr><td>3</td><td>1</td><td>5</td></tr></tbody></table>',
                        $sig3
                    ),
                    'hint' => 'Companions on the ship plus the passenger themselves make up the party size.',
                    'checkkind' => 'frame',
                    'xp' => 25,
                    'grader' => [
                        'kind' => 'frame',
                        'fn' => 'add_family_size',
                        'signature' => $sig3,
                        'preprocess' => 'drop_missing_age',
                        'expect_csv' => $expect3,
                    ],
                ],
                [
                    'title' => 'Survival rate insight',
                    'instructions' => self::step_html(
                        '<p><strong>Goal:</strong> Among passengers with a known age, what share survived?</p>'
                        . '<p>Implement <code>survival_rate</code> to return that share as a float between 0 and 1. '
                        . 'The grader applies <code>drop_missing_age</code> first — compute on the frame you receive.</p>'
                        . '<p>Your number is compared to four decimal places on this toy extract.</p>',
                        $sig4
                    ),
                    'hint' => 'Survived is coded as 0/1, so an average across passengers is the survival share.',
                    'checkkind' => 'metric',
                    'xp' => 30,
                    'grader' => [
                        'kind' => 'metric',
                        'fn' => 'survival_rate',
                        'signature' => $sig4,
                        'preprocess' => 'drop_missing_age',
                        'floor' => 0.4,
                        'expect' => '0.5000',
                    ],
                ],
            ],
        ];
    }

    private static function sales(): array {
        $csv = 'order_id,region,amount,order_date
1,West,"$1,200.00",2024-01-05
2,East,$90.50,2024-01-02
3,West,$300.00,2024-01-08
4,East,"$1,000.00",2024-01-03
';
        $clean = 'order_id,region,amount,order_date
1,West,1200.0,2024-01-05
2,East,90.5,2024-01-02
3,West,300.0,2024-01-08
4,East,1000.0,2024-01-03
';
        $totals = 'region,total
East,1090.5
West,1500.0
';
        $sig1 = self::sig_frame('clean_amounts');
        $sig2 = self::sig_frame('region_totals');
        $sig3 = self::sig_metric('west_share');
        return [
            'name' => 'Messy sales cleanup',
            'slug' => 'messy-sales-cleanup',
            'scenario' => 'Finance dumped a sales export with currency strings. Ops needs clean numbers and regional totals.',
            'track' => 'wrangling',
            'estimateminutes' => 30,
            'coverkey' => 'sales',
            'files' => [
                [
                    'path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1,
                    'content' => <<<'MD'
# Messy sales cleanup

## Situation
Finance exported orders with currency formatting in `amount`. Ops needs numeric amounts and regional totals before standup.

## Data dictionary
| Column | Meaning |
|--------|---------|
| `order_id` | Order identifier |
| `region` | Sales region label |
| `amount` | Money as text (may include `$` and commas) |
| `order_date` | Order date (YYYY-MM-DD) |

## What “done” looks like
1. Turn `amount` into real numbers  
2. Total sales by region  
3. Report West’s share of all sales  
MD
                ],
                ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'content' => self::starter_code()],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $csv],
            ],
            'steps' => [
                [
                    'title' => 'Parse currency',
                    'instructions' => self::step_html(
                        '<p>Finance typed amounts like currency labels. Implement <code>clean_amounts</code> so <code>amount</code> becomes a numeric value suitable for math, keeping other columns.</p>',
                        $sig1
                    ),
                    'hint' => 'Strip decoration from the money text until only a plain number remains.',
                    'checkkind' => 'frame', 'xp' => 30,
                    'grader' => ['kind' => 'frame', 'fn' => 'clean_amounts', 'signature' => $sig1, 'expect_csv' => $clean],
                ],
                [
                    'title' => 'Regional totals',
                    'instructions' => self::step_html(
                        '<p>After amounts are numeric, Ops wants one total per region. Implement <code>region_totals</code> returning columns <code>region</code> and <code>total</code>, sorted by region. The grader cleans amounts first.</p>',
                        $sig2
                    ),
                    'hint' => 'Combine all cleaned sales that share the same region label.',
                    'checkkind' => 'frame', 'xp' => 30,
                    'grader' => ['kind' => 'frame', 'fn' => 'region_totals', 'signature' => $sig2, 'preprocess' => 'clean_amounts', 'expect_csv' => $totals],
                ],
                [
                    'title' => 'West share',
                    'instructions' => self::step_html(
                        '<p>Implement <code>west_share</code>: using cleaned amounts, what fraction of total sales came from West? Return a float (four decimals).</p>',
                        $sig3
                    ),
                    'hint' => 'Compare West’s cleaned total to the grand total across every region.',
                    'checkkind' => 'metric', 'xp' => 25,
                    'grader' => ['kind' => 'metric', 'fn' => 'west_share', 'signature' => $sig3, 'floor' => 0.5, 'expect' => '0.5790'],
                ],
            ],
        ];
    }

    private static function churn(): array {
        $csv = 'tenure,monthly,support_tickets,churn
1,70,5,1
24,40,0,0
2,80,4,1
36,35,1,0
3,90,6,1
48,30,0,0
12,50,1,0
6,75,3,1
';
        $shapes = 'n_train,n_test
6,2
';
        $sig1 = self::sig_frame('split_shapes');
        $sig2 = self::sig_metric('logistic_accuracy');
        $extra = "from sklearn.model_selection import train_test_split\n"
            . "from sklearn.linear_model import LogisticRegression\n\n"
            . "FEATURE_COLS = ['tenure', 'monthly', 'support_tickets']\n";
        return [
            'name' => 'Churn clinic',
            'slug' => 'churn-clinic',
            'scenario' => 'Rebuild an honest train/test split and a logistic baseline for churn.',
            'track' => 'ml',
            'estimateminutes' => 40,
            'coverkey' => 'clinic',
            'files' => [
                [
                    'path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1,
                    'content' => <<<'MD'
# Churn clinic

## Situation
A junior left a leaky churn notebook. Leadership wants an honest split and a simple logistic baseline before any fancy model.

## Data dictionary
| Column | Meaning |
|--------|---------|
| `tenure` | Months as a customer |
| `monthly` | Monthly charge |
| `support_tickets` | Recent support tickets |
| `churn` | `1` churned, `0` retained |

Starter lists `FEATURE_COLS` predictors. Target is `churn`.

## What “done” looks like
1. Report train/test sizes for a 25% holdout with a fixed seed  
2. Fit logistic regression on train; report test accuracy  
MD
                ],
                ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'content' => self::starter_code($extra)],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $csv],
            ],
            'steps' => [
                [
                    'title' => 'Honest split sizes',
                    'instructions' => self::step_html(
                        '<p>Implement <code>split_shapes</code> using the feature columns plus <code>churn</code>, with a 25% test share and <code>random_state=0</code>. Return one row with <code>n_train</code> and <code>n_test</code>.</p>',
                        $sig1
                    ),
                    'hint' => 'Hold out a quarter of the rows, and keep the random seed fixed so the sizes are reproducible.',
                    'checkkind' => 'frame', 'xp' => 25,
                    'grader' => ['kind' => 'frame', 'fn' => 'split_shapes', 'signature' => $sig1, 'expect_csv' => $shapes],
                ],
                [
                    'title' => 'Logistic baseline',
                    'instructions' => self::step_html(
                        '<p>Implement <code>logistic_accuracy</code>: same split settings, train a logistic regression on the train rows, return accuracy on the test rows as a float.</p>',
                        $sig2
                    ),
                    'hint' => 'Train only on the training partition; score only on the held-out partition.',
                    'checkkind' => 'metric', 'xp' => 40,
                    'grader' => ['kind' => 'metric', 'fn' => 'logistic_accuracy', 'signature' => $sig2, 'floor' => 0.5],
                ],
            ],
        ];
    }

    private static function houses(): array {
        $csv = 'sqft,price
1000,200
1500,300
2000,400
2500,500
1200,240
1800,360
';
        $sig1 = self::sig_metric('abs_corr');
        $sig2 = self::sig_metric('r2_score_lin');
        $extra = "from sklearn.linear_model import LinearRegression\n";
        return [
            'name' => 'House prices desk',
            'slug' => 'house-prices-desk',
            'scenario' => 'A brokerage wants a quick sqft→price sanity check before a larger valuation model.',
            'track' => 'ml',
            'estimateminutes' => 25,
            'coverkey' => 'house',
            'files' => [
                [
                    'path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1,
                    'content' => <<<'MD'
# House prices desk

## Situation
Brokerage analysts want a quick check that floor area and list price move together, then a linear baseline fit.

## Data dictionary
| Column | Meaning |
|--------|---------|
| `sqft` | Interior area |
| `price` | List price (toy units) |

## What “done” looks like
1. Absolute correlation of sqft vs price  
2. Training R² of a linear model predicting price from sqft  
MD
                ],
                ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'content' => self::starter_code($extra)],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $csv],
            ],
            'steps' => [
                [
                    'title' => 'Correlation check',
                    'instructions' => self::step_html(
                        '<p>Implement <code>abs_corr</code>: return the absolute association strength between <code>sqft</code> and <code>price</code> as a float (four decimals on this toy set).</p>',
                        $sig1
                    ),
                    'hint' => 'Measure how tightly the two numeric columns move together, then take the magnitude.',
                    'checkkind' => 'metric', 'xp' => 20,
                    'grader' => ['kind' => 'metric', 'fn' => 'abs_corr', 'signature' => $sig1, 'floor' => 0.99, 'expect' => '1.0000'],
                ],
                [
                    'title' => 'Linear R² gate',
                    'instructions' => self::step_html(
                        '<p>Implement <code>r2_score_lin</code>: fit a linear model predicting <code>price</code> from <code>sqft</code> and return the training fit quality as a float.</p>',
                        $sig2
                    ),
                    'hint' => 'Fit on the full desk extract, then report how well predictions match observed prices.',
                    'checkkind' => 'metric', 'xp' => 35,
                    'grader' => ['kind' => 'metric', 'fn' => 'r2_score_lin', 'signature' => $sig2, 'floor' => 0.90],
                ],
            ],
        ];
    }

    private static function sentiment(): array {
        $csv = 'id,text
1,good film love it
2,bad movie hate it
3,great show
4,terrible film
';
        $scores = 'id,score
1,2
2,-2
3,1
4,-1
';
        $sig1 = self::sig_frame('lexicon_scores');
        $sig2 = self::sig_metric('vocab_size');
        $extra = "from sklearn.feature_extraction.text import CountVectorizer\n\n"
            . "POS = {'good', 'great', 'love'}\n"
            . "NEG = {'bad', 'hate', 'terrible'}\n";
        return [
            'name' => 'Review sentiment lab',
            'slug' => 'review-sentiment-lab',
            'scenario' => 'Content ops wants a cheap lexicon score before a heavier NLP model.',
            'track' => 'nlp',
            'estimateminutes' => 25,
            'coverkey' => 'nlp',
            'files' => [
                [
                    'path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1,
                    'content' => <<<'MD'
# Review sentiment lab

## Situation
Score short reviews with a tiny positive/negative lexicon, then measure vocabulary breadth.

## Data dictionary
| Column | Meaning |
|--------|---------|
| `id` | Review id |
| `text` | Lowercase review words separated by spaces |

Starter constants `POS` and `NEG` list lexicon words.

## Scoring rule
+1 for each positive lexicon hit, −1 for each negative hit (word tokens).

## What “done” looks like
1. Table of `id,score`  
2. Vocabulary size from a bag-of-words fit on `text`  
MD
                ],
                ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'content' => self::starter_code($extra)],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $csv],
            ],
            'steps' => [
                [
                    'title' => 'Lexicon scores',
                    'instructions' => self::step_html(
                        '<p>Implement <code>lexicon_scores</code> using the POS/NEG word lists. Return a frame with <code>id</code> and <code>score</code>.</p>',
                        $sig1
                    ),
                    'hint' => 'Count encouraging words against critical words in each review.',
                    'checkkind' => 'frame', 'xp' => 30,
                    'grader' => ['kind' => 'frame', 'fn' => 'lexicon_scores', 'signature' => $sig1, 'expect_csv' => $scores],
                ],
                [
                    'title' => 'Bag-of-words size',
                    'instructions' => self::step_html(
                        '<p>Implement <code>vocab_size</code>: learn a bag-of-words vocabulary on <code>text</code> and return how many unique tokens it contains as a float.</p>',
                        $sig2
                    ),
                    'hint' => 'Fit a token counter on the review text, then report how large the learned vocabulary is.',
                    'checkkind' => 'metric', 'xp' => 25,
                    'grader' => ['kind' => 'metric', 'fn' => 'vocab_size', 'signature' => $sig2, 'floor' => 8.0],
                ],
            ],
        ];
    }

    private static function outliers(): array {
        $csv = 'id,value
1,10
2,12
3,11
4,13
5,1000
6,9
7,14
';
        $clean = 'id,value
1,10
2,12
3,11
4,13
6,9
7,14
';
        $sig1 = self::sig_frame('iqr_filter');
        $sig2 = self::sig_metric('kept_count');
        return [
            'name' => 'Outlier watch',
            'slug' => 'outlier-watch',
            'scenario' => 'Sensor ops flagged a spike. Confirm with Tukey fences and report survivors.',
            'track' => 'eda',
            'estimateminutes' => 20,
            'coverkey' => 'eda',
            'files' => [
                [
                    'path' => 'BRIEF.md', 'role' => 'brief', 'readonly' => 1,
                    'content' => <<<'MD'
# Outlier watch

## Situation
A sensor stream spiked. Ops wants Tukey fences (1.5×IQR beyond the quartiles) on `value`, then a count of kept readings.

## Data dictionary
| Column | Meaning |
|--------|---------|
| `id` | Reading id |
| `value` | Numeric sensor reading |

## What “done” looks like
1. Keep only in-fence readings  
2. Report how many readings remain after filtering  
MD
                ],
                ['path' => 'main.py', 'role' => 'code', 'readonly' => 0, 'content' => self::starter_code()],
                ['path' => 'data.csv', 'role' => 'data', 'readonly' => 1, 'content' => $csv],
            ],
            'steps' => [
                [
                    'title' => 'IQR filter',
                    'instructions' => self::step_html(
                        '<p>Implement <code>iqr_filter</code> using the classic 1.5×IQR fences on <code>value</code>. Return the filtered frame.</p>',
                        $sig1
                    ),
                    'hint' => 'Readings far beyond the middle spread should be treated as spikes and removed.',
                    'checkkind' => 'frame', 'xp' => 30,
                    'grader' => ['kind' => 'frame', 'fn' => 'iqr_filter', 'signature' => $sig1, 'expect_csv' => $clean],
                ],
                [
                    'title' => 'Kept count',
                    'instructions' => self::step_html(
                        '<p>Implement <code>kept_count</code> to report how many rows remain. The grader applies <code>iqr_filter</code> first.</p>',
                        $sig2
                    ),
                    'hint' => 'After spikes are removed, count the surviving readings.',
                    'checkkind' => 'metric', 'xp' => 20,
                    'grader' => ['kind' => 'metric', 'fn' => 'kept_count', 'signature' => $sig2, 'preprocess' => 'iqr_filter', 'floor' => 6.0, 'expect' => '6.0000'],
                ],
            ],
        ];
    }
}
