<?php
// This file is part of Moodle - http://moodle.org/
/**
 * English strings for mod_nexinterview.
 *
 * @package    mod_nexinterview
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'NexInterview';
$string['modulename'] = 'NexInterview';
$string['modulenameplural'] = 'NexInterviews';
$string['modulename_help'] = 'Add a NexInterview default track or custom interviewer to a course as a timed activity. Students upload a resume, check mic/camera, then take the live AI interview; the gradebook gets their overall score.';
$string['pluginadministration'] = 'NexInterview administration';

$string['nexinterview:addinstance'] = 'Add a new NexInterview activity';
$string['nexinterview:view'] = 'View NexInterview activity';
$string['nexinterview:attempt'] = 'Attempt NexInterview';
$string['nexinterview:viewreports'] = 'View NexInterview reports';

$string['moduleintro'] = 'Description';
$string['interviewhdr'] = 'Interview profile & attempts';
$string['timinghdr'] = 'Availability';
$string['profilesource'] = 'Interview profile';
$string['profilesource_help'] = 'Choose a built-in hub track (SDE Intern, Frontend, Backend, AI Engineer, Resume deep-dive) or one of your custom interviewers from NexInterview → Manage interviewers.';
$string['profile_track'] = 'Track: {$a}';
$string['profile_interviewer'] = 'Interviewer: {$a}';
$string['chooseprofile'] = 'Choose a track or interviewer…';
$string['noprofiles'] = 'No interview profiles available. Install/enable local_nexinterview first.';
$string['nocustomhint'] = 'Tip: create custom interviewers under NexInterview → Manage interviewers to show them here alongside the default tracks.';
$string['interviewerunavailable'] = 'That interviewer is missing or disabled.';
$string['localrequired'] = 'The NexInterview hub plugin (local_nexinterview) must be installed first.';
$string['durationminutes'] = 'Interview time (minutes)';
$string['durationminutes_help'] = 'How long this course interview lasts. Overrides the track/interviewer default for this activity only (10–45 minutes).';
$string['durationrange'] = 'Duration must be between 10 and 45 minutes.';
$string['maxattempts'] = 'Maximum attempts per student';
$string['maxattempts_help'] = 'How many times a student may start this interview.';
$string['maxattemptsrange'] = 'Attempts must be between 1 and 20.';
$string['timeopen'] = 'Open the interview from';
$string['timeopen_help'] = 'Students can start the interview only after this date and time. Leave unchecked for no start restriction.';
$string['timeclose'] = 'Close the interview after';
$string['timeclose_help'] = 'Students cannot start or continue after this date and time. Leave unchecked for no end restriction.';
$string['closebeforeopen'] = 'The close time must be after the open time.';
$string['availabilitywindow'] = 'Available: {$a->from} → {$a->until}';
$string['now'] = 'now';
$string['nolimit'] = 'no end limit';
$string['notopenyet'] = 'This interview is not open yet. It opens on {$a}.';
$string['interviewclosed'] = 'This interview closed on {$a}.';

$string['startinterview'] = 'Start interview';
$string['continueinterview'] = 'Continue interview';
$string['viewfeedback'] = 'View feedback';
$string['viewreports'] = 'Teacher reports';
$string['yourattempts'] = 'Your attempts';
$string['attemptslimit'] = 'You have used all allowed attempts for this interview.';
$string['readyblurb'] = 'You will upload or paste your resume, allow microphone (and camera) access, then take a live voice technical screen with NexAI. Coding (if enabled for this profile) unlocks after the spoken round.';
$string['roomtitle'] = 'Interview room';
$string['noservice'] = 'Interview service error: {$a}';
$string['servicenotconfigured'] = 'Configure the interview service URL and shared secret under Site administration → Plugins → NexInterview.';
$string['noprofilebound'] = 'This activity has no interview track or interviewer selected. Ask your teacher to edit the activity.';
$string['nointerviewerbound'] = 'This activity has no interviewer selected. Ask your teacher to edit the activity.';
$string['profilelabel'] = 'Profile';
$string['feedbacktitle'] = 'Your interview feedback';
$string['overall'] = 'Overall';
$string['recommendation'] = 'Recommendation';
$string['status_inprogress'] = 'In progress';
$string['status_completed'] = 'Completed';
$string['status_abandoned'] = 'Abandoned';
$string['reportsempty'] = 'No completed attempts yet.';
$string['student'] = 'Student';
$string['score'] = 'Score';
$string['completedon'] = 'Completed';
$string['interviewerlabel'] = 'Interviewer';
$string['durationlabel'] = 'Time allowed';
$string['minutes'] = '{$a} min';

$string['strengths'] = 'Strengths';
$string['improve'] = 'Areas to improve';
$string['privacy:metadata:attempts'] = 'Stores interview attempts for this course activity.';
$string['privacy:metadata:userid'] = 'The user who took the interview.';
$string['privacy:metadata:sessionid'] = 'Remote interview session id.';
$string['privacy:metadata:overallscore'] = 'Overall interview score.';
$string['privacy:metadata:reportjson'] = 'Feedback report JSON.';
$string['privacy:metadata:service'] = 'Transcript and answers are sent to the interview service for scoring.';
$string['privacy:metadata:answers'] = 'Spoken and typed answers.';
$string['privacy:metadata:code'] = 'Code written during the coding stage.';
