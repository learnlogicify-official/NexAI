<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Library functions for local_nexinterview.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add NexInterview to the top custom menu.
 *
 * @param global_navigation $nav
 */
function local_nexinterview_extend_navigation(global_navigation $nav): void {
    global $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $enabled = get_config('local_nexinterview', 'enablemenu');
    if ($enabled === '0') {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/nexinterview:view', $context)) {
        return;
    }

    $url = '/local/nexinterview/index.php';
    $label = get_string('menuname', 'local_nexinterview');

    if (!empty($CFG->branch) && (int) $CFG->branch >= 400) {
        // Append (do not prepend) so Site administration / core primary items stay usable.
        // Only mutate in-request CFG — never persist — and dedupe any prior copies.
        $haystack = (string) ($CFG->custommenuitems ?? '');
        $nodes = preg_split("/\r\n|\n|\r/", $haystack) ?: [];
        $kept = [];
        foreach ($nodes as $line) {
            $trim = trim((string) $line);
            if ($trim === '') {
                continue;
            }
            if (stripos($trim, '/local/nexinterview/') !== false) {
                continue;
            }
            $kept[] = $line;
        }
        $kept[] = $label . '|' . $url;
        $CFG->custommenuitems = implode("\n", $kept);
        return;
    }

    $icon = new pix_icon('i/users', '');
    $node = $nav->add(
        $label,
        new moodle_url($url),
        navigation_node::TYPE_CUSTOM,
        'nexinterview',
        'nexinterview',
        $icon
    );
    $node->showinflatnavigation = true;
}

/**
 * @param moodle_page $page
 */
function local_nexinterview_setup_page(moodle_page $page): void {
    $page->add_body_class('path-local-nexinterview');
    $page->add_body_class('nxi-fullwidth');
    $page->set_pagelayout('standard');
    $page->set_heading('');
    // fonts.css is not auto-included site-wide (only styles.css is).
    $page->requires->css('/local/nexinterview/fonts.css');
}

/**
 * Header + stats for hub.
 *
 * @param int $userid
 * @return array
 */
function local_nexinterview_header_context(int $userid): array {
    global $USER;

    $stats = \local_nexinterview\local\attempts::user_stats($userid);
    $completed = (int) ($stats['completed'] ?? 0);
    $attempts = (int) ($stats['attempts'] ?? 0);
    $avg = (int) round((float) ($stats['avg'] ?? 0));
    $best = (int) round((float) ($stats['best'] ?? 0));
    $pct = $attempts > 0 ? (int) round(($completed / max(1, $attempts)) * 100) : 0;
    $sysctx = context_system::instance();
    $canmanage = has_capability('local/nexinterview:manage', $sysctx);
    $canviewall = has_capability('local/nexinterview:viewallreports', $sysctx) || is_siteadmin();

    $ongoing = local_nexinterview_ongoing_context($userid);

    return [
        'title' => get_string('pluginname', 'local_nexinterview'),
        'eyebrow' => get_string('eyebrow', 'local_nexinterview'),
        'subtitle' => get_string('subtitle', 'local_nexinterview'),
        'displayname' => fullname($USER),
        'contentpct' => $pct,
        'completedcount' => $completed,
        'attemptscount' => $attempts,
        'reportsurl' => (new moodle_url('/local/nexinterview/reports.php'))->out(false),
        'allreportsurl' => (new moodle_url('/local/nexinterview/reports.php', ['all' => 1]))->out(false),
        'canmanage' => $canmanage,
        'canviewall' => $canviewall,
        'manageurl' => (new moodle_url('/local/nexinterview/manage.php'))->out(false),
        'hasongoing' => !empty($ongoing['hasongoing']),
        'resumeurl' => $ongoing['resumeurl'] ?? '',
        'ongoingtrack' => $ongoing['ongoingtrack'] ?? '',
        'ongoingstarted' => $ongoing['ongoingstarted'] ?? '',
        'hasstats' => true,
        'stats' => [
            [
                'key' => 'completed',
                'icon' => '✓',
                'value' => $completed,
                'label' => get_string('statcompleted', 'local_nexinterview'),
            ],
            [
                'key' => 'attempts',
                'icon' => '◎',
                'value' => $attempts,
                'label' => get_string('statattempts', 'local_nexinterview'),
            ],
            [
                'key' => 'avg',
                'icon' => '⌀',
                'value' => $avg,
                'label' => get_string('statavg', 'local_nexinterview'),
            ],
            [
                'key' => 'best',
                'icon' => '★',
                'value' => $best,
                'label' => get_string('statbest', 'local_nexinterview'),
            ],
        ],
    ];
}

/**
 * Ongoing interview resume CTA for hub / start gate.
 *
 * Validates the Moodle in-progress row against the interview service when configured.
 *
 * @param int $userid
 * @return array
 */
function local_nexinterview_ongoing_context(int $userid): array {
    $rec = \local_nexinterview\local\attempts::latest_inprogress($userid);
    if (!$rec) {
        return ['hasongoing' => false];
    }

    $sessionid = (string) ($rec->sessionid ?? '');
    if ($sessionid === '') {
        \local_nexinterview\local\attempts::mark_abandoned((int) $rec->id);
        return ['hasongoing' => false];
    }

    $client = new \local_nexinterview\local\client();
    if ($client->configured()) {
        try {
            $view = $client->get($sessionid);
            $status = (string) ($view['status'] ?? '');
            if ($status === 'completed') {
                \local_nexinterview\local\attempts::sync_completed($view);
                return ['hasongoing' => false];
            }
            // Active / wrapping / etc. — allow resume.
        } catch (\Throwable $e) {
            // Stale local row (service gone or unknown session).
            \local_nexinterview\local\attempts::mark_abandoned((int) $rec->id);
            return ['hasongoing' => false];
        }
    }

    $trackid = (string) ($rec->roletrack ?? '');
    $tracktitle = $trackid !== '' ? $trackid : get_string('pluginname', 'local_nexinterview');
    foreach (local_nexinterview_tracks() as $t) {
        if (($t['id'] ?? '') === $trackid) {
            $tracktitle = (string) $t['title'];
            break;
        }
    }

    $started = (int) ($rec->timecreated ?? 0);
    return [
        'hasongoing' => true,
        'ongoingsessionid' => $sessionid,
        'ongoingtrack' => $tracktitle,
        'ongoingtrackid' => $trackid,
        'ongoingstarted' => $started
            ? userdate($started, get_string('strftimedatetimeshort', 'langconfig'))
            : '',
        'resumeurl' => (new moodle_url('/local/nexinterview/room.php', [
            'sessionid' => $sessionid,
            'track' => $trackid !== '' ? $trackid : 'sde_intern',
        ]))->out(false),
    ];
}

/**
 * Past interview rows for hub / reports.
 *
 * @param int $userid
 * @param int $limit
 * @return array
 */
function local_nexinterview_history_context(int $userid, int $limit = 8): array {
    $tracks = [];
    foreach (local_nexinterview_tracks() as $t) {
        $tracks[$t['id']] = $t['title'];
    }

    $rows = \local_nexinterview\local\attempts::list_for_user($userid, $limit, 'completed');
    $items = [];
    foreach ($rows as $rec) {
        $score = (int) round((float) ($rec->overallscore ?? 0));
        $when = (int) ($rec->timecompleted ?: $rec->timecreated);
        $trackid = (string) ($rec->roletrack ?? '');
        $items[] = [
            'sessionid' => (string) $rec->sessionid,
            'track' => $tracks[$trackid] ?? get_string('pluginname', 'local_nexinterview'),
            'score' => $score,
            'scoreclass' => $score >= 70 ? 'good' : ($score >= 50 ? 'mid' : 'low'),
            'datedisplay' => $when ? userdate($when, get_string('strftimedatefullshort', 'langconfig')) : '—',
            'url' => (new moodle_url('/local/nexinterview/feedback.php', [
                'sessionid' => (string) $rec->sessionid,
            ]))->out(false),
        ];
    }

    return [
        'hashistory' => !empty($items),
        'history' => $items,
        'historycount' => count($items),
        'reportsurl' => (new moodle_url('/local/nexinterview/reports.php'))->out(false),
        'issitewide' => false,
    ];
}

/**
 * Site-wide completed interviews for teachers / managers.
 *
 * @param int $limit
 * @return array
 */
function local_nexinterview_all_reports_context(int $limit = 80): array {
    $tracks = [];
    foreach (local_nexinterview_tracks() as $t) {
        $tracks[$t['id']] = $t['title'];
    }

    $rows = \local_nexinterview\local\attempts::list_completed_all($limit);
    $items = [];
    foreach ($rows as $rec) {
        $score = (int) round((float) ($rec->overallscore ?? 0));
        $when = (int) ($rec->timecompleted ?: $rec->timecreated);
        $trackid = (string) ($rec->roletrack ?? '');
        $items[] = [
            'sessionid' => (string) $rec->sessionid,
            'track' => $tracks[$trackid] ?? get_string('pluginname', 'local_nexinterview'),
            'score' => $score,
            'scoreclass' => $score >= 70 ? 'good' : ($score >= 50 ? 'mid' : 'low'),
            'datedisplay' => $when ? userdate($when, get_string('strftimedatefullshort', 'langconfig')) : '—',
            'student' => fullname($rec),
            'institution' => trim((string) ($rec->institution ?? '')),
            'url' => (new moodle_url('/local/nexinterview/feedback.php', [
                'sessionid' => (string) $rec->sessionid,
            ]))->out(false),
        ];
    }

    return [
        'hashistory' => !empty($items),
        'history' => $items,
        'historycount' => count($items),
        'reportsurl' => (new moodle_url('/local/nexinterview/reports.php'))->out(false),
        'issitewide' => true,
        'showstudent' => true,
    ];
}

/**
 * Whether this built-in track is resume-only (no live coding).
 */
function local_nexinterview_is_resume_track(string $id): bool {
    return $id === 'resume_deep';
}

/**
 * Built-in tracks shown on the hub.
 *
 * @return array
 */
function local_nexinterview_tracks(): array {
    return [
        [
            'id' => 'resume_deep',
            'title' => get_string('track_resume', 'local_nexinterview'),
            'subtitle' => get_string('track_resume_sub', 'local_nexinterview'),
            'topics' => 'projects,internships,ownership,impact,stack,tradeoffs',
            'icon' => 'CV',
            'duration' => get_string('trackduration_resume', 'local_nexinterview'),
            'hasfocus' => true,
            'resumeonly' => true,
            'focus' => [
                ['label' => get_string('focus_resume', 'local_nexinterview')],
                ['label' => get_string('focus_ownership', 'local_nexinterview')],
                ['label' => get_string('focus_voice', 'local_nexinterview')],
            ],
        ],
        [
            'id' => 'sde_intern',
            'title' => get_string('track_sde', 'local_nexinterview'),
            'subtitle' => get_string('track_sde_sub', 'local_nexinterview'),
            'topics' => 'arrays,strings,hashmap,stacks,complexity',
            'icon' => '{ }',
            'duration' => get_string('trackduration', 'local_nexinterview'),
            'hasfocus' => true,
            'focus' => [
                ['label' => get_string('focus_dsa', 'local_nexinterview')],
                ['label' => get_string('focus_coding', 'local_nexinterview')],
                ['label' => get_string('focus_voice', 'local_nexinterview')],
            ],
        ],
        [
            'id' => 'frontend',
            'title' => get_string('track_frontend', 'local_nexinterview'),
            'subtitle' => get_string('track_frontend_sub', 'local_nexinterview'),
            'topics' => 'arrays,strings,hashmap,dom,javascript',
            'icon' => '</>',
            'duration' => get_string('trackduration', 'local_nexinterview'),
            'hasfocus' => true,
            'focus' => [
                ['label' => get_string('focus_js', 'local_nexinterview')],
                ['label' => get_string('focus_ui', 'local_nexinterview')],
                ['label' => get_string('focus_voice', 'local_nexinterview')],
            ],
        ],
        [
            'id' => 'backend',
            'title' => get_string('track_backend', 'local_nexinterview'),
            'subtitle' => get_string('track_backend_sub', 'local_nexinterview'),
            'topics' => 'arrays,hashmap,stacks,dbms,apis',
            'icon' => 'API',
            'duration' => get_string('trackduration', 'local_nexinterview'),
            'hasfocus' => true,
            'focus' => [
                ['label' => get_string('focus_api', 'local_nexinterview')],
                ['label' => get_string('focus_data', 'local_nexinterview')],
                ['label' => get_string('focus_voice', 'local_nexinterview')],
            ],
        ],
        [
            'id' => 'ai_engineer',
            'title' => get_string('track_ai', 'local_nexinterview'),
            'subtitle' => get_string('track_ai_sub', 'local_nexinterview'),
            'topics' => 'arrays,hashmap,complexity,ml,python',
            'icon' => 'AI',
            'duration' => get_string('trackduration', 'local_nexinterview'),
            'hasfocus' => true,
            'focus' => [
                ['label' => get_string('focus_ml', 'local_nexinterview')],
                ['label' => get_string('focus_python', 'local_nexinterview')],
                ['label' => get_string('focus_voice', 'local_nexinterview')],
            ],
        ],
    ];
}
