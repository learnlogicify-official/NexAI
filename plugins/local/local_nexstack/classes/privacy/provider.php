<?php
namespace local_nexstack\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\null_provider;

/**
 * Privacy provider.
 */
class provider implements null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
