<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Create lesson tables on upgrade (existing installs).
 */
class db_install_lessons {

    public static function create_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $lesson = new \xmldb_table('local_nexcomm_lesson');
        $lesson->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $lesson->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $lesson->add_field('difficulty', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'easy');
        $lesson->add_field('summary', XMLDB_TYPE_TEXT, null, null, null);
        $lesson->add_field('videourl', XMLDB_TYPE_TEXT, null, null, null);
        $lesson->add_field('topic', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $lesson->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $lesson->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $lesson->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $lesson->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $lesson->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'difficulty']);
        if (!$dbman->table_exists($lesson)) {
            $dbman->create_table($lesson);
        }

        $line = new \xmldb_table('local_nexcomm_lessonline');
        $line->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $line->add_field('lessonid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $line->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $line->add_field('speaker', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, '');
        $line->add_field('linetext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $line->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $line->add_index('lesson_ix', XMLDB_INDEX_NOTUNIQUE, ['lessonid', 'sortorder']);
        if (!$dbman->table_exists($line)) {
            $dbman->create_table($line);
        }

        $word = new \xmldb_table('local_nexcomm_lessonword');
        $word->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $word->add_field('lessonid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $word->add_field('word', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $word->add_field('hint', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $word->add_field('sentence', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $word->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $word->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $word->add_index('lesson_ix', XMLDB_INDEX_NOTUNIQUE, ['lessonid']);
        if (!$dbman->table_exists($word)) {
            $dbman->create_table($word);
        }

        $prog = new \xmldb_table('local_nexcomm_lessonprog');
        $prog->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $prog->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $prog->add_field('lessonid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $prog->add_field('watched', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $prog->add_field('wordslearned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $prog->add_field('linesspoken', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $prog->add_field('learnscore', XMLDB_TYPE_NUMBER, '10', '2', XMLDB_NOTNULL, null, '0');
        $prog->add_field('speakscore', XMLDB_TYPE_NUMBER, '10', '2', XMLDB_NOTNULL, null, '0');
        $prog->add_field('learnjson', XMLDB_TYPE_TEXT, null, null, null);
        $prog->add_field('speakjson', XMLDB_TYPE_TEXT, null, null, null);
        $prog->add_field('complete', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $prog->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $prog->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $prog->add_index('user_lesson_uix', XMLDB_INDEX_UNIQUE, ['userid', 'lessonid']);
        if (!$dbman->table_exists($prog)) {
            $dbman->create_table($prog);
        }
    }
}
