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
use local_ai_yared_course\local\ai_guard;
use local_ai_yared_course\local\build_store;
use local_ai_yared_course\local\course_cloner;
use local_ai_yared_course\local\topic_runner;
use local_ai_yared_course\task\build_course_task;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: build a course from a template (clone, sections, topics, teacher) in the background.
 *
 * It answers at once with the id of the build; the work runs in an ad hoc task and the CRM learns the
 * result by polling get_build_status or through the signed webhook. Repeating the call with the same
 * crm_ref returns the existing build and never creates a second course.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_course extends external_api {

    /** Limits that keep one request bounded. */
    public const MAX_SECTIONS = 20;
    public const MAX_TOPICS_PER_SECTION = 12;
    public const MAX_TOPICS = 120;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'crm_ref' => new external_value(PARAM_RAW_TRIMMED, 'Unique reference of the CRM (letters, digits . _ : -), max 100'),
            'template_courseid' => new external_value(PARAM_INT, 'Template course id (must be allowed in the plugin settings)'),
            'categoryid' => new external_value(PARAM_INT, 'Destination category id'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name (unique)'),
            'idnumber' => new external_value(PARAM_RAW_TRIMMED, 'Course id number (unique)', VALUE_DEFAULT, ''),
            'startdate' => new external_value(PARAM_TEXT, 'Start date YYYY-MM-DD', VALUE_DEFAULT, ''),
            'teacher' => new external_single_structure([
                'field' => new external_value(PARAM_ALPHA, 'email, username or idnumber'),
                'value' => new external_value(PARAM_RAW_TRIMMED, 'Value of that field'),
            ], 'Teacher that will own the course'),
            'keep_template_sections' => new external_value(PARAM_BOOL, 'Keep the sections of the template', VALUE_DEFAULT, false),
            'subsectionmode' => new external_value(PARAM_ALPHA, 'accordion or mosaic', VALUE_DEFAULT, 'accordion'),
            'generate_ai' => new external_value(PARAM_BOOL, 'Generate the content of every topic with AI', VALUE_DEFAULT, false),
            'options' => api::options_structure(),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'title' => new external_value(PARAM_TEXT, 'Section title'),
                    'summary' => new external_value(PARAM_RAW, 'Section summary', VALUE_DEFAULT, ''),
                    'tileicon' => new external_value(PARAM_RAW_TRIMMED, 'Tile icon (for example fa-folder)', VALUE_DEFAULT, ''),
                    'tilecolor' => new external_value(PARAM_INT, 'Tile colour number', VALUE_DEFAULT, 0),
                    'topics' => new external_multiple_structure(
                        new external_single_structure([
                            'title' => new external_value(PARAM_TEXT, 'Topic title'),
                            'summary' => new external_value(PARAM_RAW, 'Topic summary', VALUE_DEFAULT, ''),
                            'date' => new external_value(PARAM_TEXT, 'Class date YYYY-MM-DD', VALUE_DEFAULT, ''),
                            'age' => new external_value(PARAM_INT, 'Audience age (0 = use options.age)', VALUE_DEFAULT, 0),
                        ]),
                        'Topics of the section, created as subsections',
                        VALUE_DEFAULT,
                        []
                    ),
                ]),
                'Sections of the course'
            ),
        ]);
    }

    /**
     * Queue the build.
     *
     * @return array
     */
    public static function execute($crmref, $templatecourseid, $categoryid, $fullname, $shortname, $idnumber = '',
            $startdate = '', $teacher = [], $keeptemplatesections = false, $subsectionmode = 'accordion',
            $generateai = false, $options = [], $sections = []) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'crm_ref' => $crmref,
            'template_courseid' => $templatecourseid,
            'categoryid' => $categoryid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'idnumber' => $idnumber,
            'startdate' => $startdate,
            'teacher' => $teacher,
            'keep_template_sections' => $keeptemplatesections,
            'subsectionmode' => $subsectionmode,
            'generate_ai' => $generateai,
            'options' => $options,
            'sections' => $sections,
        ]);

        $category = \core_course_category::get($params['categoryid'], IGNORE_MISSING);
        if (!$category) {
            throw new \invalid_parameter_exception('categoryid does not exist');
        }
        $catcontext = \context_coursecat::instance($category->id);
        self::validate_context($catcontext);
        require_capability('local/ai_yared_course:apibuild', $catcontext);
        require_capability('moodle/course:create', $catcontext);

        // Same crm_ref: return what already exists, before counting it against the limit.
        $crmref = $params['crm_ref'];
        if (!preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $crmref)) {
            throw new \invalid_parameter_exception('crm_ref must have 1-100 characters: letters, digits and . _ : -');
        }
        $existing = build_store::get_by_ref($crmref);
        if ($existing) {
            return self::export($existing, false);
        }

        $limit = (int) (get_config('local_ai_yared_course', 'apiratelimit') ?: 10);
        if (ai_guard::rate_limited('build', (int) $USER->id, $limit, 60)) {
            throw new \moodle_exception('api_error_ratelimit', 'local_ai_yared_course');
        }

        $payload = self::validate_payload($params);
        $teacheruser = course_cloner::find_teacher($payload['teacher']['field'], $payload['teacher']['value']);

        [$build, $created] = build_store::create($crmref, (int) $USER->id, (int) $teacheruser->id,
            (int) $params['template_courseid'], $payload);
        if ($created) {
            build_course_task::queue((int) $build->id, (int) $USER->id);
        }
        return self::export($build, !$created);
    }

    /**
     * Validate the request and build the normalised payload stored with the build.
     *
     * @param array $p Validated parameters.
     * @return array
     * @throws \invalid_parameter_exception
     */
    protected static function validate_payload(array $p): array {
        global $DB;

        if (!in_array((int) $p['template_courseid'], build_store::allowed_templates(), true)) {
            throw new \invalid_parameter_exception('template_courseid is not an allowed template');
        }
        if (!$DB->record_exists('course', ['id' => $p['template_courseid']])) {
            throw new \invalid_parameter_exception('template_courseid does not exist');
        }
        $fullname = trim($p['fullname']);
        $shortname = trim($p['shortname']);
        if ($fullname === '' || \core_text::strlen($fullname) > 254 || $shortname === '' || \core_text::strlen($shortname) > 255) {
            throw new \invalid_parameter_exception('fullname/shortname are required (max 254/255 characters)');
        }
        $idnumber = trim($p['idnumber']);
        if (\core_text::strlen($idnumber) > 100) {
            throw new \invalid_parameter_exception('idnumber is too long');
        }
        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            throw new \invalid_parameter_exception('shortname is already in use');
        }
        if ($idnumber !== '' && $DB->record_exists('course', ['idnumber' => $idnumber])) {
            throw new \invalid_parameter_exception('idnumber is already in use');
        }
        $startdate = self::date($p['startdate'], 'startdate');
        $mode = in_array($p['subsectionmode'], ['accordion', 'mosaic'], true) ? $p['subsectionmode'] : 'accordion';

        $sections = $p['sections'];
        if (!$sections || count($sections) > self::MAX_SECTIONS) {
            throw new \invalid_parameter_exception('sections: between 1 and ' . self::MAX_SECTIONS . ' are accepted');
        }
        $options = topic_runner::sanitise_options(array_merge(topic_runner::default_options(), $p['options']));
        $total = 0;
        $clean = [];
        foreach ($sections as $section) {
            $title = trim($section['title']);
            if ($title === '') {
                throw new \invalid_parameter_exception('every section needs a title');
            }
            $topics = [];
            if (count($section['topics']) > self::MAX_TOPICS_PER_SECTION) {
                throw new \invalid_parameter_exception('a section accepts at most ' . self::MAX_TOPICS_PER_SECTION . ' topics');
            }
            foreach ($section['topics'] as $topic) {
                $ttitle = trim($topic['title']);
                if ($ttitle === '') {
                    throw new \invalid_parameter_exception('every topic needs a title');
                }
                $total++;
                $topics[] = [
                    'title' => \core_text::substr($ttitle, 0, 240),
                    'summary' => clean_param((string) $topic['summary'], PARAM_CLEANHTML),
                    'date' => self::date($topic['date'], 'topic date'),
                    'age' => (int) $topic['age'] > 0 ? (int) $topic['age'] : (int) $options['age'],
                ];
            }
            $clean[] = [
                'title' => \core_text::substr($title, 0, 255),
                'summary' => clean_param((string) $section['summary'], PARAM_CLEANHTML),
                'tileicon' => preg_match('/^[a-z0-9\- ]{0,60}$/', (string) $section['tileicon']) ? (string) $section['tileicon'] : '',
                'tilecolor' => (int) $section['tilecolor'],
                'topics' => $topics,
            ];
        }
        if ($total > self::MAX_TOPICS) {
            throw new \invalid_parameter_exception('at most ' . self::MAX_TOPICS . ' topics per course');
        }

        return [
            'fullname' => $fullname,
            'shortname' => $shortname,
            'idnumber' => $idnumber,
            'categoryid' => (int) $p['categoryid'],
            'startdate' => $startdate,
            'teacher' => ['field' => $p['teacher']['field'], 'value' => $p['teacher']['value']],
            'keep_template_sections' => !empty($p['keep_template_sections']),
            'subsectionmode' => $mode,
            'generate_ai' => !empty($p['generate_ai']),
            'options' => $options,
            'sections' => $clean,
        ];
    }

    /**
     * Parse an optional YYYY-MM-DD date.
     *
     * @param string $value Value.
     * @param string $name Field name for the error.
     * @return int Timestamp, 0 when empty.
     */
    protected static function date(string $value, string $name): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ($time = strtotime($value)) === false) {
            throw new \invalid_parameter_exception($name . ' must be YYYY-MM-DD');
        }
        return (int) $time;
    }

    /**
     * Response for a build.
     *
     * @param \stdClass $build Record.
     * @param bool $duplicate Whether it already existed.
     * @return array
     */
    protected static function export(\stdClass $build, bool $duplicate): array {
        return [
            'buildid' => (int) $build->id,
            'crm_ref' => $build->crmref,
            'status' => $build->status,
            'courseid' => (int) $build->courseid,
            'duplicate' => $duplicate,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'buildid' => new external_value(PARAM_INT, 'Build id'),
            'crm_ref' => new external_value(PARAM_RAW, 'CRM reference'),
            'status' => new external_value(PARAM_ALPHA, 'queued, running, done, partial or failed'),
            'courseid' => new external_value(PARAM_INT, 'Course id (0 until the course is created)'),
            'duplicate' => new external_value(PARAM_BOOL, 'True when the crm_ref already existed and nothing new was queued'),
        ]);
    }
}
