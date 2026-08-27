<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Resume HTML export for print / PDF.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexresume\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Render resume as print-ready HTML.
 */
class export {

    /**
     * Embed-safe preview fragment for the builder panel.
     *
     * @param array $doc
     * @return string
     */
    public static function preview(array $doc): string {
        $template = templates::normalize((string) ($doc['template'] ?? ''));
        return '<style>' . templates::css($template) . '</style>'
            . '<div class="nr-resume-preview nr-resume-preview--' . s($template) . '">'
            . '<p class="nr-preview-hint">Click any text in the resume to edit it directly.</p>'
            . self::render($doc, true) . '</div>';
    }

    /**
     * Full HTML document for print / PDF export.
     *
     * @param array $doc
     * @return string
     */
    public static function html(array $doc): string {
        $c = $doc['contact'] ?? [];
        $template = templates::normalize((string) ($doc['template'] ?? ''));
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            . s($c['fullname'] ?? 'Resume') . '</title><style>'
            . 'html, body { margin: 0; padding: 0; }'
            . templates::css($template) . '</style></head><body>'
            . self::render($doc, false)
            . '</body></html>';
    }

    /**
     * @param array $doc
     * @param bool $preview
     * @return string
     */
    private static function render(array $doc, bool $preview = false): string {
        $c = $doc['contact'] ?? [];
        $sections = $doc['sections'] ?? [];
        $template = templates::normalize((string) ($doc['template'] ?? ''));

        $html = '<article class="nr-resume nr-resume--' . s($template) . '">';

        $html .= '<header class="nr-resume__head">';
        $html .= '<h1>' . self::live($c['fullname'] ?? '', 'contact.fullname', $preview) . '</h1>';
        $contactline = self::contact_line($c, $preview);
        if ($contactline !== '') {
            $html .= '<p class="nr-resume__contact">' . $contactline . '</p>';
        }
        $html .= '</header>';

        if (!empty($sections['objective']) && trim((string) ($doc['objective'] ?? '')) !== '') {
            $html .= self::section('Objective', '<p>' . self::live($doc['objective'], 'objective', $preview, true) . '</p>', $template);
        }

        $edulist = document::education_as_list($doc['education'] ?? []);
        if (!empty($sections['education']) && self::education_list_has_content($edulist)) {
            $body = '';
            foreach ($edulist as $i => $e) {
                $body .= self::education_block($e, $i, $preview);
            }
            $html .= self::section('Education', $body, $template);
        }

        if (!empty($sections['projects'])) {
            $all = $doc['projects'] ?? [];
            $shown = 0;
            $body = '';
            foreach ($all as $idx => $p) {
                if (empty($p['included'])) {
                    continue;
                }
                if ($shown >= aggregator::MAX_RESUME_PROJECTS) {
                    break;
                }
                $shown++;
                $name = trim((string) ($p['name'] ?? ''));
                $url = trim((string) ($p['url'] ?? ''));
                $namelive = self::live($name, 'projects.' . $idx . '.name', $preview);
                if ($url !== '' && !$preview) {
                    $url = clean_param($url, PARAM_URL);
                    $title = '<a href="' . s($url) . '" target="_blank" rel="noopener">' . s($name) . '</a>';
                } else {
                    $title = $namelive;
                }

                $body .= '<div class="nr-entry"><div class="nr-entry__row"><strong>' . $title;
                $body .= ' | ' . self::live((string) ($p['stack'] ?? ''), 'projects.' . $idx . '.stack', $preview);
                $body .= ' ' . self::live((string) ($p['date'] ?? ''), 'projects.' . $idx . '.date', $preview);
                $body .= '</strong></div>';

                $bullets = is_array($p['bullets'] ?? null) ? $p['bullets'] : [];
                if ($bullets || $preview) {
                    $body .= '<ul>';
                    foreach ($bullets as $bi => $b) {
                        $b = trim((string) $b);
                        if ($b === '') {
                            continue;
                        }
                        $body .= '<li>' . self::live($b, 'projects.' . $idx . '.bullets.' . $bi, $preview) . '</li>';
                    }
                    $body .= '</ul>';
                }
                $body .= '</div>';
            }
            if ($body !== '') {
                $html .= self::section('Projects', $body, $template);
            }
        }

        if (!empty($sections['skills'])) {
            $sk = $doc['skills'] ?? [];
            $rows = [];
            foreach ([
                'Languages' => 'languages',
                'Frameworks' => 'frameworks',
                'Tools & Cloud' => 'tools',
                'Fundamentals' => 'fundamentals',
            ] as $label => $key) {
                $value = self::sanitize_skill_list((string) ($sk[$key] ?? ''));
                if ($value !== '') {
                    $rows[] = '<p><strong>' . s($label) . ':</strong> '
                        . self::live($value, 'skills.' . $key, $preview) . '</p>';
                }
            }
            if ($rows) {
                $html .= self::section('Technical Skills', implode('', $rows), $template);
            }
        }

        if (!empty($sections['certifications']) && !empty($doc['certifications'])) {
            $body = '<ul class="nr-plain">';
            foreach ($doc['certifications'] as $cert) {
                $name = trim((string) ($cert['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $line = s($name);
                if (!empty($cert['link'])) {
                    $link = clean_param((string) $cert['link'], PARAM_URL);
                    if ($link) {
                        $line .= ' <a href="' . s($link) . '" target="_blank" rel="noopener">Link</a>';
                    }
                }
                if (!empty($cert['year'])) {
                    $line .= ' | ' . s($cert['year']);
                }
                $body .= '<li>' . $line . '</li>';
            }
            $body .= '</ul>';
            $html .= self::section('Certifications & Courses', $body, $template);
        }

        if (!empty($sections['competitive']) && !empty($doc['platforms'])) {
            $body = '<ul>';
            foreach ($doc['platforms'] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $body .= '<li>' . s($line) . '</li>';
                }
            }
            $body .= '</ul>';
            $html .= self::section('Competitive Programming', $body, $template);
        }

        if (!empty($sections['achievements']) && !empty($doc['achievements'])) {
            $body = '<ul>';
            foreach ($doc['achievements'] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $body .= '<li>' . s($line) . '</li>';
                }
            }
            $body .= '</ul>';
            $html .= self::section('Achievements', $body, $template);
        }

        if (!empty($sections['volunteering']) && !empty($doc['volunteering'])) {
            $body = '<ul>';
            foreach ($doc['volunteering'] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $body .= '<li>' . s($line) . '</li>';
                }
            }
            $body .= '</ul>';
            $html .= self::section('Volunteering', $body, $template);
        }

        $html .= '</article>';
        return $html;
    }

    /**
     * @param array $list
     * @return bool
     */
    private static function education_list_has_content(array $list): bool {
        foreach ($list as $e) {
            if (self::education_has_content(is_array($e) ? $e : [])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $e
     * @param int $i
     * @param bool $preview
     * @return string
     */
    private static function education_block(array $e, int $i, bool $preview): string {
        $body = '<div class="nr-entry"><div class="nr-entry__row"><strong>'
            . self::live($e['school'] ?? '', 'education.' . $i . '.school', $preview) . '</strong>';
        $body .= '<span>' . self::live($e['dates'] ?? '', 'education.' . $i . '.dates', $preview) . '</span>';
        $body .= '</div>';
        $deg = trim((string) ($e['degree'] ?? ''));
        $gpa = trim((string) ($e['gpa'] ?? ''));
        $body .= '<p>' . self::live($deg, 'education.' . $i . '.degree', $preview);
        if ($gpa !== '' || $preview) {
            $body .= ($deg !== '' ? '. ' : '') . 'CGPA: ' . self::live($gpa, 'education.' . $i . '.gpa', $preview);
        }
        $body .= '</p>';
        if (!empty($e['coursework']) || $preview) {
            $body .= '<p><em>Coursework:</em> '
                . self::live($e['coursework'] ?? '', 'education.' . $i . '.coursework', $preview, true) . '</p>';
        }
        $body .= '</div>';
        return $body;
    }

    /**
     * @param string $text
     * @param string $path
     * @param bool $preview
     * @param bool $multiline
     * @return string
     */
    private static function live(string $text, string $path, bool $preview, bool $multiline = false): string {
        $safe = s($text);
        if (!$preview) {
            return $safe;
        }
        $tag = $multiline ? 'div' : 'span';
        return '<' . $tag . ' class="nr-live" contenteditable="true" data-edit="' . s($path) . '">'
            . $safe . '</' . $tag . '>';
    }

    /**
     * @param array $education
     * @return bool
     */
    private static function education_has_content(array $education): bool {
        foreach (['school', 'degree', 'dates', 'gpa', 'coursework'] as $key) {
            if (trim((string) ($education[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $c
     * @param bool $preview
     * @return string
     */
    private static function contact_line(array $c, bool $preview = false): string {
        $parts = [];

        if ($loc = trim((string) ($c['location'] ?? '')) || $preview) {
            $loc = trim((string) ($c['location'] ?? ''));
            $parts[] = self::live($loc, 'contact.location', $preview);
        }
        $email = trim((string) ($c['email'] ?? ''));
        if ($email !== '') {
            if (!$preview && clean_param($email, PARAM_EMAIL)) {
                $parts[] = '<a href="mailto:' . s($email) . '">' . s($email) . '</a>';
            } else {
                $parts[] = self::live($email, 'contact.email', $preview);
            }
        }
        $phone = trim((string) ($c['phone'] ?? ''));
        if ($phone !== '' || $preview) {
            $parts[] = self::live($phone, 'contact.phone', $preview);
        }
        if ($linkedin = trim((string) ($c['linkedin'] ?? ''))) {
            $parts[] = $preview
                ? self::live($linkedin, 'contact.linkedin', true)
                : self::external_link($linkedin, self::link_label($linkedin));
        }
        if ($github = trim((string) ($c['github'] ?? ''))) {
            $parts[] = $preview
                ? self::live($github, 'contact.github', true)
                : self::external_link(self::github_url($github), self::github_label($github));
        }
        if ($portfolio = trim((string) ($c['portfolio'] ?? ''))) {
            $parts[] = $preview
                ? self::live($portfolio, 'contact.portfolio', true)
                : self::external_link($portfolio, self::link_label($portfolio));
        }

        return implode(' | ', array_filter($parts, static function ($p) {
            return trim(strip_tags($p)) !== '' || str_contains($p, 'data-edit');
        }));
    }

    /**
     * @param string $raw
     * @return string
     */
    private static function github_url(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            return clean_param($raw, PARAM_URL) ?: '';
        }
        $handle = ltrim($raw, '@/');
        return 'https://github.com/' . $handle;
    }

    /**
     * @param string $raw
     * @return string
     */
    private static function github_label(string $raw): string {
        $raw = trim($raw);
        if (preg_match('#github\.com/([^/\s]+)#i', $raw, $m)) {
            return $m[1];
        }
        return ltrim($raw, '@/');
    }

    /**
     * @param string $raw
     * @return string
     */
    private static function link_label(string $raw): string {
        $raw = trim($raw);
        if (preg_match('#^https?://#i', $raw)) {
            return preg_replace('#^https?://(www\.)?#i', '', $raw) ?? $raw;
        }
        return $raw;
    }

    /**
     * @param string $url
     * @param string $label
     * @return string
     */
    private static function external_link(string $url, string $label): string {
        $href = $url;
        if (!preg_match('#^https?://#i', $href)) {
            $href = 'https://' . ltrim($href, '/');
        }
        $href = clean_param($href, PARAM_URL);
        if (!$href) {
            return s($label);
        }
        return '<a href="' . s($href) . '" target="_blank" rel="noopener">' . s($label) . '</a>';
    }

    /**
     * Strip numeric-only tokens from comma lists (legacy bad imports).
     *
     * @param string $text
     * @return string
     */
    private static function sanitize_skill_list(string $text): string {
        $items = array_map('trim', explode(',', $text));
        $items = array_values(array_filter($items, static function ($item) {
            return $item !== '' && !preg_match('/^\d+$/', $item);
        }));
        return implode(', ', $items);
    }

    /**
     * @param string $title
     * @param string $body
     * @param string $template
     * @return string
     */
    private static function section(string $title, string $body, string $template): string {
        $heading = templates::section_heading($title, $template);
        return '<section class="nr-resume__section"><h2>' . s($heading) . '</h2>' . $body . '</section>';
    }
}
