<?php
namespace local_nexstack\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * HTTP client for the NexStack sandbox server.
 */
class sandbox {

    public static function enabled(): bool {
        return (bool) (int) (get_config('local_nexstack', 'sandbox_enabled') ?: 0);
    }

    public static function base_url(): string {
        return rtrim((string) (get_config('local_nexstack', 'sandbox_url') ?: ''), '/');
    }

    public static function token(): string {
        return (string) (get_config('local_nexstack', 'sandbox_token') ?: '');
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $payload
     * @return array
     */
    public static function request(string $method, string $path, ?array $payload = null): array {
        $base = self::base_url();
        $token = self::token();
        if ($base === '' || $token === '') {
            throw new \moodle_exception('sandboxnotconfigured', 'local_nexstack');
        }

        $url = $base . $path;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        // Admin-configured sandbox URL — skip Moodle SSRF blocklist (localhost / custom ports).
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader($headers);
        $options = [
            'CURLOPT_TIMEOUT' => 300,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_FOLLOWLOCATION' => 1,
        ];

        $raw = '';
        $method = strtoupper($method);
        if ($method === 'GET') {
            $raw = $curl->get($url, [], $options);
        } else if ($method === 'DELETE') {
            $raw = $curl->delete($url, [], $options);
        } else if ($method === 'POST') {
            $raw = $curl->post($url, json_encode($payload ?? new \stdClass()), $options);
        } else {
            throw new \invalid_parameter_exception('Unsupported method');
        }

        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        $rawstr = (string) $raw;
        if ($rawstr !== '' && stripos($rawstr, 'URL is blocked') !== false) {
            throw new \moodle_exception('sandboxblocked', 'local_nexstack', '', $base);
        }
        $decoded = json_decode($rawstr, true);
        if (!is_array($decoded)) {
            $hint = $code ? ($code . ' ') : '';
            $hint .= substr($rawstr !== '' ? $rawstr : 'empty response (check Sandbox URL / network)', 0, 200);
            throw new \moodle_exception('sandboxbadresponse', 'local_nexstack', '', $hint);
        }
        if ($code >= 400) {
            $msg = $decoded['error'] ?? ('HTTP ' . $code);
            throw new \moodle_exception('sandboxerror', 'local_nexstack', '', $msg);
        }
        return $decoded;
    }

    /**
     * @param array $files path => content
     * @return array
     */
    public static function boot_session(int $userid, int $missionid, array $files, array $opts = []): array {
        $body = [
            'userId' => (string) $userid,
            'missionId' => (string) $missionid,
            'files' => $files,
        ];
        // Do not force install/start — Railway process mode picks build+serve + proxy path.
        if (!empty($opts['image'])) {
            $body['image'] = $opts['image'];
        }
        if (!empty($opts['install']) && is_array($opts['install'])) {
            $body['install'] = $opts['install'];
        }
        if (!empty($opts['start']) && is_array($opts['start'])) {
            $body['start'] = $opts['start'];
        }
        if (!empty($opts['previewPort'])) {
            $body['previewPort'] = (int) $opts['previewPort'];
        }
        return self::request('POST', '/v1/sessions', $body);
    }

    public static function sync_files(string $sessionid, array $files): array {
        return self::request('POST', '/v1/sessions/' . rawurlencode($sessionid) . '/files', [
            'files' => $files,
        ]);
    }

    public static function exec(string $sessionid, string $cmd, array $args = []): array {
        return self::request('POST', '/v1/sessions/' . rawurlencode($sessionid) . '/exec', [
            'cmd' => $cmd,
            'args' => array_values($args),
        ]);
    }

    public static function status(string $sessionid): array {
        return self::request('GET', '/v1/sessions/' . rawurlencode($sessionid));
    }

    public static function destroy(string $sessionid): array {
        return self::request('DELETE', '/v1/sessions/' . rawurlencode($sessionid));
    }
}
