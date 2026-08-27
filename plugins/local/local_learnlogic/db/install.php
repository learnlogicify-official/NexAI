<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Post-install seed for local_learnlogic.
 *
 * @package    local_learnlogic
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Seed a couple of sample problems after first install.
 */
function xmldb_local_learnlogic_install() {
    global $DB, $USER;

    $now = time();
    $uid = !empty($USER->id) ? (int) $USER->id : 0;

    $problems = [
        [
            'name' => 'Two Sum',
            'slug' => 'two-sum',
            'difficulty' => 'easy',
            'statement' => '<p>Given an array of integers <code>nums</code> and an integer <code>target</code>, '
                . 'return indices of the two numbers that add up to target.</p>'
                . '<p>Input: first line <code>n</code>, second line <code>n</code> integers, third line <code>target</code>. '
                . 'Output: two indices separated by space.</p>',
            'preload' => "import sys\n\ndef main():\n    data = sys.stdin.read().strip().split()\n"
                . "    if not data:\n        return\n    n = int(data[0])\n    nums = list(map(int, data[1:1+n]))\n"
                . "    target = int(data[1+n])\n    # TODO: print two indices\n    print(0, 1)\n\nif __name__ == '__main__':\n    main()\n",
            'tests' => [
                ['stdin' => "4\n2 7 11 15\n9\n", 'expected' => "0 1", 'display' => 'sample'],
                ['stdin' => "3\n3 2 4\n6\n", 'expected' => "1 2", 'display' => 'sample'],
                ['stdin' => "2\n3 3\n6\n", 'expected' => "0 1", 'display' => 'hidden'],
            ],
            'tags' => ['array', 'hash-table'],
        ],
        [
            'name' => 'FizzBuzz',
            'slug' => 'fizzbuzz',
            'difficulty' => 'easy',
            'statement' => '<p>Given an integer <code>n</code>, for each integer <code>i</code> in the range from 1 to n inclusive, '
                . 'print <code>FizzBuzz</code> if divisible by 3 and 5, <code>Fizz</code> if by 3, <code>Buzz</code> if by 5, '
                . 'otherwise print <code>i</code>. One value per line.</p>',
            'preload' => "n = int(input())\nfor i in range(1, n + 1):\n    # TODO\n    print(i)\n",
            'tests' => [
                ['stdin' => "5\n", 'expected' => "1\n2\nFizz\n4\nBuzz", 'display' => 'sample'],
                ['stdin' => "15\n", 'expected' => "1\n2\nFizz\n4\nBuzz\nFizz\n7\n8\nFizz\nBuzz\n11\nFizz\n13\n14\nFizzBuzz", 'display' => 'hidden'],
            ],
            'tags' => ['math', 'string'],
        ],
    ];

    foreach ($problems as $p) {
        if ($DB->record_exists('local_learnlogic_problem', ['slug' => $p['slug']])) {
            continue;
        }
        $pid = $DB->insert_record('local_learnlogic_problem', (object) [
            'name' => $p['name'],
            'slug' => $p['slug'],
            'statement' => $p['statement'],
            'difficulty' => $p['difficulty'],
            'status' => 'ready',
            'defaultlanguage' => 'python3',
            'sourcequestionid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $uid,
        ]);
        $DB->insert_record('local_learnlogic_lang', (object) [
            'problemid' => $pid,
            'language' => 'python3',
            'preload' => $p['preload'],
            'prototype' => 0,
        ]);
        $order = 0;
        foreach ($p['tests'] as $t) {
            $DB->insert_record('local_learnlogic_testcase', (object) [
                'problemid' => $pid,
                'stdin' => $t['stdin'],
                'expected' => $t['expected'],
                'display' => $t['display'],
                'sortorder' => $order++,
                'explanation' => '',
            ]);
        }
        foreach ($p['tags'] as $tagname) {
            $tag = $DB->get_record('local_learnlogic_tag', ['name' => $tagname]);
            if (!$tag) {
                $tagid = $DB->insert_record('local_learnlogic_tag', (object) ['name' => $tagname]);
            } else {
                $tagid = (int) $tag->id;
            }
            $DB->insert_record('local_learnlogic_problem_tag', (object) [
                'problemid' => $pid,
                'tagid' => $tagid,
            ]);
        }
    }
}
