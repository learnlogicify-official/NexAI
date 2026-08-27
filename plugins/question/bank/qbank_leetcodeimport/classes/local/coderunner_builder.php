<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Build Moodle XML for CodeRunner questions.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Serialize converted problems to Moodle XML (CodeRunner).
 */
class coderunner_builder {

    /** CodeRunner precheck: Examples */
    public const PRECHECK_EXAMPLES = 2;

    /**
     * Build a full <quiz> XML document from one or more converted questions.
     *
     * @param array[] $questions
     * @param array $defaults
     * @return string
     */
    public function build_quiz_xml(array $questions, array $defaults): string {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= "<quiz>\n";
        foreach ($questions as $q) {
            $out .= $this->build_question_xml($q, $defaults);
        }
        $out .= "</quiz>\n";
        return $out;
    }

    /**
     * @param array $converted
     * @param array $defaults
     * @return string
     */
    public function build_question_xml(array $converted, array $defaults): string {
        $type = (string) ($defaults['coderunnertype'] ?? 'multilanguage');
        $grade = (float) ($defaults['defaultgrade'] ?? 1);
        $penalty = (string) ($defaults['penaltyregime'] ?? '10, 20, ...');
        $allornothing = 1;
        if (array_key_exists('allornothing', $defaults)) {
            $rawao = $defaults['allornothing'];
            $allornothing = ($rawao === false || $rawao === 0 || $rawao === '0' || $rawao === 'false') ? 0 : 1;
        }
        $lines = (int) ($defaults['answerboxlines'] ?? 12);
        $cols = (int) ($defaults['answerboxcolumns'] ?? 100);
        $validate = !empty($defaults['validateonsave']) ? 1 : 0;
        $precheck = (int) ($defaults['precheck'] ?? self::PRECHECK_EXAMPLES);
        $hidecheck = !empty($defaults['hidecheck']) ? 1 : 0;
        $hiderestdefault = array_key_exists('hiderestiffail', $defaults)
            ? (!empty($defaults['hiderestiffail']) ? 1 : 0)
            : 1;

        $name = $this->xml_text($converted['name'] ?? 'Problem');
        $qtext = $this->cdata((string) ($converted['questiontext_html'] ?? ''));
        $preload = $this->cdata((string) ($converted['answerpreload'] ?? ''));
        $answer = $this->cdata((string) ($converted['answer'] ?? ''));
        $idnumber = $this->esc($this->idnumber($converted));
        $images = is_array($converted['images'] ?? null) ? $converted['images'] : [];

        $xml = "  <question type=\"coderunner\">\n";
        $xml .= "    <name>\n      <text>{$name}</text>\n    </name>\n";
        $xml .= "    <questiontext format=\"html\">\n";
        $xml .= "      <text><![CDATA[{$qtext}]]></text>\n";
        foreach ($images as $img) {
            $fname = $this->esc((string) ($img['filename'] ?? 'image.png'));
            $b64 = preg_replace('/\s+/', '', (string) ($img['base64'] ?? '')) ?? '';
            if ($b64 === '') {
                continue;
            }
            $xml .= "      <file name=\"{$fname}\" path=\"/\" encoding=\"base64\">{$b64}</file>\n";
        }
        $xml .= "    </questiontext>\n";
        $xml .= "    <generalfeedback format=\"html\">\n      <text></text>\n    </generalfeedback>\n";
        $xml .= '    <defaultgrade>' . $this->num($grade) . "</defaultgrade>\n";
        $xml .= "    <penalty>0</penalty>\n";
        $xml .= "    <hidden>0</hidden>\n";
        $xml .= "    <idnumber>{$idnumber}</idnumber>\n";
        $xml .= "    <coderunnertype>" . $this->esc($type) . "</coderunnertype>\n";
        $xml .= "    <prototypetype>0</prototypetype>\n";
        $xml .= "    <allornothing>{$allornothing}</allornothing>\n";
        $xml .= "    <penaltyregime>" . $this->esc($penalty) . "</penaltyregime>\n";
        $xml .= "    <precheck>{$precheck}</precheck>\n";
        $xml .= "    <hidecheck>{$hidecheck}</hidecheck>\n";
        $xml .= "    <showsource>0</showsource>\n";
        $xml .= "    <answerboxlines>{$lines}</answerboxlines>\n";
        $xml .= "    <answerboxcolumns>{$cols}</answerboxcolumns>\n";
        $xml .= "    <answerpreload><![CDATA[{$preload}]]></answerpreload>\n";
        $xml .= "    <globalextra></globalextra>\n";
        $xml .= "    <useace></useace>\n";
        $xml .= "    <resultcolumns></resultcolumns>\n";
        $xml .= "    <template></template>\n";
        $xml .= "    <iscombinatortemplate></iscombinatortemplate>\n";
        $xml .= "    <allowmultiplestdins></allowmultiplestdins>\n";
        $xml .= "    <answer><![CDATA[{$answer}]]></answer>\n";
        $xml .= "    <validateonsave>{$validate}</validateonsave>\n";
        $xml .= "    <testsplitterre></testsplitterre>\n";
        $xml .= "    <language></language>\n";
        $xml .= "    <acelang></acelang>\n";
        $xml .= "    <sandbox></sandbox>\n";
        $xml .= "    <grader></grader>\n";
        $xml .= "    <cputimelimitsecs></cputimelimitsecs>\n";
        $xml .= "    <memlimitmb></memlimitmb>\n";
        $xml .= "    <sandboxparams></sandboxparams>\n";
        $xml .= "    <templateparams></templateparams>\n";
        $xml .= "    <hoisttemplateparams>1</hoisttemplateparams>\n";
        $xml .= "    <extractcodefromjson>1</extractcodefromjson>\n";
        $xml .= "    <templateparamslang>None</templateparamslang>\n";
        $xml .= "    <templateparamsevalpertry>0</templateparamsevalpertry>\n";
        $xml .= "    <templateparamsevald>{}</templateparamsevald>\n";
        $xml .= "    <twigall>0</twigall>\n";
        $xml .= "    <uiplugin></uiplugin>\n";
        $xml .= "    <uiparameters><![CDATA[{\"live_autocompletion\": true}]]></uiparameters>\n";
        $xml .= "    <attachments>0</attachments>\n";
        $xml .= "    <attachmentsrequired>0</attachmentsrequired>\n";
        $xml .= "    <maxfilesize>10240</maxfilesize>\n";
        $xml .= "    <filenamesregex></filenamesregex>\n";
        $xml .= "    <filenamesexplain></filenamesexplain>\n";
        $xml .= "    <displayfeedback>1</displayfeedback>\n";
        $xml .= "    <giveupallowed>0</giveupallowed>\n";
        $xml .= "    <prototypeextra></prototypeextra>\n";
        $xml .= "    <testcases>\n";

        foreach ($converted['testcases'] as $tc) {
            $mark = sprintf('%.7f', (float) ($tc['mark'] ?? 1));
            $useasexample = !empty($tc['useasexample']) ? 1 : 0;
            // Always SHOW (even efficiency cases). Hide-rest-if-fail for non-examples.
            $display = 'SHOW';
            if (array_key_exists('hiderestiffail', $tc)) {
                $hiderest = !empty($tc['hiderestiffail']) ? 1 : 0;
            } else {
                $hiderest = $useasexample ? 0 : $hiderestdefault;
            }
            if (!$useasexample && $hiderestdefault) {
                $hiderest = 1;
            }
            if ($useasexample) {
                $hiderest = 0;
            }
            $xml .= "      <testcase testtype=\"0\" useasexample=\"{$useasexample}\""
                . " hiderestiffail=\"{$hiderest}\" mark=\"{$mark}\" >\n";
            $xml .= "      <testcode>\n                <text>"
                . $this->text_or_cdata((string) ($tc['testcode'] ?? '')) . "</text>\n      </testcode>\n";
            $xml .= "      <stdin>\n                <text>"
                . $this->plain_or_cdata((string) ($tc['stdin'] ?? '')) . "</text>\n      </stdin>\n";
            $xml .= "      <expected>\n                <text>"
                . $this->plain_or_cdata((string) ($tc['expected'] ?? '')) . "</text>\n      </expected>\n";
            $xml .= "      <extra>\n                <text>"
                . $this->plain_or_cdata((string) ($tc['extra'] ?? '')) . "</text>\n      </extra>\n";
            $xml .= "      <display>\n                <text>{$display}</text>\n      </display>\n";
            $xml .= "    </testcase>\n";
        }

        $xml .= "    </testcases>\n";

        $tags = $converted['tags'] ?? [];
        if (is_array($tags) && $tags) {
            $xml .= "    <tags>\n";
            foreach ($tags as $tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $xml .= "      <tag>\n        <text>" . $this->esc($tag) . "</text>\n      </tag>\n";
            }
            $xml .= "    </tags>\n";
        }

        $xml .= "  </question>\n";
        return $xml;
    }

    /**
     * @param array $converted
     * @return string
     */
    private function idnumber(array $converted): string {
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower((string) ($converted['meta']['slug'] ?? 'leetcode')));
        $fid = preg_replace('/\D+/', '', (string) ($converted['meta']['frontend_id'] ?? ''));
        return 'lc' . ($fid !== '' ? $fid : '') . '-' . trim((string) $slug, '-');
    }

    /**
     * @param float $n
     * @return string
     */
    private function num(float $n): string {
        if (abs($n - round($n)) < 0.0000001) {
            return (string) (int) round($n);
        }
        return sprintf('%.7f', $n);
    }

    /**
     * @param string $s
     * @return string
     */
    private function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param string $s
     * @return string
     */
    private function xml_text(string $s): string {
        return $this->esc($s);
    }

    /**
     * @param string $s
     * @return string
     */
    private function cdata(string $s): string {
        return str_replace(']]>', ']]]]><![CDATA[>', $s);
    }

    /**
     * Prefer plain text like sample XML when no special chars; else CDATA.
     *
     * @param string $s
     * @return string
     */
    private function plain_or_cdata(string $s): string {
        if ($s === '') {
            return '';
        }
        if (strpos($s, '<') !== false || strpos($s, '&') !== false || strpos($s, ']]>') !== false) {
            return '<![CDATA[' . $this->cdata($s) . ']]>';
        }
        return $s;
    }

    /**
     * @param string $s
     * @return string
     */
    private function text_or_cdata(string $s): string {
        return $this->plain_or_cdata($s);
    }
}
