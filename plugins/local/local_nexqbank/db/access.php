<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Capabilities for local_nexqbank.
 *
 * @package    local_nexqbank
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/nexqbank:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            // Intentionally empty — page enforces is_siteadmin().
        ],
    ],
];
