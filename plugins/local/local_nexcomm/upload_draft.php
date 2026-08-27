<?php
/**
 * Upload speaking audio into the user's draft file area.
 *
 * @package   local_nexcomm
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/nexcomm:attempt', $context);

header('Content-Type: application/json');

$itemid = optional_param('itemid', 0, PARAM_INT);
if ($itemid < 1) {
    $itemid = file_get_unused_draft_itemid();
}

if (empty($_FILES['speech']) || !is_uploaded_file($_FILES['speech']['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'No file']);
    exit;
}

$filename = clean_param($_FILES['speech']['name'] ?? 'speech.webm', PARAM_FILE);
if ($filename === '') {
    $filename = 'speech.webm';
}

$usercontext = context_user::instance($USER->id);
$fs = get_file_storage();

// Clear previous draft files for this itemid.
$fs->delete_area_files($usercontext->id, 'user', 'draft', $itemid);

$record = [
    'contextid' => $usercontext->id,
    'component' => 'user',
    'filearea' => 'draft',
    'itemid' => $itemid,
    'filepath' => '/',
    'filename' => $filename,
    'userid' => $USER->id,
];

$fs->create_file_from_pathname($record, $_FILES['speech']['tmp_name']);

echo json_encode(['ok' => true, 'itemid' => $itemid, 'filename' => $filename]);
