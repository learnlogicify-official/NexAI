<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Privacy provider for local_nexdashboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexdashboard\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;

/**
 * Stores weekly goal as a user preference only.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(
            'local_nexdashboard_weekly_goal',
            'privacy:metadata:preference:weeklygoal'
        );
        return $collection;
    }

    public static function export_user_preferences(int $userid): void {
        $goal = get_user_preferences('local_nexdashboard_weekly_goal', null, $userid);
        if ($goal !== null && $goal !== false && $goal !== '') {
            writer::export_user_preference(
                'local_nexdashboard',
                'local_nexdashboard_weekly_goal',
                $goal,
                get_string('privacy:metadata:preference:weeklygoal', 'local_nexdashboard')
            );
        }
    }
}
