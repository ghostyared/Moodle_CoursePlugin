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
 * Stores the topics designed for an academic period.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_store {

    /** Status of a topic that has not been turned into a course section yet. */
    public const STATUS_PENDING = 'pending';
    /** Status of a topic whose section was already created. */
    public const STATUS_GENERATED = 'generated';

    /**
     * Get the topics of a course ordered by their position.
     *
     * @param int $courseid Course id.
     * @return \stdClass[] Indexed by id.
     */
    public static function get_topics(int $courseid): array {
        global $DB;
        return $DB->get_records('local_ai_yared_course_topic', ['courseid' => $courseid], 'sortorder ASC, id ASC');
    }

    /**
     * Get one topic.
     *
     * @param int $id Topic id.
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        $topic = $DB->get_record('local_ai_yared_course_topic', ['id' => $id]);
        return $topic ?: null;
    }

    /**
     * Create or update the topics of a course from the rows sent by the designer.
     *
     * Rows without a title are ignored. Rows that are no longer present are deleted.
     *
     * @param int $courseid Course id.
     * @param array $rows Each row: id, title, summary, classdate, age.
     */
    public static function save(int $courseid, array $rows): void {
        global $DB;

        // Short, database-only writes: all or nothing.
        $transaction = $DB->start_delegated_transaction();

        $existing = $DB->get_records('local_ai_yared_course_topic', ['courseid' => $courseid], '', 'id');
        $now = time();
        $order = 0;
        $kept = [];

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $order++;
            if ($title === '') {
                continue;
            }

            $record = (object) [
                'courseid' => $courseid,
                'sortorder' => $order,
                'title' => \core_text::substr($title, 0, 255),
                'summary' => (string) ($row['summary'] ?? ''),
                'classdate' => (int) ($row['classdate'] ?? 0),
                'age' => max(0, (int) ($row['age'] ?? 0)),
                'timemodified' => $now,
            ];

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($existing[$id])) {
                $record->id = $id;
                $DB->update_record('local_ai_yared_course_topic', $record);
                $kept[$id] = true;
            } else {
                $record->status = self::STATUS_PENDING;
                $record->sectionid = 0;
                $record->sectionnum = 0;
                $record->timecreated = $now;
                $kept[$DB->insert_record('local_ai_yared_course_topic', $record)] = true;
            }
        }

        // Remove the topics the teacher deleted from the table.
        foreach ($existing as $id => $unused) {
            if (!isset($kept[$id])) {
                $DB->delete_records('local_ai_yared_course_topic', ['id' => $id]);
            }
        }

        $transaction->allow_commit();
    }

    /**
     * Remember the section that was created for a topic.
     *
     * @param int $id Topic id.
     * @param int $sectionid course_sections.id.
     * @param int $sectionnum Section number.
     */
    public static function mark_generated(int $id, int $sectionid, int $sectionnum): void {
        global $DB;
        $DB->update_record('local_ai_yared_course_topic', (object) [
            'id' => $id,
            'sectionid' => $sectionid,
            'sectionnum' => $sectionnum,
            'status' => self::STATUS_GENERATED,
            'timemodified' => time(),
        ]);
    }

    /**
     * Count the topics of a course by status.
     *
     * @param int $courseid Course id.
     * @param string $status Status to count.
     * @return int
     */
    public static function count_by_status(int $courseid, string $status): int {
        global $DB;
        return $DB->count_records('local_ai_yared_course_topic', ['courseid' => $courseid, 'status' => $status]);
    }

    /**
     * Create or update a single topic (used by the external API).
     *
     * @param int $courseid Course id.
     * @param int $id Topic id (0 to create).
     * @param string $title Topic title.
     * @param string $summary Topic summary.
     * @param int $classdate Class date (timestamp, 0 if none).
     * @return int The topic id.
     */
    public static function upsert(int $courseid, int $id, string $title, string $summary, int $classdate): int {
        global $DB;

        $now = time();
        $title = \core_text::substr(trim($title), 0, 255);

        if ($id > 0 && $DB->record_exists('local_ai_yared_course_topic', ['id' => $id, 'courseid' => $courseid])) {
            $DB->update_record('local_ai_yared_course_topic', (object) [
                'id' => $id,
                'title' => $title,
                'summary' => $summary,
                'classdate' => $classdate,
                'timemodified' => $now,
            ]);
            return $id;
        }

        $max = (int) $DB->get_field_sql('SELECT MAX(sortorder) FROM {local_ai_yared_course_topic} WHERE courseid = ?',
            [$courseid]);

        return (int) $DB->insert_record('local_ai_yared_course_topic', (object) [
            'courseid' => $courseid,
            'sortorder' => $max + 1,
            'title' => $title,
            'summary' => $summary,
            'classdate' => $classdate,
            'sectionid' => 0,
            'sectionnum' => 0,
            'status' => self::STATUS_PENDING,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Update the class date of several topics at once (used by the external API).
     *
     * @param int $courseid Course id.
     * @param array $dates topicid => timestamp|string date.
     * @return int Number of topics updated.
     */
    public static function update_dates(int $courseid, array $dates): int {
        global $DB;

        $now = time();
        $updated = 0;
        foreach ($dates as $topicid => $date) {
            $topicid = (int) $topicid;
            $timestamp = is_numeric($date) ? (int) $date : (int) strtotime((string) $date);
            if ($topicid <= 0 || $timestamp <= 0) {
                continue;
            }
            if (!$DB->record_exists('local_ai_yared_course_topic', ['id' => $topicid, 'courseid' => $courseid])) {
                continue;
            }
            $DB->update_record('local_ai_yared_course_topic', (object) [
                'id' => $topicid,
                'classdate' => $timestamp,
                'timemodified' => $now,
            ]);
            $updated++;
        }
        return $updated;
    }
}
