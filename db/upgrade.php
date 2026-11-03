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
 * Upgrade steps for local_ai_yared_course.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_local_ai_yared_course_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026101902) {
        // Define table local_ai_yared_course_topic to be created.
        $table = new xmldb_table('local_ai_yared_course_topic');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('classdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sectionnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('courseid-sortorder', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'sortorder']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101902, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102001) {
        // The former AI question bank activity was merged into this plugin: no schema change.
        upgrade_plugin_savepoint(true, 2026102001, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102002) {
        // Gemini 2.5 Flash is no longer available to new users: the default is now 3.8 Flash.
        upgrade_plugin_savepoint(true, 2026102002, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102003) {
        // Each designed topic can now have its own audience age (0 = use the global level).
        $table = new xmldb_table('local_ai_yared_course_topic');
        $field = new xmldb_field('age', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'summary');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026102003, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102005) {
        // Main AI provider plus two optional fallbacks: settings only, no schema change.
        upgrade_plugin_savepoint(true, 2026102005, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102006) {
        // The internal topic designer is no longer linked from the course menu: no schema change.
        upgrade_plugin_savepoint(true, 2026102006, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102007) {
        // Rename the external service display name; the shortname and the app integration do not change.
        $service = $DB->get_record('external_services', ['shortname' => 'local_ai_yared_course']);
        if ($service && trim($service->name) === 'AI Yared Course') {
            $service->name = 'Yared.AI Creador de Material';
            $service->timemodified = time();
            $DB->update_record('external_services', $service);
        }

        // Rename the bank links already created inside courses, keeping their topic suffix.
        $select = $DB->sql_like('name', ':oldes', false) . ' OR ' . $DB->sql_like('name', ':olden', false);
        $urls = $DB->get_records_select('url', $select, [
            'oldes' => 'Banco de preguntas IA:%',
            'olden' => 'AI question bank:%',
        ]);
        foreach ($urls as $url) {
            if (strpos((string) $url->externalurl, '/question/edit.php') === false) {
                continue;
            }
            if (strpos($url->name, 'Banco de preguntas IA: ') === 0) {
                $url->name = 'Yared.AI Creador de Pregunta: ' . substr($url->name, strlen('Banco de preguntas IA: '));
            } else if (strpos($url->name, 'AI question bank: ') === 0) {
                $url->name = 'Yared.AI Question Creator: ' . substr($url->name, strlen('AI question bank: '));
            } else {
                continue;
            }
            $url->timemodified = time();
            $DB->update_record('url', $url);
        }

        upgrade_plugin_savepoint(true, 2026102007, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102008) {
        // Waiting view for the question generator: no schema change.
        upgrade_plugin_savepoint(true, 2026102008, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102009) {
        // LaTeX and image support for generated questions: no schema change.
        upgrade_plugin_savepoint(true, 2026102009, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102010) {
        // Optional statement image upload: form and file storage only, no schema change.
        upgrade_plugin_savepoint(true, 2026102010, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102011) {
        // PDF/image question importer: temporary files live in the user context, no schema change.
        upgrade_plugin_savepoint(true, 2026102011, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102012) {
        // Three creation modes and the crop editor: no schema change.
        upgrade_plugin_savepoint(true, 2026102012, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102013) {
        // Gemini 3.7 Flash can take more than three minutes for a tiny request. Use the fast lite
        // model by default and give slow multimodal requests the recommended 10-minute timeout.
        $cfg = get_config('local_ai_yared_course');
        if (!$cfg) {
            $cfg = new \stdClass();
        }
        foreach (['model', 'model_2', 'model_3'] as $modelkey) {
            if (($cfg->{$modelkey} ?? '') === 'gemini-3.7-flash') {
                set_config($modelkey, 'gemini-3.5-flash-lite', 'local_ai_yared_course');
            }
        }
        if (isset($cfg->timeout) && (int) $cfg->timeout === 180) {
            set_config('timeout', 600, 'local_ai_yared_course');
        }
        upgrade_plugin_savepoint(true, 2026102013, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102014) {
        // PDF pages can be rasterised and cropped; batches now accept up to 10 source images.
        // Settings and temporary files only, no schema change.
        upgrade_plugin_savepoint(true, 2026102014, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102015) {
        // Scheduled task to clean abandoned imports, a real privacy provider and the topic_generated event:
        // no schema change.
        upgrade_plugin_savepoint(true, 2026102015, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102016) {
        // More robust PDF rendering (retry, clean helper output) and visible error code: no schema change.
        upgrade_plugin_savepoint(true, 2026102016, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102017) {
        // PDF renderer picks a Python that really has PyMuPDF; shorter text-only notice: no schema change.
        upgrade_plugin_savepoint(true, 2026102017, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102018) {
        // Importer: the model returns the letter of the correct option and solves before answering: no schema change.
        upgrade_plugin_savepoint(true, 2026102018, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102019) {
        // Admin page shows the PDF conversion status (Docker or regular server): no schema change.
        upgrade_plugin_savepoint(true, 2026102019, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102020) {
        // PyMuPDF status check in Site administration > Reports > Status and a notice after installing: no schema change.
        \local_ai_yared_course\local\pdf_renderer::notify_if_missing();
        upgrade_plugin_savepoint(true, 2026102020, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102021) {
        // The installation commands for PyMuPDF are now shown in the notice, the status check and the settings.
        upgrade_plugin_savepoint(true, 2026102021, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102022) {
        // Importer: options removed from the statement and a preview button in the review: no schema change.
        upgrade_plugin_savepoint(true, 2026102022, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102023) {
        // The LaTeX typesetting of the review preview receives a jQuery object, as the filters API expects: no schema change.
        upgrade_plugin_savepoint(true, 2026102023, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102024) {
        // Five AI backups, more providers, optional PDF, one-page quiz and discussion forum: no schema change.
        upgrade_plugin_savepoint(true, 2026102024, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102025) {
        // Background jobs table.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ai_yared_course_job');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('cancelreq', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('total', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('done', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failed', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('step', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('log', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('options', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('results', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid-courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026102025, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102026) {
        // Topic index at the top of existing sections: no schema change.
        upgrade_plugin_savepoint(true, 2026102026, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102027) {
        // Topics as collapsed subsections (Moodle 5 / Flexsections): no schema change.
        upgrade_plugin_savepoint(true, 2026102027, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102028) {
        // FlexTiles: topics created in an existing section become accordion subsections by default.
        // The change is resolved at runtime from the course format; no schema change.
        upgrade_plugin_savepoint(true, 2026102028, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102029) {
        // FlexTiles: remove an orphan section when the format cannot attach it to the chosen parent.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102029, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102230) {
        // Quiz wizard: creates a standard mod_quiz from one or more question banks.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102230, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102231) {
        // Quiz wizard: show FlexTiles/Flexsections subsections in the placement list.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102231, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102232) {
        // Use the visible name Formato-Mariategui in course-format messages.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102232, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102333) {
        // Material creator: the placement list shows the section hierarchy (↳ + parent name),
        // and Formato-Mariategui subsections always get a mandatory display mode
        // (accordion by default, or mosaic). No schema change.
        upgrade_plugin_savepoint(true, 2026102333, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102400) {
        // 0.17.0: table for courses built from a template by the external API (CRM).
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ai_yared_course_build');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('crmref', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('requesterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('teacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('webhookstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('crmref', XMLDB_INDEX_UNIQUE, ['crmref']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // The original service was open to any user with a token. Restrict it, but first authorise every
        // user that already holds a token for it, so the applications in use keep working.
        $service = $DB->get_record('external_services', ['shortname' => 'local_ai_yared_course']);
        if ($service && empty($service->restrictedusers)) {
            $userids = $DB->get_fieldset_select('external_tokens', 'DISTINCT userid', 'externalserviceid = ?',
                [$service->id]);
            foreach ($userids as $userid) {
                if (!$DB->record_exists('external_services_users',
                        ['externalserviceid' => $service->id, 'userid' => $userid])) {
                    $DB->insert_record('external_services_users', (object) [
                        'externalserviceid' => $service->id,
                        'userid' => $userid,
                        'iprestriction' => null,
                        'validuntil' => null,
                        'timecreated' => time(),
                    ]);
                }
            }
            $DB->set_field('external_services', 'restrictedusers', 1, ['id' => $service->id]);
        }

        upgrade_plugin_savepoint(true, 2026102400, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102500) {
        // 0.18.0: usage and error log shown in the admin report.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ai_yared_course_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timelast', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('username', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('coursecode', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('age', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('agelevel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('provider', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('keyhint', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, '');
        $table->add_field('status', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'ok');
        $table->add_field('errorcat', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('httpcode', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('errormsg', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('occurrences', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('durationms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tokensin', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tokensout', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('components', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('type-time', XMLDB_INDEX_NOTUNIQUE, ['eventtype', 'timecreated']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('provider-time', XMLDB_INDEX_NOTUNIQUE, ['provider', 'timelast']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026102500, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102501) {
        // 0.18.1: PDF/image importer: LaTeX only where there is real notation, figure-only crops, up to 8 alternatives,
        // new question types (multiple selection, short answer, essay) and the reading passage kept in the statement.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102501, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102502) {
        // 0.19.0: importer in two phases: image decided per question, recommended format per question that the teacher
        // can change (multiple choice, multiple selection, true/false, select missing word, drag and drop into text,
        // short answer, essay) with preview, another variant and another model. Alternatives and feedback language
        // limited to Latin American Spanish or English. No schema change.
        upgrade_plugin_savepoint(true, 2026102502, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102503) {
        // 0.19.1: fixes the review screen script (buttons did nothing), mathematical exercises get the crop of the whole
        // exercise by default again, and the preview opens in a modal window. No schema change.
        upgrade_plugin_savepoint(true, 2026102503, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102504) {
        // 0.19.2: questions are rewritten in parallel (up to 3 per teacher) and only their card is updated;
        // new look of the review screen and of the preview modal. No schema change.
        upgrade_plugin_savepoint(true, 2026102504, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102505) {
        // 0.20.0: short answer and essay are never produced automatically (open exercises are rewritten as multiple
        // choice or true/false, with a second pass), the crop the teacher adjusts is kept on every rewrite, legend and
        // help on the buttons, "another model" hidden with a single provider, "view original" in the preview.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102505, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102601) {
        // 0.21.0: mathematics course selector with suggested topics, figures drawn by the plugin (SVG) from a
        // description written by the AI, and the AI no longer proposes image URLs. No schema change.
        upgrade_plugin_savepoint(true, 2026102601, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102701) {
        // 0.22.0: the generator can keep its questions as a draft that the teacher reviews, edits and approves before they
        // are saved (same review screen as the import), with "another question" and the figure as an optional picture.
        // No schema change.
        upgrade_plugin_savepoint(true, 2026102701, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102702) {
        // 0.23.0: classic generator with every question format (multiple choice, multiple selection, true/false, missing
        // word, drag and drop, "mixed" and, only when chosen, short answer and essay); the review panel offers format,
        // another variant and another model, with the legend. No schema change.
        upgrade_plugin_savepoint(true, 2026102702, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026102703) {
        // 0.24.0: optional reference picture from Wikimedia Commons in the review screen (search, choose, "process and
        // rewrite"); the credit is added to the feedback when saving. New admin setting "imagesearch". No schema change.
        upgrade_plugin_savepoint(true, 2026102703, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026103001) {
        // 0.24.1: plugin directory review fixes (LICENSE file, privacy declarations for Wikimedia Commons and the CRM
        // webhook, English README, copyright headers). No functional or schema change.
        upgrade_plugin_savepoint(true, 2026103001, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026110201) {
        // 0.25.0: the Material Creator detects mathematics topics (or the teacher chooses the kind) and generates exercises
        // with LaTeX and figures for them instead of theory questions. No schema change.
        upgrade_plugin_savepoint(true, 2026110201, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026110202) {
        // 0.26.0: preview of the topic before it is created in the course (editable by the teacher), and an editable class
        // presentation (slides) stored in the course file area. No schema change.
        upgrade_plugin_savepoint(true, 2026110202, 'local', 'ai_yared_course');
    }

    if ($oldversion < 2026110203) {
        // 0.26.1: fix creating a topic from the preview (request parameter names), link back to the batch list, and
        // a clear message (keeping the draft) when the creation fails. No schema change.
        upgrade_plugin_savepoint(true, 2026110203, 'local', 'ai_yared_course');
    }

    return true;
}
