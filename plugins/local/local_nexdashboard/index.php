<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Student dashboard.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/nexdashboard:view', $context);

$PAGE->set_title(get_string('pluginname', 'local_nexdashboard'));
local_nexdashboard_setup_page($PAGE);
$PAGE->requires->css('/local/nexdashboard/styles.css');

$PAGE->requires->js_call_amd('local_nexdashboard/dashboard', 'init', [[
    'strings' => [
        'nextaction' => get_string('nextaction', 'local_nexdashboard'),
        'continuelearning' => get_string('continuelearning', 'local_nexdashboard'),
        'progress' => get_string('progress', 'local_nexdashboard'),
        'viewall' => get_string('viewall', 'local_nexdashboard'),
        'learninganalytics' => get_string('learninganalytics', 'local_nexdashboard'),
        'weekly' => get_string('weekly', 'local_nexdashboard'),
        'daily' => get_string('daily', 'local_nexdashboard'),
        'monthly' => get_string('monthly', 'local_nexdashboard'),
        'period' => get_string('period', 'local_nexdashboard'),
        'metric' => get_string('metric', 'local_nexdashboard'),
        'xpearned' => get_string('xpearned', 'local_nexdashboard'),
        'problemssolved' => get_string('problemssolved', 'local_nexdashboard'),
        'timespent' => get_string('timespent', 'local_nexdashboard'),
        'totalxp' => get_string('totalxp', 'local_nexdashboard'),
        'totalxpearned' => get_string('totalxpearned', 'local_nexdashboard'),
        'totalproblemssolved' => get_string('totalproblemssolved', 'local_nexdashboard'),
        'totaltimespent' => get_string('totaltimespent', 'local_nexdashboard'),
        'alltime' => get_string('alltime', 'local_nexdashboard'),
        'alltimelabel' => get_string('alltimelabel', 'local_nexdashboard'),
        'xphint' => get_string('xphint', 'local_nexdashboard', (object) [
            'grades' => '{$a->grades}',
            'practice' => '{$a->practice}',
            'codelab' => '{$a->codelab}',
        ]),
        'average' => get_string('average', 'local_nexdashboard'),
        'perweek' => get_string('perweek', 'local_nexdashboard'),
        'perday' => get_string('perday', 'local_nexdashboard'),
        'permonth' => get_string('permonth', 'local_nexdashboard'),
        'trend' => get_string('trend', 'local_nexdashboard'),
        'trendhint' => get_string('trendhint', 'local_nexdashboard'),
        'chartempty' => get_string('chartempty', 'local_nexdashboard'),
        'minutesunit' => get_string('minutesunit', 'local_nexdashboard', '{$a}'),
        'dailystreak' => get_string('dailystreak', 'local_nexdashboard'),
        'days' => get_string('days', 'local_nexdashboard'),
        'keepstreak' => get_string('keepstreak', 'local_nexdashboard'),
        'streakhint' => get_string('streakhint', 'local_nexdashboard'),
        'playerstats' => get_string('playerstats', 'local_nexdashboard'),
        'totalsolved' => get_string('totalsolved', 'local_nexdashboard'),
        'rank' => get_string('rank', 'local_nexdashboard'),
        'accuracy' => get_string('accuracy', 'local_nexdashboard'),
        'currentstreak' => get_string('currentstreak', 'local_nexdashboard'),
        'learningtime' => get_string('learningtime', 'local_nexdashboard'),
        'courses' => get_string('courses', 'local_nexdashboard'),
        'nocourses' => get_string('nocourses', 'local_nexdashboard'),
        'loading' => get_string('loading', 'local_nexdashboard'),
        'loaderror' => get_string('loaderror', 'local_nexdashboard'),
        'skillmap' => get_string('skillmap', 'local_nexdashboard'),
        'skillmap_empty' => get_string('skillmap_empty', 'local_nexdashboard'),
        'skillmap_hint' => get_string('skillmap_hint', 'local_nexdashboard'),
        'tracks' => get_string('tracks', 'local_nexdashboard'),
        'tracks_empty' => get_string('tracks_empty', 'local_nexdashboard'),
        'tracks_hint' => get_string('tracks_hint', 'local_nexdashboard'),
        'needsattention' => get_string('needsattention', 'local_nexdashboard'),
        'needsattention_empty' => get_string('needsattention_empty', 'local_nexdashboard'),
        'weeklygoal' => get_string('weeklygoal', 'local_nexdashboard'),
        'goaldone' => get_string('goaldone', 'local_nexdashboard'),
        'peers' => get_string('peers', 'local_nexdashboard'),
        'onlineusers' => get_string('onlineusers', 'local_nexdashboard'),
        'onlineperioddefault' => get_string('onlineperioddefault', 'local_nexdashboard'),
        'onlineempty' => get_string('onlineempty', 'local_nexdashboard'),
        'browseusers' => get_string('browseusers', 'local_nexdashboard'),
        'peers_global' => get_string('peers_global', 'local_nexdashboard'),
        'peers_college' => get_string('peers_college', 'local_nexdashboard'),
        'yourrank' => get_string('yourrank', 'local_nexdashboard'),
        'viewleaderboard' => get_string('viewleaderboard', 'local_nexdashboard'),
        'askforhelp' => get_string('askforhelp', 'local_nexdashboard'),
        'retry' => get_string('retry', 'local_nexdashboard'),
        'deadlines' => get_string('deadlines', 'local_nexdashboard'),
        'deadlines_empty' => get_string('deadlines_empty', 'local_nexdashboard'),
        'recentactivity' => get_string('recentactivity', 'local_nexdashboard'),
        'recentactivity_empty' => get_string('recentactivity_empty', 'local_nexdashboard'),
        'activityok' => get_string('activityok', 'local_nexdashboard'),
        'activityfail' => get_string('activityfail', 'local_nexdashboard'),
        'practicelabel' => get_string('practicelabel', 'local_nexdashboard'),
        'practice' => get_string('practice', 'local_nexdashboard'),
        'codelab' => get_string('codelab', 'local_nexdashboard'),
        'monthsummary' => get_string('monthsummary', 'local_nexdashboard'),
        'copysummary' => get_string('copysummary', 'local_nexdashboard'),
        'copied' => get_string('copied', 'local_nexdashboard'),
        'practicesolved' => get_string('practicesolved', 'local_nexdashboard'),
        'practicesolvedlabel' => get_string('practicesolvedlabel', 'local_nexdashboard'),
        'coursecodingsolved' => get_string('coursecodingsolved', 'local_nexdashboard'),
        'coursemcqcorrect' => get_string('coursemcqcorrect', 'local_nexdashboard'),
        'battleswon' => get_string('battleswon', 'local_nexdashboard'),
        'interviewscompleted' => get_string('interviewscompleted', 'local_nexdashboard'),
        'interviewstaken' => get_string('interviewstaken', 'local_nexdashboard'),
        'platformsconnected' => get_string('platformsconnected', 'local_nexdashboard'),
        'githubconnected' => get_string('githubconnected', 'local_nexdashboard'),
        'githubyes' => get_string('githubyes', 'local_nexdashboard'),
        'githubno' => get_string('githubno', 'local_nexdashboard'),
        'missionsdone' => get_string('missionsdone', 'local_nexdashboard'),
        'stepspassed' => get_string('stepspassed', 'local_nexdashboard'),
        'todaysfocus' => get_string('todaysfocus', 'local_nexdashboard'),
        'currentstreak' => get_string('currentstreak', 'local_nexdashboard'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_nexdashboard/dashboard', []);
echo $OUTPUT->footer();
