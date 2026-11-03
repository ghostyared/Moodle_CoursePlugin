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
 * Result table of a finished background job.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\local;

use html_table;
use html_writer;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the summary of every topic that a job created.
 */
class job_report {

    /**
     * Summary notification and table.
     *
     * @param \stdClass $course Course.
     * @param array $results One entry per topic: ['title', 'result', 'error'].
     * @param array $flags What was requested: findvideo, createpdf, createbank, createquiz, createassign, createforum.
     * @return string HTML.
     */
    public static function render(\stdClass $course, array $results, array $flags): string {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $context = \context_course::instance($course->id);
        $flags += ['findvideo' => 0, 'createpdf' => 0, 'createbank' => 0, 'createquiz' => 0, 'createassign' => 0,
            'createforum' => 0];
        $findvideo = $flags['findvideo'];
        $createpdf = $flags['createpdf'];
        $createbank = $flags['createbank'];
        $createquiz = $flags['createquiz'];
        $createassign = $flags['createassign'];
        $createforum = $flags['createforum'];

        ob_start();
        // Summary of every topic: section and the components that were actually created.
        $table = new html_table();
        $table->attributes['class'] = 'table table-sm table-striped align-middle generaltable';
        $table->head = [
            get_string('result_n', 'local_ai_yared_course'),
            get_string('result_topic', 'local_ai_yared_course'),
            get_string('result_provider', 'local_ai_yared_course'),
            get_string('result_section', 'local_ai_yared_course'),
            get_string('result_video', 'local_ai_yared_course'),
            get_string('result_pdf', 'local_ai_yared_course'),
            get_string('result_bank', 'local_ai_yared_course'),
            get_string('result_quiz', 'local_ai_yared_course'),
            get_string('result_assign', 'local_ai_yared_course'),
            get_string('result_forum', 'local_ai_yared_course'),
            get_string('result_warnings', 'local_ai_yared_course'),
        ];

        foreach ($results as $index => $entry) {
            if ($entry['error'] !== null) {
                $table->data[] = [
                    $index + 1,
                    s($entry['title']),
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                    html_writer::span(s($entry['error']), 'text-danger'),
                ];
                continue;
            }

            $result = $entry['result'];
            if (!empty($result['draft'])) {
                // Content generated and waiting for the teacher's approval: nothing exists in the course yet.
                $table->data[] = [
                    $index + 1,
                    s($entry['title']),
                    html_writer::span(!empty($result['profile']) ? s($result['profile']) : '—'),
                    html_writer::link(new moodle_url('/local/ai_yared_course/topicpreview.php',
                        ['id' => $course->id, 'draft' => (int) $result['draft']]),
                        get_string('preview_open', 'local_ai_yared_course'), ['class' => 'btn btn-primary btn-sm']),
                    $findvideo ? (!empty($result['hasvideo']) ? get_string('yes') : get_string('no')) : '—',
                    $createpdf ? (!empty($result['haspdf']) ? get_string('yes') : get_string('no')) : '—',
                    $createbank ? get_string('questions_short', 'local_ai_yared_course', (int) $result['questions']) : '—',
                    '—',
                    '—',
                    '—',
                    !empty($result['warnings'])
                        ? html_writer::tag('details',
                            html_writer::tag('summary', get_string('warnings_show', 'local_ai_yared_course',
                                count($result['warnings']))) . html_writer::alist($result['warnings'], ['class' => 'mb-0 small']))
                        : '—',
                ];
                continue;
            }
            $sectionurl = course_get_url($course, $result['sectionnum']);
            $section = $sectionurl ? html_writer::link($sectionurl, get_string('gotosection', 'local_ai_yared_course'))
                : s($result['sectionnum']);

            $quizlink = '—';
            $assignlink = '—';
            $forumlink = '—';
            foreach ($result['items'] as $item) {
                if ($item['modname'] === 'quiz' && $quizlink === '—') {
                    $quizlink = html_writer::link(new moodle_url('/mod/quiz/view.php', ['id' => $item['cmid']]),
                        get_string('view'), ['title' => $item['name']]);
                }
                if ($item['modname'] === 'forum' && $forumlink === '—') {
                    $forumlink = html_writer::link(new moodle_url('/mod/forum/view.php', ['id' => $item['cmid']]),
                        get_string('view'), ['title' => $item['name']]);
                }
                if ($item['modname'] === 'assign' && $assignlink === '—') {
                    $assignlink = html_writer::link(new moodle_url('/mod/assign/view.php', ['id' => $item['cmid']]),
                        get_string('view'), ['title' => $item['name']]);
                }
            }

            if (!empty($result['warnings'])) {
                $warnings = html_writer::tag('details',
                    html_writer::tag('summary', get_string('warnings_show', 'local_ai_yared_course', count($result['warnings']))) .
                    html_writer::alist($result['warnings'], ['class' => 'mb-0 small']));
            } else {
                $warnings = '—';
            }

            $providerlabel = !empty($result['profile']) ? s($result['profile']) : '—';
            $providertitle = trim((string) ($result['providername'] ?? '') . ' · ' .
                (string) ($result['model'] ?? ''), ' ·');
            $providercell = html_writer::span($providerlabel, '', ['title' => s($providertitle)]);

            $video = $findvideo ? ($result['hasvideo'] ? get_string('yes') : get_string('no')) : '—';
            $pdf = $createpdf ? ($result['haspdf'] ? get_string('yes') : get_string('no')) : '—';
            $bank = '—';
            if ($createbank) {
                $banklabel = get_string('questions_short', 'local_ai_yared_course', (int) $result['questions']);
                if (!empty($result['categoryid'])) {
                    $bankurl = new moodle_url('/question/edit.php', ['courseid' => $course->id,
                        'cat' => $result['categoryid'] . ',' . $context->id]);
                    $bank = html_writer::link($bankurl, $banklabel);
                } else {
                    $bank = $banklabel;
                }
            }

            $table->data[] = [
                $index + 1,
                s($entry['title']),
                $providercell,
                $section,
                $video,
                $pdf,
                $bank,
                $createquiz ? $quizlink : '—',
                $createassign ? $assignlink : '—',
                $createforum ? $forumlink : '—',
                $warnings,
            ];
        }

        echo html_writer::start_div('table-responsive');
        echo html_writer::table($table);
        echo html_writer::end_div();

        if (!$createquiz && !array_filter($results, function ($entry) {
            return !empty($entry['result']['draft']);
        })) {
            echo html_writer::tag('p', get_string('result_noquiz_note', 'local_ai_yared_course'), ['class' => 'text-muted']);
        }
        return ob_get_clean();
    }
}
