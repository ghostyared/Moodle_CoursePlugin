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
use local_ai_yared_course\local\build_store;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: template courses the CRM may use.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_templates extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * List the allowed templates this user can actually clone.
     *
     * @return array
     */
    public static function execute() {
        global $DB;

        self::validate_context(\context_system::instance());
        $out = [];
        foreach (build_store::allowed_templates() as $id) {
            $course = $DB->get_record('course', ['id' => $id], 'id, fullname, shortname, format, category');
            if (!$course) {
                continue;
            }
            $context = \context_course::instance($course->id);
            if (!has_capability('moodle/backup:backupcourse', $context)
                    || !has_capability('local/ai_yared_course:apibuild', \context_coursecat::instance($course->category))) {
                continue;
            }
            $out[] = [
                'id' => (int) $course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $context]),
                'shortname' => $course->shortname,
                'format' => $course->format,
            ];
        }
        return $out;
    }

    /**
     * Returns.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course id'),
            'fullname' => new external_value(PARAM_RAW, 'Full name'),
            'shortname' => new external_value(PARAM_RAW, 'Short name'),
            'format' => new external_value(PARAM_ALPHANUMEXT, 'Course format'),
        ]));
    }
}
