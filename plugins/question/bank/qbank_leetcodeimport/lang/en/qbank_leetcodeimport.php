<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Language strings for qbank_leetcodeimport.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'LeetCode → CodeRunner';
$string['privacy:metadata'] = 'The LeetCode import plugin sends problem text to OpenAI to build CodeRunner questions. Problem IDs and generated content are processed transiently; no personal data is stored by this plugin beyond standard Moodle question records.';

$string['navtitle'] = 'LeetCode import';
$string['pageheading'] = 'Import LeetCode problems as CodeRunner';
$string['pageheading_help'] = 'Enter one or more LeetCode problem IDs, slugs, or URLs. The plugin fetches each problem, uses OpenAI to build CodeRunner question text and test cases, then imports Moodle XML into the selected category.';

$string['problemids'] = 'Problem IDs / slugs / URLs';
$string['problemids_help'] = 'One per line. Accepts frontend IDs (e.g. 1), slugs (two-sum), or full LeetCode URLs.';

$string['bulksettings'] = 'Bulk CodeRunner settings';
$string['coderunnertype'] = 'CodeRunner type';
$string['coderunnertype_help'] = 'Choose a prototype installed on this site. Types marked “duplicate” are broken in CodeRunner until extras are deleted from CR_PROTOTYPES.';
$string['language'] = 'Language hint for OpenAI';
$string['usestdin'] = 'Use stdin / stdout tests (no testcode)';
$string['usestdin_help'] = 'Recommended for multilanguage and paper-style problems. Student programs read input from stdin. Always forced on for multilanguage.';
$string['defaultgrade'] = 'Default grade';
$string['penaltyregime'] = 'Penalty regime';
$string['allornothing'] = 'All-or-nothing marking';
$string['precheck'] = 'Precheck';
$string['precheck_help'] = 'CodeRunner Precheck button mode. Use “Examples” so Precheck runs only example test cases.';
$string['precheck_disabled'] = 'Disabled';
$string['precheck_empty'] = 'Empty';
$string['precheck_examples'] = 'Examples';
$string['precheck_selected'] = 'Selected';
$string['precheck_all'] = 'All';
$string['hiderestiffail'] = 'Hide rest if fail (non-example tests)';
$string['hiderestiffail_help'] = 'When enabled, non-example test cases set hiderestiffail=1 (like your exported XML). Example cases keep hiderestiffail=0.';
$string['answerboxlines'] = 'Answer box lines';
$string['answerboxcolumns'] = 'Answer box columns';
$string['validateonsave'] = 'Validate on save';
$string['hidecheck'] = 'Validate on save';
$string['generatehiddentests'] = 'Add hidden efficiency tests (large inputs)';
$string['hiddentestcount'] = 'Extra hidden tests per problem';
$string['includeanswer'] = 'Fill Answer box with full solution';
$string['includeanswer_help'] = 'When enabled (recommended), OpenAI writes a complete working program into the CodeRunner Answer field.';
$string['stoponerror'] = 'Stop on first error';
$string['dryrun'] = 'Dry run (download XML only, do not import)';
$string['openai_model_override'] = 'OpenAI model';
$string['openai_model_override_help'] = 'Use gpt-4o or gpt-4.1 for better testcases and complete solutions. Mini models are faster but often incomplete.';

$string['progress_start'] = 'Importing {$a} problem(s) via live AJAX (one request each). Progress updates below — keep this tab open.';
$string['progress_fetch'] = '[{$a->n}/{$a->total}] Fetching LeetCode: {$a->id}';
$string['progress_openai'] = '[{$a->n}/{$a->total}] OpenAI converting: {$a->title}';
$string['progress_import'] = '[{$a->n}/{$a->total}] Importing into question bank: {$a->name}';
$string['progress_dryrun'] = 'Dry run OK · {$a} tests (not imported)';
$string['progress_failed'] = '[{$a->n}/{$a->total}] Failed: {$a->error}';
$string['progress_ajax_start'] = 'Starting live import…';
$string['progress_ajax_complete'] = 'All requested problems finished.';
$string['progress_images'] = 'Recreating {$a} figure(s) from LeetCode into the question…';
$string['skipped_exists'] = 'Already in this category (idnumber {$a}) — skipped';

$string['noprototypes'] = 'No CodeRunner prototypes found in the database. Showing common defaults — install/repair CodeRunner prototypes first.';
$string['duplicatetprototype'] = 'Duplicate CodeRunner prototype “{$a->type}” ({$a->count} copies, question ids {$a->ids}). CodeRunner requires exactly one prototype per type.';
$string['duplicatetprototype_fix'] = 'Fix: Question bank → category CR_PROTOTYPES (system) → delete the extra BUILT_IN_PROTOTYPE_* copies so each type remains once, then purge caches. Until then, pick a non-duplicate type or use Dry run.';
$string['duplicatetprototype_block'] = 'Cannot import as “{$a}” because that prototype is duplicated on this site. Delete extra CR_PROTOTYPES copies first, or choose another type.';

$string['settingsheading'] = 'OpenAI & defaults';
$string['openai_apikey'] = 'OpenAI API key';
$string['openai_apikey_desc'] = 'Required. Used to convert LeetCode problems into CodeRunner JSON (question text, preload, test cases).';
$string['openai_model'] = 'Default OpenAI model';
$string['openai_model_desc'] = 'Recommended: gpt-4o. Change site-wide default here; each import can override.';
$string['openai_baseurl'] = 'OpenAI API base URL';
$string['openai_baseurl_desc'] = 'Default https://api.openai.com/v1 — change only for compatible proxies.';
$string['default_coderunnertype'] = 'Default CodeRunner type';
$string['default_coderunnertype_desc'] = 'Usually multilanguage or python3 (must be unique in CR_PROTOTYPES).';
$string['default_language'] = 'Default language hint';
$string['leetcode_session'] = 'LeetCode session cookie (optional)';
$string['leetcode_session_desc'] = 'Only needed for paid/locked problems. Paste LEETCODE_SESSION value if required.';
$string['leetcode_csrf'] = 'LeetCode CSRF token (optional)';
$string['leetcode_csrf_desc'] = 'csrftoken cookie value, used with session for authenticated GraphQL.';

$string['missingapikey'] = 'OpenAI API key is not configured. Set it in Site administration → Plugins → Question bank plugins → LeetCode → CodeRunner.';
$string['missingcoderunner'] = 'qtype_coderunner is not installed. Install CodeRunner before using this plugin.';
$string['noproblems'] = 'Enter at least one LeetCode problem ID, slug, or URL.';
$string['fetchfailed'] = 'Failed to fetch LeetCode problem "{$a}"';
$string['openaifailed'] = 'OpenAI conversion failed for "{$a}"';
$string['importfailed'] = 'XML import failed';
$string['importpartial'] = 'Imported with some errors';
$string['summary'] = 'Processed {$a->total}: imported {$a->imported}, skipped {$a->skipped}, failed {$a->failed}';
$string['downloadxml'] = 'Download generated Moodle XML';
$string['resultheading'] = 'Import results';
$string['ok'] = 'OK';
$string['failed'] = 'Failed';
$string['problem'] = 'Problem';
$string['status'] = 'Status';
$string['detail'] = 'Detail';

$string['taskprogress'] = 'Fetching and converting problems…';
$string['coderunnertypes'] = 'python3,java_method,cpp_function,c_function,nodejs';
