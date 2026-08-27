<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Save platform handles.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

/**
 * Save handles AJAX.
 */
class save_handles extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'handles' => new external_multiple_structure(
                new external_single_structure([
                    'platform' => new external_value(PARAM_ALPHANUMEXT, 'Platform key'),
                    'handle' => new external_value(PARAM_TEXT, 'Username', VALUE_DEFAULT, ''),
                ])
            ),
        ]);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }

    /**
     * @param array $handles
     * @return array
     */
    public static function execute(array $handles): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/local/nexportfolio/lib.php');

        self::validate_parameters(self::execute_parameters(), ['handles' => $handles]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexportfolio:manageown', $context);

        $allowed = array_keys(local_nexportfolio_platforms());

        $now = time();

        foreach ($handles as $item) {
            $platform = strtolower(trim($item['platform']));
            $handle = trim($item['handle']);
            if (!in_array($platform, $allowed, true)) {
                continue;
            }
            $existing = $DB->get_record('local_nexportfolio_handles', [
                'userid' => $USER->id,
                'platform' => $platform,
            ]);
            if ($handle === '') {
                if ($existing) {
                    $DB->delete_records('local_nexportfolio_handles', ['id' => $existing->id]);
                }
                continue;
            }
            if ($existing) {
                $existing->handle = \core_text::substr($handle, 0, 100);
                $existing->timemodified = $now;
                $DB->update_record('local_nexportfolio_handles', $existing);
            } else {
                $DB->insert_record('local_nexportfolio_handles', (object) [
                    'userid' => $USER->id,
                    'platform' => $platform,
                    'handle' => \core_text::substr($handle, 0, 100),
                    'verified' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }

        return [
            'ok' => true,
            'message' => get_string('handlesaved', 'local_nexportfolio'),
        ];
    }
}
