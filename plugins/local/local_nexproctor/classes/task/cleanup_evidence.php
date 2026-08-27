<?php
namespace local_nexproctor\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Delete old evidence.
 */
class cleanup_evidence extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_cleanup', 'local_nexproctor');
    }

    public function execute() {
        global $DB;
        $days = (int) get_config('local_nexproctor', 'retentiondays');
        if ($days <= 0) {
            $days = 30;
        }
        $cutoff = time() - ($days * DAYSECS);
        $sessions = $DB->get_records_select('local_nexproctor_sessions', 'startedat < ?', [$cutoff], '', 'id, cmid');
        $fs = get_file_storage();
        foreach ($sessions as $s) {
            $ctx = \context_module::instance($s->cmid, IGNORE_MISSING);
            if ($ctx) {
                foreach (['snapshot', 'screengrab', 'audioclip', 'prestart'] as $area) {
                    $fs->delete_area_files($ctx->id, 'local_nexproctor', $area, $s->id);
                }
            }
            $DB->delete_records('local_nexproctor_evidence', ['sessionid' => $s->id]);
            $DB->delete_records('local_nexproctor_events', ['sessionid' => $s->id]);
            $DB->delete_records('local_nexproctor_sessions', ['id' => $s->id]);
        }
    }
}
