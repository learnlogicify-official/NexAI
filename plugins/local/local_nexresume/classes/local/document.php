<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Resume document persistence and merge logic.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexresume\local;

defined('MOODLE_INTERNAL') || die();

/**
 * CRUD + merge for local_nexresume_doc.
 */
class document {

    /**
     * Load merged resume for a user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_merged(int $userid): array {
        $fresh = aggregator::collect($userid);
        $saved = self::load_raw($userid);
        if (!$saved) {
            return $fresh;
        }
        return self::merge($fresh, $saved);
    }

    /**
     * Re-pull platform data while keeping student-edited fields.
     *
     * @param int $userid
     * @return array
     */
    public static function refresh(int $userid): array {
        $saved = self::load_raw($userid);
        $fresh = aggregator::collect($userid);
        if (!$saved) {
            return $fresh;
        }

        $merged = $fresh;
        if (!empty($saved['contact']) && is_array($saved['contact'])) {
            $merged['contact'] = self::merge_nonempty($fresh['contact'] ?? [], $saved['contact']);
        }
        $merged['education'] = self::merge_education($fresh['education'] ?? [], $saved['education'] ?? []);
        foreach (['objective', 'achievements', 'volunteering', 'certifications'] as $key) {
            if (array_key_exists($key, $saved)) {
                $merged[$key] = $saved[$key];
            }
        }
        if (!empty($saved['sections']) && is_array($saved['sections'])) {
            $merged['sections'] = array_merge($fresh['sections'] ?? [], $saved['sections']);
        }
        if (!empty($saved['template'])) {
            $merged['template'] = templates::normalize((string) $saved['template']);
        }
        if (!empty($saved['skills']) && is_array($saved['skills'])) {
            // Keep manual skill edits if student changed defaults.
            foreach (['languages', 'frameworks', 'tools', 'fundamentals'] as $sk) {
                if (!empty($saved['skills'][$sk])) {
                    $merged['skills'][$sk] = $saved['skills'][$sk];
                }
            }
        }

        $merged['projects'] = self::merge_projects($fresh['projects'] ?? [], $saved['projects'] ?? []);
        $merged['platforms'] = $fresh['platforms'] ?? [];
        $merged['sources'] = $fresh['sources'] ?? [];
        $merged['meta']['completeness'] = self::calc_completeness($merged);

        return self::save($userid, $merged);
    }

    /**
     * @param int $userid
     * @param array $payload
     * @return array
     */
    public static function save(int $userid, array $payload): array {
        global $DB;

        $payload = self::normalize_payload($payload);

        $now = time();
        $record = $DB->get_record('local_nexresume_doc', ['userid' => $userid]);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($record) {
            $record->contentjson = $json;
            $record->timemodified = $now;
            $DB->update_record('local_nexresume_doc', $record);
        } else {
            $DB->insert_record('local_nexresume_doc', (object) [
                'userid' => $userid,
                'contentjson' => $json,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        return self::get_merged($userid);
    }

    /**
     * @param int $userid
     * @return array|null
     */
    private static function load_raw(int $userid): ?array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_nexresume_doc')) {
            return null;
        }
        $row = $DB->get_record('local_nexresume_doc', ['userid' => $userid]);
        if (!$row || empty($row->contentjson)) {
            return null;
        }
        $data = json_decode($row->contentjson, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Merge saved edits onto fresh platform data.
     *
     * @param array $fresh
     * @param array $saved
     * @return array
     */
    private static function merge(array $fresh, array $saved): array {
        $out = $fresh;

        foreach (['contact', 'skills'] as $key) {
            if (!empty($saved[$key]) && is_array($saved[$key])) {
                $out[$key] = self::merge_nonempty($out[$key] ?? [], $saved[$key]);
            }
        }

        $out['education'] = self::merge_education($fresh['education'] ?? [], $saved['education'] ?? []);

        if (!empty($saved['sections']) && is_array($saved['sections'])) {
            $out['sections'] = array_merge($out['sections'] ?? [], $saved['sections']);
        }

        foreach (['objective', 'achievements', 'volunteering', 'certifications'] as $key) {
            if (array_key_exists($key, $saved)) {
                $out[$key] = $saved[$key];
            }
        }

        if (!empty($saved['template'])) {
            $out['template'] = templates::normalize((string) $saved['template']);
        }

        $out['projects'] = self::merge_projects($fresh['projects'] ?? [], $saved['projects'] ?? []);
        if (!empty($fresh['projects']) && empty($saved['projects'])) {
            $out['sections']['projects'] = true;
        }

        if (!empty($saved['platforms']) && is_array($saved['platforms'])) {
            $out['platforms'] = $saved['platforms'];
        }

        $out['meta']['saved'] = true;
        $out['meta']['completeness'] = self::calc_completeness($out);

        return $out;
    }

    /**
     * @param array $base
     * @param array $overrides
     * @return array
     */
    private static function merge_nonempty(array $base, array $overrides): array {
        $out = $base;
        foreach ($overrides as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private static function merge_education(array $fresh, array $saved): array {
        $savedlist = self::education_as_list($saved);
        $hascontent = false;
        foreach ($savedlist as $row) {
            if (self::education_row_has_content($row)) {
                $hascontent = true;
                break;
            }
        }
        if ($hascontent || count($savedlist) > 1) {
            return $savedlist;
        }
        $freshlist = self::education_as_list($fresh);
        return $freshlist ?: [self::empty_education()];
    }

    /**
     * @param array $row
     * @return bool
     */
    private static function education_row_has_content(array $row): bool {
        foreach (['school', 'degree', 'dates', 'gpa', 'coursework'] as $key) {
            if (trim((string) ($row[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $value
     * @return array
     */
    public static function education_as_list(array $value): array {
        if ($value === []) {
            return [];
        }
        if (isset($value['school']) || isset($value['degree']) || isset($value['gpa'])) {
            return [array_merge(self::empty_education(), $value)];
        }
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = array_merge(self::empty_education(), $row);
        }
        return $out;
    }

    /**
     * @return array
     */
    public static function empty_education(): array {
        return [
            'school' => '',
            'degree' => '',
            'dates' => '',
            'gpa' => '',
            'coursework' => '',
        ];
    }

    /**
     * @param array $freshprojects
     * @param array $savedprojects
     * @return array
     */
    private static function merge_projects(array $freshprojects, array $savedprojects): array {
        $savedbyid = [];
        foreach ($savedprojects as $p) {
            if (!empty($p['id'])) {
                $savedbyid[(int) $p['id']] = $p;
            }
        }

        $out = [];
        foreach ($freshprojects as $p) {
            $id = (int) ($p['id'] ?? 0);
            if (isset($savedbyid[$id])) {
                $s = $savedbyid[$id];
                $out[] = array_merge($p, [
                    'included' => !empty($s['included']),
                    'name' => (string) ($s['name'] ?? $p['name']),
                    'url' => (string) ($s['url'] ?? $p['url']),
                    'stack' => (string) ($s['stack'] ?? $p['stack']),
                    'date' => (string) ($s['date'] ?? $p['date']),
                    'bullets' => is_array($s['bullets'] ?? null) ? $s['bullets'] : ($p['bullets'] ?? []),
                ]);
            } else {
                $out[] = $p;
            }
        }

        foreach ($savedprojects as $p) {
            if (empty($p['id']) && !empty($p['name'])) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param array $doc
     * @return int
     */
    private static function calc_completeness(array $doc): int {
        return aggregator::completeness(
            $doc['contact'] ?? [],
            $doc['education'] ?? [],
            $doc['skills'] ?? [],
            $doc['projects'] ?? [],
            $doc['platforms'] ?? []
        );
    }

    /**
     * Cap selected projects and sanitize skill lists before save.
     *
     * @param array $payload
     * @return array
     */
    private static function normalize_payload(array $payload): array {
        $payload['template'] = templates::normalize((string) ($payload['template'] ?? templates::DEFAULT));

        if (isset($payload['education']) && is_array($payload['education'])) {
            $list = self::education_as_list($payload['education']);
            $payload['education'] = $list ?: [self::empty_education()];
        }

        if (!empty($payload['projects']) && is_array($payload['projects'])) {
            $included = 0;
            foreach ($payload['projects'] as $i => $p) {
                if (!empty($p['included'])) {
                    $included++;
                    if ($included > aggregator::MAX_RESUME_PROJECTS) {
                        $payload['projects'][$i]['included'] = false;
                    }
                }
            }
        }
        if (!empty($payload['skills']) && is_array($payload['skills'])) {
            foreach (['languages', 'frameworks', 'tools', 'fundamentals'] as $key) {
                if (!empty($payload['skills'][$key])) {
                    $items = array_map('trim', explode(',', (string) $payload['skills'][$key]));
                    $items = array_values(array_filter($items, static function ($item) {
                        return $item !== '' && !preg_match('/^\d+$/', $item);
                    }));
                    $payload['skills'][$key] = implode(', ', $items);
                }
            }
        }
        return $payload;
    }
}
