<?php
namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Seed English Central–style placement dialogue lessons.
 */
class lesson_seed {

    public static function ensure(): void {
        global $DB;
        if ($DB->count_records('local_nexcomm_lesson') > 0) {
            return;
        }
        self::install();
    }

    public static function install(): void {
        global $DB;
        $now = time();
        foreach (self::definitions() as $def) {
            $id = (int) $DB->insert_record('local_nexcomm_lesson', (object) [
                'title' => $def['title'],
                'difficulty' => $def['difficulty'],
                'summary' => $def['summary'],
                'videourl' => $def['videourl'] ?? '',
                'topic' => $def['topic'],
                'status' => 'ready',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $i = 0;
            foreach ($def['lines'] as $line) {
                $DB->insert_record('local_nexcomm_lessonline', (object) [
                    'lessonid' => $id,
                    'sortorder' => $i++,
                    'speaker' => $line[0],
                    'linetext' => $line[1],
                ]);
            }
            $i = 0;
            foreach ($def['words'] as $w) {
                $DB->insert_record('local_nexcomm_lessonword', (object) [
                    'lessonid' => $id,
                    'word' => $w[0],
                    'hint' => $w[1],
                    'sentence' => $w[2],
                    'sortorder' => $i++,
                ]);
            }
        }
    }

    /**
     * @return array
     */
    public static function definitions(): array {
        return [
            [
                'title' => 'HR screening call',
                'difficulty' => 'easy',
                'topic' => 'interview',
                'summary' => 'A short campus HR screening call. Watch the dialogue, learn key words, then speak each line.',
                'videourl' => '',
                'lines' => [
                    ['HR', 'Good morning. Thanks for joining the screening call today.'],
                    ['You', 'Good morning. Thank you for the opportunity.'],
                    ['HR', 'Could you briefly introduce yourself?'],
                    ['You', 'Sure. I am a final-year computer science student with internship experience in web development.'],
                    ['HR', 'What interests you about this role?'],
                    ['You', 'I want to build real products, learn from a strong team, and grow as an engineer.'],
                    ['HR', 'Great. We will share next steps by email.'],
                    ['You', 'Thank you. I look forward to hearing from you.'],
                ],
                'words' => [
                    ['screening', 'first filter interview', 'This is a short screening call today.'],
                    ['opportunity', 'chance', 'Thank you for the opportunity.'],
                    ['internship', 'work experience during studies', 'I have internship experience in web development.'],
                    ['engineer', 'software professional', 'I want to grow as an engineer.'],
                ],
            ],
            [
                'title' => 'Clarify a project deadline',
                'difficulty' => 'medium',
                'topic' => 'workplace',
                'summary' => 'Workplace English: confirm an unclear deadline with your manager.',
                'videourl' => '',
                'lines' => [
                    ['Manager', 'Please finish the dashboard ASAP this week if possible.'],
                    ['You', 'Just to confirm, do you need the charts by Friday, or is Monday acceptable?'],
                    ['Manager', 'Friday for charts. CSV export can wait until Monday.'],
                    ['You', 'Understood. I will deliver charts on Friday and export on Monday.'],
                    ['Manager', 'Please post a short update in Slack by Thursday noon.'],
                    ['You', 'I will share progress and any blockers by Thursday noon.'],
                ],
                'words' => [
                    ['confirm', 'make sure', 'Just to confirm, do you need the charts by Friday?'],
                    ['acceptable', 'okay / allowed', 'Is Monday acceptable?'],
                    ['export', 'download as a file', 'CSV export can wait until Monday.'],
                    ['blockers', 'things stopping progress', 'I will share progress and any blockers.'],
                ],
            ],
            [
                'title' => 'Group discussion opener',
                'difficulty' => 'medium',
                'topic' => 'gd',
                'summary' => 'Open a placement GD on remote work for freshers.',
                'videourl' => '',
                'lines' => [
                    ['Moderator', 'Today’s topic is remote work versus office for freshers. Who would like to begin?'],
                    ['You', 'I can start. Remote work offers flexibility, but freshers often learn faster with in-person mentoring.'],
                    ['Peer', 'I disagree. Many companies ship successfully with remote onboarding.'],
                    ['You', 'That is fair. A hybrid model may give both guidance and flexibility.'],
                    ['Moderator', 'Please keep points short and respectful.'],
                    ['You', 'To summarise, hybrid work can balance learning speed and flexibility for freshers.'],
                ],
                'words' => [
                    ['flexibility', 'freedom to choose', 'Remote work offers flexibility.'],
                    ['mentoring', 'guidance from seniors', 'Freshers learn faster with in-person mentoring.'],
                    ['hybrid', 'mix of remote and office', 'A hybrid model may work best.'],
                    ['summarise', 'give a short conclusion', 'To summarise, hybrid work balances both needs.'],
                ],
            ],
            [
                'title' => 'Client status update',
                'difficulty' => 'hard',
                'topic' => 'workplace',
                'summary' => 'Give a clear client update after a production issue.',
                'videourl' => '',
                'lines' => [
                    ['Client', 'We noticed payment latency around 2 PM. What happened?'],
                    ['You', 'We detected elevated latency at 2:10 and rolled back release 1.8.2 at 2:25.'],
                    ['Client', 'Is the system stable now?'],
                    ['You', 'Yes. Monitoring has been green since 2:40. We are preparing a root-cause analysis.'],
                    ['Client', 'When will we receive the RCA?'],
                    ['You', 'We will share the RCA document tomorrow by 11 AM.'],
                    ['Client', 'Thank you for the clear update.'],
                    ['You', 'Thank you. We will keep you informed if anything changes.'],
                ],
                'words' => [
                    ['latency', 'delay / slow response', 'We detected elevated latency at 2:10.'],
                    ['rollback', 'undo a release', 'We rolled back release 1.8.2.'],
                    ['monitoring', 'system health checks', 'Monitoring has been green since 2:40.'],
                    ['analysis', 'detailed investigation', 'We are preparing a root-cause analysis.'],
                ],
            ],
            [
                'title' => 'Thank-you after interview',
                'difficulty' => 'easy',
                'topic' => 'email-speak',
                'summary' => 'Practise a spoken thank-you note after a campus interview.',
                'videourl' => '',
                'lines' => [
                    ['You', 'Dear Ms Sharma, thank you for interviewing me today.'],
                    ['You', 'I especially enjoyed our discussion about the mentorship program for new joiners.'],
                    ['You', 'The role matches my interest in building reliable backend services.'],
                    ['You', 'Please let me know if you need any additional information.'],
                    ['You', 'I look forward to the next steps. Kind regards.'],
                ],
                'words' => [
                    ['interviewing', 'conducting an interview', 'Thank you for interviewing me today.'],
                    ['mentorship', 'structured guidance', 'I enjoyed discussing the mentorship program.'],
                    ['reliable', 'dependable / stable', 'I want to build reliable backend services.'],
                    ['regards', 'polite closing', 'I look forward to the next steps. Kind regards.'],
                ],
            ],
        ];
    }
}
