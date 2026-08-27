<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Web services for local_nexproctor.
 * @package local_nexproctor
 */
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexproctor_start_session' => [
        'classname' => 'local_nexproctor\\external\\start_session',
        'methodname' => 'execute',
        'description' => 'Start or resume a proctoring session after preflight',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexproctor_log_event' => [
        'classname' => 'local_nexproctor\\external\\log_event',
        'methodname' => 'execute',
        'description' => 'Log a proctoring event',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexproctor_upload_evidence' => [
        'classname' => 'local_nexproctor\\external\\upload_evidence',
        'methodname' => 'execute',
        'description' => 'Upload snapshot/screengrab/audioclip evidence (base64)',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexproctor_end_session' => [
        'classname' => 'local_nexproctor\\external\\end_session',
        'methodname' => 'execute',
        'description' => 'End a proctoring session',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexproctor_complete_preflight' => [
        'classname' => 'local_nexproctor\\external\\complete_preflight',
        'methodname' => 'execute',
        'description' => 'Mark preflight checks complete for a quiz',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_nexproctor_get_session_summary' => [
        'classname' => 'local_nexproctor\\external\\get_session_summary',
        'methodname' => 'execute',
        'description' => 'Proctoring summary for a quiz attempt (review page)',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];