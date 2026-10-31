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
 * External functions of local_ai_yared_course, for other applications.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ai_yared_course_create_course' => [
        'classname' => 'local_ai_yared_course\external\create_course',
        'methodname' => 'execute',
        'description' => 'Create a course with its designed topics, optionally generating the content with AI.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'moodle/course:create',
    ],
    'local_ai_yared_course_create_topic' => [
        'classname' => 'local_ai_yared_course\external\create_topic',
        'methodname' => 'execute',
        'description' => 'Create or update a topic of a course and optionally generate its content.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/ai_yared_course:manage',
    ],
    'local_ai_yared_course_list_topics' => [
        'classname' => 'local_ai_yared_course\external\list_topics',
        'methodname' => 'execute',
        'description' => 'List the topics designed in a course.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/ai_yared_course:manage',
    ],
    'local_ai_yared_course_list_courses' => [
        'classname' => 'local_ai_yared_course\external\list_courses',
        'methodname' => 'execute',
        'description' => 'List the courses that have topics designed with the AI topic builder.',
        'type' => 'read',
        'ajax' => false,
    ],
    'local_ai_yared_course_update_syllabus' => [
        'classname' => 'local_ai_yared_course\external\update_syllabus',
        'methodname' => 'execute',
        'description' => 'Update the class dates of the topics and republish the syllabus.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/ai_yared_course:manage',
    ],
    'local_ai_yared_course_build_course' => [
        'classname' => 'local_ai_yared_course\external\build_course',
        'methodname' => 'execute',
        'description' => 'Build a course from a template (clone, sections, topics, teacher) in the background.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/ai_yared_course:apibuild',
    ],
    'local_ai_yared_course_get_build_status' => [
        'classname' => 'local_ai_yared_course\external\get_build_status',
        'methodname' => 'execute',
        'description' => 'State of a course build requested through the API.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/ai_yared_course:apibuild',
    ],
    'local_ai_yared_course_list_templates' => [
        'classname' => 'local_ai_yared_course\external\list_templates',
        'methodname' => 'execute',
        'description' => 'Template courses that can be used to build new courses.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/ai_yared_course:apibuild',
    ],
];

$services = [
    'Yared.AI Creador de Material' => [
        'functions' => [
            'local_ai_yared_course_create_course',
            'local_ai_yared_course_create_topic',
            'local_ai_yared_course_list_topics',
            'local_ai_yared_course_list_courses',
            'local_ai_yared_course_update_syllabus',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'local_ai_yared_course',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
    'Yared.AI CRM Docentes' => [
        'functions' => [
            'local_ai_yared_course_build_course',
            'local_ai_yared_course_get_build_status',
            'local_ai_yared_course_list_templates',
            'core_webservice_get_site_info',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'local_ai_yared_course_crm',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
