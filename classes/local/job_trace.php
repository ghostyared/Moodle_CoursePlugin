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
 * Progress trace of a background job: stores each step in the database and stops on cancel.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

class job_trace extends \progress_trace {

    /** @var int Job id. */
    protected $jobid;

    /**
     * Constructor.
     *
     * @param int $jobid Job id.
     */
    public function __construct(int $jobid) {
        $this->jobid = $jobid;
    }

    /**
     * Store the step and stop the job if the teacher cancelled it.
     *
     * @param string $message Message.
     * @param int $depth Depth (ignored).
     * @throws job_cancelled
     */
    public function output($message, $depth = 0) {
        if (job_store::cancel_requested($this->jobid)) {
            throw new job_cancelled();
        }
        job_store::add_log($this->jobid, (string) $message);
    }

    /**
     * Nothing to close.
     *
     * @return bool
     */
    public function finished() {
        return true;
    }
}
