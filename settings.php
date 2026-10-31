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
 * Admin settings for local_ai_yared_course: AI provider and YouTube search.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ai_yared_course', get_string('pluginname', 'local_ai_yared_course'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // AI providers: main plus five optional backups.
        $providers = [
            'gemini' => get_string('provider_gemini', 'local_ai_yared_course'),
            'openai' => get_string('provider_openai', 'local_ai_yared_course'),
            'grok' => get_string('provider_grok', 'local_ai_yared_course'),
            'deepseek' => get_string('provider_deepseek', 'local_ai_yared_course'),
            'qwen' => get_string('provider_qwen', 'local_ai_yared_course'),
            'kimi' => get_string('provider_kimi', 'local_ai_yared_course'),
            'mistral' => get_string('provider_mistral', 'local_ai_yared_course'),
            'groq' => get_string('provider_groq', 'local_ai_yared_course'),
            'openrouter' => get_string('provider_openrouter', 'local_ai_yared_course'),
            'zhipu' => get_string('provider_zhipu', 'local_ai_yared_course'),
            'custom' => get_string('provider_custom', 'local_ai_yared_course'),
        ];

        // Slot 1 uses the original setting names (provider, apikey...). Slots 2..6 append _2.._6.
        $aislots = [['suffix' => '', 'number' => 1, 'enabled' => 1]];
        for ($i = 2; $i <= \local_ai_yared_course\local\ai_client::MAX_SLOTS; $i++) {
            $aislots[] = ['suffix' => '_' . $i, 'number' => $i, 'enabled' => 0];
        }

        foreach ($aislots as $slot) {
            $suffix = $slot['suffix'];
            $number = $slot['number'];

            if ($number === 1) {
                $settings->add(new admin_setting_heading('local_ai_yared_course/aiheading',
                    get_string('aiheading', 'local_ai_yared_course'),
                    get_string('aiheading_desc', 'local_ai_yared_course')));
            } else {
                $settings->add(new admin_setting_heading('local_ai_yared_course/aiheading' . $suffix,
                    get_string('provider_fallback', 'local_ai_yared_course', $number - 1),
                    get_string('provider_fallback_desc', 'local_ai_yared_course')));
            }

            $settings->add(new admin_setting_configcheckbox('local_ai_yared_course/enabled' . $suffix,
                get_string('enabled', 'local_ai_yared_course'),
                get_string('enabled_desc', 'local_ai_yared_course'), $slot['enabled']));

            $settings->add(new admin_setting_configselect('local_ai_yared_course/provider' . $suffix,
                get_string('provider', 'local_ai_yared_course'), get_string('provider_desc', 'local_ai_yared_course'),
                'gemini', $providers));

            $settings->add(new admin_setting_configpasswordunmask('local_ai_yared_course/apikey' . $suffix,
                get_string('apikey', 'local_ai_yared_course'), get_string('apikey_desc', 'local_ai_yared_course'), ''));

            $settings->add(new admin_setting_configtext('local_ai_yared_course/model' . $suffix,
                get_string('model', 'local_ai_yared_course'), get_string('model_desc', 'local_ai_yared_course'), '',
                PARAM_TEXT));

            $settings->add(new admin_setting_configtext('local_ai_yared_course/baseurl' . $suffix,
                get_string('baseurl', 'local_ai_yared_course'), get_string('baseurl_desc', 'local_ai_yared_course'), '',
                PARAM_URL));

            $settings->add(new admin_setting_configcheckbox('local_ai_yared_course/jsonmode' . $suffix,
                get_string('jsonmode', 'local_ai_yared_course'), get_string('jsonmode_desc', 'local_ai_yared_course'), 1));

            $testurl = new moodle_url('/local/ai_yared_course/testconnection.php',
                ['profile' => $number, 'sesskey' => sesskey()]);
            $settings->add(new admin_setting_description('local_ai_yared_course/testconnection' . $suffix,
                get_string('testconnection', 'local_ai_yared_course'),
                get_string('testconnection_profile_desc', 'local_ai_yared_course') . '<br>' .
                html_writer::link($testurl, get_string('testconnection_run', 'local_ai_yared_course'),
                    ['class' => 'btn btn-secondary mt-2'])));
        }

        // Options shared by every provider.
        $settings->add(new admin_setting_heading('local_ai_yared_course/generalaiheading',
            get_string('generalaiheading', 'local_ai_yared_course'),
            get_string('generalaiheading_desc', 'local_ai_yared_course')));

        $settings->add(new admin_setting_configtext('local_ai_yared_course/temperature',
            get_string('temperature', 'local_ai_yared_course'), get_string('temperature_desc', 'local_ai_yared_course'),
            '0.7', PARAM_RAW_TRIMMED));

        $settings->add(new admin_setting_configtext('local_ai_yared_course/timeout',
            get_string('timeout', 'local_ai_yared_course'), get_string('timeout_desc', 'local_ai_yared_course'),
            600, PARAM_INT));

        // Status of the PDF conversion (needs Python 3 + PyMuPDF). Checked only when this page is shown, so
        // the admin search and the upgrade do not start Python.
        if (optional_param('section', '', PARAM_ALPHANUMEXT) === 'local_ai_yared_course') {
            $pdfstatus = \local_ai_yared_course\local\pdf_renderer::get_status();
            $envname = get_string($pdfstatus['docker'] ? 'pdfenv_docker' : 'pdfenv_host', 'local_ai_yared_course');
            if ($pdfstatus['pymupdf']) {
                $html = html_writer::div(get_string('pdfstatus_ok', 'local_ai_yared_course', (object) [
                    'python' => s($pdfstatus['pythonpath'] !== '' ? $pdfstatus['pythonpath'] : $pdfstatus['python']),
                    'pythonversion' => s($pdfstatus['pythonversion']),
                    'version' => s($pdfstatus['pymupdfversion']),
                    'env' => $envname,
                ]), 'alert alert-success mb-2');
            } else {
                $key = $pdfstatus['python'] === null ? 'pdfstatus_nopython' : 'pdfstatus_nopymupdf';
                $html = html_writer::div(get_string($key, 'local_ai_yared_course', (object) [
                    'python' => s((string) $pdfstatus['python']),
                    'env' => $envname,
                ]), 'alert alert-warning mb-2');
                $html .= \local_ai_yared_course\local\pdf_renderer::install_help_html($pdfstatus['docker']);
            }
            if ($pdfstatus['pymupdf']) {
                // Already installed: keep the steps at hand for the next environment (QA, production, a new site).
                $html .= html_writer::tag('details', html_writer::tag('summary', get_string('pdfhelp_other', 'local_ai_yared_course'))
                    . \local_ai_yared_course\local\pdf_renderer::install_help_html(true)
                    . \local_ai_yared_course\local\pdf_renderer::install_help_html(false), ['class' => 'mb-2']);
            }
            $settings->add(new admin_setting_description('local_ai_yared_course/pdfstatus',
                get_string('pdfstatus', 'local_ai_yared_course'), $html));
        }

        $settings->add(new admin_setting_configtext('local_ai_yared_course/pdfpython',
            get_string('pdfpython', 'local_ai_yared_course'), get_string('pdfpython_desc', 'local_ai_yared_course'),
            '', PARAM_TEXT));

        $settings->add(new admin_setting_configselect('local_ai_yared_course/batchsize',
            get_string('batchsize', 'local_ai_yared_course'), get_string('batchsize_desc', 'local_ai_yared_course'),
            10, [5 => 5, 10 => 10, 15 => 15, 20 => 20]));

        // Age sent to the AI for each level of the question generator.
        $settings->add(new admin_setting_heading('local_ai_yared_course/levelsheading',
            get_string('levelsheading', 'local_ai_yared_course'), get_string('levelsheading_desc', 'local_ai_yared_course')));
        foreach (['initial' => 3, 'primary' => 12, 'secondary' => 15, 'university' => 20] as $levelkey => $levelage) {
            $settings->add(new admin_setting_configtext('local_ai_yared_course/age_' . $levelkey,
                get_string('levelage', 'local_ai_yared_course',
                    get_string('level_' . $levelkey, 'local_ai_yared_course')),
                get_string('levelage_desc', 'local_ai_yared_course'), $levelage, PARAM_INT));
        }

        // Usage report.
        $settings->add(new admin_setting_heading('local_ai_yared_course/reportheading',
            get_string('reportlink', 'local_ai_yared_course'),
            get_string('reportlink_desc', 'local_ai_yared_course',
                (new moodle_url('/local/ai_yared_course/report.php'))->out(false))));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/logretentiondays',
            get_string('logretentiondays', 'local_ai_yared_course'), get_string('logretentiondays_desc', 'local_ai_yared_course'),
            30, PARAM_INT));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/failalert',
            get_string('failalert', 'local_ai_yared_course'), get_string('failalert_desc', 'local_ai_yared_course'),
            5, PARAM_INT));

        // Shared protection for many teachers working at the same time.
        $settings->add(new admin_setting_heading('local_ai_yared_course/guardheading',
            get_string('guardheading', 'local_ai_yared_course'), get_string('guardheading_desc', 'local_ai_yared_course')));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/aimaxconcurrent',
            get_string('aimaxconcurrent', 'local_ai_yared_course'), get_string('aimaxconcurrent_desc', 'local_ai_yared_course'),
            4, PARAM_INT));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/aislotwait',
            get_string('aislotwait', 'local_ai_yared_course'), get_string('aislotwait_desc', 'local_ai_yared_course'),
            300, PARAM_INT));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/aitripseconds',
            get_string('aitripseconds', 'local_ai_yared_course'), get_string('aitripseconds_desc', 'local_ai_yared_course'),
            60, PARAM_INT));

        // External API used by the CRM.
        $settings->add(new admin_setting_heading('local_ai_yared_course/crmheading',
            get_string('crmheading', 'local_ai_yared_course'), get_string('crmheading_desc', 'local_ai_yared_course')));
        $settings->add(new admin_setting_configtextarea('local_ai_yared_course/templatecourses',
            get_string('templatecourses', 'local_ai_yared_course'), get_string('templatecourses_desc', 'local_ai_yared_course'),
            '', PARAM_TEXT, 30, 2));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/apiratelimit',
            get_string('apiratelimit', 'local_ai_yared_course'), get_string('apiratelimit_desc', 'local_ai_yared_course'),
            10, PARAM_INT));
        $settings->add(new admin_setting_configtext('local_ai_yared_course/webhookurl',
            get_string('webhookurl', 'local_ai_yared_course'), get_string('webhookurl_desc', 'local_ai_yared_course'),
            '', PARAM_URL));
        $settings->add(new admin_setting_configpasswordunmask('local_ai_yared_course/webhooksecret',
            get_string('webhooksecret', 'local_ai_yared_course'), get_string('webhooksecret_desc', 'local_ai_yared_course'),
            ''));
        $settings->add(new admin_setting_configcheckbox('local_ai_yared_course/webhookallowhttp',
            get_string('webhookallowhttp', 'local_ai_yared_course'), get_string('webhookallowhttp_desc', 'local_ai_yared_course'),
            0));

        // Reference pictures.
        $settings->add(new admin_setting_configcheckbox('local_ai_yared_course/imagesearch',
            get_string('imagesearch', 'local_ai_yared_course'), get_string('imagesearch_desc', 'local_ai_yared_course'), 1));

        // YouTube.
        $settings->add(new admin_setting_heading('local_ai_yared_course/youtubeheading',
            get_string('youtubeheading', 'local_ai_yared_course'), get_string('youtubeheading_desc', 'local_ai_yared_course')));
        $settings->add(new admin_setting_configpasswordunmask('local_ai_yared_course/youtubekey',
            get_string('youtubekey', 'local_ai_yared_course'), get_string('youtubekey_desc', 'local_ai_yared_course'), ''));
    }
}

// The report page is also available to users who were given the viewreport capability (not only administrators).
if ($hassiteconfig || (get_capability_info('local/ai_yared_course:viewreport') &&
        has_capability('local/ai_yared_course:viewreport', context_system::instance()))) {
    $ADMIN->add('localplugins', new admin_externalpage('local_ai_yared_course_report',
        get_string('report_title', 'local_ai_yared_course'),
        new moodle_url('/local/ai_yared_course/report.php'), 'local/ai_yared_course:viewreport'));
}
