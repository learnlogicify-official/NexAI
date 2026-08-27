<?php
// This file is part of Moodle - http://moodle.org/
/**
 * ATS-friendly resume template registry.
 *
 * @package    local_nexresume
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexresume\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resume layout templates (single-column, ATS-safe).
 */
class templates {

    /** @var string */
    public const DEFAULT = 'professional';

    /**
     * @return string[]
     */
    public static function ids(): array {
        return ['classic', 'professional', 'student', 'compact', 'modern'];
    }

    /**
     * @param string $id
     * @return bool
     */
    public static function is_valid(string $id): bool {
        return in_array($id, self::ids(), true);
    }

    /**
     * @param string $id
     * @return string
     */
    public static function normalize(string $id): string {
        return self::is_valid($id) ? $id : self::DEFAULT;
    }

    /**
     * @return array<int, array{id: string, name: string, desc: string}>
     */
    public static function list_for_ui(): array {
        $out = [];
        foreach (self::ids() as $id) {
            $out[] = [
                'id' => $id,
                'name' => get_string('template_' . $id, 'local_nexresume'),
                'desc' => get_string('template_' . $id . '_desc', 'local_nexresume'),
            ];
        }
        return $out;
    }

    /**
     * Section heading text for a template.
     *
     * @param string $title
     * @param string $template
     * @return string
     */
    public static function section_heading(string $title, string $template): string {
        if (in_array($template, ['student', 'modern'], true)) {
            return $title;
        }
        return strtoupper($title);
    }

    /**
     * Combined CSS for print and preview.
     *
     * @param string $template
     * @return string
     */
    public static function css(string $template): string {
        $template = self::normalize($template);
        return self::base_css() . self::variant_css($template);
    }

    /**
     * @return string
     */
    private static function base_css(): string {
        return '
            .nr-resume { max-width: 8.5in; margin: 0 auto; color: #111; box-sizing: border-box; }
            .nr-resume *, .nr-resume *::before, .nr-resume *::after { box-sizing: border-box; }
            .nr-resume__head h1 { margin: 0 0 0.12in; line-height: 1.15; }
            .nr-resume__contact { margin: 0; line-height: 1.45; }
            .nr-resume__contact a { text-decoration: none; color: inherit; }
            .nr-resume__contact a:hover { text-decoration: underline; }
            .nr-resume__section { margin-bottom: 0.2in; page-break-inside: avoid; }
            .nr-resume__section h2 { margin: 0 0 0.08in; padding-bottom: 2px; line-height: 1.2; }
            .nr-entry { margin-bottom: 0.12in; }
            .nr-entry__row { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
            .nr-entry__row strong { font-weight: 700; }
            .nr-entry__row strong a { color: inherit; text-decoration: none; }
            .nr-entry__row span { white-space: nowrap; }
            .nr-resume p { margin: 0.04in 0; line-height: 1.35; }
            .nr-resume ul { margin: 0.04in 0 0.08in; padding-left: 0.2in; }
            .nr-resume li { margin-bottom: 0.03in; line-height: 1.32; }
            .nr-plain { list-style: none; padding-left: 0; }
            @media print {
                .nr-resume { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .nr-resume__contact a, .nr-entry__row a { color: #111 !important; }
            }
        ';
    }

    /**
     * @param string $template
     * @return string
     */
    private static function variant_css(string $template): string {
        $map = [
            'classic' => '
                .nr-resume--classic { font-family: "Times New Roman", Times, Georgia, serif; font-size: 11pt; color: #111; padding: 0.6in 0.7in; }
                .nr-resume--classic .nr-resume__head { text-align: center; margin-bottom: 0.32in; }
                .nr-resume--classic .nr-resume__head h1 { font-size: 22pt; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
                .nr-resume--classic .nr-resume__contact { font-size: 10pt; text-align: center; }
                .nr-resume--classic .nr-resume__section h2 { font-size: 12pt; font-family: "Times New Roman", Times, serif; border-bottom: 1px solid #000; letter-spacing: 0.12em; text-align: center; }
                .nr-resume--classic .nr-entry__row strong { font-size: 11pt; }
            ',
            'professional' => '
                .nr-resume--professional { font-family: Arial, Helvetica, sans-serif; font-size: 10.5pt; color: #111; padding: 0.45in 0.55in 0.45in 0.7in; border-left: 10px solid #1e3a8a; }
                .nr-resume--professional .nr-resume__head { text-align: left; margin-bottom: 0.22in; border-bottom: 3px solid #1e3a8a; padding-bottom: 0.12in; }
                .nr-resume--professional .nr-resume__head h1 { font-size: 22pt; font-weight: 800; letter-spacing: -0.02em; color: #1e3a8a; text-transform: none; }
                .nr-resume--professional .nr-resume__contact { font-size: 9.5pt; color: #1f2937; }
                .nr-resume--professional .nr-resume__section h2 { font-size: 10.5pt; font-weight: 800; color: #1e3a8a; border-bottom: 2px solid #1e3a8a; letter-spacing: 0.08em; }
                .nr-resume--professional .nr-entry__row strong { font-size: 10.5pt; }
            ',
            'student' => '
                .nr-resume--student { font-family: Calibri, "Segoe UI", Arial, sans-serif; font-size: 11pt; padding: 0; }
                .nr-resume--student .nr-resume__head { text-align: left; margin: 0 0 0.28in; padding: 0.38in 0.55in; background: #eff6ff; border-bottom: 6px solid #2563eb; }
                .nr-resume--student .nr-resume__head h1 { font-size: 24pt; font-weight: 800; color: #1e40af; }
                .nr-resume--student .nr-resume__contact { font-size: 10.5pt; color: #1e3a8a; }
                .nr-resume--student .nr-resume__section { padding: 0 0.55in; }
                .nr-resume--student .nr-resume__section h2 { font-size: 12pt; font-weight: 800; color: #2563eb; border-bottom: 2px solid #93c5fd; letter-spacing: 0.01em; text-transform: none; }
                .nr-resume--student .nr-entry__row strong { font-size: 11pt; color: #0f172a; }
            ',
            'compact' => '
                .nr-resume--compact { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; padding: 0.32in 0.4in; }
                .nr-resume--compact .nr-resume__head { text-align: left; margin-bottom: 0.12in; }
                .nr-resume--compact .nr-resume__head h1 { font-size: 14pt; font-weight: 800; }
                .nr-resume--compact .nr-resume__contact { font-size: 8.5pt; }
                .nr-resume--compact .nr-resume__section { margin-bottom: 0.1in; }
                .nr-resume--compact .nr-resume__section h2 { font-size: 9pt; font-weight: 800; background: #111; color: #fff; padding: 2px 6px; border: 0; letter-spacing: 0.08em; }
                .nr-resume--compact .nr-entry { margin-bottom: 0.06in; }
                .nr-resume--compact ul { margin-top: 0.01in; padding-left: 0.14in; }
                .nr-resume--compact li { margin-bottom: 0.01in; line-height: 1.2; }
            ',
            'modern' => '
                .nr-resume--modern { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #111827; padding: 0.48in 0.55in; }
                .nr-resume--modern .nr-resume__head { text-align: left; margin-bottom: 0.24in; padding-bottom: 0.14in; border-bottom: 4px solid #0f766e; }
                .nr-resume--modern .nr-resume__head h1 { font-size: 20pt; font-weight: 700; color: #0f766e; letter-spacing: -0.02em; }
                .nr-resume--modern .nr-resume__contact { font-size: 9.5pt; color: #115e59; }
                .nr-resume--modern .nr-resume__section h2 { font-size: 10.5pt; font-weight: 700; color: #fff; background: #0f766e; padding: 0.07in 0.12in; border: 0; letter-spacing: 0.04em; text-transform: none; border-radius: 2px; }
                .nr-resume--modern .nr-entry__row strong { font-size: 10.5pt; }
            ',
        ];
        return $map[$template] ?? $map['professional'];
    }
}
