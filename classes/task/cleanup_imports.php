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

/**
 * Scheduled task: delete abandoned PDF/image question imports.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Deletes the pending imports that were never saved or discarded.
 */
class cleanup_imports extends \core\task\scheduled_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup_imports', 'local_ai_yared_course');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $deleted = \local_ai_yared_course\local\import_store::cleanup_all_old();
        $jobs = \local_ai_yared_course\local\job_store::cleanup(30);
        mtrace('local_ai_yared_course: ' . $jobs . ' old generation job(s) deleted.');
        mtrace('local_ai_yared_course: ' . $deleted . ' abandoned import(s) deleted.');
        $logs = \local_ai_yared_course\local\usage_log::cleanup();
        mtrace('local_ai_yared_course: ' . $logs . ' old usage log row(s) deleted.');
    }
}
