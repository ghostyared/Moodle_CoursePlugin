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
 * Storage of the course builds requested by the CRM through the external API.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_store {

    /** Table name. */
    public const TABLE = 'local_ai_yared_course_build';

    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    /** Course built and every topic created. */
    public const DONE = 'done';
    /** Course built, but some topics failed or the teacher cancelled the generation. */
    public const PARTIAL = 'partial';
    /** The course could not be built. */
    public const FAILED = 'failed';

    /**
     * Create the request, or return the existing one for the same CRM reference.
     *
     * The unique index on crmref makes this safe when the CRM retries or two requests arrive together.
     *
     * @param string $crmref CRM reference.
     * @param int $requesterid Service user that made the request.
     * @param int $teacherid Teacher that will own the course.
     * @param int $templateid Template course.
     * @param array $payload Validated payload.
     * @return array [stdClass record, bool created]
     */
    public static function create(string $crmref, int $requesterid, int $teacherid, int $templateid,
            array $payload): array {
        global $DB;

        $existing = $DB->get_record(self::TABLE, ['crmref' => $crmref]);
        if ($existing) {
            return [$existing, false];
        }
        $now = time();
        try {
            $id = $DB->insert_record(self::TABLE, (object) [
                'crmref' => $crmref,
                'status' => self::QUEUED,
                'requesterid' => $requesterid,
                'teacherid' => $teacherid,
                'templateid' => $templateid,
                'courseid' => 0,
                'jobid' => 0,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'result' => '',
                'error' => '',
                'webhookstatus' => 'none',
                'timecreated' => $now,
                'timemodified' => $now,
                'timefinished' => 0,
            ]);
        } catch (\dml_exception $e) {
            // Lost the race against the unique index: the other request owns the reference.
            $existing = $DB->get_record(self::TABLE, ['crmref' => $crmref]);
            if ($existing) {
                return [$existing, false];
            }
            throw $e;
        }
        return [$DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST), true];
    }

    /**
     * Load a request.
     *
     * @param int $id Id.
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Load a request by CRM reference.
     *
     * @param string $crmref Reference.
     * @return \stdClass|null
     */
    public static function get_by_ref(string $crmref): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['crmref' => $crmref]) ?: null;
    }

    /**
     * Update fields of a request.
     *
     * @param int $id Id.
     * @param array $fields Fields.
     */
    public static function update(int $id, array $fields): void {
        global $DB;
        $fields['id'] = $id;
        $fields['timemodified'] = time();
        $DB->update_record(self::TABLE, (object) $fields);
    }

    /**
     * Whether a request is finished.
     *
     * @param \stdClass $build Record.
     * @return bool
     */
    public static function is_finished(\stdClass $build): bool {
        return in_array($build->status, [self::DONE, self::PARTIAL, self::FAILED], true);
    }

    /**
     * Template course ids accepted by the API (admin setting, one id per line or comma separated).
     *
     * @return int[]
     */
    public static function allowed_templates(): array {
        $raw = (string) get_config('local_ai_yared_course', 'templatecourses');
        $ids = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
