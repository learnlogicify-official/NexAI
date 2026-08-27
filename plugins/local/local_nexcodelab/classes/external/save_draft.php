<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: save draft.
 *
 * @package    local_nexcodelab
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcodelab\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

/**
 * Autosave draft code.
 */
class save_draft extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'problemid' => new external_value(PARAM_INT, 'Problem id'),
            'language' => new external_value(PARAM_TEXT, 'Language'),
            'code' => new external_value(PARAM_RAW, 'Code'),
        ]);
    }

    public static function execute(int $problemid, string $language, string $code): array {
        global $DB, $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexcodelab:attempt', $context);

        $params = self::validate_parameters(self::execute_parameters(), compact('problemid', 'language', 'code'));
        $now = time();
        $existing = $DB->get_record('local_nexcodelab_draft', [
            'userid' => (int) $USER->id,
            'problemid' => (int) $params['problemid'],
            'language' => (string) $params['language'],
        ]);
        if ($existing) {
            $existing->code = (string) $params['code'];
            $existing->timemodified = $now;
            $DB->update_record('local_nexcodelab_draft', $existing);
        } else {
            $DB->insert_record('local_nexcodelab_draft', (object) [
                'userid' => (int) $USER->id,
                'problemid' => (int) $params['problemid'],
                'language' => (string) $params['language'],
                'code' => (string) $params['code'],
                'timemodified' => $now,
            ]);
        }
        return ['ok' => true, 'timemodified' => $now];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'ok'),
            'timemodified' => new external_value(PARAM_INT, 'timemodified'),
        ]);
    }
}
