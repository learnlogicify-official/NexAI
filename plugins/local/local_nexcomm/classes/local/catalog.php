<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Activity catalog helpers.
 */
class catalog {

    public const SKILLS = ['reading', 'listening', 'speaking', 'writing'];

    /**
     * @param int $userid
     * @param string $skill
     * @param string $difficulty
     * @param string $search
     * @param int $page
     * @param int $perpage
     * @return array{items:array,total:int}
     */
    public static function list_activities(
        int $userid,
        string $skill = '',
        string $difficulty = '',
        string $search = '',
        int $page = 0,
        int $perpage = 24
    ): array {
        global $DB;

        $params = [];
        $where = "status = 'ready'";
        $skill = strtolower(trim($skill));
        if (in_array($skill, self::SKILLS, true)) {
            $where .= ' AND skill = :skill';
            $params['skill'] = $skill;
        }
        $difficulty = strtolower(trim($difficulty));
        if (in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $where .= ' AND difficulty = :diff';
            $params['diff'] = $difficulty;
        }
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND ' . $DB->sql_like('title', ':q', false);
            $params['q'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        $total = (int) $DB->count_records_select('local_nexcomm_activity', $where, $params);
        $perpage = max(1, min(50, $perpage));
        $page = max(0, $page);
        $records = $DB->get_records_select(
            'local_nexcomm_activity',
            $where,
            $params,
            'skill ASC, difficulty ASC, title ASC',
            '*',
            $page * $perpage,
            $perpage
        );

        $items = [];
        foreach ($records as $rec) {
            $items[] = self::export_card($rec, $userid);
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param \stdClass $rec
     * @param int $userid
     * @return array
     */
    public static function export_card(\stdClass $rec, int $userid): array {
        global $DB;
        $userstatus = 'notstarted';
        $best = null;
        if ($userid > 0) {
            $attempts = $DB->get_records('local_nexcomm_attempt', [
                'userid' => $userid,
                'activityid' => $rec->id,
            ], 'timemodified DESC');
            foreach ($attempts as $a) {
                if ($a->status === 'passed' || ($a->status === 'submitted' && in_array($rec->skill, ['speaking', 'writing'], true))) {
                    $userstatus = 'completed';
                    $best = $a;
                    break;
                }
                if ($userstatus === 'notstarted') {
                    $userstatus = $a->status === 'failed' ? 'failed' : 'inprogress';
                    $best = $a;
                }
            }
        }
        return [
            'id' => (int) $rec->id,
            'skill' => (string) $rec->skill,
            'difficulty' => (string) $rec->difficulty,
            'title' => (string) $rec->title,
            'tags' => (string) ($rec->tags ?? ''),
            'userstatus' => $userstatus,
            'score' => $best ? (float) $best->score : null,
            'url' => (new \moodle_url('/local/nexcomm/attempt.php', ['id' => $rec->id]))->out(false),
        ];
    }

    /**
     * Full activity for attempt UI.
     *
     * @param int $activityid
     * @param int $userid
     * @return array
     */
    public static function get_activity(int $activityid, int $userid = 0): array {
        global $DB;
        $rec = $DB->get_record('local_nexcomm_activity', ['id' => $activityid], '*', MUST_EXIST);
        if ($rec->status !== 'ready' && !has_capability('local/nexcomm:manage', \context_system::instance())) {
            throw new \moodle_exception('notfound', 'local_nexcomm');
        }

        $questions = [];
        foreach ($DB->get_records('local_nexcomm_question', ['activityid' => $activityid], 'sortorder ASC') as $q) {
            $choices = json_decode($q->choices, true) ?: [];
            $safechoices = [];
            foreach ($choices as $key => $label) {
                $safechoices[] = ['key' => (string) $key, 'label' => (string) $label];
            }
            $questions[] = [
                'id' => (int) $q->id,
                'stem' => (string) $q->stem,
                'choices' => $safechoices,
            ];
        }

        $card = self::export_card($rec, $userid);
        return [
            'id' => (int) $rec->id,
            'skill' => (string) $rec->skill,
            'difficulty' => (string) $rec->difficulty,
            'title' => (string) $rec->title,
            'body' => (string) ($rec->body ?? ''),
            'prompt' => (string) ($rec->prompt ?? ''),
            'audiourl' => (string) ($rec->audiourl ?? ''),
            'passmark' => (int) $rec->passmark,
            'minwords' => (int) $rec->minwords,
            'timelimit' => (int) $rec->timelimit,
            'tags' => (string) ($rec->tags ?? ''),
            'questions' => $questions,
            'userstatus' => $card['userstatus'],
            'catalogurl' => (new \moodle_url('/local/nexcomm/catalog.php'))->out(false),
        ];
    }

    /**
     * @param int $userid
     * @return array{completed:int,inprogress:int,notstarted:int,total:int}
     */
    public static function funnel_counts(int $userid): array {
        global $DB;
        $total = (int) $DB->count_records('local_nexcomm_activity', ['status' => 'ready']);
        if ($total < 1 || $userid < 1) {
            return ['completed' => 0, 'inprogress' => 0, 'notstarted' => $total, 'total' => $total];
        }
        $ready = $DB->get_records('local_nexcomm_activity', ['status' => 'ready'], '', 'id, skill');
        $completed = 0;
        $inprogress = 0;
        foreach ($ready as $a) {
            $card = self::export_card($a, $userid);
            if ($card['userstatus'] === 'completed') {
                $completed++;
            } else if ($card['userstatus'] === 'inprogress' || $card['userstatus'] === 'failed') {
                $inprogress++;
            }
        }
        return [
            'completed' => $completed,
            'inprogress' => $inprogress,
            'notstarted' => max(0, $total - $completed - $inprogress),
            'total' => $total,
        ];
    }

    /**
     * Weighted readiness 0–100.
     *
     * @param int $userid
     * @return int
     */
    public static function readiness_pct(int $userid): int {
        $weights = ['speaking' => 35, 'writing' => 25, 'listening' => 20, 'reading' => 20];
        $score = 0;
        $weightsum = 0;
        foreach ($weights as $skill => $w) {
            $weightsum += $w;
            $counts = self::skill_ready_and_passed($userid, $skill);
            if ($counts['ready'] < 1) {
                continue;
            }
            $score += $w * ($counts['passed'] / $counts['ready']);
        }
        if ($weightsum < 1) {
            return 0;
        }
        // Recalculate only over skills that have content.
        $effective = 0;
        $got = 0;
        foreach ($weights as $skill => $w) {
            $counts = self::skill_ready_and_passed($userid, $skill);
            if ($counts['ready'] < 1) {
                continue;
            }
            $effective += $w;
            $got += $w * ($counts['passed'] / $counts['ready']);
        }
        if ($effective < 1) {
            return 0;
        }
        return (int) round(($got / $effective) * 100);
    }

    /**
     * @param int $userid
     * @param string $skill
     * @return array{ready:int,passed:int}
     */
    public static function skill_ready_and_passed(int $userid, string $skill): array {
        global $DB;
        $ready = (int) $DB->count_records('local_nexcomm_activity', ['status' => 'ready', 'skill' => $skill]);
        if ($ready < 1 || $userid < 1) {
            return ['ready' => $ready, 'passed' => 0];
        }
        $ids = $DB->get_fieldset_select('local_nexcomm_activity', 'id', "status = 'ready' AND skill = ?", [$skill]);
        $passed = 0;
        foreach ($ids as $id) {
            $card = self::export_card((object) ['id' => $id, 'skill' => $skill, 'difficulty' => '', 'title' => '', 'tags' => ''], $userid);
            // Need full record for skill check on speak/write - export_card uses skill from rec.
            $rec = $DB->get_record('local_nexcomm_activity', ['id' => $id], 'id, skill, difficulty, title, tags');
            if (!$rec) {
                continue;
            }
            $card = self::export_card($rec, $userid);
            if ($card['userstatus'] === 'completed') {
                $passed++;
            }
        }
        return ['ready' => $ready, 'passed' => $passed];
    }

    /**
     * @param int $userid
     * @return array<string,int>
     */
    public static function skill_pass_counts(int $userid): array {
        $out = [];
        foreach (self::SKILLS as $skill) {
            $out[$skill] = self::skill_ready_and_passed($userid, $skill)['passed'];
        }
        return $out;
    }
}
