<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Adhoc task: enrol a batch of users into a NexCoursePro course.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\task;

defined('MOODLE_INTERNAL') || die();

use format_nexcoursepro\local\enrol_roster;

/**
 * Background enrolment runner (cron / adhoc).
 */
class enrol_users_adhoc extends \core\task\adhoc_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('enroltaskname', 'format_nexcoursepro');
    }

    public function execute(): void {
        $data = $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        $roleid = (int) ($data->roleid ?? 0);
        $userids = [];
        if (!empty($data->userids) && is_array($data->userids)) {
            foreach ($data->userids as $userid) {
                $userids[] = (int) $userid;
            }
        }
        if ($courseid < 2 || !$userids) {
            return;
        }
        // Skip if a shutdown worker already finished this batch.
        $key = 'nxpro_enrol_done_' . sha1($courseid . ':' . $roleid . ':' . implode(',', $userids));
        if (get_config('format_nexcoursepro', $key)) {
            unset_config($key, 'format_nexcoursepro');
            return;
        }
        enrol_roster::enrol_users($courseid, $userids, $roleid);
    }
}
