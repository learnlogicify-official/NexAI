<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Output hooks for format_nexcourse.
 *
 * Header/stats chrome is only rendered on the Course tab (format content output).
 * Other secondary-nav tabs intentionally do not inject that chrome.
 *
 * @package   format_nexcourse
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [];
