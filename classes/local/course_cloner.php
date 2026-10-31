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

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->libdir . '/enrollib.php');

/**
 * Builds a course from a template: clone, clean, create the sections and enrol the teacher.
 *
 * Everything here runs as the service user of the CRM, so the permissions the role grants are the ones
 * that matter. The topics (subsections with the generated material) are created afterwards by the
 * normal job runner, as the teacher.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_cloner {

    /**
     * Find the teacher by email, username or idnumber. It must match exactly one active account.
     *
     * @param string $field email|username|idnumber.
     * @param string $value Value.
     * @return \stdClass
     * @throws \moodle_exception When there is no match or it is ambiguous.
     */
    public static function find_teacher(string $field, string $value): \stdClass {
        global $DB, $CFG;
        if (!in_array($field, ['email', 'username', 'idnumber'], true) || trim($value) === '') {
            throw new \moodle_exception('api_error_teacher', 'local_ai_yared_course');
        }
        $value = $field === 'username' ? \core_text::strtolower(trim($value)) : trim($value);
        $users = $DB->get_records('user', [
            $field => $value, 'deleted' => 0, 'suspended' => 0, 'mnethostid' => $CFG->mnet_localhost_id,
        ]);
        if (count($users) !== 1) {
            throw new \moodle_exception('api_error_teacher', 'local_ai_yared_course');
        }
        return reset($users);
    }

    /**
     * Clone the template without user data and leave the new course hidden.
     *
     * @param int $templateid Template course id.
     * @param string $fullname Full name.
     * @param string $shortname Short name.
     * @param string $idnumber Id number ('' for none).
     * @param int $categoryid Destination category.
     * @param int $startdate Start date timestamp, 0 to keep the template's.
     * @return \stdClass New course record.
     * @throws \moodle_exception If a name is in use or the clone fails.
     */
    public static function clone_template(int $templateid, string $fullname, string $shortname, string $idnumber,
            int $categoryid, int $startdate): \stdClass {
        global $DB;

        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            throw new \moodle_exception('api_error_shortname', 'local_ai_yared_course', '', s($shortname));
        }
        if ($idnumber !== '' && $DB->record_exists('course', ['idnumber' => $idnumber])) {
            throw new \moodle_exception('api_error_idnumber', 'local_ai_yared_course', '', s($idnumber));
        }

        // Backup and restore of the template in the same site, without users. It checks
        // moodle/backup:backupcourse, moodle/restore:restorecourse and moodle/course:create for this user.
        $new = \core_course_external::duplicate_course($templateid, $fullname, $shortname, $categoryid, 0,
            [['name' => 'users', 'value' => 0]]);
        $course = get_course((int) $new['id']);

        $update = ['id' => $course->id, 'visible' => 0, 'visibleold' => 0, 'timemodified' => time()];
        if ($idnumber !== '') {
            $update['idnumber'] = $idnumber;
        }
        if ($startdate > 0) {
            $update['startdate'] = $startdate;
        }
        $DB->update_record('course', (object) $update);
        rebuild_course_cache($course->id, true);
        return get_course($course->id);
    }

    /**
     * Make sure the course uses Formato-Mariategui, the format that supports the section hierarchy.
     *
     * @param \stdClass $course Course.
     * @return \stdClass Course.
     * @throws \moodle_exception If the format is not installed.
     */
    public static function ensure_flextiles(\stdClass $course): \stdClass {
        if (\core_component::get_plugin_directory('format', 'flextiles') === null) {
            throw new \moodle_exception('api_error_noformat', 'local_ai_yared_course');
        }
        if ((string) $course->format !== 'flextiles') {
            update_course((object) ['id' => $course->id, 'format' => 'flextiles']);
            rebuild_course_cache($course->id, true);
            $course = get_course($course->id);
        }
        return $course;
    }

    /**
     * Delete the sections of the template (everything except section 0), last to first.
     *
     * @param \stdClass $course Course.
     */
    public static function clear_sections(\stdClass $course): void {
        global $DB;
        $sections = $DB->get_records_select('course_sections', 'course = ? AND section > 0', [$course->id], 'section DESC');
        foreach ($sections as $section) {
            // A parent may have removed its children already.
            if ($DB->record_exists('course_sections', ['id' => $section->id])) {
                course_delete_section($course, $section, true);
            }
        }
        rebuild_course_cache($course->id, true);
    }

    /**
     * Create the top-level sections with name, summary and tile options.
     *
     * @param \stdClass $course Course.
     * @param array $sections Each: title, summary, tileicon, tilecolor.
     * @return int[] Section numbers in the same order as $sections.
     */
    public static function create_sections(\stdClass $course, array $sections): array {
        global $DB;

        $format = course_get_format($course);
        $icons = method_exists($format, 'get_tile_icon_options') ? array_keys($format->get_tile_icon_options()) : [];
        $colours = method_exists($format, 'get_tile_colour_options') ? array_keys($format->get_tile_colour_options()) : [];

        $numbers = [];
        foreach ($sections as $section) {
            $number = (int) $format->create_new_section(0);
            $record = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $number], '*', MUST_EXIST);
            course_update_section($course, $record, [
                'name' => \core_text::substr((string) $section['title'], 0, 255),
                'summary' => clean_param((string) ($section['summary'] ?? ''), PARAM_CLEANHTML),
                'summaryformat' => FORMAT_HTML,
            ]);

            $options = ['id' => $record->id];
            if (!empty($section['tileicon']) && in_array($section['tileicon'], $icons, true)) {
                $options['tileicon'] = $section['tileicon'];
            }
            if (!empty($section['tilecolor']) && in_array((int) $section['tilecolor'], array_map('intval', $colours), true)) {
                $options['tilecolor'] = (int) $section['tilecolor'];
            }
            if (count($options) > 1) {
                $format->update_section_format_options($options);
            }
            $numbers[] = $number;
        }
        rebuild_course_cache($course->id, true);
        return $numbers;
    }

    /**
     * Enrol the teacher as editing teacher with the manual enrolment method.
     *
     * The service role must be allowed to assign that role (Allow role assignments matrix), which is checked here.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $teacher Teacher.
     * @throws \moodle_exception If the manual enrolment is unavailable or the role cannot be assigned.
     */
    public static function enrol_teacher(\stdClass $course, \stdClass $teacher): void {
        global $DB;

        $context = \context_course::instance($course->id);
        require_capability('enrol/manual:enrol', $context);

        $role = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        if (!array_key_exists((int) $role->id, get_assignable_roles($context))) {
            throw new \moodle_exception('api_error_assignrole', 'local_ai_yared_course');
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('api_error_noenrol', 'local_ai_yared_course');
        }
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        }
        if (!$instance) {
            throw new \moodle_exception('api_error_noenrol', 'local_ai_yared_course');
        }
        if ((int) $instance->status !== ENROL_INSTANCE_ENABLED) {
            $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
        }
        $plugin->enrol_user($instance, $teacher->id, $role->id);
    }
}
