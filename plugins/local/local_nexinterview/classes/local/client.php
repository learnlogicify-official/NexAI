<?php
namespace local_nexinterview\local;

defined('MOODLE_INTERNAL') || die();

/**
 * HMAC-signed HTTP client for interview-service.
 */
class client {

    /** @var string */
    private $baseurl;
    /** @var string */
    private $secret;

    public function __construct(?string $baseurl = null, ?string $secret = null) {
        $url = $baseurl ?? (string) get_config('local_nexinterview', 'serviceurl');
        $url = trim($url);
        // Admins often paste host only — assume HTTPS.
        if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        // Tolerate accidental /v1 or /v1/health pasted into settings.
        $url = preg_replace('#/(v1(?:/health)?)/?$#i', '', $url) ?? $url;
        $this->baseurl = rtrim($url, '/');
        $this->secret = trim((string) ($secret ?? get_config('local_nexinterview', 'sharedsecret')));
    }

    public function configured(): bool {
        return $this->baseurl !== '' && $this->secret !== ''
            && preg_match('#^https?://#i', $this->baseurl);
    }

    public function baseurl(): string {
        return $this->baseurl;
    }

    public function healthy(): bool {
        try {
            $resp = $this->raw('GET', '/v1/health', null);
            return !empty($resp['ok']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Human-readable connectivity probe for admins.
     *
     * @return array{ok:bool, message:string, http_code:int, body:string}
     */
    public function probe(): array {
        if (!$this->configured()) {
            return [
                'ok' => false,
                'message' => 'Service URL or shared secret is empty / invalid. '
                    . 'Use the full Railway HTTPS URL (e.g. https://your-service.up.railway.app) '
                    . 'and the same SHARED_SECRET as on Railway.',
                'http_code' => 0,
                'body' => '',
            ];
        }
        try {
            $resp = $this->raw('GET', '/v1/health', null);
            return [
                'ok' => !empty($resp['ok']),
                'message' => !empty($resp['ok'])
                    ? 'Connected to ' . $this->baseurl
                    : 'Health endpoint returned unexpected JSON',
                'http_code' => 200,
                'body' => json_encode($resp),
            ];
        } catch (\moodle_exception $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage() . (!empty($e->debuginfo) ? ' — ' . $e->debuginfo : ''),
                'http_code' => 0,
                'body' => (string) $e->debuginfo,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'http_code' => 0,
                'body' => '',
            ];
        }
    }

    public static function sign(array $parts, string $secret, int $timestamp): string {
        $msg = implode('|', array_map('strval', array_merge($parts, [$timestamp])));
        return hash_hmac('sha256', $msg, $secret);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return array
     */
    public function raw(string $method, string $path, ?array $body): array {
        global $CFG;

        if (!$this->configured()) {
            throw new \moodle_exception(
                'noservice',
                'local_nexinterview',
                '',
                'Plugin settings missing. Set Site administration → Plugins → Local plugins → NexInterview.'
            );
        }

        require_once($CFG->libdir . '/filelib.php');

        $url = $this->baseurl . $path;
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 90,
            'CURLOPT_CONNECTTIMEOUT' => 20,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
        ]);

        $method = strtoupper($method);
        if ($method === 'GET') {
            $curl->setHeader(['Accept: application/json']);
            $raw = $curl->get($url);
        } else {
            $payload = json_encode($body ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
            $curl->setHeader([
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen((string) $payload),
            ]);
            $raw = $curl->post($url, $payload);
        }

        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        $errno = method_exists($curl, 'get_errno') ? (int) $curl->get_errno() : 0;
        $error = trim((string) $curl->error);
        $rawstr = is_string($raw) ? $raw : '';

        if ($rawstr === '' || $code === 0) {
            $hint = $error !== '' ? $error : 'empty response';
            if ($errno) {
                $hint .= " (curl {$errno})";
            }
            throw new \moodle_exception(
                'noservice',
                'local_nexinterview',
                '',
                "No response from {$url}. {$hint}. "
                . 'Check Moodle can reach Railway (HTTPS URL, not 127.0.0.1), '
                . 'and that the Railway service is online.'
            );
        }

        $data = json_decode($rawstr, true);
        if (!is_array($data)) {
            $snippet = trim(preg_replace('/\s+/', ' ', substr(strip_tags($rawstr), 0, 180)) ?? '');
            throw new \moodle_exception(
                'noservice',
                'local_nexinterview',
                '',
                "Non-JSON response HTTP {$code} from {$url}. Body: {$snippet}"
            );
        }

        if ($code >= 400) {
            $detail = $data['detail'] ?? $data['message'] ?? 'service_error';
            if (is_array($detail)) {
                $detail = json_encode($detail);
            }
            throw new \moodle_exception(
                'noservice',
                'local_nexinterview',
                '',
                "HTTP {$code} from interview service: {$detail}. "
                . 'If this is Invalid signature, SHARED_SECRET on Railway must match the Moodle plugin secret exactly.'
            );
        }

        return $data;
    }

    public function start(array $payload): array {
        $ts = time();
        $payload['resume_text'] = (string) ($payload['resume_text'] ?? '');
        $payload['moodle_problem_id'] = (int) ($payload['moodle_problem_id'] ?? 0);
        $payload['moodle_problem_title'] = (string) ($payload['moodle_problem_title'] ?? '');
        $payload['interviewer_name'] = 'NexAI';
        $payload['interviewer_style'] = (string) ($payload['interviewer_style'] ?? 'friendly');
        $payload['interviewer_briefing'] = (string) ($payload['interviewer_briefing'] ?? '');
        $payload['include_coding'] = !empty($payload['include_coding']);
        $payload['moodle_interviewer_id'] = (int) ($payload['moodle_interviewer_id'] ?? 0);
        $payload['qa_minutes'] = max(0, (int) ($payload['qa_minutes'] ?? 0));
        $payload['timestamp'] = $ts;
        $payload['signature'] = self::sign([
            'start',
            $payload['moodle_user_id'],
            $payload['moodle_cm_id'],
            $payload['moodle_instance_id'],
            $payload['role_track'],
            $payload['duration_minutes'],
        ], $this->secret, $ts);
        return $this->raw('POST', '/v1/sessions/start', $payload);
    }

    public function message(string $sessionid, string $message, float $durationsec = 0.0): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/message', [
            'session_id' => $sessionid,
            'message' => $message,
            'duration_sec' => (float) $durationsec,
            'timestamp' => $ts,
            'signature' => self::sign(['message', $sessionid], $this->secret, $ts),
        ]);
    }

    public function snapshot(string $sessionid, string $code, string $source = 'autosave'): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/snapshot', [
            'session_id' => $sessionid,
            'code' => $code,
            'source' => $source,
            'timestamp' => $ts,
            'signature' => self::sign(['snapshot', $sessionid], $this->secret, $ts),
        ]);
    }

    public function run(string $sessionid, string $code, string $mode = 'sample'): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/run', [
            'session_id' => $sessionid,
            'code' => $code,
            'mode' => $mode,
            'timestamp' => $ts,
            'signature' => self::sign(['run', $sessionid, $mode], $this->secret, $ts),
        ]);
    }

    public function end(string $sessionid): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/end', [
            'session_id' => $sessionid,
            'timestamp' => $ts,
            'signature' => self::sign(['end', $sessionid], $this->secret, $ts),
        ]);
    }

    public function get(string $sessionid): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/get', [
            'session_id' => $sessionid,
            'timestamp' => $ts,
            'signature' => self::sign(['get', $sessionid], $this->secret, $ts),
        ]);
    }

    /**
     * OpenAI TTS via interview-service. Returns ok/content_type/audio_base64/error.
     */
    public function tts(string $sessionid, string $text): array {
        $ts = time();
        $sid = $sessionid !== '' ? $sessionid : '-';
        // Must match Python body.text[:80] (Unicode code points).
        $preview = \core_text::substr($text, 0, 80);
        return $this->raw('POST', '/v1/tts', [
            'session_id' => $sessionid,
            'text' => $text,
            'timestamp' => $ts,
            'signature' => self::sign(['tts', $sid, $preview], $this->secret, $ts),
        ]);
    }

    /**
     * OpenAI Whisper STT via interview-service.
     */
    public function stt(string $sessionid, string $audiobase64, string $filename = 'audio.webm', string $language = ''): array {
        $ts = time();
        $sid = $sessionid !== '' ? $sessionid : '-';
        $b64 = (string) $audiobase64;
        return $this->raw('POST', '/v1/stt', [
            'session_id' => $sessionid,
            'audio_base64' => $b64,
            'filename' => $filename,
            'language' => $language,
            'timestamp' => $ts,
            'signature' => self::sign(['stt', $sid, strlen($b64)], $this->secret, $ts),
        ]);
    }

    /**
     * Ephemeral OpenAI Realtime client secret for browser WebRTC.
     */
    public function realtime_token(string $sessionid): array {
        $ts = time();
        return $this->raw('POST', '/v1/realtime/token', [
            'session_id' => $sessionid,
            'timestamp' => $ts,
            'signature' => self::sign(['realtime_token', $sessionid], $this->secret, $ts),
        ]);
    }

    /**
     * Gladia Live V2 WebSocket URL (API key stays on interview-service).
     */
    public function gladia_live(string $sessionid, string $language = 'en', int $samplerate = 16000): array {
        $ts = time();
        return $this->raw('POST', '/v1/gladia/live', [
            'session_id' => $sessionid,
            'language' => $language,
            'sample_rate' => $samplerate,
            'timestamp' => $ts,
            'signature' => self::sign(['gladia_live', $sessionid], $this->secret, $ts),
        ]);
    }

    /**
     * Report NexPractice submit result (tests passed) to the interview engine.
     */
    public function coding_result(
        string $sessionid,
        int $passed,
        int $total,
        bool $allpassed,
        int $problemid = 0
    ): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/coding_result', [
            'session_id' => $sessionid,
            'passed' => $passed,
            'total' => $total,
            'all_passed' => $allpassed,
            'problem_id' => $problemid,
            'timestamp' => $ts,
            'signature' => self::sign(
                ['coding_result', $sessionid, $passed, $total],
                $this->secret,
                $ts
            ),
        ]);
    }

    /**
     * Assign the next Moodle/NexPractice problem mid-interview.
     */
    public function assign_problem(string $sessionid, int $problemid, string $title = ''): array {
        $ts = time();
        return $this->raw('POST', '/v1/sessions/assign_problem', [
            'session_id' => $sessionid,
            'problem_id' => $problemid,
            'problem_title' => $title,
            'timestamp' => $ts,
            'signature' => self::sign(
                ['assign_problem', $sessionid, $problemid],
                $this->secret,
                $ts
            ),
        ]);
    }

    public function reports_for_user(int $userid): array {
        $ts = time();
        $sig = self::sign(['reports_user', $userid], $this->secret, $ts);
        $path = '/v1/reports/user/' . $userid . '?timestamp=' . $ts . '&signature=' . urlencode($sig);
        return $this->raw('GET', $path, null);
    }

    public function reports_for_cm(int $cmid): array {
        $ts = time();
        $sig = self::sign(['reports', $cmid], $this->secret, $ts);
        $path = '/v1/reports/cm/' . $cmid . '?timestamp=' . $ts . '&signature=' . urlencode($sig);
        return $this->raw('GET', $path, null);
    }
}
