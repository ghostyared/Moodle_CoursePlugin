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
 * Admin page that tests the AI provider and the YouTube search.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);
require_sesskey();
core_php_time_limit::raise(300);

$profile = min(\local_ai_yared_course\local\ai_client::MAX_SLOTS, max(1, optional_param('profile', 1, PARAM_INT)));
$client = \local_ai_yared_course\local\ai_factory::create_profile($profile);
$profiletitle = get_string('testconnection_profile', 'local_ai_yared_course',
    $client->get_active_profile_label());

$PAGE->set_context($systemcontext);
$PAGE->set_url('/local/ai_yared_course/testconnection.php', ['profile' => $profile]);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($profiletitle);
$PAGE->set_heading($profiletitle);

echo $OUTPUT->header();
echo $OUTPUT->heading($profiletitle);
echo $OUTPUT->notification(get_string('testconnection_profile_desc', 'local_ai_yared_course'), 'info');

// AI provider.
if (!$client->is_configured()) {
    echo $OUTPUT->notification(get_string('profile_notconfigured', 'local_ai_yared_course'), 'warning');
} else {
    try {
        $reply = $client->complete(
            'You are a connectivity test. Reply only with a JSON object.',
            'Reply with this JSON object: {"status":"ok"}'
        );
        echo $OUTPUT->notification(get_string('testing_ai_ok', 'local_ai_yared_course', (object) [
            'provider' => $client->get_provider_name(),
            'model' => $client->get_model(),
        ]), 'success');
        echo html_writer::tag('pre', s(core_text::substr(trim($reply), 0, 300)));
    } catch (moodle_exception $e) {
        echo $OUTPUT->notification($e->getMessage(), 'error');
    }
}

// YouTube: only on the main profile, it is not provider-specific.
if ($profile === 1) {
    $finder = new \local_ai_yared_course\local\youtube_finder();
    if (!$finder->is_configured()) {
        echo $OUTPUT->notification(get_string('warn_videokey', 'local_ai_yared_course'), 'warning');
    } else {
        $video = $finder->find('educational video for kids', 'en', 8);
        if ($video) {
            echo $OUTPUT->notification(get_string('testing_youtube_ok', 'local_ai_yared_course', s($video['title'])),
                'success');
        } else {
            echo $OUTPUT->notification(get_string('testing_youtube_error', 'local_ai_yared_course',
                s($finder->get_error())), 'error');
        }
    }
}

$back = new moodle_url('/admin/settings.php', ['section' => 'local_ai_yared_course']);
echo $OUTPUT->single_button($back, get_string('back'), 'get');
echo $OUTPUT->footer();
