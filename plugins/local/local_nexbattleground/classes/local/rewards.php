<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Battle XP rewards.
 *
 * @package   local_nexbattleground
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexbattleground\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Win rewards for battles.
 */
class rewards {

    /**
     * XP for a battle win by difficulty.
     *
     * @param string $difficulty
     * @return int
     */
    public static function xp_for_difficulty(string $difficulty): int {
        $difficulty = strtolower(trim($difficulty));
        $map = [
            'easy' => 25,
            'medium' => 50,
            'hard' => 75,
            'veryhard' => 75,
        ];
        return $map[$difficulty] ?? 25;
    }

    /**
     * Resolve difficulty from battle, falling back to the problem.
     *
     * @param \stdClass $battle
     * @return string
     */
    public static function battle_difficulty(\stdClass $battle): string {
        global $DB;
        $diff = strtolower(trim((string) ($battle->difficulty ?? '')));
        if (in_array($diff, ['easy', 'medium', 'hard', 'veryhard'], true)) {
            return $diff;
        }
        $pid = (int) ($battle->problemid ?? 0);
        if ($pid > 0 && $DB->get_manager()->table_exists('local_learnlogic_problem')) {
            $pd = strtolower((string) $DB->get_field('local_learnlogic_problem', 'difficulty', ['id' => $pid]));
            if (in_array($pd, ['easy', 'medium', 'hard', 'veryhard'], true)) {
                return $pd;
            }
        }
        return 'easy';
    }

    /**
     * Award win XP once per battle (credited to NexPractice XP total).
     *
     * @param \stdClass $battle
     * @param int $winnerid
     * @return int XP awarded (0 if already granted / tie / invalid)
     */
    public static function award_win(\stdClass $battle, int $winnerid): int {
        global $DB;

        if ($winnerid < 1) {
            return 0;
        }
        $battleid = (int) $battle->id;
        $reason = 'battle_win_' . $battleid;

        if ($DB->get_manager()->table_exists('local_learnlogic_xpevent')) {
            if ($DB->record_exists('local_learnlogic_xpevent', [
                'userid' => $winnerid,
                'reason' => $reason,
            ])) {
                return 0;
            }
        }

        $amount = self::xp_for_difficulty(self::battle_difficulty($battle));
        if ($amount < 1) {
            return 0;
        }

        if (class_exists('\\local_learnlogic\\local\\gamification')) {
            \local_learnlogic\local\gamification::add_xp(
                $winnerid,
                $amount,
                (int) ($battle->problemid ?? 0),
                $reason
            );
            return $amount;
        }

        // Fallback if Practice tables exist without the class bootstrap.
        if ($DB->get_manager()->table_exists('local_learnlogic_userxp')) {
            $now = time();
            $rec = $DB->get_record('local_learnlogic_userxp', ['userid' => $winnerid]);
            if ($rec) {
                $rec->xp = (int) $rec->xp + $amount;
                $rec->timemodified = $now;
                $DB->update_record('local_learnlogic_userxp', $rec);
            } else {
                $DB->insert_record('local_learnlogic_userxp', (object) [
                    'userid' => $winnerid,
                    'xp' => $amount,
                    'timemodified' => $now,
                ]);
            }
            if ($DB->get_manager()->table_exists('local_learnlogic_xpevent')) {
                $DB->insert_record('local_learnlogic_xpevent', (object) [
                    'userid' => $winnerid,
                    'problemid' => (int) ($battle->problemid ?? 0),
                    'amount' => $amount,
                    'reason' => $reason,
                    'timecreated' => $now,
                ]);
            }
        }

        return $amount;
    }

    /**
     * XP the viewer earned (or would see) for this finished battle.
     *
     * @param \stdClass $battle
     * @param int $userid
     * @return int
     */
    public static function xp_for_viewer(\stdClass $battle, int $userid): int {
        global $DB;
        if (($battle->status ?? '') !== 'finished') {
            return 0;
        }
        if ((int) ($battle->winnerid ?? 0) !== $userid) {
            return 0;
        }
        if (($battle->outcome ?? '') === 'tie' || ($battle->outcome ?? '') === 'declined'
                || ($battle->outcome ?? '') === 'cancelled') {
            return 0;
        }
        $reason = 'battle_win_' . (int) $battle->id;
        if ($DB->get_manager()->table_exists('local_learnlogic_xpevent')) {
            $amount = $DB->get_field('local_learnlogic_xpevent', 'amount', [
                'userid' => $userid,
                'reason' => $reason,
            ]);
            if ($amount !== false) {
                return (int) $amount;
            }
        }
        return self::xp_for_difficulty(self::battle_difficulty($battle));
    }
}
