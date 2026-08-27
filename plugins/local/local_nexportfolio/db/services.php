<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Web service definitions for local_nexportfolio.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nexportfolio_save_handles' => [
        'classname' => 'local_nexportfolio\external\save_handles',
        'methodname' => 'execute',
        'description' => 'Save coding platform usernames for the current user',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexportfolio:manageown',
    ],
    'local_nexportfolio_refresh_platform' => [
        'classname' => 'local_nexportfolio\external\refresh_platform',
        'methodname' => 'execute',
        'description' => 'Fetch and cache stats for one platform',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexportfolio:manageown',
    ],
    'local_nexportfolio_get_portfolio' => [
        'classname' => 'local_nexportfolio\external\get_portfolio',
        'methodname' => 'execute',
        'description' => 'Get connected platforms and cached stats',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexportfolio:view',
    ],
    'local_nexportfolio_github_status' => [
        'classname' => 'local_nexportfolio\external\github_status',
        'methodname' => 'execute',
        'description' => 'GitHub connection status',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexportfolio:view',
    ],
    'local_nexportfolio_import_github' => [
        'classname' => 'local_nexportfolio\external\import_github',
        'methodname' => 'execute',
        'description' => 'Import GitHub repositories as portfolio projects',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexportfolio:manageown',
    ],
    'local_nexportfolio_disconnect_github' => [
        'classname' => 'local_nexportfolio\external\disconnect_github',
        'methodname' => 'execute',
        'description' => 'Disconnect GitHub and remove imported projects',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/nexportfolio:manageown',
    ],
];
