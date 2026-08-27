<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Question bank navigation node.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport;

/**
 * Adds "LeetCode import" to the question bank tabs.
 */
class navigation extends \core_question\local\bank\navigation_node_base {

    public function get_navigation_title(): string {
        return get_string('navtitle', 'qbank_leetcodeimport');
    }

    public function get_navigation_key(): string {
        return 'leetcodeimport';
    }

    public function get_navigation_url(): \moodle_url {
        return new \moodle_url('/question/bank/leetcodeimport/import.php');
    }
}
