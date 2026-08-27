<?php
namespace local_nexinterview\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resume text helpers (paste + PDF).
 */
class resume {

    /**
     * Extract text from uploaded draft file area or raw bytes.
     */
    public static function from_draft_itemid(int $userid, int $draftitemid): string {
        global $USER;
        if ($draftitemid <= 0) {
            return '';
        }
        $usercontext = \context_user::instance($userid ?: (int) $USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $name = strtolower($file->get_filename());
            $content = $file->get_content();
            if (substr($name, -4) === '.pdf' || $file->get_mimetype() === 'application/pdf') {
                return self::from_pdf_bytes($content);
            }
            if (preg_match('/\.(txt|md|csv)$/', $name) || str_starts_with((string) $file->get_mimetype(), 'text/')) {
                return self::normalize((string) $content);
            }
        }
        return '';
    }

    /**
     * Decode base64 PDF (optionally data-URL) and extract text.
     */
    public static function from_pdf_base64(string $b64): string {
        $b64 = trim($b64);
        if ($b64 === '') {
            return '';
        }
        if (preg_match('#^data:application/pdf;base64,#i', $b64)) {
            $b64 = substr($b64, strlen('data:application/pdf;base64,'));
        }
        $b64 = preg_replace('/\s+/', '', $b64) ?? $b64;
        $bytes = base64_decode($b64, true);
        if (!is_string($bytes) || $bytes === '') {
            return '';
        }
        return self::from_pdf_bytes($bytes);
    }

    /**
     * Pull readable text from PDF content streams (FlateDecode + Tj/TJ).
     * Never scrape raw binary / random parentheses — that produced mojibake questions.
     */
    public static function from_pdf_bytes(string $bytes): string {
        if ($bytes === '') {
            return '';
        }
        $parts = [];
        $offset = 0;
        $len = strlen($bytes);
        $guard = 0;
        while ($guard++ < 400) {
            $pos = strpos($bytes, 'stream', $offset);
            if ($pos === false) {
                break;
            }
            $before = $pos > 0 ? $bytes[$pos - 1] : ' ';
            $afterc = $pos + 6 < $len ? $bytes[$pos + 6] : ' ';
            if (ctype_alnum($before) || ctype_alnum($afterc)) {
                $offset = $pos + 6;
                continue;
            }
            $dict = substr($bytes, max(0, $pos - 500), min(500, $pos));
            $start = $pos + 6;
            if ($start < $len && $bytes[$start] === "\r") {
                $start++;
            }
            if ($start < $len && $bytes[$start] === "\n") {
                $start++;
            }
            $end = strpos($bytes, 'endstream', $start);
            if ($end === false) {
                break;
            }
            $body = substr($bytes, $start, $end - $start);
            $offset = $end + 9;
            if (strlen($body) < 8 || strlen($body) > 4000000) {
                continue;
            }
            $decoded = $body;
            if (preg_match('/\/(FlateDecode|Fl)\b/', $dict)) {
                $try = @gzuncompress($body);
                if ($try === false) {
                    $try = @gzinflate($body);
                }
                if ($try === false && strlen($body) > 2) {
                    $try = @gzuncompress(substr($body, 2));
                }
                if (!is_string($try) || $try === '') {
                    continue;
                }
                $decoded = $try;
            }
            $txt = self::pdf_operators_to_text($decoded);
            if ($txt !== '') {
                $parts[] = $txt;
            }
        }
        $text = self::keep_readable(self::normalize(implode("\n", $parts)));
        if (self::is_readable_resume($text)) {
            return $text;
        }
        $text = self::keep_readable(self::normalize(self::pdf_operators_to_text($bytes)));
        return self::is_readable_resume($text) ? $text : '';
    }

    public static function normalize(string $text): string {
        $text = self::fix_utf8($text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 16000) {
            $text = mb_substr($text, 0, 16000, 'UTF-8');
        } else if (strlen($text) > 16000) {
            $text = substr($text, 0, 16000);
        }
        return $text;
    }

    /**
     * True when the string looks like a human resume, not PDF binary.
     */
    public static function is_readable_resume(string $text): bool {
        $letters = preg_match_all('/[A-Za-z]/', $text);
        // Align with client gate — modern PDFs often include punctuation/symbols.
        return $letters >= 40 && self::letter_ratio($text) >= 0.28;
    }

    /**
     * Force valid UTF-8 so Moodle PARAM_RAW does not throw.
     * Do not call core_text::fix_utf8() — it does not exist on all Moodle versions.
     */
    public static function fix_utf8(string $text): string {
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
            if (!mb_check_encoding($text, 'UTF-8')) {
                $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                if (is_string($converted) && $converted !== '') {
                    $text = $converted;
                }
            }
            $text = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (!is_string($text)) {
                $text = '';
            }
        }

        if (function_exists('iconv')) {
            $iconv = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($iconv)) {
                $text = $iconv;
            }
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $text) ?? '';
        }

        return is_string($text) ? $text : '';
    }

    private static function pdf_decode_literal(string $s): string {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] !== '\\' || $i + 1 >= $len) {
                $out .= $s[$i];
                continue;
            }
            $n = $s[$i + 1];
            if ($n >= '0' && $n <= '7') {
                $oct = $n;
                $j = 2;
                while ($j <= 3 && $i + $j < $len && $s[$i + $j] >= '0' && $s[$i + $j] <= '7') {
                    $oct .= $s[$i + $j];
                    $j++;
                }
                $out .= chr(octdec($oct) & 255);
                $i += strlen($oct);
                continue;
            }
            $map = [
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08",
                'f' => "\x0c", '(' => '(', ')' => ')', '\\' => '\\',
            ];
            $out .= $map[$n] ?? $n;
            $i++;
        }
        if (strlen($out) < 2) {
            return $out;
        }
        $b0 = ord($out[0]);
        $b1 = ord($out[1]);
        if (function_exists('mb_convert_encoding')) {
            if ($b0 === 0xFE && $b1 === 0xFF) {
                $conv = @mb_convert_encoding(substr($out, 2), 'UTF-8', 'UTF-16BE');
                if (is_string($conv) && $conv !== '') {
                    return $conv;
                }
            }
            if ($b0 === 0xFF && $b1 === 0xFE) {
                $conv = @mb_convert_encoding(substr($out, 2), 'UTF-8', 'UTF-16LE');
                if (is_string($conv) && $conv !== '') {
                    return $conv;
                }
            }
            $nulls = substr_count($out, "\x00");
            if ($nulls > strlen($out) * 0.25) {
                $conv = @mb_convert_encoding($out, 'UTF-8', 'UTF-16BE');
                if (is_string($conv) && preg_match('/[A-Za-z]{3,}/', $conv)) {
                    return $conv;
                }
            }
        }
        return $out;
    }

    private static function pdf_hex_to_text(string $hex): string {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if ($hex === '' || (strlen($hex) % 2) === 1) {
            $hex .= '0';
        }
        $bin = @hex2bin($hex);
        if (!is_string($bin) || $bin === '') {
            return '';
        }
        return self::pdf_decode_literal($bin);
    }

    private static function pdf_operators_to_text(string $content): string {
        $lines = [];
        if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/', $content, $m)) {
            foreach ($m[1] as $lit) {
                $t = trim(preg_replace('/\s+/', ' ', self::pdf_decode_literal($lit)) ?? '');
                if (self::is_readable_fragment($t)) {
                    $lines[] = $t;
                }
            }
        }
        // Hex string show-text: <48656C6C6F> Tj
        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/', $content, $m)) {
            foreach ($m[1] as $hex) {
                $t = trim(preg_replace('/\s+/', ' ', self::pdf_hex_to_text($hex)) ?? '');
                if (self::is_readable_fragment($t)) {
                    $lines[] = $t;
                }
            }
        }
        // Single-quote / double-quote text ops used by some exporters.
        if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*[\'"]/', $content, $m)) {
            foreach ($m[1] as $lit) {
                $t = trim(preg_replace('/\s+/', ' ', self::pdf_decode_literal($lit)) ?? '');
                if (self::is_readable_fragment($t)) {
                    $lines[] = $t;
                }
            }
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $m)) {
            foreach ($m[1] as $arr) {
                $parts = [];
                if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $arr, $lits)) {
                    foreach ($lits[1] as $lit) {
                        $parts[] = self::pdf_decode_literal($lit);
                    }
                }
                if (preg_match_all('/<([0-9A-Fa-f\s]+)>/', $arr, $hexes)) {
                    foreach ($hexes[1] as $hex) {
                        $parts[] = self::pdf_hex_to_text($hex);
                    }
                }
                $t = trim(preg_replace('/\s+/', ' ', implode('', $parts)) ?? '');
                if (self::is_readable_fragment($t)) {
                    $lines[] = $t;
                }
            }
        }
        return implode("\n", $lines);
    }

    private static function letter_ratio(string $t): float {
        $len = strlen($t);
        if ($len <= 0) {
            return 0.0;
        }
        return preg_match_all('/[A-Za-z]/', $t) / $len;
    }

    private static function is_readable_fragment(string $t): bool {
        $t = trim($t);
        if (strlen($t) < 2) {
            return false;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $t)) {
            return false;
        }
        $letters = preg_match_all('/[A-Za-z]/', $t);
        if ($letters < 2) {
            return false;
        }
        if (preg_match('/[ÿþßŠ¢ãÞµ«»¤¦§œžÐ]{2,}/u', $t) && $letters < 10) {
            return false;
        }
        return self::letter_ratio($t) >= 0.45 || $letters >= 12;
    }

    private static function keep_readable(string $text): string {
        $kept = [];
        foreach (preg_split("/\n+/", $text) ?: [] as $ln) {
            $ln = trim($ln);
            if ($ln === '') {
                continue;
            }
            if (self::is_readable_fragment($ln) || self::letter_ratio($ln) >= 0.5) {
                $kept[] = $ln;
            }
        }
        return trim(implode("\n", $kept));
    }
}
