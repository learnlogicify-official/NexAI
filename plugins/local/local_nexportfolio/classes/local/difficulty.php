<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Normalize platform difficulty buckets into Easy / Medium / Hard.
 *
 * @package    local_nexportfolio
 * @copyright  2026 Nex Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexportfolio\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Difficulty helpers.
 */
class difficulty {

    /**
     * Fold arbitrary platform labels into easy / medium / hard.
     *
     * Examples:
     * - GFG: school, basic → easy
     * - Coding Ninjas: moderate → medium, ninja → hard
     * - CodeChef: school, beginner → easy; challenge → hard
     * - Codeforces: numeric rating strings bucketed separately via from_cf_rating()
     *
     * @param array $raw Label => count (keys lowercased preferred)
     * @return array{easy:int, medium:int, hard:int}
     */
    public static function to_emh(array $raw): array {
        $out = ['easy' => 0, 'medium' => 0, 'hard' => 0];
        foreach ($raw as $label => $count) {
            $n = (int) $count;
            if ($n <= 0) {
                continue;
            }
            $key = strtolower(trim((string) $label));
            $bucket = self::label_bucket($key);
            if ($bucket !== null) {
                $out[$bucket] += $n;
            }
        }
        return $out;
    }

    /**
     * Map one label to easy|medium|hard|null.
     *
     * @param string $key Lowercased label
     * @return string|null
     */
    public static function label_bucket(string $key): ?string {
        if ($key === '' || $key === 'all' || $key === 'total' || $key === 'others' || $key === 'other') {
            return null;
        }
        // Numeric Codeforces-style ratings.
        if (ctype_digit($key) || preg_match('/^\d+(\.\d+)?$/', $key)) {
            return self::cf_rating_bucket((float) $key);
        }

        $easy = [
            'easy', 'school', 'basic', 'beginner', 'fundamental', 'fundamentals',
            'a', 'a+', 'div 4', 'div4', 'starter',
        ];
        $medium = [
            'medium', 'moderate', 'intermediate', 'b', 'b+', 'div 3', 'div3',
        ];
        $hard = [
            'hard', 'ninja', 'challenge', 'expert', 'advanced', 'c', 'c+', 'd', 'd+',
            'div 2', 'div2', 'div 1', 'div1',
        ];

        if (in_array($key, $easy, true) || str_contains($key, 'easy') || str_contains($key, 'school')
            || str_contains($key, 'basic') || str_contains($key, 'beginner')) {
            return 'easy';
        }
        if (in_array($key, $medium, true) || str_contains($key, 'medium') || str_contains($key, 'moderate')
            || str_contains($key, 'intermediate')) {
            return 'medium';
        }
        if (in_array($key, $hard, true) || str_contains($key, 'hard') || str_contains($key, 'ninja')
            || str_contains($key, 'challenge') || str_contains($key, 'expert') || str_contains($key, 'advanced')) {
            return 'hard';
        }
        return null;
    }

    /**
     * Codeforces problem rating → EMH.
     * ≤1200 easy, 1201–1600 medium, ≥1601 hard.
     *
     * @param float $rating
     * @return string
     */
    public static function cf_rating_bucket(float $rating): string {
        if ($rating <= 1200) {
            return 'easy';
        }
        if ($rating <= 1600) {
            return 'medium';
        }
        return 'hard';
    }

    /**
     * CodeChef practice difficulty rating → EMH (when only numeric bands exist).
     * ≤1000 easy, 1001–1800 medium, ≥1801 hard.
     *
     * @param float $rating
     * @return string
     */
    public static function codechef_rating_bucket(float $rating): string {
        if ($rating <= 1000) {
            return 'easy';
        }
        if ($rating <= 1800) {
            return 'medium';
        }
        return 'hard';
    }
}
