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

/**
 * Notifies the CRM when a build finishes.
 *
 * The URL and the secret come only from the plugin settings (never from the request, so a caller cannot
 * make Moodle contact an address of its choice). The body is signed with HMAC-SHA256 over
 * "timestamp.body", sent in the X-Signature and X-Timestamp headers. Moodle's curl class applies the site's
 * HTTP security rules (blocked hosts and ports), so a CRM in a private network must be allowed in
 * Site administration > General > Security > HTTP security.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook {

    /**
     * Send the final state of a build.
     *
     * @param \stdClass $build Build record (already updated).
     * @return string none|sent|failed
     */
    public static function send(\stdClass $build): string {
        $cfg = get_config('local_ai_yared_course');
        $url = trim((string) ($cfg->webhookurl ?? ''));
        $secret = (string) ($cfg->webhooksecret ?? '');
        if ($url === '' || $secret === '') {
            return 'none';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && !empty($cfg->webhookallowhttp))) {
            return 'failed';
        }

        $result = json_decode((string) $build->result, true);
        $body = json_encode([
            'event' => 'course_build.' . $build->status,
            'buildid' => (int) $build->id,
            'crm_ref' => $build->crmref,
            'status' => $build->status,
            'courseid' => (int) $build->courseid,
            'courseurl' => $build->courseid ? (new \moodle_url('/course/view.php', ['id' => $build->courseid]))->out(false) : '',
            'error' => (string) $build->error,
            'result' => is_array($result) ? $result : null,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $status = 'failed';
        foreach ([0, 3, 10] as $delay) {
            if ($delay) {
                sleep($delay);
            }
            $curl = new \curl();
            $curl->setHeader([
                'Content-Type: application/json',
                'X-Timestamp: ' . $timestamp,
                'X-Signature: sha256=' . $signature,
                'X-Idempotency-Key: ' . $build->crmref . ':' . $build->status,
            ]);
            $curl->post($url, $body, ['CURLOPT_TIMEOUT' => 20, 'CURLOPT_CONNECTTIMEOUT' => 10]);
            $http = (int) ($curl->get_info()['http_code'] ?? 0);
            if (!$curl->get_errno() && $http >= 200 && $http < 300) {
                $status = 'sent';
                break;
            }
            debugging('local_ai_yared_course webhook attempt failed (HTTP ' . $http . ')', DEBUG_DEVELOPER);
        }
        return $status;
    }
}
