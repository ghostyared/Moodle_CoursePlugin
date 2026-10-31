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
 * Progress of a background generation job: live status, cancel button and final report.
 *
 * Only the teacher who started the job (or a site administrator) can open it.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_yared_course\local\job_report;
use local_ai_yared_course\local\job_store;

$id = required_param('id', PARAM_INT);
$job = job_store::get($id);
if (!$job) {
    throw new moodle_exception('job_notfound', 'local_ai_yared_course');
}
$course = get_course((int) $job->courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:use', $context);

// Jobs are private: another teacher cannot see or cancel them.
if ((int) $job->userid !== (int) $USER->id && !is_siteadmin()) {
    throw new moodle_exception('job_notfound', 'local_ai_yared_course');
}

$url = new moodle_url('/local/ai_yared_course/job.php', ['id' => $job->id]);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$formurl = new moodle_url('/local/ai_yared_course/index.php', ['id' => $course->id]);

// Cancel request.
if (optional_param('cancel', 0, PARAM_INT)) {
    require_sesskey();
    job_store::request_cancel($job);
    redirect($url, get_string('job_cancel_sent', 'local_ai_yared_course'), null,
        \core\output\notification::NOTIFY_INFO);
}

$job = job_store::check_stalled($job);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('job_title', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));
// The page only reads: release the session so other tabs of the teacher are not blocked.
\core\session\manager::write_close();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('job_title', 'local_ai_yared_course'));

$finished = job_store::is_finished($job);

echo html_writer::start_div('', ['id' => 'aiyared-job', 'data-finished' => $finished ? '1' : '0']);

// Live part (hidden when the job finished).
$total = max(1, (int) $job->total);
$percent = (int) round(100 * ((int) $job->done + (int) $job->failed) / $total);
echo html_writer::start_div('card mb-3', ['id' => 'aiyared-job-live', 'style' => $finished ? 'display:none' : '']);
echo html_writer::start_div('card-body');
echo html_writer::tag('p', html_writer::span('', 'spinner-border spinner-border-sm me-2', ['role' => 'status']) .
    html_writer::tag('strong', get_string('job_running_title', 'local_ai_yared_course')) . ' ' .
    html_writer::span(get_string('job_status_' . $job->status, 'local_ai_yared_course'), 'badge bg-secondary',
        ['id' => 'aiyared-job-status']), ['class' => 'mb-2']);
echo html_writer::div(html_writer::div('', 'progress-bar', ['id' => 'aiyared-job-bar', 'role' => 'progressbar',
    'style' => 'width:' . $percent . '%']), 'progress mb-2');
echo html_writer::tag('p', get_string('job_progress', 'local_ai_yared_course', (object) [
    'done' => (int) $job->done + (int) $job->failed, 'total' => (int) $job->total]),
    ['class' => 'mb-2 small text-muted', 'id' => 'aiyared-job-count']);
$lines = $job->log !== '' ? explode("\n", (string) $job->log) : [];
echo html_writer::div(implode('', array_map(function ($line) {
    return html_writer::div(s($line), 'aiyared-progress-line');
}, array_slice($lines, -15))), 'aiyared-progress border rounded p-2 mb-3 bg-light', ['id' => 'aiyared-job-log']);
echo html_writer::tag('p', get_string('job_close_note', 'local_ai_yared_course'), ['class' => 'small text-muted mb-2']);

$cancelurl = new moodle_url($url, ['cancel' => 1, 'sesskey' => sesskey()]);
echo html_writer::link($cancelurl, get_string('job_cancel', 'local_ai_yared_course'), [
    'class' => 'btn btn-outline-danger', 'id' => 'aiyared-job-cancel',
    'onclick' => 'return confirm(' . json_encode(get_string('job_cancel_confirm', 'local_ai_yared_course')) . ');',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Final part.
if ($finished) {
    if ($job->status === job_store::FAILED && trim((string) $job->error) !== '') {
        echo $OUTPUT->notification(s($job->error), 'error');
    }
    if ($job->status === job_store::CANCELLED) {
        echo $OUTPUT->notification(get_string('job_cancelled_note', 'local_ai_yared_course'), 'warning');
    } else if ($job->status === job_store::DONE) {
        echo $OUTPUT->notification(get_string('batch_created', 'local_ai_yared_course', (object) [
            'done' => (int) $job->done, 'total' => (int) $job->total, 'failed' => (int) $job->failed]),
            (int) $job->failed > 0 ? 'warning' : 'success');
    }
    $results = job_store::results($job);
    if ($results) {
        $options = job_store::options($job);
        echo html_writer::start_div('table-responsive');
        echo job_report::render($course, $results, [
            'findvideo' => !empty($options['findvideo']), 'createpdf' => !empty($options['createpdf']),
            'createbank' => !empty($options['createbank']), 'createquiz' => !empty($options['createquiz']),
            'createassign' => !empty($options['createassign']), 'createforum' => !empty($options['createforum']),
        ]);
        echo html_writer::end_div();
    }
    echo html_writer::div(
        $OUTPUT->single_button($courseurl, get_string('gotocourse', 'local_ai_yared_course'), 'get', ['type' => 'primary']) .
        $OUTPUT->single_button($formurl, get_string('createanotherbatch', 'local_ai_yared_course'), 'get'),
        'd-flex flex-wrap gap-2 mb-3');
} else {
    // Poll the status every 3 seconds; reload when the job finishes to show the final report.
    $statusurl = (new moodle_url('/local/ai_yared_course/jobstatus.php', ['id' => $job->id]))->out(false);
    $labels = [];
    foreach ([job_store::QUEUED, job_store::RUNNING, job_store::DONE, job_store::FAILED, job_store::CANCELLED] as $st) {
        $labels[$st] = get_string('job_status_' . $st, 'local_ai_yared_course');
    }
    $countlabel = get_string('job_progress', 'local_ai_yared_course', (object) ['done' => '__D__', 'total' => '__T__']);
    echo '<script>(function(){"use strict";var url=' . json_encode($statusurl) . ',labels=' . json_encode($labels)
        . ',cl=' . json_encode($countlabel) . ',fails=0;'
        . 'function tick(){fetch(url,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){'
        . 'fails=0;if(d.finished){window.location.reload();return;}'
        . 'document.getElementById("aiyared-job-status").textContent=labels[d.status]||d.status;'
        . 'document.getElementById("aiyared-job-bar").style.width=d.percent+"%";'
        . 'document.getElementById("aiyared-job-count").textContent=cl.replace("__D__",d.processed).replace("__T__",d.total);'
        . 'var log=document.getElementById("aiyared-job-log");log.innerHTML="";'
        . 'd.log.forEach(function(l){var e=document.createElement("div");e.className="aiyared-progress-line";e.textContent=l;log.appendChild(e);});'
        . 'log.scrollTop=log.scrollHeight;setTimeout(tick,3000);'
        . '}).catch(function(){fails++;setTimeout(tick,Math.min(30000,3000*(fails+1)));});}'
        . 'setTimeout(tick,2000);})();</script>';
}
echo html_writer::end_div();

echo $OUTPUT->footer();
