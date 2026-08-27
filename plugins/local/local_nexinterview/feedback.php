<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Post-interview feedback.
 *
 * @package    local_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexinterview:view', $context);

$sessionid = required_param('sessionid', PARAM_ALPHANUMEXT);

$attempt = \local_nexinterview\local\attempts::get_by_session($sessionid);
$canall = has_capability('local/nexinterview:viewallreports', $context) || is_siteadmin();
if (!$attempt || ((int) $attempt->userid !== (int) $USER->id && !$canall)) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('feedbacktitle', 'local_nexinterview'));
}

$PAGE->set_url(new moodle_url('/local/nexinterview/feedback.php', ['sessionid' => $sessionid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('feedbacktitle', 'local_nexinterview'));
local_nexinterview_setup_page($PAGE);
$PAGE->requires->css('/local/nexinterview/styles.css');

$client = new \local_nexinterview\local\client();
$view = [];
$error = '';
try {
    $view = $client->get($sessionid);
    \local_nexinterview\local\attempts::sync_completed($view);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$report = is_array($view['report'] ?? null) ? $view['report'] : null;
$scores = is_array($view['scores'] ?? null) ? $view['scores'] : [];
$overall = (int) round((float) ($report['overall_score'] ?? $scores['overall'] ?? 0));
$overall = max(0, min(100, $overall));

$bandraw = strtolower((string) ($report['band'] ?? ''));
$recraw = strtolower((string) ($report['recommendation'] ?? ''));

$bandmap = [
    'strong' => get_string('band_strong', 'local_nexinterview'),
    'borderline' => get_string('band_borderline', 'local_nexinterview'),
    'needs_work' => get_string('band_needs_work', 'local_nexinterview'),
];
$recmap = [
    'recommend' => get_string('rec_recommend', 'local_nexinterview'),
    'maybe' => get_string('rec_maybe', 'local_nexinterview'),
    'not_ready' => get_string('rec_not_ready', 'local_nexinterview'),
];

$dimdefs = [
    'conceptual' => get_string('dim_conceptual', 'local_nexinterview'),
    'problem_solving' => get_string('dim_problem_solving', 'local_nexinterview'),
    'idea' => get_string('dim_problem_solving', 'local_nexinterview'),
    'coding' => get_string('dim_coding', 'local_nexinterview'),
    'explanation' => get_string('dim_explanation', 'local_nexinterview'),
    'explain' => get_string('dim_explanation', 'local_nexinterview'),
    'communication' => get_string('dim_communication', 'local_nexinterview'),
    'independence' => get_string('dim_independence', 'local_nexinterview'),
];

$rawdims = [];
if (!empty($report['dimensions']) && is_array($report['dimensions'])) {
    $rawdims = $report['dimensions'];
} else {
    foreach (['conceptual', 'idea', 'coding', 'explain', 'communication'] as $key) {
        if (isset($scores[$key])) {
            $rawdims[$key] = $scores[$key];
        }
    }
}

$dims = [];
$seenlabels = [];
foreach ($rawdims as $key => $val) {
    $key = (string) $key;
    if ($key === 'overall' || $key === 'independence') {
        continue;
    }
    $label = $dimdefs[$key] ?? ucwords(str_replace('_', ' ', $key));
    if (isset($seenlabels[$label])) {
        continue;
    }
    $seenlabels[$label] = true;
    $num = max(0, min(100, (int) round((float) $val)));
    $tone = $num >= 70 ? 'good' : ($num >= 50 ? 'mid' : 'low');
    $dims[] = [
        'label' => $label,
        'value' => $num,
        'pct' => $num,
        'tone' => $tone,
    ];
}

$listify = static function (array $items): array {
    $out = [];
    foreach ($items as $item) {
        $text = is_array($item) ? (string) ($item['text'] ?? $item['label'] ?? '') : (string) $item;
        $text = trim($text);
        if ($text !== '') {
            $out[] = ['text' => $text];
        }
    }
    return $out;
};

$indepmeta = is_array($report['independence'] ?? null) ? $report['independence'] : [];
$independence = (int) round((float) ($indepmeta['independence_score'] ?? $rawdims['independence'] ?? 0));
$independence = max(0, min(100, $independence));
$indepband = (string) ($indepmeta['independence_band'] ?? '');
$indepbandlabels = [
    'high_independence' => get_string('indep_high', 'local_nexinterview'),
    'mixed_independence' => get_string('indep_mixed', 'local_nexinterview'),
    'hint_dependent' => get_string('indep_low', 'local_nexinterview'),
];
$hasindependence = $independence > 0 || !empty($indepmeta);

$skillrows = [];
$skillgraph = is_array($report['skill_graph'] ?? null) ? $report['skill_graph'] : [];
foreach ($skillgraph as $parent => $node) {
    if (!is_array($node)) {
        continue;
    }
    $plabel = (string) ($node['label'] ?? $parent);
    $children = is_array($node['children'] ?? null) ? $node['children'] : [];
    foreach ($children as $child => $val) {
        $num = max(0, min(100, (int) round(((float) $val) * 100)));
        $skillrows[] = [
            'label' => $plabel . ' / ' . $child,
            'value' => $num,
            'pct' => $num,
            'tone' => $num >= 70 ? 'good' : ($num >= 50 ? 'mid' : 'low'),
        ];
    }
}
usort($skillrows, static function ($a, $b) {
    return $a['value'] <=> $b['value'];
});
$skillrows = array_slice($skillrows, 0, 8);

$timeline = [];
$pushtimeline = static function (string $role, string $stage, string $preview) use (&$timeline): void {
    $preview = trim($preview);
    if ($preview === '') {
        return;
    }
    $timeline[] = [
        'role' => $role,
        'stage' => $stage,
        'preview' => $preview,
        'isevidence' => $role === 'evidence',
        'isassistant' => $role === 'assistant',
        'isstudent' => $role === 'student',
    ];
};

// Prefer full session turns — report.timeline is often truncated server-side.
$turns = is_array($view['turns'] ?? null) ? $view['turns'] : [];
if (!empty($turns)) {
    foreach ($turns as $row) {
        if (!is_array($row)) {
            continue;
        }
        $role = (string) ($row['role'] ?? '');
        if ($role !== 'assistant' && $role !== 'student') {
            continue;
        }
        $pushtimeline(
            $role,
            (string) ($row['stage'] ?? ''),
            (string) ($row['content'] ?? $row['preview'] ?? '')
        );
    }
    foreach (is_array($report['timeline'] ?? null) ? $report['timeline'] : [] as $row) {
        if (!is_array($row) || (string) ($row['role'] ?? '') !== 'evidence') {
            continue;
        }
        $pushtimeline('evidence', (string) ($row['stage'] ?? ''), (string) ($row['preview'] ?? ''));
    }
} else {
    foreach (is_array($report['timeline'] ?? null) ? $report['timeline'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pushtimeline(
            (string) ($row['role'] ?? ''),
            (string) ($row['stage'] ?? ''),
            (string) ($row['preview'] ?? $row['content'] ?? '')
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexinterview/feedback', [
    'error' => $error,
    'hasreport' => !empty($report) || !empty($scores),
    'overall' => $overall,
    'bandkey' => preg_replace('/[^a-z_]/', '', $bandraw) ?: 'mid',
    'bandlabel' => $bandmap[$bandraw] ?? ($bandraw !== '' ? $bandraw : ''),
    'reckey' => preg_replace('/[^a-z_]/', '', $recraw) ?: 'mid',
    'reclabel' => $recmap[$recraw] ?? ($recraw !== '' ? $recraw : ''),
    'hasdims' => !empty($dims),
    'dims' => $dims,
    'hasindependence' => $hasindependence,
    'independence' => $independence,
    'indeppct' => $independence,
    'indeptone' => $independence >= 70 ? 'good' : ($independence >= 50 ? 'mid' : 'low'),
    'indepbandlabel' => $indepbandlabels[$indepband] ?? '',
    'hasskills' => !empty($skillrows),
    'skills' => $skillrows,
    'hastimeline' => !empty($timeline),
    'timeline' => $timeline,
    'strengths' => $listify($report['strengths'] ?? []),
    'gaps' => $listify($report['gaps'] ?? []),
    'nextsteps' => $listify($report['next_steps'] ?? []),
    'huburl' => (new moodle_url('/local/nexinterview/index.php'))->out(false),
    'reportsurl' => (new moodle_url('/local/nexinterview/reports.php'))->out(false),
]);
echo $OUTPUT->footer();
