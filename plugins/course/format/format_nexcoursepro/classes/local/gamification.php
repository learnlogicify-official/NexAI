<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Level thresholds and avatar helpers for the course leaderboard.
 *
 * Level logic mirrors block_game (score thresholds → level).
 * Avatars reuse the block_game a1–a68 artwork shipped under pix/.
 *
 * @package   format_nexcoursepro
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_nexcoursepro\local;

defined('MOODLE_INTERNAL') || die();

use moodle_url;

/**
 * Levels + avatars.
 */
class gamification {

    /** Free avatars (no level required). */
    public const AVATAR_FREE_MAX = 8;

    /** Highest avatar index shipped in pix/. */
    public const AVATAR_MAX = 68;

    /**
     * Default score thresholds (same spirit as block_game defaults, tuned for grades).
     *
     * @return int[]
     */
    public static function default_thresholds(): array {
        return [
            1 => 40, 2 => 55, 3 => 70, 4 => 80, 5 => 90, 6 => 100,
            7 => 120, 8 => 140, 9 => 160, 10 => 180, 11 => 200, 12 => 220,
            13 => 240, 14 => 260, 15 => 300,
        ];
    }

    /**
     * Configured level thresholds (1-indexed score required for that level).
     *
     * @return array{number:int,thresholds:int[],enabled:bool}
     */
    public static function level_config(): array {
        $enabled = (bool) get_config('format_nexcoursepro', 'show_level');
        $number = (int) get_config('format_nexcoursepro', 'level_number');
        if ($number < 1) {
            $number = 6;
        }
        $number = min(15, $number);
        $defaults = self::default_thresholds();
        $thresholds = [];
        for ($i = 1; $i <= $number; $i++) {
            $raw = get_config('format_nexcoursepro', 'level_up' . $i);
            // Fall back to defaults when a setting was never saved (false) or cleared.
            if ($raw === false || $raw === null || $raw === '') {
                $thresholds[$i] = (int) ($defaults[$i] ?? 0);
            } else {
                $thresholds[$i] = max(0, (int) $raw);
            }
        }
        return [
            'enabled' => $enabled,
            'number' => $number,
            'thresholds' => $thresholds,
        ];
    }

    /**
     * Resolve level from a numeric score (course total grade), matching block_game.
     *
     * Level = count of configured thresholds the score has reached (capped at level_number).
     * Progress % toward the next level = score / next_threshold * 100 (block_game_get_percente_level).
     *
     * @param float|null $score Course grade points (same value shown as Score).
     * @return array{level:int,percent:float,nextscore:int|null,nextlevel:string,max:bool,enabled:bool}
     */
    public static function level_from_score(?float $score): array {
        $cfg = self::level_config();
        if (!$cfg['enabled']) {
            return [
                'level' => 0,
                'percent' => 0.0,
                'nextscore' => null,
                'nextlevel' => '',
                'max' => false,
                'enabled' => false,
            ];
        }

        // Match block_game_sets_level: compare against each configured mark in order.
        $score = $score === null ? 0.0 : (float) $score;
        $levelup = [];
        for ($i = 1; $i <= $cfg['number']; $i++) {
            $levelup[] = (int) $cfg['thresholds'][$i];
        }
        $level = 0;
        foreach ($levelup as $threshold) {
            if ($score >= $threshold) {
                $level++;
            }
        }
        if ($level >= $cfg['number']) {
            $level = $cfg['number'];
        }
        $max = $level >= $cfg['number'];

        if ($max) {
            return [
                'level' => $level,
                'percent' => 100.0,
                'nextscore' => null,
                'nextlevel' => get_string('leaderboardlevelmax', 'format_nexcoursepro'),
                'max' => true,
                'enabled' => true,
            ];
        }

        // Next mark is level_up{level+1}; $levelup is 0-indexed → index $level.
        $need = (int) ($levelup[$level] ?? 0);
        $percent = 0.0;
        if ($need <= 0) {
            $percent = 100.0;
        } else if ($score > 0) {
            // Same formula as block_game_get_percente_level().
            $percent = $score >= $need
                ? 100.0
                : round(($score * 100) / $need, 1);
        }

        return [
            'level' => $level,
            'percent' => (float) $percent,
            'nextscore' => $need,
            'nextlevel' => get_string('leaderboardnextlevel', 'format_nexcoursepro', $need),
            'max' => false,
            'enabled' => true,
        ];
    }

    /**
     * Minimum level required to unlock an avatar (block_game banding).
     *
     * @param int $avatar
     * @return int
     */
    public static function avatar_required_level(int $avatar): int {
        if ($avatar <= self::AVATAR_FREE_MAX) {
            return 0;
        }
        // Avatars 9-12 → L1, 13-16 → L2, ... (4 per level).
        return (int) ceil(($avatar - self::AVATAR_FREE_MAX) / 4);
    }

    /**
     * @param int $userid
     * @param int $level
     * @return int
     */
    public static function avatar_for_user(int $userid, int $level = 0): int {
        $saved = (int) get_user_preferences('format_nexcoursepro_avatar', 0, $userid);
        if ($saved >= 1 && $saved <= self::AVATAR_MAX
                && self::avatar_required_level($saved) <= $level) {
            return $saved;
        }
        return (($userid - 1) % self::AVATAR_FREE_MAX) + 1;
    }

    /**
     * @param int $avatar
     * @return string
     */
    public static function avatar_url(int $avatar): string {
        $avatar = max(1, min(self::AVATAR_MAX, $avatar));
        return (new moodle_url('/course/format/nexcoursepro/pix/a' . $avatar . '.svg'))->out(false);
    }

    /**
     * @param int $level
     * @return string
     */
    public static function level_icon_url(int $level): string {
        $level = max(0, min(15, $level));
        return (new moodle_url('/course/format/nexcoursepro/pix/lv' . $level . '.svg'))->out(false);
    }

    /**
     * Avatar picker payload for the current user.
     *
     * @param int $userid
     * @param int $level
     * @param int $selected
     * @return array
     */
    public static function avatar_choices(int $userid, int $level, int $selected): array {
        $out = [];
        for ($i = 1; $i <= self::AVATAR_MAX; $i++) {
            $need = self::avatar_required_level($i);
            $unlocked = $need <= $level;
            $out[] = [
                'id' => $i,
                'url' => self::avatar_url($i),
                'unlocked' => $unlocked,
                'requiredlevel' => $need,
                'selected' => $i === $selected,
            ];
        }
        return $out;
    }

    /**
     * Persist avatar choice when unlocked for the user's level.
     *
     * @param int $userid
     * @param int $avatar
     * @param float|null $score
     * @return array{ok:bool,avatar:int,url:string,message:string}
     */
    public static function set_avatar(int $userid, int $avatar, ?float $score): array {
        $avatar = (int) $avatar;
        if ($avatar < 1 || $avatar > self::AVATAR_MAX) {
            return [
                'ok' => false,
                'avatar' => 0,
                'url' => '',
                'message' => get_string('leaderboardavatarbad', 'format_nexcoursepro'),
            ];
        }
        $levelinfo = self::level_from_score($score);
        $level = (int) $levelinfo['level'];
        if (self::avatar_required_level($avatar) > $level) {
            return [
                'ok' => false,
                'avatar' => self::avatar_for_user($userid, $level),
                'url' => self::avatar_url(self::avatar_for_user($userid, $level)),
                'message' => get_string('leaderboardavatarlocked', 'format_nexcoursepro', self::avatar_required_level($avatar)),
            ];
        }
        set_user_preference('format_nexcoursepro_avatar', $avatar, $userid);
        return [
            'ok' => true,
            'avatar' => $avatar,
            'url' => self::avatar_url($avatar),
            'message' => '',
        ];
    }
}
