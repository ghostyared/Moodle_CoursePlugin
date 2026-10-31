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

namespace local_ai_yared_course\task;

use local_ai_yared_course\local\build_store;
use local_ai_yared_course\local\course_cloner;
use local_ai_yared_course\local\job_runner;
use local_ai_yared_course\local\job_store;
use local_ai_yared_course\local\syllabus;
use local_ai_yared_course\local\topic_store;
use local_ai_yared_course\local\webhook;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a course requested by the CRM: clone the template, create the sections, enrol the teacher and
 * create the topics (optionally with AI) as subsections.
 *
 * It runs as the service user of the CRM (set_userid) for the structure, and switches to the teacher to
 * generate the material, so the service account never needs permission to create activities or questions.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_course_task extends \core\task\adhoc_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_build_course', 'local_ai_yared_course');
    }

    /**
     * Queue a build to run with the service user.
     *
     * @param int $buildid Build id.
     * @param int $userid Service user.
     */
    public static function queue(int $buildid, int $userid): void {
        $task = new self();
        $task->set_custom_data(['buildid' => $buildid]);
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run. Every failure is stored in the build and nothing is thrown: Moodle would retry the task and
     * the course could be created twice.
     */
    public function execute() {
        global $DB, $USER;

        $data = $this->get_custom_data();
        $build = build_store::get((int) ($data->buildid ?? 0));
        if (!$build || $build->status !== build_store::QUEUED) {
            return;
        }
        $id = (int) $build->id;
        build_store::update($id, ['status' => build_store::RUNNING]);
        \core_php_time_limit::raise(0);

        $payload = json_decode((string) $build->payload, true) ?: [];
        $status = build_store::DONE;
        $error = '';
        $result = ['sections' => [], 'topics_total' => 0, 'topics_ok' => 0, 'topics_failed' => 0];
        $courseid = 0;

        try {
            $teacher = $DB->get_record('user', ['id' => (int) $build->teacherid, 'deleted' => 0], '*', MUST_EXIST);

            // 1-4. Clone, clean and create the sections.
            $course = course_cloner::clone_template((int) $build->templateid, $payload['fullname'], $payload['shortname'],
                (string) $payload['idnumber'], (int) $payload['categoryid'], (int) $payload['startdate']);
            $courseid = (int) $course->id;
            build_store::update($id, ['courseid' => $courseid]);

            $course = course_cloner::ensure_flextiles($course);
            if (empty($payload['keep_template_sections'])) {
                course_cloner::clear_sections($course);
            }
            $numbers = course_cloner::create_sections($course, $payload['sections']);

            // 5. The teacher can see and edit the course.
            course_cloner::enrol_teacher($course, $teacher);

            // 6. Syllabus and topics.
            $rows = [];
            $jobtopics = [];
            $position = [];
            foreach ($payload['sections'] as $si => $section) {
                $result['sections'][$si] = ['title' => $section['title'], 'sectionnum' => $numbers[$si], 'topics' => []];
                foreach ($section['topics'] as $topic) {
                    $rows[] = ['id' => 0, 'title' => $topic['title'], 'summary' => $topic['summary'],
                        'classdate' => (int) $topic['date'], 'age' => (int) $topic['age']];
                    $jobtopics[] = ['title' => $topic['title'], 'summary' => $topic['summary'], 'age' => (int) $topic['age'],
                        'classdate' => (int) $topic['date'], 'sectionnum' => $numbers[$si]];
                    $position[] = $si;
                }
            }
            $result['topics_total'] = count($jobtopics);

            if ($rows) {
                topic_store::save($courseid, $rows);
                $stored = array_values(topic_store::get_topics($courseid));
                syllabus::publish($course, topic_store::get_topics($courseid));

                $o = $payload['options'];
                $jobid = job_store::create((int) $teacher->id, $courseid, [
                    'topics' => $jobtopics,
                    'ai' => !empty($payload['generate_ai']),
                    'language' => $o['language'],
                    'sectionmode' => 'existing',
                    'sectionnum' => 0,
                    'questioncount' => (int) $o['questioncount'],
                    'findvideo' => !empty($o['findvideo']),
                    'createbank' => !empty($o['createbank']),
                    'createquiz' => !empty($o['createquiz']),
                    'createassign' => !empty($o['createassign']),
                    'createpdf' => !empty($o['createpdf']),
                    'createforum' => !empty($o['createforum']),
                    'usesubsection' => true,
                    'subsectionmode' => $payload['subsectionmode'],
                    'hidesection' => false,
                ], count($jobtopics));
                build_store::update($id, ['jobid' => $jobid]);

                // The material is created with the permissions of the teacher, who is now editing teacher of the course.
                $serviceuser = clone $USER;
                \core\cron::setup_user($teacher);
                try {
                    job_runner::run(job_store::get($jobid));
                } finally {
                    \core\cron::setup_user($serviceuser);
                }

                $job = job_store::get($jobid);
                $results = job_store::results($job);
                foreach ($jobtopics as $i => $jobtopic) {
                    $item = $results[$i] ?? null;
                    $entry = ['title' => $jobtopic['title'], 'status' => 'skipped', 'sectionnum' => 0,
                        'sectionid' => 0, 'warnings' => [], 'error' => ''];
                    if ($item && !empty($item['result'])) {
                        $r = $item['result'];
                        $entry['status'] = 'ok';
                        $entry['sectionnum'] = (int) ($r['sectionnum'] ?? 0);
                        $entry['sectionid'] = (int) ($r['sectionid'] ?? 0);
                        $entry['warnings'] = array_values((array) ($r['warnings'] ?? []));
                        if (isset($stored[$i])) {
                            topic_store::mark_generated((int) $stored[$i]->id, $entry['sectionid'], $entry['sectionnum']);
                        }
                        $result['topics_ok']++;
                    } else if ($item) {
                        $entry['status'] = 'error';
                        $entry['error'] = (string) ($item['error'] ?? '');
                        $result['topics_failed']++;
                    }
                    $result['sections'][$position[$i]]['topics'][] = $entry;
                }
                if ($job->status !== job_store::DONE || $result['topics_failed'] > 0
                        || $result['topics_ok'] < $result['topics_total']) {
                    $status = build_store::PARTIAL;
                    $error = (string) $job->error;
                }
            }
            rebuild_course_cache($courseid, true);
        } catch (\Throwable $e) {
            $status = build_store::FAILED;
            $error = $e->getMessage();
            debugging('local_ai_yared_course build ' . $id . ' failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                DEBUG_DEVELOPER);
        }

        $final = $DB->get_record(build_store::TABLE, ['id' => $id], '*', MUST_EXIST);
        build_store::update($id, [
            'status' => $status,
            'courseid' => $courseid ?: (int) $final->courseid,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'error' => $error,
            'timefinished' => time(),
        ]);

        \local_ai_yared_course\local\usage_log::event(\local_ai_yared_course\local\usage_log::BUILD, $courseid,
            (string) ($payload['shortname'] ?? ''), 0,
            ['topics' => (int) $result['topics_total'], 'ok' => (int) $result['topics_ok'], 'failed' => (int) $result['topics_failed']],
            $status === build_store::DONE ? 'ok' : ($status === build_store::PARTIAL ? 'warn' : 'error'), $error,
            (int) $build->teacherid);

        try {
            $build = build_store::get($id);
            build_store::update($id, ['webhookstatus' => webhook::send($build)]);
        } catch (\Throwable $e) {
            build_store::update($id, ['webhookstatus' => 'failed']);
        }
    }
}
