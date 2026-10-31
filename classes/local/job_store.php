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
 * Background jobs: one record per teacher request, so requests never mix.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

class job_store {

    /** Table name. */
    public const TABLE = 'local_ai_yared_course_job';

    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** Seconds without progress after which a running job is considered stalled. */
    public const STALL_SECONDS = 1800;

    /** Seconds a job may wait in the queue (cron stopped?) before it is reported as failed. */
    public const QUEUE_SECONDS = 3600;

    /** Lines kept in the progress log. */
    public const LOG_LINES = 40;

    /**
     * Create a queued job.
     *
     * @param int $userid Owner.
     * @param int $courseid Course.
     * @param array $options Everything the worker needs (topics and options).
     * @param int $total Number of topics.
     * @return int Job id.
     */
    public static function create(int $userid, int $courseid, array $options, int $total): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => self::QUEUED,
            'cancelreq' => 0,
            'total' => $total,
            'done' => 0,
            'failed' => 0,
            'step' => '',
            'log' => '',
            'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'results' => '[]',
            'error' => '',
            'timecreated' => $now,
            'timemodified' => $now,
            'timefinished' => 0,
        ]);
    }

    /**
     * Create a job only if the user has no unfinished one. The check and the insert run under a
     * per-user lock, so two requests at the same moment cannot both create a job.
     *
     * @param int $userid Owner.
     * @param int $courseid Course.
     * @param array $options Everything the worker needs (topics and options).
     * @param int $total Number of topics.
     * @return int|null Job id, or null when the user already has an active job (or the lock timed out).
     */
    public static function create_exclusive(int $userid, int $courseid, array $options, int $total): ?int {
        $lock = ai_guard::lock_user($userid);
        if (!$lock) {
            return null;
        }
        try {
            if (self::get_active_for_user($userid)) {
                return null;
            }
            return self::create($userid, $courseid, $options, $total);
        } finally {
            $lock->release();
        }
    }

    /**
     * Load a job.
     *
     * @param int $id Job id.
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        $job = $DB->get_record(self::TABLE, ['id' => $id]);
        return $job ?: null;
    }

    /**
     * Whether the job has finished (in any way).
     *
     * @param \stdClass $job Job.
     * @return bool
     */
    public static function is_finished(\stdClass $job): bool {
        return in_array($job->status, [self::DONE, self::FAILED, self::CANCELLED], true);
    }

    /**
     * Update fields of a job and touch its modification time.
     *
     * @param int $id Job id.
     * @param array $fields Fields to change.
     */
    public static function update(int $id, array $fields): void {
        global $DB;
        $fields['id'] = $id;
        $fields['timemodified'] = time();
        $DB->update_record(self::TABLE, (object) $fields);
    }

    /**
     * Mark jobs that stopped moving as failed, so the teacher is never left waiting forever.
     *
     * @param \stdClass $job Job.
     * @return \stdClass The same job, refreshed when it was marked.
     */
    public static function check_stalled(\stdClass $job): \stdClass {
        if (self::is_finished($job)) {
            return $job;
        }
        $limit = $job->status === self::QUEUED ? self::QUEUE_SECONDS : self::STALL_SECONDS;
        if ((int) $job->timemodified < time() - $limit) {
            $key = $job->status === self::QUEUED ? 'job_error_notstarted' : 'job_error_stalled';
            self::update((int) $job->id, [
                'status' => self::FAILED,
                'error' => get_string($key, 'local_ai_yared_course'),
                'timefinished' => time(),
            ]);
            return self::get((int) $job->id);
        }
        return $job;
    }

    /**
     * Ask to cancel a job. A queued job is cancelled at once; a running one stops at the next step.
     *
     * @param \stdClass $job Job.
     */
    public static function request_cancel(\stdClass $job): void {
        if (self::is_finished($job)) {
            return;
        }
        if ($job->status === self::QUEUED) {
            self::update((int) $job->id, ['status' => self::CANCELLED, 'cancelreq' => 1,
                'timefinished' => time()]);
            return;
        }
        self::update((int) $job->id, ['cancelreq' => 1]);
    }

    /**
     * Whether a cancellation was requested (read straight from the database).
     *
     * @param int $id Job id.
     * @return bool
     */
    public static function cancel_requested(int $id): bool {
        global $DB;
        return (bool) $DB->get_field(self::TABLE, 'cancelreq', ['id' => $id]);
    }

    /**
     * Append a line to the progress log and set it as the current step.
     *
     * @param int $id Job id.
     * @param string $line Message.
     */
    public static function add_log(int $id, string $line): void {
        global $DB;
        $line = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $line)), 0, 240);
        if ($line === '') {
            return;
        }
        $log = (string) $DB->get_field(self::TABLE, 'log', ['id' => $id]);
        $lines = $log === '' ? [] : explode("\n", $log);
        $lines[] = $line;
        $lines = array_slice($lines, -self::LOG_LINES);
        self::update($id, ['log' => implode("\n", $lines), 'step' => $line]);
    }

    /**
     * Unfinished job of a user, if any: each teacher runs one job at a time.
     *
     * @param int $userid User.
     * @return \stdClass|null
     */
    public static function get_active_for_user(int $userid): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select(self::TABLE,
            'userid = :userid AND status IN (:queued, :running)',
            ['userid' => $userid, 'queued' => self::QUEUED, 'running' => self::RUNNING],
            'id DESC');
        foreach ($records as $record) {
            $record = self::check_stalled($record);
            if (!self::is_finished($record)) {
                return $record;
            }
        }
        return null;
    }

    /**
     * Latest jobs of a user in a course.
     *
     * @param int $userid User.
     * @param int $courseid Course.
     * @param int $limit Maximum number of jobs.
     * @return \stdClass[]
     */
    public static function get_recent(int $userid, int $courseid, int $limit = 5): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['userid' => $userid, 'courseid' => $courseid], 'id DESC', '*', 0, $limit);
    }

    /**
     * Decoded results of a job.
     *
     * @param \stdClass $job Job.
     * @return array
     */
    public static function results(\stdClass $job): array {
        $data = json_decode((string) $job->results, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Decoded options of a job.
     *
     * @param \stdClass $job Job.
     * @return array
     */
    public static function options(\stdClass $job): array {
        $data = json_decode((string) $job->options, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Delete old finished jobs.
     *
     * @param int $days Age in days.
     * @return int Number of jobs deleted.
     */
    public static function cleanup(int $days = 30): int {
        global $DB;
        $select = 'timecreated < :limit AND status NOT IN (:queued, :running)';
        $params = ['limit' => time() - $days * DAYSECS, 'queued' => self::QUEUED, 'running' => self::RUNNING];
        $count = $DB->count_records_select(self::TABLE, $select, $params);
        $DB->delete_records_select(self::TABLE, $select, $params);
        return $count;
    }
}
