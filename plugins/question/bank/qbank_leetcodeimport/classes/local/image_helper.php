<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Extract and download images from LeetCode problem HTML.
 *
 * @package    qbank_leetcodeimport
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_leetcodeimport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Recreate LeetCode images as Moodle question files (@@PLUGINFILE@@).
 */
class image_helper {

    /**
     * Find, download, and normalize images from LeetCode HTML content.
     *
     * @param string $html
     * @param string $slug Used in filenames
     * @return array<int,array{filename:string,base64:string,mimetype:string,alt:string,width?:int}>
     */
    public static function collect_from_html(string $html, string $slug = 'lc'): array {
        if ($html === '' || stripos($html, '<img') === false) {
            return [];
        }

        $urls = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $src) {
                $src = html_entity_decode(trim($src), ENT_QUOTES, 'UTF-8');
                if ($src === '' || str_starts_with($src, 'data:')) {
                    continue;
                }
                $urls[] = $src;
            }
        }
        $urls = array_values(array_unique($urls));
        if (!$urls) {
            return [];
        }

        $slug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower($slug)) ?: 'lc';
        $out = [];
        $i = 1;
        foreach ($urls as $url) {
            $abs = self::absolutize($url);
            $bin = self::download($abs);
            if ($bin === null || $bin === '') {
                continue;
            }
            $mime = self::detect_mime($bin, $abs);
            $ext = self::ext_for_mime($mime);
            $filename = $slug . '-img' . $i . '.' . $ext;
            $out[] = [
                'filename' => $filename,
                'base64' => base64_encode($bin),
                'mimetype' => $mime,
                'alt' => 'Figure ' . $i,
            ];
            $i++;
            if ($i > 12) {
                break; // Safety cap.
            }
        }
        return $out;
    }

    /**
     * Insert Moodle pluginfile images into question HTML (after Problem Statement header).
     *
     * @param string $html
     * @param array $images
     * @return string
     */
    public static function inject_into_html(string $html, array $images): string {
        if (!$images) {
            return $html;
        }
        $block = '<p><strong>Figures:</strong></p>';
        foreach ($images as $img) {
            $name = htmlspecialchars((string) $img['filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alt = htmlspecialchars((string) ($img['alt'] ?? 'Figure'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $block .= '<p><img src="@@PLUGINFILE@@/' . $name . '" alt="' . $alt
                . '" class="qbank-lc-figure" style="max-width:100%;height:auto;" /></p>';
        }

        if (preg_match('/(<p><strong>Problem Statement:<\/strong><\/p>)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[1][1] + strlen($m[1][0]);
            return substr($html, 0, $pos) . $block . substr($html, $pos);
        }
        return $block . $html;
    }

    /**
     * @param string $url
     * @return string
     */
    private static function absolutize(string $url): string {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return 'https://leetcode.com' . $url;
        }
        return 'https://leetcode.com/' . ltrim($url, '/');
    }

    /**
     * @param string $url
     * @return string|null
     */
    private static function download(string $url): ?string {
        $curl = new \curl();
        $curl->setHeader([
            'User-Agent: Mozilla/5.0 (compatible; Moodle-qbank_leetcodeimport/0.1)',
            'Referer: https://leetcode.com/',
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ]);
        $raw = $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => 45,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_FOLLOWLOCATION' => 1,
        ]);
        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($code < 200 || $code >= 300 || !is_string($raw) || $raw === '') {
            return null;
        }
        // Reject tiny/non-image payloads.
        if (strlen($raw) < 32) {
            return null;
        }
        return $raw;
    }

    /**
     * @param string $bin
     * @param string $url
     * @return string
     */
    private static function detect_mime(string $bin, string $url): string {
        if (class_exists('finfo')) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $f->buffer($bin);
            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                return $mime;
            }
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    /**
     * @param string $mime
     * @return string
     */
    private static function ext_for_mime(string $mime): string {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'png',
        };
    }
}
