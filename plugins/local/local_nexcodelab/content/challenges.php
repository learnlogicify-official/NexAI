<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Starter DS/ML challenge definitions for NexCodeLab seed.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * First 20 pandas / sklearn challenges (fixtures under fixtures/<slug>/).
 *
 * @return array[]
 */
function local_nexcodelab_starter_challenges(): array {
    return [
        [
            'name' => 'Drop Missing Rows',
            'slug' => 'drop-missing-rows',
            'difficulty' => 'easy',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/drop-missing-rows/input.csv',
            'tags' => ['pandas', 'missing-values'],
            'statement' => '<p>Implement <code>clean_df(df)</code> that drops any row with a missing value and returns the cleaned DataFrame (reset index optional).</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO: drop rows with any NaN\n    return df\n",
            'tests' => [
                [
                    'stdin' => '',
                    'expected' => "id,age\n1,22.0\n3,25.0\n",
                    'display' => 'sample',
                    'explanation' => 'Row with missing age removed',
                ],
                [
                    'stdin' => '',
                    'expected' => "id,age\n1,22.0\n3,25.0\n",
                    'display' => 'hidden',
                    'explanation' => '',
                ],
            ],
        ],
        [
            'name' => 'Fill Mean Age',
            'slug' => 'fill-mean-age',
            'difficulty' => 'easy',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/fill-mean-age/input.csv',
            'tags' => ['pandas', 'imputation'],
            'statement' => '<p>Implement <code>clean_df(df)</code> that fills missing <code>age</code> values with the column mean (leave other columns unchanged).</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "id,age\n1,20.0\n2,30.0\n3,25.0\n", 'display' => 'sample', 'explanation' => 'Mean of 20 and 30 is 25'],
                ['stdin' => '', 'expected' => "id,age\n1,20.0\n2,30.0\n3,25.0\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Titanic Column Select',
            'slug' => 'titanic-column-select',
            'difficulty' => 'easy',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/titanic-column-select/input.csv',
            'tags' => ['pandas', 'titanic'],
            'statement' => '<p>Return a DataFrame with only columns <code>PassengerId</code>, <code>Survived</code>, <code>Sex</code>, <code>Age</code> in that order.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "PassengerId,Survived,Sex,Age\n1,0,male,22.0\n2,1,female,38.0\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "PassengerId,Survived,Sex,Age\n1,0,male,22.0\n2,1,female,38.0\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Parse Messy Dates',
            'slug' => 'parse-messy-dates',
            'difficulty' => 'medium',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/parse-messy-dates/input.csv',
            'tags' => ['pandas', 'datetime'],
            'statement' => '<p>Parse <code>order_date</code> to datetime and return rows sorted ascending by date. Drop rows that cannot be parsed.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "order_id,order_date\n2,2024-01-02\n1,2024-01-05\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "order_id,order_date\n2,2024-01-02\n1,2024-01-05\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Normalize Currency Strings',
            'slug' => 'normalize-currency',
            'difficulty' => 'medium',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/normalize-currency/input.csv',
            'tags' => ['pandas', 'cleaning'],
            'statement' => '<p>Convert <code>amount</code> like <code>$1,234.50</code> into float column <code>amount</code>. Keep <code>sku</code>.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "sku,amount\nA,1234.5\nB,9.99\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "sku,amount\nA,1234.5\nB,9.99\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Group Sales Totals',
            'slug' => 'group-sales-totals',
            'difficulty' => 'easy',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/group-sales-totals/input.csv',
            'tags' => ['pandas', 'groupby'],
            'statement' => '<p>Return a DataFrame with columns <code>region</code>, <code>total</code> = sum of <code>sales</code>, sorted by region.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "region,total\nEast,30\nWest,50\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "region,total\nEast,30\nWest,50\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Outlier IQR Filter',
            'slug' => 'outlier-iqr-filter',
            'difficulty' => 'medium',
            'track' => 'eda',
            'fixturepath' => 'fixtures/outlier-iqr-filter/input.csv',
            'tags' => ['pandas', 'outliers', 'eda'],
            'statement' => '<p>Keep rows whose <code>value</code> lies within [Q1 − 1.5·IQR, Q3 + 1.5·IQR].</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "id,value\n1,10\n2,12\n3,11\n4,13\n", 'display' => 'sample', 'explanation' => '1000 is an outlier'],
                ['stdin' => '', 'expected' => "id,value\n1,10\n2,12\n3,11\n4,13\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Correlation Pair Report',
            'slug' => 'correlation-pair-report',
            'difficulty' => 'medium',
            'track' => 'eda',
            'fixturepath' => 'fixtures/correlation-pair-report/input.csv',
            'tags' => ['pandas', 'correlation', 'eda'],
            'statement' => '<p>Return the absolute Pearson correlation between <code>x</code> and <code>y</code> as a float printed to 4 decimals via <code>solve(df)</code> returning that float.</p>',
            'preload' => "import pandas as pd\n\ndef solve(df: pd.DataFrame) -> float:\n    # TODO: return abs(corr(x,y))\n    return 0.0\n",
            'tests' => [
                ['stdin' => '', 'expected' => '1.0000', 'display' => 'sample', 'explanation' => 'Perfect positive correlation'],
                ['stdin' => '', 'expected' => '1.0000', 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Value Counts Top-N',
            'slug' => 'value-counts-topn',
            'difficulty' => 'easy',
            'track' => 'eda',
            'fixturepath' => 'fixtures/value-counts-topn/input.csv',
            'tags' => ['pandas', 'eda'],
            'statement' => '<p>Return a DataFrame of the top 2 most frequent <code>category</code> values with columns <code>category</code>, <code>count</code>.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "category,count\na,3\nb,2\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "category,count\na,3\nb,2\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Train/Test Split Shapes',
            'slug' => 'train-test-split-shapes',
            'difficulty' => 'easy',
            'track' => 'ml',
            'fixturepath' => 'fixtures/train-test-split-shapes/input.csv',
            'tags' => ['sklearn', 'split'],
            'statement' => '<p>Using <code>train_test_split</code> with <code>test_size=0.25</code> and <code>random_state=0</code> on features <code>f1,f2</code> and target <code>y</code>, return a DataFrame with one row: <code>n_train,n_test</code>.</p>',
            'preload' => "import pandas as pd\nfrom sklearn.model_selection import train_test_split\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return pd.DataFrame({'n_train': [0], 'n_test': [0]})\n",
            'tests' => [
                ['stdin' => '', 'expected' => "n_train,n_test\n6,2\n", 'display' => 'sample', 'explanation' => '8 rows → 6/2'],
                ['stdin' => '', 'expected' => "n_train,n_test\n6,2\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Logistic Churn Baseline',
            'slug' => 'logistic-churn-baseline',
            'difficulty' => 'medium',
            'track' => 'ml',
            'fixturepath' => 'fixtures/logistic-churn-baseline/input.csv',
            'tags' => ['sklearn', 'classification'],
            'statement' => '<p>Train <code>LogisticRegression</code> on features except <code>churn</code>. Return accuracy on the full set as float (4 decimals printed by grader). Implement <code>solve(df) -> float</code>.</p>',
            'preload' => "import pandas as pd\nfrom sklearn.linear_model import LogisticRegression\n\ndef solve(df: pd.DataFrame) -> float:\n    # TODO\n    return 0.0\n",
            'tests' => [
                ['stdin' => '', 'expected' => '0.7500', 'display' => 'sample', 'explanation' => 'Floor: accuracy ≥ 0.75'],
                ['stdin' => '', 'expected' => '0.7500', 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'House Price Linear Regression',
            'slug' => 'house-price-linear',
            'difficulty' => 'medium',
            'track' => 'ml',
            'fixturepath' => 'fixtures/house-price-linear/input.csv',
            'tags' => ['sklearn', 'regression'],
            'statement' => '<p>Fit <code>LinearRegression</code> predicting <code>price</code> from <code>sqft</code>. Return R² on the training set via <code>solve(df) -> float</code> (expect ≥ 0.90).</p>',
            'preload' => "import pandas as pd\nfrom sklearn.linear_model import LinearRegression\n\ndef solve(df: pd.DataFrame) -> float:\n    # TODO\n    return 0.0\n",
            'tests' => [
                ['stdin' => '', 'expected' => '0.9000', 'display' => 'sample', 'explanation' => 'Metric floor 0.90'],
                ['stdin' => '', 'expected' => '0.9000', 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'KMeans Customer Clusters',
            'slug' => 'kmeans-customer-clusters',
            'difficulty' => 'medium',
            'track' => 'ml',
            'fixturepath' => 'fixtures/kmeans-customer-clusters/input.csv',
            'tags' => ['sklearn', 'clustering'],
            'statement' => '<p>Fit <code>KMeans(n_clusters=2, random_state=0, n_init=10)</code> on <code>x,y</code>. Return a DataFrame with column <code>cluster</code> (labels) in input row order.</p>',
            'preload' => "import pandas as pd\nfrom sklearn.cluster import KMeans\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "cluster\n0\n0\n1\n1\n", 'display' => 'sample', 'explanation' => 'Two obvious blobs'],
                ['stdin' => '', 'expected' => "cluster\n0\n0\n1\n1\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'StandardScaler Pipeline',
            'slug' => 'standardscaler-pipeline',
            'difficulty' => 'medium',
            'track' => 'ml',
            'fixturepath' => 'fixtures/standardscaler-pipeline/input.csv',
            'tags' => ['sklearn', 'preprocessing'],
            'statement' => '<p>Apply <code>StandardScaler</code> to numeric columns and return the scaled DataFrame with same column names (approx values OK within 1e-6 for asserts).</p>',
            'preload' => "import pandas as pd\nfrom sklearn.preprocessing import StandardScaler\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "a,b\n-1.0,1.0\n1.0,-1.0\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "a,b\n-1.0,1.0\n1.0,-1.0\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Confusion Matrix Counts',
            'slug' => 'confusion-matrix-counts',
            'difficulty' => 'easy',
            'track' => 'ml',
            'fixturepath' => 'fixtures/confusion-matrix-counts/input.csv',
            'tags' => ['sklearn', 'metrics'],
            'statement' => '<p>Given columns <code>y_true</code>, <code>y_pred</code>, return a one-row DataFrame with <code>tn,fp,fn,tp</code>.</p>',
            'preload' => "import pandas as pd\nfrom sklearn.metrics import confusion_matrix\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return pd.DataFrame({'tn':[0],'fp':[0],'fn':[0],'tp':[0]})\n",
            'tests' => [
                ['stdin' => '', 'expected' => "tn,fp,fn,tp\n1,1,1,1\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "tn,fp,fn,tp\n1,1,1,1\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Bag of Words Counts',
            'slug' => 'bag-of-words-counts',
            'difficulty' => 'easy',
            'track' => 'nlp',
            'fixturepath' => 'fixtures/bag-of-words-counts/input.csv',
            'tags' => ['sklearn', 'nlp'],
            'statement' => '<p>Vectorize <code>text</code> with <code>CountVectorizer</code>. Return DataFrame with a single column <code>n_features</code> = vocabulary size.</p>',
            'preload' => "import pandas as pd\nfrom sklearn.feature_extraction.text import CountVectorizer\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return pd.DataFrame({'n_features': [0]})\n",
            'tests' => [
                ['stdin' => '', 'expected' => "n_features\n4\n", 'display' => 'sample', 'explanation' => 'good bad movie film → 4'],
                ['stdin' => '', 'expected' => "n_features\n4\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Sentiment Lexicon Score',
            'slug' => 'sentiment-lexicon-score',
            'difficulty' => 'easy',
            'track' => 'nlp',
            'fixturepath' => 'fixtures/sentiment-lexicon-score/input.csv',
            'tags' => ['nlp', 'pandas'],
            'statement' => '<p>Score each review: +1 per word in {good,great,love}, −1 per {bad,hate,terrible}. Return DataFrame <code>id,score</code>.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "id,score\n1,1\n2,-1\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "id,score\n1,1\n2,-1\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'One-Hot Encode Sex',
            'slug' => 'one-hot-encode-sex',
            'difficulty' => 'easy',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/one-hot-encode-sex/input.csv',
            'tags' => ['pandas', 'encoding'],
            'statement' => '<p>One-hot encode <code>sex</code> with <code>pd.get_dummies</code> (columns <code>sex_female</code>, <code>sex_male</code>) and keep <code>id</code>.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "id,sex_female,sex_male\n1,0,1\n2,1,0\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "id,sex_female,sex_male\n1,0,1\n2,1,0\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Feature: Family Size',
            'slug' => 'feature-family-size',
            'difficulty' => 'easy',
            'track' => 'wrangling',
            'fixturepath' => 'fixtures/feature-family-size/input.csv',
            'tags' => ['pandas', 'feature-engineering'],
            'statement' => '<p>Add <code>family_size = sibsp + parch + 1</code> and return all columns including the new one.</p>',
            'preload' => "import pandas as pd\n\ndef clean_df(df: pd.DataFrame) -> pd.DataFrame:\n    # TODO\n    return df\n",
            'tests' => [
                ['stdin' => '', 'expected' => "id,sibsp,parch,family_size\n1,1,0,2\n2,0,2,3\n", 'display' => 'sample', 'explanation' => ''],
                ['stdin' => '', 'expected' => "id,sibsp,parch,family_size\n1,1,0,2\n2,0,2,3\n", 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
        [
            'name' => 'Decision Tree Depth Cap',
            'slug' => 'decision-tree-depth-cap',
            'difficulty' => 'hard',
            'track' => 'ml',
            'fixturepath' => 'fixtures/decision-tree-depth-cap/input.csv',
            'tags' => ['sklearn', 'trees'],
            'statement' => '<p>Train <code>DecisionTreeClassifier(max_depth=2, random_state=0)</code> on features except <code>label</code>. Return training accuracy via <code>solve(df) -> float</code> (expect ≥ 0.80).</p>',
            'preload' => "import pandas as pd\nfrom sklearn.tree import DecisionTreeClassifier\n\ndef solve(df: pd.DataFrame) -> float:\n    # TODO\n    return 0.0\n",
            'tests' => [
                ['stdin' => '', 'expected' => '0.8000', 'display' => 'sample', 'explanation' => 'Metric floor'],
                ['stdin' => '', 'expected' => '0.8000', 'display' => 'hidden', 'explanation' => ''],
            ],
        ],
    ];
}
