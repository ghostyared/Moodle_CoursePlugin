<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_yared_course\external;

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_yared_course\local\topic_runner;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared parameter and return structures for the external API.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /**
     * Definition of the generation options accepted by the API.
     *
     * @return external_single_structure
     */
    public static function options_structure(): external_single_structure {
        return new external_single_structure([
            'age' => new external_value(PARAM_INT, 'Audience age (3-23)', VALUE_DEFAULT, 12),
            'language' => new external_value(PARAM_ALPHA, 'Content language code (es, en, pt, fr)', VALUE_DEFAULT, 'es'),
            'findvideo' => new external_value(PARAM_BOOL, 'Search a support video on YouTube', VALUE_DEFAULT, true),
            'questioncount' => new external_value(PARAM_INT, 'Questions generated for the bank', VALUE_DEFAULT, 10),
            'extensiontype' => new external_value(PARAM_ALPHA, 'Extension activity: assign or quiz', VALUE_DEFAULT, 'assign'),
            'hidesection' => new external_value(PARAM_BOOL, 'Create the new section hidden', VALUE_DEFAULT, true),
            'quizopen' => new external_value(PARAM_TEXT, 'Graded practice open date (YYYY-MM-DD)', VALUE_DEFAULT, ''),
            'quizclosedays' => new external_value(PARAM_INT, 'Days to close the practice', VALUE_DEFAULT, 7),
            'quiztimelimit' => new external_value(PARAM_INT, 'Practice duration in minutes', VALUE_DEFAULT, 60),
            'quizattempts' => new external_value(PARAM_INT, 'Practice attempts (0 = unlimited)', VALUE_DEFAULT, 2),
            'quizgrade' => new external_value(PARAM_INT, 'Practice maximum grade', VALUE_DEFAULT, 20),
            'quizquestions' => new external_value(PARAM_INT, 'Questions added to the practice', VALUE_DEFAULT, 10),
            'quizvisible' => new external_value(PARAM_BOOL, 'Show the practice to students', VALUE_DEFAULT, false),
            'createbank' => new external_value(PARAM_BOOL, 'Create the question bank', VALUE_DEFAULT, true),
            'createquiz' => new external_value(PARAM_BOOL, 'Create the graded practice', VALUE_DEFAULT, true),
            'createassign' => new external_value(PARAM_BOOL, 'Create the extension assignment', VALUE_DEFAULT, true),
            'createpdf' => new external_value(PARAM_BOOL, 'Create the class PDF', VALUE_DEFAULT, true),
            'createforum' => new external_value(PARAM_BOOL, 'Create the discussion forum', VALUE_DEFAULT, true),
        ], 'Generation options', VALUE_DEFAULT, []);
    }

    /**
     * Normalise the options received from the API, filling in the defaults.
     *
     * @param array $options Raw options.
     * @return array Options ready for topic_runner.
     */
    public static function normalise_options(array $options): array {
        $defaults = topic_runner::default_options();
        $options = topic_runner::sanitise_options($options + $defaults);

        $open = $options['quizopen'];
        if ($open === '' || $open === null) {
            $options['quizopen'] = $defaults['quizopen'];
        } else if (!is_numeric($open)) {
            $options['quizopen'] = (int) strtotime((string) $open);
        } else {
            $options['quizopen'] = (int) $open;
        }

        return $options;
    }

    /**
     * Structure of one topic as returned by the API.
     *
     * @return external_single_structure
     */
    public static function topic_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Topic id'),
            'title' => new external_value(PARAM_TEXT, 'Topic title'),
            'summary' => new external_value(PARAM_RAW, 'Topic summary'),
            'date' => new external_value(PARAM_TEXT, 'Class date (YYYY-MM-DD)'),
            'status' => new external_value(PARAM_ALPHA, 'pending or generated'),
            'sectionnum' => new external_value(PARAM_INT, 'Course section number (0 if not generated)'),
        ]);
    }

    /**
     * Structure of one topic date.
     *
     * @return external_single_structure
     */
    public static function date_structure(): external_single_structure {
        return new external_single_structure([
            'topicid' => new external_value(PARAM_INT, 'Topic id'),
            'date' => new external_value(PARAM_TEXT, 'Class date (YYYY-MM-DD)'),
        ]);
    }

    /**
     * Convenience: convert the array of topics received in a request to topic_store rows.
     *
     * @param array $topics List of ['title', 'summary', 'date'].
     * @return array Rows ready for topic_store::save().
     */
    public static function topics_to_rows(array $topics): array {
        $rows = [];
        foreach ($topics as $topic) {
            $date = trim((string) ($topic['date'] ?? ''));
            $rows[] = [
                'id' => 0,
                'title' => (string) ($topic['title'] ?? ''),
                'summary' => (string) ($topic['summary'] ?? ''),
                'classdate' => $date !== '' ? (int) strtotime($date) : 0,
            ];
        }
        return $rows;
    }

    /**
     * Build the list of topics of a course in the API format.
     *
     * @param int $courseid Course id.
     * @return array
     */
    public static function export_topics(int $courseid): array {
        $out = [];
        foreach (\local_ai_yared_course\local\topic_store::get_topics($courseid) as $topic) {
            $out[] = [
                'id' => (int) $topic->id,
                'title' => $topic->title,
                'summary' => (string) $topic->summary,
                'date' => (int) $topic->classdate > 0 ? date('Y-m-d', (int) $topic->classdate) : '',
                'status' => $topic->status,
                'sectionnum' => (int) $topic->sectionnum,
            ];
        }
        return $out;
    }
}
