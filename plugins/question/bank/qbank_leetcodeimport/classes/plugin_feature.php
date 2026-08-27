<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Question bank feature entrypoint.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport;

use core_question\local\bank\navigation_node_base;
use core_question\local\bank\plugin_features_base;

/**
 * Declares question bank navigation for LeetCode import.
 */
class plugin_feature extends plugin_features_base {

    /**
     * Navigation tab.
     *
     * @return navigation_node_base|null
     */
    public function get_navigation_node(): ?navigation_node_base {
        return new navigation();
    }
}
