<?php
// This file is part of Moodle - http://moodle.org/
/**
 * AJAX: student dashboard payload.
 *
 * @package    local_nexdashboard
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexdashboard\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;
use local_nexdashboard\local\aggregator;

/**
 * Get dashboard data.
 */
class get_dashboard extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/nexdashboard:view', $context);
        return aggregator::build((int) $USER->id);
    }

    public static function execute_returns(): external_single_structure {
        $card = new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'title'),
            'subtitle' => new external_value(PARAM_TEXT, 'subtitle', VALUE_OPTIONAL),
            'url' => new external_value(PARAM_URL, 'url'),
            'source' => new external_value(PARAM_ALPHANUMEXT, 'source', VALUE_OPTIONAL),
            'progress' => new external_value(PARAM_INT, 'progress', VALUE_OPTIONAL),
            'cta' => new external_value(PARAM_TEXT, 'cta', VALUE_OPTIONAL),
            'badge' => new external_value(PARAM_TEXT, 'badge', VALUE_OPTIONAL),
        ]);
        $course = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'progress' => new external_value(PARAM_INT, 'progress'),
            'url' => new external_value(PARAM_URL, 'url'),
            'source' => new external_value(PARAM_ALPHANUMEXT, 'source'),
            'cta' => new external_value(PARAM_TEXT, 'cta'),
        ]);
        $point = new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'label'),
            'xp' => new external_value(PARAM_INT, 'xp'),
        ]);
        $chartpoint = new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'label'),
            'value' => new external_value(PARAM_INT, 'value'),
        ]);
        $chartbundle = new external_single_structure([
            'series' => new external_multiple_structure($chartpoint),
            'avg' => new external_value(PARAM_FLOAT, 'avg'),
            'trend' => new external_value(PARAM_INT, 'trend'),
            'avgLabel' => new external_value(PARAM_TEXT, 'avg label'),
        ]);
        $periodcharts = new external_single_structure([
            'xp' => $chartbundle,
            'solved' => $chartbundle,
            'time' => $chartbundle,
        ]);
        $day = new external_single_structure([
            'label' => new external_value(PARAM_TEXT, 'label'),
            'active' => new external_value(PARAM_BOOL, 'active'),
            'isToday' => new external_value(PARAM_BOOL, 'isToday'),
        ]);
        $skill = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'attempts' => new external_value(PARAM_INT, 'attempts'),
            'accepted' => new external_value(PARAM_INT, 'accepted'),
            'accuracy' => new external_value(PARAM_INT, 'accuracy'),
            'url' => new external_value(PARAM_URL, 'url'),
        ]);
        $track = new external_single_structure([
            'key' => new external_value(PARAM_ALPHANUMEXT, 'key'),
            'label' => new external_value(PARAM_TEXT, 'label'),
            'done' => new external_value(PARAM_INT, 'done'),
            'total' => new external_value(PARAM_INT, 'total'),
            'pct' => new external_value(PARAM_INT, 'pct'),
            'url' => new external_value(PARAM_URL, 'url'),
        ]);
        $stuck = new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'title'),
            'detail' => new external_value(PARAM_TEXT, 'detail'),
            'url' => new external_value(PARAM_URL, 'url'),
            'source' => new external_value(PARAM_ALPHANUMEXT, 'source'),
            'fails' => new external_value(PARAM_INT, 'fails'),
            'cta' => new external_value(PARAM_TEXT, 'cta', VALUE_OPTIONAL),
            'helpCta' => new external_value(PARAM_TEXT, 'helpCta', VALUE_OPTIONAL),
            'helpUrl' => new external_value(PARAM_URL, 'helpUrl', VALUE_OPTIONAL),
        ]);
        $peer = new external_single_structure([
            'rank' => new external_value(PARAM_INT, 'rank'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'xp' => new external_value(PARAM_INT, 'xp'),
            'isMe' => new external_value(PARAM_BOOL, 'isMe'),
        ]);
        $onlineuser = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'name' => new external_value(PARAM_TEXT, 'name'),
            'picture' => new external_value(PARAM_RAW, 'picture', VALUE_OPTIONAL),
            'haspicture' => new external_value(PARAM_BOOL, 'has picture'),
            'timeago' => new external_value(PARAM_TEXT, 'time ago'),
            'url' => new external_value(PARAM_URL, 'profile url'),
            'isMe' => new external_value(PARAM_BOOL, 'is current user'),
        ]);
        $deadline = new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'title'),
            'when' => new external_value(PARAM_TEXT, 'when'),
            'timestart' => new external_value(PARAM_INT, 'timestart'),
            'url' => new external_value(PARAM_URL, 'url'),
            'type' => new external_value(PARAM_TEXT, 'type'),
        ]);
        $activity = new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'title'),
            'detail' => new external_value(PARAM_TEXT, 'detail'),
            'url' => new external_value(PARAM_URL, 'url'),
            'source' => new external_value(PARAM_ALPHANUMEXT, 'source'),
            'when' => new external_value(PARAM_TEXT, 'when'),
            'ok' => new external_value(PARAM_BOOL, 'ok'),
        ]);

        return new external_single_structure([
            'greeting' => new external_value(PARAM_TEXT, 'greeting'),
            'welcomeback' => new external_value(PARAM_TEXT, 'welcome'),
            'tagline' => new external_value(PARAM_TEXT, 'tagline'),
            'displayname' => new external_value(PARAM_TEXT, 'name'),
            'learningTime' => new external_value(PARAM_TEXT, 'time'),
            'learningTimePending' => new external_value(PARAM_BOOL, 'learning time still loading', VALUE_OPTIONAL),
            'courseCount' => new external_value(PARAM_INT, 'courses'),
            'nextAction' => new external_single_structure([
                'title' => new external_value(PARAM_TEXT, 'title'),
                'detail' => new external_value(PARAM_TEXT, 'detail'),
                'url' => new external_value(PARAM_URL, 'url'),
                'cta' => new external_value(PARAM_TEXT, 'cta'),
            ]),
            'continueCards' => new external_multiple_structure($card),
            'courses' => new external_multiple_structure($course),
            'skills' => new external_multiple_structure($skill),
            'tracks' => new external_multiple_structure($track),
            'stuck' => new external_multiple_structure($stuck),
            'peers' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'enabled'),
                'institution' => new external_value(PARAM_TEXT, 'institution'),
                'rank' => new external_value(PARAM_INT, 'rank'),
                'total' => new external_value(PARAM_INT, 'total'),
                'peers' => new external_multiple_structure($peer),
                'url' => new external_value(PARAM_RAW, 'url'),
            ]),
            'onlineUsers' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'enabled'),
                'count' => new external_value(PARAM_INT, 'count'),
                'period' => new external_value(PARAM_TEXT, 'period label'),
                'users' => new external_multiple_structure($onlineuser),
                'url' => new external_value(PARAM_URL, 'browse users url', VALUE_OPTIONAL),
            ], 'online users for site admins', VALUE_OPTIONAL),
            'goal' => new external_single_structure([
                'label' => new external_value(PARAM_TEXT, 'label'),
                'current' => new external_value(PARAM_INT, 'current'),
                'target' => new external_value(PARAM_INT, 'target'),
                'pct' => new external_value(PARAM_INT, 'pct'),
                'done' => new external_value(PARAM_BOOL, 'done'),
                'choices' => new external_multiple_structure(new external_value(PARAM_INT, 'choice')),
            ]),
            'deadlines' => new external_multiple_structure($deadline),
            'recentActivity' => new external_multiple_structure($activity),
            'monthSummary' => new external_single_structure([
                'label' => new external_value(PARAM_TEXT, 'label'),
                'courseCodingSolved' => new external_value(PARAM_INT, 'course coding solves this month', VALUE_OPTIONAL),
                'courseMcqCorrect' => new external_value(PARAM_INT, 'course MCQ correct this month', VALUE_OPTIONAL),
                'practiceSolved' => new external_value(PARAM_INT, 'practice'),
                'battlesWon' => new external_value(PARAM_INT, 'battles won this month', VALUE_OPTIONAL),
                'interviewsCompleted' => new external_value(PARAM_INT, 'interviews completed this month', VALUE_OPTIONAL),
                'missionsDone' => new external_value(PARAM_INT, 'missions', VALUE_OPTIONAL),
                'stepsPassed' => new external_value(PARAM_INT, 'steps', VALUE_OPTIONAL),
                'xp' => new external_value(PARAM_INT, 'xp'),
                'shareText' => new external_value(PARAM_TEXT, 'share'),
            ]),
            'analytics' => new external_single_structure([
                'totalXp' => new external_value(PARAM_INT, 'xp'),
                'totalSolved' => new external_value(PARAM_INT, 'all-time problems solved', VALUE_OPTIONAL),
                'totalTimeMinutes' => new external_value(PARAM_INT, 'estimated learning minutes', VALUE_OPTIONAL),
                'courseGrades' => new external_value(PARAM_INT, 'sum of course final grades', VALUE_OPTIONAL),
                'practiceXp' => new external_value(PARAM_INT, 'NexPractice XP', VALUE_OPTIONAL),
                'codelabXp' => new external_value(PARAM_INT, 'CodeLab XP', VALUE_OPTIONAL),
                'avgPerWeek' => new external_value(PARAM_INT, 'avg'),
                'trendPct' => new external_value(PARAM_INT, 'trend'),
                'series' => new external_multiple_structure($point),
                'charts' => new external_single_structure([
                    'daily' => $periodcharts,
                    'weekly' => $periodcharts,
                    'monthly' => $periodcharts,
                ], 'period/metric chart bundles', VALUE_OPTIONAL),
            ]),
            'streak' => new external_single_structure([
                'current' => new external_value(PARAM_INT, 'current'),
                'longest' => new external_value(PARAM_INT, 'longest'),
                'days' => new external_multiple_structure($day),
                'hint' => new external_value(PARAM_TEXT, 'hint', VALUE_OPTIONAL),
            ]),
            'player' => new external_single_structure([
                'solved' => new external_value(PARAM_INT, 'solved'),
                'rank' => new external_value(PARAM_INT, 'rank'),
                'accuracy' => new external_value(PARAM_INT, 'accuracy'),
                'streak' => new external_value(PARAM_INT, 'streak'),
                'courseCodingSolved' => new external_value(PARAM_INT, 'course coding', VALUE_OPTIONAL),
                'courseMcqCorrect' => new external_value(PARAM_INT, 'course MCQ', VALUE_OPTIONAL),
                'practiceSolved' => new external_value(PARAM_INT, 'practice', VALUE_OPTIONAL),
                'battlesWon' => new external_value(PARAM_INT, 'battles won', VALUE_OPTIONAL),
                'platformsConnected' => new external_value(PARAM_INT, 'platforms connected', VALUE_OPTIONAL),
                'platformsTotal' => new external_value(PARAM_INT, 'platforms total', VALUE_OPTIONAL),
                'githubConnected' => new external_value(PARAM_BOOL, 'github connected', VALUE_OPTIONAL),
                'interviewsTaken' => new external_value(PARAM_INT, 'interviews taken', VALUE_OPTIONAL),
                'interviewsCompleted' => new external_value(PARAM_INT, 'interviews completed', VALUE_OPTIONAL),
            ]),
            'links' => new external_single_structure([
                'practice' => new external_value(PARAM_URL, 'practice'),
                'codelab' => new external_value(PARAM_URL, 'codelab'),
                'mycourses' => new external_value(PARAM_URL, 'mycourses'),
                'practiceLeaderboard' => new external_value(PARAM_URL, 'practiceLeaderboard', VALUE_OPTIONAL),
                'codelabLeaderboard' => new external_value(PARAM_URL, 'codelabLeaderboard', VALUE_OPTIONAL),
                'overallLeaderboard' => new external_value(PARAM_URL, 'overallLeaderboard', VALUE_OPTIONAL),
                'battleground' => new external_value(PARAM_URL, 'battleground', VALUE_OPTIONAL),
                'portfolio' => new external_value(PARAM_URL, 'portfolio', VALUE_OPTIONAL),
                'interview' => new external_value(PARAM_URL, 'interview', VALUE_OPTIONAL),
                'messages' => new external_value(PARAM_URL, 'messages', VALUE_OPTIONAL),
                'calendar' => new external_value(PARAM_URL, 'calendar', VALUE_OPTIONAL),
            ]),
            'hasPractice' => new external_value(PARAM_BOOL, 'hasPractice'),
            'hasCodeLab' => new external_value(PARAM_BOOL, 'hasCodeLab'),
            'hasBattleGround' => new external_value(PARAM_BOOL, 'hasBattleGround', VALUE_OPTIONAL),
            'hasPortfolio' => new external_value(PARAM_BOOL, 'hasPortfolio', VALUE_OPTIONAL),
            'hasInterview' => new external_value(PARAM_BOOL, 'hasInterview', VALUE_OPTIONAL),
        ]);
    }
}
