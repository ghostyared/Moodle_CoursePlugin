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
 * JSON status of a background job, polled by the progress page.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_yared_course\local\job_store;

$id = required_param('id', PARAM_INT);
$job = job_store::get($id);
if (!$job) {
    throw new moodle_exception('job_notfound', 'local_ai_yared_course');
}
$course = get_course((int) $job->courseid);
require_login($course);
require_capability('local/ai_yared_course:use', context_course::instance($course->id));
if ((int) $job->userid !== (int) $USER->id && !is_siteadmin()) {
    throw new moodle_exception('job_notfound', 'local_ai_yared_course');
}
\core\session\manager::write_close();

$job = job_store::check_stalled($job);
$total = max(1, (int) $job->total);
$processed = (int) $job->done + (int) $job->failed;
$lines = $job->log !== '' ? explode("\n", (string) $job->log) : [];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => $job->status,
    'finished' => job_store::is_finished($job),
    'processed' => $processed,
    'total' => (int) $job->total,
    'percent' => min(100, (int) round(100 * $processed / $total)),
    'log' => array_slice($lines, -15),
]);
