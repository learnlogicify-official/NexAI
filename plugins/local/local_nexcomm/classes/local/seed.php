<?php
// This file is part of Moodle - http://moodle.org/
/**
 * Seed placement-ready NexComm activities.
 *
 * @package   local_nexcomm
 * @copyright 2026 NexAcademy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nexcomm\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Install / upgrade seed content (~40 activities).
 */
class seed {

    /**
     * Seed if catalog is empty.
     */
    public static function ensure(): void {
        global $DB;
        if ($DB->count_records('local_nexcomm_activity') > 0) {
            return;
        }
        self::install();
    }

    /**
     * Insert MVP placement pack.
     */
    public static function install(): void {
        global $DB, $USER;

        $now = time();
        $uid = !empty($USER->id) ? (int) $USER->id : 0;

        foreach (self::definitions() as $def) {
            $id = (int) $DB->insert_record('local_nexcomm_activity', (object) [
                'skill' => $def['skill'],
                'difficulty' => $def['difficulty'],
                'title' => $def['title'],
                'body' => $def['body'] ?? '',
                'prompt' => $def['prompt'] ?? '',
                'audiourl' => $def['audiourl'] ?? '',
                'status' => 'ready',
                'passmark' => $def['passmark'] ?? 70,
                'minwords' => $def['minwords'] ?? 0,
                'timelimit' => 0,
                'tags' => $def['tags'] ?? '',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $uid,
            ]);
            $order = 0;
            foreach ($def['questions'] ?? [] as $q) {
                $DB->insert_record('local_nexcomm_question', (object) [
                    'activityid' => $id,
                    'qtype' => 'mcq',
                    'stem' => $q['stem'],
                    'choices' => json_encode($q['choices']),
                    'correctkey' => $q['correct'],
                    'sortorder' => $order++,
                ]);
            }
        }
    }

    /**
     * @return array
     */
    public static function definitions(): array {
        $mcq = static function (string $stem, array $choices, string $correct): array {
            return ['stem' => $stem, 'choices' => $choices, 'correct' => $correct];
        };

        // Listening: document that sites can replace audiourl with hosted TTS/clips.
        // Placeholder uses empty audio; passage text mirrors the script for MVP.
        $listennote = "\n\n[Audio placeholder: paste a short clip URL in Manage → Audio URL, or use browser TTS while practising.]";

        $items = [];

        // —— Speaking (10) ——
        $speak = [
            ['Tell me about yourself', 'easy', 'Introduce yourself for a 60–90 second campus placement interview. Cover education, one project, and one strength.', 'hr,intro'],
            ['What are your strengths?', 'easy', 'Share two strengths with a short example for each that an HR interviewer would remember.', 'hr,strengths'],
            ['Walk me through a project', 'medium', 'Explain one academic or internship project: problem, your role, tech/approach, and outcome.', 'project,gd'],
            ['Describe a conflict you handled', 'medium', 'Narrate a team conflict: situation, what you did, and what you learned—keep it professional.', 'behavioral'],
            ['Why should we hire you?', 'medium', 'Give a 90-second pitch tying your skills to a software/IT role on campus.', 'hr,pitch'],
            ['Explain a technical concept simply', 'hard', 'Pick one concept you know (e.g. REST API, OOP, DBMS index) and explain it to a non-technical manager.', 'tech,comm'],
            ['Tell me about a failure', 'hard', 'Describe a failure or setback, ownership, recovery, and change in behaviour.', 'behavioral'],
            ['Where do you see yourself in 3 years?', 'easy', 'Answer realistically for an entry-level role; show growth mindset without empty clichés.', 'hr'],
            ['Group discussion opening', 'medium', 'Open a GD on “Remote work vs office for freshers”—30–45 seconds, clear stance + one reason.', 'gd'],
            ['Clarify a vague requirement', 'hard', 'A manager says “make the app faster.” Speak the clarifying questions you would ask before coding.', 'workplace'],
        ];
        foreach ($speak as $s) {
            $items[] = [
                'skill' => 'speaking',
                'difficulty' => $s[1],
                'title' => $s[0],
                'prompt' => $s[2],
                'tags' => $s[3],
                'passmark' => 70,
            ];
        }

        // —— Writing (10) ——
        $write = [
            ['Application email for internship', 'easy', 'Write a short email applying for a summer internship. Include subject line intent, who you are, why the company, and a polite close.', 60, 'email'],
            ['Thank-you after interview', 'easy', 'Write a thank-you email after a campus interview. Reference one topic discussed and restate interest.', 50, 'email'],
            ['Leave request to manager', 'easy', 'Write a professional leave request for 2 days next week with reason and handover note.', 40, 'workplace'],
            ['Deadline clarification', 'medium', 'A client email is ambiguous about delivery date. Write a reply that clarifies the deadline and proposes a checkpoint.', 70, 'email,clarity'],
            ['Bug report to teammate', 'medium', 'Write a Slack/email style note describing a reproducible bug: steps, expected vs actual, environment.', 55, 'workplace'],
            ['Apology for missed standup', 'easy', 'Write a brief apology for missing standup and share your update + next steps.', 40, 'workplace'],
            ['Follow-up on unanswered email', 'medium', 'Politely follow up on an email sent 5 days ago about internship status—no pressure tone.', 45, 'email'],
            ['Refuse weekend work politely', 'hard', 'Decline a last-minute weekend task while offering an alternative plan for Monday. Stay respectful.', 70, 'workplace'],
            ['Summarise meeting notes', 'hard', 'Convert: “Discussed login bug, Rahul owns fix by Fri, Priya tests Mon, release Tue if green.” into crisp email notes with owners/dates.', 50, 'workplace'],
            ['LinkedIn connection note', 'easy', 'Write a short LinkedIn note to an alumni who works at your dream company (max ~300 characters intent; write ~40–60 words).', 40, 'networking'],
        ];
        foreach ($write as $w) {
            $items[] = [
                'skill' => 'writing',
                'difficulty' => $w[1],
                'title' => $w[0],
                'prompt' => $w[2],
                'minwords' => $w[3],
                'tags' => $w[4],
                'passmark' => 70,
            ];
        }

        // —— Reading (10) ——
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'easy', 'title' => 'JD: Junior software engineer',
            'tags' => 'jd,reading', 'passmark' => 70,
            'body' => "We are hiring a Junior Software Engineer.\n\nRequirements:\n- Strong problem-solving in any one language (Python/Java/JS)\n- Basics of data structures and SQL\n- Willingness to learn Git and code reviews\n\nNice to have: internship experience, hackathon participation.\n\nRole: build features with a mentor, write tests, and communicate weekly progress.",
            'questions' => [
                $mcq('Which skill is required (not just nice-to-have)?', [
                    'A' => 'Hackathon medals', 'B' => 'SQL basics', 'C' => 'Published research papers', 'D' => '5 years experience',
                ], 'B'),
                $mcq('What is part of the role?', [
                    'A' => 'Managing payroll', 'B' => 'Writing tests and sharing weekly progress', 'C' => 'Only attending meetings', 'D' => 'Sales cold-calling',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'easy', 'title' => 'Campus placement email',
            'tags' => 'email,reading', 'passmark' => 70,
            'body' => "Subject: Infosys drive — documents checklist\n\nDear students,\nPlease bring: college ID, government ID, updated resume (2 copies), and mark sheets.\nReporting time: 8:30 AM. Gate closes at 9:00 AM.\nDress code: formal.\n\nRegards,\nTraining & Placement Cell",
            'questions' => [
                $mcq('When does the gate close?', [
                    'A' => '8:30 AM', 'B' => '9:00 AM', 'C' => '10:00 AM', 'D' => 'Not mentioned',
                ], 'B'),
                $mcq('How many resume copies are needed?', [
                    'A' => '1', 'B' => '2', 'C' => '3', 'D' => 'None',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'medium', 'title' => 'Verbal RC: remote internships',
            'tags' => 'verbal,rc', 'passmark' => 70,
            'body' => "Remote internships expanded access for students outside metro cities, but they also demand stronger written communication. Mentors cannot glance over a shoulder; blockers must be written clearly. Students who send crisp updates and questions tend to receive faster guidance than those who wait for scheduled calls only.",
            'questions' => [
                $mcq('Main idea of the passage?', [
                    'A' => 'Remote internships remove the need to communicate', 'B' => 'Remote work increases need for clear written updates',
                    'C' => 'Only metro students succeed remotely', 'D' => 'Calls are better than writing always',
                ], 'B'),
                $mcq('Who gets faster guidance, according to the text?', [
                    'A' => 'Students who wait for calls only', 'B' => 'Students who send crisp updates and questions',
                    'C' => 'Students who never ask questions', 'D' => 'Students who avoid mentors',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'medium', 'title' => 'Policy: code of conduct excerpt',
            'tags' => 'workplace,reading', 'passmark' => 70,
            'body' => "Employees must not share customer data outside approved tools. Screenshots containing PII should be avoided in chat. If a data leak is suspected, inform the security channel within 1 hour and do not delete evidence.",
            'questions' => [
                $mcq('What should you do if you suspect a leak?', [
                    'A' => 'Delete logs immediately', 'B' => 'Inform security channel within 1 hour',
                    'C' => 'Post on social media', 'D' => 'Wait until the next appraisal',
                ], 'B'),
                $mcq('Screenshots with PII in chat are…', [
                    'A' => 'Encouraged', 'B' => 'To be avoided', 'C' => 'Mandatory', 'D' => 'Only for managers',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'hard', 'title' => 'Offer letter snippet',
            'tags' => 'offer,reading', 'passmark' => 70,
            'body' => "Joining date: 15 September. Probation: 6 months. Notice during probation: 30 days. Relocation bonus of ₹25,000 is recoverable if you resign within 12 months of joining. Background verification must clear before day 1.",
            'questions' => [
                $mcq('When is the relocation bonus recoverable?', [
                    'A' => 'If you resign within 12 months of joining', 'B' => 'Never', 'C' => 'Only after 5 years', 'D' => 'If you take leave',
                ], 'A'),
                $mcq('Notice period during probation is…', [
                    'A' => '7 days', 'B' => '15 days', 'C' => '30 days', 'D' => '90 days',
                ], 'C'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'easy', 'title' => 'Slack thread: release status',
            'tags' => 'workplace,reading', 'passmark' => 70,
            'body' => "Asha: Login fix is in QA.\nRahul: Blocking bug on Safari—ETA 4 PM today.\nPriya: Docs updated; marketing can announce tomorrow if QA green by 6 PM.",
            'questions' => [
                $mcq('What blocks release messaging?', [
                    'A' => 'Marketing preference only', 'B' => 'QA must be green by 6 PM', 'C' => 'Office Wi-Fi', 'D' => 'Printer ink',
                ], 'B'),
                $mcq('Who owns the Safari bug?', [
                    'A' => 'Asha', 'B' => 'Rahul', 'C' => 'Priya', 'D' => 'Unknown',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'medium', 'title' => 'Email: ambiguous deadline',
            'tags' => 'email,reading', 'passmark' => 70,
            'body' => "Hi—please share the dashboard “ASAP by end of week if possible, else early next week works too, unless finance needs it Friday.”",
            'questions' => [
                $mcq('What is the clearest risk in this email?', [
                    'A' => 'Too many fonts', 'B' => 'Unclear priority/deadline', 'C' => 'Missing emoji', 'D' => 'Wrong language',
                ], 'B'),
                $mcq('Best next step for the receiver?', [
                    'A' => 'Ignore the email', 'B' => 'Ask which deadline is binding and who the stakeholder is',
                    'C' => 'Delete the dashboard', 'D' => 'Reply only with “OK”',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'hard', 'title' => 'RC: soft skills myth',
            'tags' => 'verbal,rc', 'passmark' => 70,
            'body' => "Many students treat communication as decoration after technical prep. In placement interviews, unclear explanations often hide strong logic. Practising structured answers—context, action, result—improves both speaking and writing under time pressure.",
            'questions' => [
                $mcq('Author’s view on communication?', [
                    'A' => 'It is optional decoration', 'B' => 'It can reveal or hide technical logic',
                    'C' => 'It replaces coding skill', 'D' => 'It only matters for sales roles',
                ], 'B'),
                $mcq('What structure is recommended?', [
                    'A' => 'Joke, meme, exit', 'B' => 'Context, action, result', 'C' => 'Only buzzwords', 'D' => 'Silence',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'medium', 'title' => 'Internship feedback form',
            'tags' => 'feedback,reading', 'passmark' => 70,
            'body' => "Strengths: owns tasks, asks clarifying questions early.\nImprove: document decisions in the shared wiki; reduce last-minute PR comments by reviewing checklist first.",
            'questions' => [
                $mcq('What should the intern improve?', [
                    'A' => 'Stop asking questions', 'B' => 'Document decisions and use PR checklist earlier',
                    'C' => 'Avoid the wiki', 'D' => 'Never open PRs',
                ], 'B'),
                $mcq('A listed strength is…', [
                    'A' => 'Ignoring blockers', 'B' => 'Asking clarifying questions early', 'C' => 'Skipping standups', 'D' => 'Late delivery',
                ], 'B'),
            ],
        ];
        $items[] = [
            'skill' => 'reading', 'difficulty' => 'easy', 'title' => 'Invitation: pre-placement talk',
            'tags' => 'campus,reading', 'passmark' => 70,
            'body' => "PPT by Acme Corp — Friday 3 PM, Seminar Hall B. Bring questions on culture and growth. Attendance is mandatory for registered students; unregistered may sit if seats remain after 2:50 PM.",
            'questions' => [
                $mcq('Where is the talk?', [
                    'A' => 'Hall A', 'B' => 'Seminar Hall B', 'C' => 'Online only', 'D' => 'Library',
                ], 'B'),
                $mcq('Unregistered students may enter…', [
                    'A' => 'Anytime', 'B' => 'If seats remain after 2:50 PM', 'C' => 'Never', 'D' => 'Only with CEO',
                ], 'B'),
            ],
        ];

        // —— Listening (10) ——
        $listen = [
            [
                'HR briefing: interview rounds', 'easy',
                "Script: Welcome. Today has three rounds: aptitude, technical, and HR. Each round is elimination. Keep your resume ready and phones on silent.",
                [
                    $mcq('How many rounds are mentioned?', [
                        'A' => 'Two', 'B' => 'Three', 'C' => 'Four', 'D' => 'One',
                    ], 'B'),
                    $mcq('What should phones be?', [
                        'A' => 'On loudspeaker', 'B' => 'On silent', 'C' => 'Video recording', 'D' => 'Shared with peers',
                    ], 'B'),
                ],
            ],
            [
                'Manager: standup expectations', 'easy',
                "Script: In standup, share yesterday’s work, today’s plan, and blockers in under one minute. Do not debug live in the meeting.",
                [
                    $mcq('What should you not do in standup?', [
                        'A' => 'Share blockers', 'B' => 'Debug live in the meeting', 'C' => 'Share today’s plan', 'D' => 'Keep it short',
                    ], 'B'),
                    $mcq('Time expectation per person is…', [
                        'A' => 'Under one minute', 'B' => 'Thirty minutes', 'C' => 'No limit', 'D' => 'Half a day',
                    ], 'A'),
                ],
            ],
            [
                'Client call: scope change', 'medium',
                "Script: We still want the dashboard Friday, but please add export to CSV. If CSV slips, keep Friday for charts and ship export Monday.",
                [
                    $mcq('Hard deadline still Friday for…', [
                        'A' => 'CSV export only', 'B' => 'Charts/dashboard (export can slip to Monday)', 'C' => 'Nothing', 'D' => 'Marketing video',
                    ], 'B'),
                    $mcq('New request added is…', [
                        'A' => 'PDF watermark', 'B' => 'CSV export', 'C' => 'New logo', 'D' => 'Office party',
                    ], 'B'),
                ],
            ],
            [
                'Mentor: code review tip', 'medium',
                "Script: Before requesting review, run tests, check naming, and write a PR description with why—not only what. Reviewers should comment on design, not only typos.",
                [
                    $mcq('PR description should include…', [
                        'A' => 'Only file names', 'B' => 'Why, not only what', 'C' => 'Memes', 'D' => 'Salary expectation',
                    ], 'B'),
                    $mcq('Reviewers should focus on…', [
                        'A' => 'Design as well, not only typos', 'B' => 'Typos only', 'C' => 'Ignoring tests', 'D' => 'Personal attacks',
                    ], 'A'),
                ],
            ],
            [
                'HR: behavioral interview tip', 'medium',
                "Script: Use STAR—Situation, Task, Action, Result. Quantify results when possible. Avoid blaming teammates; show ownership.",
                [
                    $mcq('STAR stands for…', [
                        'A' => 'Stop, Talk, Argue, Run', 'B' => 'Situation, Task, Action, Result',
                        'C' => 'Study, Test, Apply, Rest', 'D' => 'Share, Tag, Ask, Reply',
                    ], 'B'),
                    $mcq('What should you avoid?', [
                        'A' => 'Ownership', 'B' => 'Blaming teammates', 'C' => 'Quantifying results', 'D' => 'Clear structure',
                    ], 'B'),
                ],
            ],
            [
                'Ops: incident update', 'hard',
                "Script: Payment latency spiked at 2:10 PM. We rolled back release 1.8.2 at 2:25. Monitoring is green since 2:40. RCA doc due tomorrow 11 AM.",
                [
                    $mcq('What fixed the issue?', [
                        'A' => 'New marketing campaign', 'B' => 'Rollback of release 1.8.2', 'C' => 'Hiring freeze', 'D' => 'Office AC repair',
                    ], 'B'),
                    $mcq('When is the RCA due?', [
                        'A' => 'Today 2:40', 'B' => 'Tomorrow 11 AM', 'C' => 'Next month', 'D' => 'Not stated',
                    ], 'B'),
                ],
            ],
            [
                'Placement cell: document rules', 'easy',
                "Script: Carry originals and photocopies. Do not laminate mark sheets. Name on resume must match ID exactly.",
                [
                    $mcq('Mark sheets should be…', [
                        'A' => 'Laminated', 'B' => 'Not laminated', 'C' => 'Handwritten only', 'D' => 'Deleted',
                    ], 'B'),
                    $mcq('Resume name must…', [
                        'A' => 'Be a nickname', 'B' => 'Match ID exactly', 'C' => 'Be blank', 'D' => 'Use only initials',
                    ], 'B'),
                ],
            ],
            [
                'Team norms: async updates', 'medium',
                "Script: If you will miss a meeting, post an update in #team before it starts. Tag the owner of any blocker. Do not assume silence means agreement.",
                [
                    $mcq('If you miss a meeting you should…', [
                        'A' => 'Stay silent', 'B' => 'Post an update before it starts', 'C' => 'Quit Slack', 'D' => 'Only tell friends',
                    ], 'B'),
                    $mcq('Silence means…', [
                        'A' => 'Agreement always', 'B' => 'Should not be assumed as agreement', 'C' => 'Approval of budget', 'D' => 'Holiday',
                    ], 'B'),
                ],
            ],
            [
                'Panel: why this company', 'hard',
                "Script: We look for candidates who connect our product to a user problem they care about—not generic praise. Mention a feature and who it helps.",
                [
                    $mcq('What do interviewers prefer?', [
                        'A' => 'Generic praise only', 'B' => 'Linking product to a user problem/feature impact',
                        'C' => 'Memorised slogans', 'D' => 'No product knowledge',
                    ], 'B'),
                    $mcq('You should mention…', [
                        'A' => 'A feature and who it helps', 'B' => 'Only salary bands', 'C' => 'Competitor gossip', 'D' => 'Nothing specific',
                    ], 'A'),
                ],
            ],
            [
                'Buddy: first-week advice', 'easy',
                "Script: Set up your tools on day one. Ask for a small starter ticket. Write down acronyms. Schedule a 15-minute weekly sync with your mentor.",
                [
                    $mcq('Recommended weekly habit?', [
                        'A' => 'Skip mentor chats', 'B' => '15-minute weekly sync with mentor', 'C' => 'Only annual reviews', 'D' => 'No notes',
                    ], 'B'),
                    $mcq('On day one you should…', [
                        'A' => 'Set up tools', 'B' => 'Rewrite the architecture alone', 'C' => 'Ignore onboarding', 'D' => 'Change production passwords',
                    ], 'A'),
                ],
            ],
        ];
        foreach ($listen as $L) {
            $items[] = [
                'skill' => 'listening',
                'difficulty' => $L[1],
                'title' => $L[0],
                'body' => $L[2] . $listennote,
                'audiourl' => '',
                'tags' => 'listening,hr',
                'passmark' => 70,
                'questions' => $L[3],
            ];
        }

        return $items;
    }
}
