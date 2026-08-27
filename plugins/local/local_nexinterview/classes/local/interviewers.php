<?php
namespace local_nexinterview\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom interviewer profiles (how the session should run).
 */
class interviewers {

    /** Interviewer persona / tone. */
    public const STYLES = ['friendly', 'strict', 'brief', 'socratic', 'supportive', 'panel'];

    public const ROLE_TRACKS = ['sde_intern', 'frontend', 'backend', 'ai_engineer', 'resume_deep'];

    public const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    public const PACES = ['relaxed', 'standard', 'brisk'];

    public const QUESTION_MIXES = ['conceptual', 'mixed', 'behavioral', 'system_design'];

    public const FOLLOWUP_DEPTHS = ['light', 'moderate', 'deep'];

    /**
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0 || !$DB->get_manager()->table_exists('local_nexinterview_interviewer')) {
            return null;
        }
        $row = $DB->get_record('local_nexinterview_interviewer', ['id' => $id]);
        return $row ?: null;
    }

    /**
     * Enabled profiles for the student hub.
     *
     * @return array<int,\stdClass>
     */
    public static function list_enabled(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexinterview_interviewer')) {
            return [];
        }
        return $DB->get_records_select(
            'local_nexinterview_interviewer',
            'enabled = 1',
            null,
            'sortorder ASC, name ASC'
        ) ?: [];
    }

    /**
     * All profiles for the manage UI.
     *
     * @return array<int,\stdClass>
     */
    public static function list_all(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexinterview_interviewer')) {
            return [];
        }
        return $DB->get_records('local_nexinterview_interviewer', null, 'sortorder ASC, name ASC') ?: [];
    }

    /**
     * Hub card context for Mustache.
     *
     * @return array<int,array>
     */
    public static function hub_cards(): array {
        $out = [];
        foreach (self::list_enabled() as $row) {
            $mins = max(10, min(45, (int) $row->durationminutes));
            $tags = [];
            foreach (array_slice(self::topics_list($row->topics), 0, 3) as $t) {
                $tags[] = ['label' => $t];
            }
            $diff = self::normalize_choice((string) ($row->difficulty ?? 'intermediate'), self::DIFFICULTIES, 'intermediate');
            $tags[] = ['label' => get_string('difficulty_' . $diff, 'local_nexinterview')];
            if ((int) $row->includecoding) {
                $tags[] = ['label' => get_string('focus_coding', 'local_nexinterview')];
            }
            $style = self::normalize_choice((string) $row->style, self::STYLES, 'friendly');
            $tags[] = ['label' => get_string('style_' . $style, 'local_nexinterview')];
            $out[] = [
                'id' => 'custom_' . (int) $row->id,
                'interviewerid' => (int) $row->id,
                'title' => (string) $row->name,
                'subtitle' => (string) ($row->description ?: get_string('custominterviewer_defaultsub', 'local_nexinterview')),
                'topics' => (string) $row->topics,
                'icon' => '✦',
                'duration' => '~' . $mins . ' min',
                'hasfocus' => !empty($tags),
                'focus' => $tags,
                'url' => (new \moodle_url('/local/nexinterview/start.php', [
                    'interviewerid' => (int) $row->id,
                ]))->out(false),
                'iscustom' => true,
            ];
        }
        return $out;
    }

    /**
     * @param string[] $allowed
     */
    public static function normalize_choice(string $value, array $allowed, string $default): string {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @return string[]
     */
    public static function topics_list(string $csv): array {
        $parts = preg_split('/\s*,\s*/', trim($csv)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(preg_replace('/[^a-zA-Z0-9_+\-\s\/.]/', '', $p) ?? '');
            $p = trim(preg_replace('/\s+/', ' ', $p) ?? '');
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Default focus topics by role track (avoid forcing hashmap on every custom profile).
     *
     * @return string[]
     */
    public static function default_topics_for_track(string $role): array {
        return match ($role) {
            'frontend' => ['javascript', 'dom', 'react', 'css', 'accessibility'],
            'backend' => ['apis', 'sql', 'indexes', 'caching', 'auth'],
            'ai_engineer' => ['python', 'ml basics', 'rag', 'evaluation', 'data pipelines'],
            'resume_deep' => ['projects', 'internships', 'ownership', 'impact', 'stack'],
            default => ['problem solving', 'data structures', 'apis', 'debugging', 'tradeoffs'],
        };
    }

    /**
     * Payload fragment for interview-service start.
     *
     * @return array<string,mixed>
     */
    public static function service_payload(\stdClass $row): array {
        $role = (string) $row->roletrack;
        if (!in_array($role, self::ROLE_TRACKS, true)) {
            $role = 'sde_intern';
        }
        $topics = self::topics_list((string) $row->topics);
        if (!$topics) {
            $topics = self::default_topics_for_track($role);
        }
        $style = self::normalize_choice((string) $row->style, self::STYLES, 'friendly');
        $difficulty = self::normalize_choice(
            (string) ($row->difficulty ?? 'intermediate'),
            self::DIFFICULTIES,
            'intermediate'
        );
        $pace = self::normalize_choice((string) ($row->pace ?? 'standard'), self::PACES, 'standard');
        $questionmix = self::normalize_choice(
            (string) ($row->questionmix ?? 'conceptual'),
            self::QUESTION_MIXES,
            'conceptual'
        );
        $followupdepth = self::normalize_choice(
            (string) ($row->followupdepth ?? 'moderate'),
            self::FOLLOWUP_DEPTHS,
            'moderate'
        );
        $duration = max(10, min(45, (int) $row->durationminutes));
        $qamins = max(0, min($duration - 2, (int) ($row->qaminutes ?? 0)));
        return [
            'role_track' => $role,
            'duration_minutes' => $duration,
            'topics' => $topics,
            // Spoken identity is always NexAI; profile name is hub display only.
            'interviewer_name' => 'NexAI',
            'interviewer_style' => $style,
            'interviewer_briefing' => trim((string) $row->briefing),
            'include_coding' => (bool) ((int) $row->includecoding),
            'moodle_interviewer_id' => (int) $row->id,
            'difficulty' => $difficulty,
            'pace' => $pace,
            'question_mix' => $questionmix,
            'followup_depth' => $followupdepth,
            'avoid_topics' => trim((string) ($row->avoidtopics ?? '')),
            'qa_minutes' => $qamins,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return int Record id
     */
    public static function save(array $data, int $userid = 0): int {
        global $DB, $USER;
        $now = time();
        $uid = $userid > 0 ? $userid : (int) ($USER->id ?? 0);
        $id = (int) ($data['id'] ?? 0);

        $style = self::normalize_choice((string) ($data['style'] ?? 'friendly'), self::STYLES, 'friendly');
        $role = (string) ($data['roletrack'] ?? 'sde_intern');
        if (!in_array($role, self::ROLE_TRACKS, true)) {
            $role = 'sde_intern';
        }
        $difficulty = self::normalize_choice(
            (string) ($data['difficulty'] ?? 'intermediate'),
            self::DIFFICULTIES,
            'intermediate'
        );
        $pace = self::normalize_choice((string) ($data['pace'] ?? 'standard'), self::PACES, 'standard');
        $questionmix = self::normalize_choice(
            (string) ($data['questionmix'] ?? 'conceptual'),
            self::QUESTION_MIXES,
            'conceptual'
        );
        $followupdepth = self::normalize_choice(
            (string) ($data['followupdepth'] ?? 'moderate'),
            self::FOLLOWUP_DEPTHS,
            'moderate'
        );

        $record = (object) [
            'name' => trim((string) ($data['name'] ?? '')) ?: get_string('custominterviewer_defaultname', 'local_nexinterview'),
            'description' => trim((string) ($data['description'] ?? '')),
            'roletrack' => $role,
            'topics' => implode(',', self::topics_list((string) ($data['topics'] ?? ''))),
            'avoidtopics' => trim((string) ($data['avoidtopics'] ?? '')),
            'durationminutes' => max(10, min(45, (int) ($data['durationminutes'] ?? 17))),
            'style' => $style,
            'difficulty' => $difficulty,
            'pace' => $pace,
            'questionmix' => $questionmix,
            'followupdepth' => $followupdepth,
            'briefing' => trim((string) ($data['briefing'] ?? '')),
            'includecoding' => !empty($data['includecoding']) ? 1 : 0,
            'qaminutes' => 0,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'sortorder' => (int) ($data['sortorder'] ?? 0),
            'timemodified' => $now,
            'usermodified' => $uid,
        ];
        $qamins = max(0, (int) ($data['qaminutes'] ?? 0));
        if ($qamins > 0) {
            $qamins = min($record->durationminutes - 2, $qamins);
        }
        $record->qaminutes = max(0, $qamins);
        if ($record->topics === '') {
            $record->topics = implode(',', self::default_topics_for_track($role));
        }

        if ($id > 0 && $DB->record_exists('local_nexinterview_interviewer', ['id' => $id])) {
            $record->id = $id;
            $DB->update_record('local_nexinterview_interviewer', $record);
            return $id;
        }

        $record->timecreated = $now;
        return (int) $DB->insert_record('local_nexinterview_interviewer', $record);
    }

    public static function delete(int $id): void {
        global $DB;
        if ($id > 0) {
            $DB->delete_records('local_nexinterview_interviewer', ['id' => $id]);
        }
    }
}
