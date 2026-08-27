<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * HTTP helpers for platform fetchers.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared curl helper.
 */
class http {

    /**
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @return array{code:int, body:string, error?:string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 20): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $hasua = false;
        foreach ($headers as $h) {
            if (stripos((string) $h, 'User-Agent:') === 0) {
                $hasua = true;
                break;
            }
        }
        if (!$hasua) {
            $headers[] = 'User-Agent: ' . $ua;
        }

        // 1) Moodle curl — do NOT set CURLOPT_FOLLOWLOCATION (can return empty with open_basedir;
        // Moodle emulates redirects itself).
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader($headers);
        $opts = [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(15, $timeout),
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_RETURNTRANSFER' => 1,
        ];
        $body = $curl->get($url, [], $opts);
        $info = $curl->get_info();
        $code = isset($info['http_code']) ? (int) $info['http_code'] : 0;
        $err = is_string($curl->error ?? null) ? (string) $curl->error : '';

        if (is_string($body) && $body !== '' && self::looks_usable($body, $code)) {
            return ['code' => $code ?: 200, 'body' => $body];
        }

        // 2) Retry Moodle curl with SSL verify disabled (common on misconfigured hosts).
        $curl2 = new \curl(['ignoresecurity' => true]);
        $curl2->setHeader($headers);
        $opts['CURLOPT_SSL_VERIFYPEER'] = 0;
        $opts['CURLOPT_SSL_VERIFYHOST'] = 0;
        $body2 = $curl2->get($url, [], $opts);
        $info2 = $curl2->get_info();
        $code2 = isset($info2['http_code']) ? (int) $info2['http_code'] : 0;
        if (is_string($body2) && $body2 !== '' && self::looks_usable($body2, $code2)) {
            return ['code' => $code2 ?: 200, 'body' => $body2];
        }

        // 3) Native PHP curl fallback (bypasses Moodle redirect quirks).
        $native = self::native_get($url, $headers, $timeout);
        if ($native['body'] !== '' && self::looks_usable($native['body'], $native['code'])) {
            return $native;
        }

        return [
            'code' => $native['code'] ?: $code2 ?: $code,
            'body' => '',
            'error' => $native['error'] ?: $err ?: 'Empty response from host',
        ];
    }

    /**
     * @param string $body
     * @param int $code
     * @return bool
     */
    private static function looks_usable(string $body, int $code): bool {
        if ($body === '') {
            return false;
        }
        // Accept JSON/HTML even when http_code is missing (0) after redirect emulation bugs.
        if ($code === 0 || ($code >= 200 && $code < 400)) {
            $trim = ltrim($body);
            if ($trim[0] === '{' || $trim[0] === '[') {
                return true;
            }
            if (stripos($body, '<html') !== false || stripos($body, '<!DOCTYPE') !== false) {
                return strlen($body) > 200;
            }
            return strlen($body) > 20;
        }
        return false;
    }

    /**
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @return array{code:int, body:string, error?:string}
     */
    private static function native_get(string $url, array $headers, int $timeout): array {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'body' => '', 'error' => 'PHP curl extension missing'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'code' => $code,
            'body' => is_string($body) ? $body : '',
            'error' => $error ?: '',
        ];
    }

    /**
     * @param string $url
     * @param array $headers
     * @param int $timeout
     * @return array|null
     */
    public static function get_json(string $url, array $headers = [], int $timeout = 20): ?array {
        $res = self::get($url, $headers, $timeout);
        if ($res['body'] === '') {
            return null;
        }
        // Tolerate non-2xx if body is JSON (some CDNs oddities).
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $url
     * @param array $payload
     * @param array $headers
     * @param int $timeout
     * @return array|null
     */
    public static function post_json(string $url, array $payload, array $headers = [], int $timeout = 20): ?array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $headers = array_merge([
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $headers);

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader($headers);
        $raw = $curl->post($url, $body, [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(15, $timeout),
        ]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * POST JSON body (GitHub GraphQL, etc.).
     *
     * @param string $url
     * @param array $payload
     * @param array $headers
     * @param int $timeout
     * @return array|null
     */
    public static function post_body_json(string $url, array $payload, array $headers = [], int $timeout = 25): ?array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $body = json_encode($payload);
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader($headers);
        $raw = $curl->post($url, $body, [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(15, $timeout),
        ]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
