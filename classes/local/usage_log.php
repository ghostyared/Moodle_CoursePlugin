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

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Usage and error log of the tool, shown in the admin report.
 *
 * What is stored (one row each):
 * - topic: every topic generated (teacher, course code, title, age, provider, components created, tokens).
 * - aifail: every AI failure, never the successful calls. Repeated failures of the same provider key and
 *   category within five minutes are merged into one row with a counter, so an outage cannot flood the table.
 * - quiz, bank, import, build: one row per quiz created, questions generated, import saved or CRM build.
 *
 * Writing the log must never break the work of a teacher: every write is wrapped and errors are ignored.
 * Prompts, answers of the AI and API keys are never stored.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_log {

    /** Table. */
    public const TABLE = 'local_ai_yared_course_log';

    public const TOPIC = 'topic';
    public const AIFAIL = 'aifail';
    public const QUIZ = 'quiz';
    public const BANK = 'bank';
    public const IMPORT = 'import';
    public const BUILD = 'build';

    /** Failures of the same provider key and category inside this window share one row. */
    protected const MERGE_SECONDS = 300;

    /** Hard limit of rows, so the table cannot grow without control whatever the retention is. */
    protected const MAX_ROWS = 50000;

    /** @var int Course being worked on by the current job, so AI failures can be tied to it. */
    protected static $courseid = 0;

    /**
     * Remember the course of the work in progress (0 to forget it).
     *
     * @param int $courseid Course id.
     */
    public static function set_course(int $courseid): void {
        self::$courseid = $courseid;
    }

    /**
     * Log one generated topic (or a topic that failed).
     *
     * @param \stdClass $course Course.
     * @param int $userid Teacher.
     * @param string $title Topic title.
     * @param int $age Audience age.
     * @param array|null $result Result of course_builder (null when it failed).
     * @param string $error Error message when it failed.
     * @param int $durationms Duration.
     * @param array $usage ['in' => tokens, 'out' => tokens].
     */
    public static function topic(\stdClass $course, int $userid, string $title, int $age, ?array $result, string $error,
            int $durationms, array $usage): void {
        $components = [];
        $status = 'error';
        if ($result !== null) {
            $status = empty($result['warnings']) ? 'ok' : 'warn';
            foreach ((array) ($result['items'] ?? []) as $item) {
                $mod = (string) ($item['modname'] ?? '');
                if ($mod !== '') {
                    $components[$mod] = ($components[$mod] ?? 0) + 1;
                }
            }
            $components['questions'] = (int) ($result['questions'] ?? 0);
            $components['video'] = !empty($result['hasvideo']) ? 1 : 0;
            $components['pdf'] = !empty($result['haspdf']) ? 1 : 0;
            $components['warnings'] = count((array) $result['warnings']);
        }
        self::insert([
            'eventtype' => self::TOPIC,
            'userid' => $userid,
            'courseid' => (int) $course->id,
            'title' => $title,
            'age' => $age,
            'provider' => (string) ($result['provider'] ?? ''),
            'model' => (string) ($result['model'] ?? ''),
            'status' => $status,
            'errormsg' => $error,
            'durationms' => $durationms,
            'tokensin' => (int) ($usage['in'] ?? 0),
            'tokensout' => (int) ($usage['out'] ?? 0),
            'components' => $components ? json_encode($components) : '',
        ], $course);
    }

    /**
     * Log a simple event: quiz, bank, import or build.
     *
     * @param string $type Event type.
     * @param int $courseid Course id (0 if none).
     * @param string $title Short description.
     * @param int $age Age (0 if not applicable).
     * @param array $components Counters, for example ['questions' => 10].
     * @param string $status ok|warn|error.
     * @param string $error Error message.
     * @param int $userid User (0 = current user).
     * @param array $extra Extra fields, for example provider and model.
     */
    public static function event(string $type, int $courseid, string $title, int $age = 0, array $components = [],
            string $status = 'ok', string $error = '', int $userid = 0, array $extra = []): void {
        self::insert($extra + [
            'eventtype' => $type,
            'userid' => $userid,
            'courseid' => $courseid,
            'title' => $title,
            'age' => $age,
            'status' => $status,
            'errormsg' => $error,
            'components' => $components ? json_encode($components) : '',
        ]);
    }

    /**
     * Log an AI failure (never called for successful calls).
     *
     * @param string $label Name of the provider slot, for example "Respaldo 1 #2".
     * @param string $provider Provider key.
     * @param string $model Model.
     * @param string $apikey API key used (only a short hash is kept).
     * @param string $category quota|auth|model|server|timeout|connection|empty|blocked|vision|other.
     * @param int $http HTTP status (0 for connection errors).
     * @param string $message Error message.
     */
    public static function ai_failure(string $label, string $provider, string $model, string $apikey, string $category,
            int $http, string $message): void {
        global $DB;
        try {
            $keyhint = $apikey === '' ? '' : substr(sha1($apikey), 0, 6);
            $message = self::clean_message($message, $apikey);
            $now = time();

            $recent = $DB->get_record_select(self::TABLE,
                'eventtype = :t AND provider = :p AND keyhint = :k AND errorcat = :c AND timelast > :since',
                ['t' => self::AIFAIL, 'p' => $provider, 'k' => $keyhint, 'c' => $category,
                    'since' => $now - self::MERGE_SECONDS], 'id', 'id', IGNORE_MULTIPLE);
            if ($recent) {
                $DB->execute('UPDATE {' . self::TABLE . '} SET occurrences = occurrences + 1, timelast = :now WHERE id = :id',
                    ['now' => $now, 'id' => $recent->id]);
                return;
            }
            self::insert([
                'eventtype' => self::AIFAIL,
                'courseid' => self::$courseid,
                'title' => $label,
                'provider' => $provider,
                'model' => $model,
                'keyhint' => $keyhint,
                'status' => 'error',
                'errorcat' => $category,
                'httpcode' => $http,
                'errormsg' => $message,
            ]);
        } catch (\Throwable $e) {
            debugging('local_ai_yared_course usage_log: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Remove API keys and long tokens from a message and shorten it.
     *
     * @param string $message Raw message.
     * @param string $apikey Key that was used.
     * @return string
     */
    protected static function clean_message(string $message, string $apikey): string {
        $message = trim(strip_tags($message));
        if ($apikey !== '') {
            $message = str_replace($apikey, '***', $message);
        }
        $message = preg_replace('/[A-Za-z0-9_\-]{28,}/', '***', $message);
        $message = preg_replace('/\s+/u', ' ', $message);
        return \core_text::substr($message, 0, 250);
    }

    /**
     * Insert a row. Fills user and course snapshots (name and course code) so the report still makes
     * sense after the user or the course are renamed or deleted.
     *
     * @param array $fields Fields of the row.
     * @param \stdClass|null $course Course, loaded from courseid when omitted.
     */
    protected static function insert(array $fields, ?\stdClass $course = null): void {
        global $DB, $USER;
        try {
            $now = time();
            $userid = (int) ($fields['userid'] ?? 0);
            if ($userid <= 0 && !empty($USER->id)) {
                $userid = (int) $USER->id;
            }
            $username = '';
            if ($userid > 0 && ($user = \core_user::get_user($userid))) {
                $username = fullname($user);
            }
            $courseid = (int) ($fields['courseid'] ?? 0);
            $coursecode = '';
            if ($courseid > 0) {
                $course = $course ?: $DB->get_record('course', ['id' => $courseid], 'id, shortname');
                $coursecode = $course ? (string) $course->shortname : '';
            }
            $age = (int) ($fields['age'] ?? 0);
            $row = (object) [
                'eventtype' => (string) $fields['eventtype'],
                'timecreated' => $now,
                'timelast' => $now,
                'userid' => $userid,
                'username' => \core_text::substr($username, 0, 255),
                'courseid' => $courseid,
                'coursecode' => \core_text::substr($coursecode, 0, 255),
                'title' => \core_text::substr((string) ($fields['title'] ?? ''), 0, 255),
                'age' => $age,
                'agelevel' => $age > 0 ? levels::key($age) : '',
                'provider' => \core_text::substr((string) ($fields['provider'] ?? ''), 0, 40),
                'model' => \core_text::substr((string) ($fields['model'] ?? ''), 0, 100),
                'keyhint' => (string) ($fields['keyhint'] ?? ''),
                'status' => (string) ($fields['status'] ?? 'ok'),
                'errorcat' => (string) ($fields['errorcat'] ?? ''),
                'httpcode' => (int) ($fields['httpcode'] ?? 0),
                'errormsg' => \core_text::substr(self::clean_message((string) ($fields['errormsg'] ?? ''), ''), 0, 255),
                'occurrences' => 1,
                'durationms' => (int) ($fields['durationms'] ?? 0),
                'tokensin' => (int) ($fields['tokensin'] ?? 0),
                'tokensout' => (int) ($fields['tokensout'] ?? 0),
                'components' => (string) ($fields['components'] ?? ''),
            ];
            $DB->insert_record(self::TABLE, $row, false);
        } catch (\Throwable $e) {
            // The log is never allowed to stop the work.
            debugging('local_ai_yared_course usage_log: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Delete old rows: older than the retention and, whatever the age, beyond the hard limit.
     *
     * @param int $days Retention in days (0 = use the setting, default 30).
     * @return int Rows deleted.
     */
    public static function cleanup(int $days = 0): int {
        global $DB;
        if ($days <= 0) {
            $days = self::retention_days();
        }
        $before = $DB->count_records(self::TABLE);
        $DB->delete_records_select(self::TABLE, 'timecreated < ?', [time() - $days * DAYSECS]);

        $count = $DB->count_records(self::TABLE);
        if ($count > self::MAX_ROWS) {
            $cut = $DB->get_records(self::TABLE, null, 'id DESC', 'id', self::MAX_ROWS - 1, 1);
            if ($cut) {
                $DB->delete_records_select(self::TABLE, 'id < ?', [reset($cut)->id]);
            }
        }
        return $before - $DB->count_records(self::TABLE);
    }

    /**
     * Retention in days (setting, 30 by default).
     *
     * @return int
     */
    public static function retention_days(): int {
        $days = (int) get_config('local_ai_yared_course', 'logretentiondays');
        return $days > 0 ? min(365, $days) : 30;
    }
}
