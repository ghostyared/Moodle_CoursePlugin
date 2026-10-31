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

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * Publishes the syllabus of the academic period as a label at the top of the course.
 *
 * The label is created once and updated afterwards, so the teacher can also edit it by hand: the
 * plugin only rewrites it when the topics are saved or generated again.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus {

    /** idnumber used to recognise the syllabus label. */
    public const IDNUMBER = 'local_ai_yared_course_syllabus';

    /**
     * Create or update the syllabus label in the general section (section 0).
     *
     * @param \stdClass $course Course.
     * @param \stdClass[] $topics Topics, indexed by id.
     * @return void
     */
    public static function publish(\stdClass $course, array $topics): void {
        global $DB;

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);
        $html = self::render($topics);

        $sql = "SELECT cm.id AS cmid, l.id AS labelid
                  FROM {course_modules} cm
                  JOIN {label} l ON l.id = cm.instance
                 WHERE cm.course = :courseid AND cm.module = :moduleid AND cm.idnumber = :idnumber";
        $existing = $DB->get_record_sql($sql, [
            'courseid' => $course->id,
            'moduleid' => $moduleid,
            'idnumber' => self::IDNUMBER,
        ]);

        // Nothing to publish and nothing to update.
        if (empty($topics) && !$existing) {
            return;
        }

        if ($existing) {
            // Update the label in place: an update_record on the label is enough for the course page.
            $DB->update_record('label', (object) [
                'id' => $existing->labelid,
                'intro' => $html,
                'introformat' => FORMAT_HTML,
                'timemodified' => time(),
            ]);
            rebuild_course_cache($course->id, true);
            return;
        }

        $info = (object) [
            'modulename' => 'label',
            'module' => $moduleid,
            'course' => $course->id,
            'section' => 0,
            'name' => get_string('syllabus_name', 'local_ai_yared_course'),
            'intro' => $html,
            'introformat' => FORMAT_HTML,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => self::IDNUMBER,
            'groupmode' => 0,
            'groupingid' => 0,
            'availability' => null,
            'completion' => 0,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
            'conditiongradegroup' => [],
            'conditionfieldgroup' => [],
            'conditioncompletiongroup' => [],
        ];
        add_moduleinfo($info, $course);
        rebuild_course_cache($course->id, true);
    }

    /**
     * Build the HTML of the syllabus.
     *
     * @param \stdClass[] $topics Topics, indexed by id.
     * @return string HTML.
     */
    public static function render(array $topics): string {
        $html = '<div style="border:1px solid rgba(0,0,0,.12);border-radius:16px;overflow:hidden;margin:0 0 .5rem">';
        $html .= '<div style="background:linear-gradient(135deg,#1e88e5,#0d47a1);color:#ffffff;padding:1rem 1.4rem">';
        $html .= '<h3 style="margin:0;color:#ffffff;font-weight:700">📋 '
            . htmlspecialchars(get_string('syllabus_heading', 'local_ai_yared_course'), ENT_QUOTES, 'UTF-8') . '</h3>';
        $html .= '</div>';

        if (empty($topics)) {
            $html .= '<div style="padding:1rem 1.4rem">'
                . htmlspecialchars(get_string('syllabus_empty', 'local_ai_yared_course'), ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '</div>';
            return $html;
        }

        $html .= '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">';
        $html .= '<thead><tr style="background:rgba(127,127,127,.08)">';
        foreach (['syllabus_col_n', 'syllabus_col_topic', 'syllabus_col_date'] as $key) {
            $html .= '<th style="text-align:left;padding:.6rem 1rem;font-size:.8rem;text-transform:uppercase;'
                . 'letter-spacing:.05em">' . htmlspecialchars(get_string($key, 'local_ai_yared_course'), ENT_QUOTES, 'UTF-8')
                . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $number = 0;
        foreach ($topics as $topic) {
            $number++;
            $date = (int) $topic->classdate > 0 ? userdate((int) $topic->classdate, '%d-%m-%Y')
                : get_string('syllabus_nodate', 'local_ai_yared_course');
            $html .= '<tr style="border-top:1px solid rgba(0,0,0,.08)">';
            $html .= '<td style="padding:.6rem 1rem;white-space:nowrap">' . $number . '</td>';
            $html .= '<td style="padding:.6rem 1rem"><strong>'
                . htmlspecialchars($topic->title, ENT_QUOTES, 'UTF-8') . '</strong></td>';
            $html .= '<td style="padding:.6rem 1rem;white-space:nowrap">' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8')
                . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div></div>';
        return $html;
    }
}
