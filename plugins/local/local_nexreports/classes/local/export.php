<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Tabular export helpers for NexReports (CSV / Excel / PDF).
 *
 * @package    local_nexreports
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexreports\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Streams tabular report data in the requested download format.
 */
class export {

    /**
     * Download table rows. $columns is field => CAPITAL HEADER label.
     * Only those fields are exported (no extras).
     *
     * @param string $basename Filename without extension
     * @param array<string,string> $columns
     * @param array $rows
     * @param string $format csv|excel|pdf
     */
    public static function download(string $basename, array $columns, array $rows, string $format = 'csv'): void {
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
            $format = 'csv';
        }
        $basename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $basename) ?: 'nexreports';

        $outrows = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[$key] = $row[$key] ?? '';
            }
            $outrows[] = (object) $line;
        }

        if (class_exists('\core\dataformat')) {
            \core\dataformat::download_data($basename, $format, $columns, new \ArrayObject($outrows));
            exit;
        }

        // Fallbacks when core dataformat is unavailable.
        if ($format === 'excel') {
            self::excel_xml($basename . '.xls', $columns, $outrows);
        } else if ($format === 'pdf') {
            self::pdf_html($basename . '.pdf', $columns, $outrows);
        } else {
            self::csv($basename . '.csv', array_values($columns), self::rows_for_csv($columns, $outrows));
        }
    }

    /**
     * @param array<string,string> $columns
     * @param object[] $rows
     * @return array<int,array<string,mixed>>
     */
    private static function rows_for_csv(array $columns, array $rows): array {
        $out = [];
        $headers = array_values($columns);
        $keys = array_keys($columns);
        foreach ($rows as $row) {
            $row = (array) $row;
            $line = [];
            foreach ($keys as $i => $key) {
                $line[$headers[$i]] = $row[$key] ?? '';
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Send a CSV download and exit.
     *
     * @param string $filename
     * @param array $headers
     * @param array $rows Array of numeric/associative rows matching headers order
     */
    public static function csv(string $filename, array $headers, array $rows): void {
        $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename) ?: 'nexreports.csv';
        if (!preg_match('/\.csv$/i', $filename)) {
            $filename .= '.csv';
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // Excel-friendly BOM.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            $line = [];
            if (array_keys($row) !== range(0, count($row) - 1)) {
                foreach ($headers as $key) {
                    $line[] = $row[$key] ?? '';
                }
            } else {
                $line = $row;
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /**
     * SpreadsheetML fallback (.xls) readable by Excel.
     *
     * @param string $filename
     * @param array<string,string> $columns
     * @param object[] $rows
     */
    private static function excel_xml(string $filename, array $columns, array $rows): void {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        echo '<Worksheet ss:Name="Report"><Table>';
        echo '<Row>';
        foreach ($columns as $label) {
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string) $label, ENT_QUOTES | ENT_XML1) . '</Data></Cell>';
        }
        echo '</Row>';
        foreach ($rows as $row) {
            $row = (array) $row;
            echo '<Row>';
            foreach (array_keys($columns) as $key) {
                $val = (string) ($row[$key] ?? '');
                $type = is_numeric($val) && $val !== '' ? 'Number' : 'String';
                echo '<Cell><Data ss:Type="' . $type . '">' .
                    htmlspecialchars($val, ENT_QUOTES | ENT_XML1) . '</Data></Cell>';
            }
            echo '</Row>';
        }
        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    /**
     * Minimal HTML→download PDF fallback (browser print / open as HTML named .pdf).
     *
     * @param string $filename
     * @param array<string,string> $columns
     * @param object[] $rows
     */
    private static function pdf_html(string $filename, array $columns, array $rows): void {
        global $CFG;
        if (file_exists($CFG->libdir . '/pdflib.php')) {
            require_once($CFG->libdir . '/pdflib.php');
            if (class_exists('pdf')) {
                $pdf = new \pdf();
                $pdf->SetTitle($filename);
                $pdf->AddPage('L');
                $html = '<table border="1" cellpadding="4"><thead><tr>';
                foreach ($columns as $label) {
                    $html .= '<th>' . htmlspecialchars((string) $label) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $html .= '<tr>';
                    foreach (array_keys($columns) as $key) {
                        $html .= '<td>' . htmlspecialchars((string) ($row[$key] ?? '')) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->Output($filename, 'D');
                exit;
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/\.pdf$/i', '.html', $filename) . '"');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report</title></head><body>';
        echo '<table border="1" cellpadding="4" cellspacing="0"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th>' . htmlspecialchars((string) $label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $row = (array) $row;
            echo '<tr>';
            foreach (array_keys($columns) as $key) {
                echo '<td>' . htmlspecialchars((string) ($row[$key] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    /**
     * Human-readable duration: "x hours y minutes and z seconds".
     *
     * @param int $seconds
     * @return string
     */
    public static function duration_words(int $seconds): string {
        $seconds = max(0, (int) $seconds);
        $hours = intdiv($seconds, HOURSECS);
        $minutes = intdiv($seconds % HOURSECS, MINSECS);
        $secs = $seconds % MINSECS;
        return $hours . ' hours ' . $minutes . ' minutes and ' . $secs . ' seconds';
    }

    /**
     * HHH:MM:SS duration (hours may exceed 24), matching report UI.
     *
     * @param int $seconds
     * @return string
     */
    public static function duration_hms(int $seconds): string {
        $seconds = max(0, (int) $seconds);
        $h = intdiv($seconds, HOURSECS);
        $m = intdiv($seconds % HOURSECS, MINSECS);
        $s = $seconds % MINSECS;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
