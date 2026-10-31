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
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_yared_course\local\build_store;
use local_ai_yared_course\local\job_store;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: state of a course build.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_build_status extends external_api {

    /** Seconds without any progress after which a running build is reported as failed. */
    protected const STALL_SECONDS = 10800;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'buildid' => new external_value(PARAM_INT, 'Build id (0 to use crm_ref)', VALUE_DEFAULT, 0),
            'crm_ref' => new external_value(PARAM_RAW_TRIMMED, 'CRM reference', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get the state.
     *
     * @param int $buildid Build id.
     * @param string $crmref CRM reference.
     * @return array
     */
    public static function execute($buildid = 0, $crmref = '') {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['buildid' => $buildid, 'crm_ref' => $crmref]);
        self::validate_context(\context_system::instance());

        $build = $params['buildid'] > 0 ? build_store::get((int) $params['buildid'])
            : ($params['crm_ref'] !== '' ? build_store::get_by_ref($params['crm_ref']) : null);
        // Only the account that requested a build (or a site administrator) can see it.
        if (!$build || ((int) $build->requesterid !== (int) $USER->id && !is_siteadmin())) {
            throw new \moodle_exception('api_error_notfound', 'local_ai_yared_course');
        }

        $total = 0;
        $processed = 0;
        $step = '';
        $lastactivity = (int) $build->timemodified;
        if ((int) $build->jobid > 0 && ($job = job_store::get((int) $build->jobid))) {
            $total = (int) $job->total;
            $processed = (int) $job->done + (int) $job->failed;
            $step = (string) $job->step;
            $lastactivity = max($lastactivity, (int) $job->timemodified);
        }
        if (in_array($build->status, [build_store::QUEUED, build_store::RUNNING], true)
                && time() - $lastactivity > self::STALL_SECONDS) {
            build_store::update((int) $build->id, [
                'status' => build_store::FAILED,
                'error' => 'stalled',
                'timefinished' => time(),
            ]);
            $build = build_store::get((int) $build->id);
        }

        return [
            'buildid' => (int) $build->id,
            'crm_ref' => $build->crmref,
            'status' => $build->status,
            'courseid' => (int) $build->courseid,
            'courseurl' => $build->courseid ? (new \moodle_url('/course/view.php', ['id' => $build->courseid]))->out(false) : '',
            'topics_total' => $total,
            'topics_processed' => $processed,
            'step' => $step,
            'error' => (string) $build->error,
            'webhook' => (string) $build->webhookstatus,
            'result' => (string) $build->result,
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
            'courseid' => new external_value(PARAM_INT, 'Course id (0 until created)'),
            'courseurl' => new external_value(PARAM_RAW, 'Course URL'),
            'topics_total' => new external_value(PARAM_INT, 'Topics to create'),
            'topics_processed' => new external_value(PARAM_INT, 'Topics already processed'),
            'step' => new external_value(PARAM_RAW, 'Current step'),
            'error' => new external_value(PARAM_RAW, 'Error message, if any'),
            'webhook' => new external_value(PARAM_ALPHA, 'Webhook state: none, sent or failed'),
            'result' => new external_value(PARAM_RAW, 'JSON with the sections and topics created'),
        ]);
    }
}
