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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: list the courses that have topics designed with this plugin.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_courses extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Filter by category (0 = all)', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum number of courses returned', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * Return the courses that have designed topics and the caller can manage.
     *
     * @param int $categoryid Category filter.
     * @param int $limit Maximum number of courses.
     * @return array
     */
    public static function execute($categoryid = 0, $limit = 100) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'limit' => $limit,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $sqlparams = [];
        $where = '';
        if ($params['categoryid'] > 0) {
            $where = 'AND c.category = :categoryid';
            $sqlparams['categoryid'] = $params['categoryid'];
        }

        $sql = "SELECT c.id, c.fullname, c.shortname, c.visible, COUNT(t.id) AS topiccount
                  FROM {course} c
                  JOIN {local_ai_yared_course_topic} t ON t.courseid = c.id
                 WHERE c.id > 1 $where
              GROUP BY c.id, c.fullname, c.shortname, c.visible
              ORDER BY c.fullname ASC";

        $limit = max(1, min((int) $params['limit'], 500));
        $records = $DB->get_records_sql($sql, $sqlparams, 0, $limit);

        $courses = [];
        foreach ($records as $record) {
            $coursectx = \context_course::instance($record->id, IGNORE_MISSING);
            if (!$coursectx || !has_capability('local/ai_yared_course:manage', $coursectx)) {
                continue;
            }
            $courses[] = [
                'courseid' => (int) $record->id,
                'fullname' => $record->fullname,
                'shortname' => $record->shortname,
                'visible' => (bool) $record->visible,
                'topiccount' => (int) $record->topiccount,
            ];
        }

        return ['courses' => $courses];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
                'topiccount' => new external_value(PARAM_INT, 'Number of designed topics'),
            ]), 'The courses that have designed topics'),
        ]);
    }
}
