<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Download last generated Moodle XML from session.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

global $SESSION;

$xml = $SESSION->qbank_leetcodeimport_xml ?? '';
unset($SESSION->qbank_leetcodeimport_xml);

if ($xml === '') {
    throw new moodle_exception('noproblems', 'qbank_leetcodeimport');
}

$tmpdir = make_request_directory();
$path = $tmpdir . '/leetcode-coderunner.xml';
file_put_contents($path, $xml);
send_file($path, 'leetcode-coderunner.xml', 0, 0, false, true, 'application/xml');
