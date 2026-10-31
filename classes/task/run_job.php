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

/**
 * Runs one background generation job as the teacher who requested it.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\task;

defined('MOODLE_INTERNAL') || die();

class run_job extends \core\task\adhoc_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_run_job', 'local_ai_yared_course');
    }

    /**
     * Queue a job for its owner. The cron runs the task with that user, so capabilities, file drafts and
     * logs belong to the teacher and two teachers never share state.
     *
     * @param int $jobid Job id.
     * @param int $userid Owner.
     */
    public static function queue(int $jobid, int $userid): void {
        $task = new self();
        $task->set_custom_data(['jobid' => $jobid]);
        $task->set_userid($userid);
        // Several jobs of different teachers may run at the same time.
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run the job. Every failure is stored in the job; nothing is thrown, so Moodle does not retry
     * (a retry would create the topics twice).
     */
    public function execute() {
        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        $job = \local_ai_yared_course\local\job_store::get($jobid);
        if (!$job || $job->status !== \local_ai_yared_course\local\job_store::QUEUED) {
            return;
        }
        \local_ai_yared_course\local\job_runner::run($job);
    }
}
