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
 * Library callbacks for local_ai_yared_course.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the AI course builder and question bank links to the course navigation ("More" menu).
 *
 * The internal topic designer page (topics.php) is deliberately not linked here: the main
 * "Yared.AI Creador de Material" wizard is the supported interface for teachers.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 */
function local_ai_yared_course_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (!has_capability('local/ai_yared_course:use', $context) || !has_capability('moodle/course:manageactivities', $context)) {
        return;
    }
    $url = new moodle_url('/local/ai_yared_course/index.php', ['id' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'local_ai_yared_course'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_ai_yared_course',
        new pix_icon('i/course', '')
    );

    $bankurl = new moodle_url('/local/ai_yared_course/bank.php', ['id' => $course->id]);
    $navigation->add(
        get_string('bank_menu', 'local_ai_yared_course'),
        $bankurl,
        navigation_node::TYPE_SETTING,
        null,
        'local_ai_yared_course_bank',
        null
    );

    if (has_capability('mod/quiz:addinstance', $context)) {
        $quizurl = new moodle_url('/local/ai_yared_course/quiz.php', ['id' => $course->id]);
        $navigation->add(
            get_string('quiz_wizard_menu', 'local_ai_yared_course'),
            $quizurl,
            navigation_node::TYPE_SETTING,
            null,
            'local_ai_yared_course_quiz',
            new pix_icon('i/grades', '')
        );
    }
}

/**
 * Status checks shown in Site administration > Reports > Status.
 *
 * @return \core\check\check[]
 */
function local_ai_yared_course_status_checks(): array {
    return [new \local_ai_yared_course\check\pymupdf(), new \local_ai_yared_course\check\commons()];
}

/**
 * Serve the crops and the manifest of a pending PDF/image import.
 *
 * Import review files live in the user context (a temporary review, not a course); the pictures of a class presentation
 * live in the course context.
 *
 * @param stdClass $course Course, when the URL includes it.
 * @param stdClass|null $cm Course module, when the URL includes it.
 * @param context $context Context of the URL.
 * @param string $filearea File area.
 * @param array $args Extra path arguments.
 * @param bool $forcedownload Whether the download is forced.
 * @param array $options Additional options.
 * @return bool False when the file cannot be served.
 */
function local_ai_yared_course_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload,
        array $options = []) {
    global $USER;

    if ($filearea === \local_ai_yared_course\local\slides::AREA && $context->contextlevel === CONTEXT_COURSE) {
        // Pictures of a class presentation: served with the same rules as the presentation page.
        $course = get_course($context->instanceid);
        require_login($course);
        $itemid = (int) array_shift($args);
        $filename = array_pop($args);
        if ($args || $filename === null) {
            return false;
        }
        $deck = \local_ai_yared_course\local\slides::load($context, $itemid);
        if (!$deck || !\local_ai_yared_course\local\slides::can_view($course, $context, $deck)) {
            return false;
        }
        $file = get_file_storage()->get_file($context->id, 'local_ai_yared_course', \local_ai_yared_course\local\slides::AREA,
            $itemid, '/', clean_filename($filename));
        if (!$file || $file->is_directory() || $file->get_filename() === \local_ai_yared_course\local\slides::DECK) {
            return false;
        }
        send_stored_file($file, 0, 0, false, $options);
        return true;
    }

    if ($filearea !== 'import' || $context->contextlevel !== CONTEXT_USER) {
        return false;
    }
    require_login();

    if ((int) $context->instanceid !== (int) $USER->id
            && !has_capability('moodle/site:config', context_system::instance())) {
        return false;
    }

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    // The import area only stores files at its root.
    if ($args || $filename === null) {
        return false;
    }
    $file = get_file_storage()->get_file($context->id, 'local_ai_yared_course', 'import', $itemid,
        '/', clean_filename($filename));
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, false, $options);
    return true;
}
