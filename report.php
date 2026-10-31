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
 * Usage and error report of the tool: who generates what, with which AI, and why an AI failed.
 *
 * Only reads the plugin's own log table, with a date window and row limits, so opening it does not
 * load the site. Export: CSV or Excel of the current tab and filters.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_ai_yared_course\local\usage_log;

admin_externalpage_setup('local_ai_yared_course_report');

$tab = optional_param('tab', 'summary', PARAM_ALPHA);
if (!in_array($tab, ['summary', 'events', 'errors'], true)) {
    $tab = 'summary';
}
$maxdays = usage_log::retention_days();
$days = optional_param('days', min(7, $maxdays), PARAM_INT);
$days = max(1, min($maxdays, $days));
$teacher = trim(optional_param('teacher', '', PARAM_TEXT));
$coursecode = trim(optional_param('course', '', PARAM_TEXT));
$provider = optional_param('provider', '', PARAM_ALPHANUMEXT);
$type = optional_param('type', '', PARAM_ALPHA);
$download = optional_param('download', 0, PARAM_INT);
$dataformat = optional_param('dataformat', 'csv', PARAM_ALPHA);

$since = time() - $days * DAYSECS;
$T = '{' . usage_log::TABLE . '}';
$threshold = max(1, (int) (get_config('local_ai_yared_course', 'failalert') ?: 5));

/**
 * WHERE clause shared by the tabs.
 *
 * @param string $extra Extra condition.
 * @param array $extraparams Its parameters.
 * @return array [sql, params]
 */
$build = function (string $extra = '', array $extraparams = []) use ($since, $teacher, $coursecode, $provider, $type, $DB) {
    $where = ['timecreated >= :since'];
    $params = ['since' => $since];
    if ($teacher !== '') {
        $where[] = $DB->sql_like('username', ':teacher', false, false);
        $params['teacher'] = '%' . $DB->sql_like_escape($teacher) . '%';
    }
    if ($coursecode !== '') {
        $where[] = $DB->sql_like('coursecode', ':course', false, false);
        $params['course'] = '%' . $DB->sql_like_escape($coursecode) . '%';
    }
    if ($provider !== '') {
        $where[] = 'provider = :provider';
        $params['provider'] = $provider;
    }
    if ($type !== '' && in_array($type, ['topic', 'quiz', 'bank', 'import', 'build'], true)) {
        $where[] = 'eventtype = :type';
        $params['type'] = $type;
    }
    if ($extra !== '') {
        $where[] = $extra;
        $params += $extraparams;
    }
    return [implode(' AND ', $where), $params];
};

/**
 * Text for the components JSON.
 *
 * @param string $json Components.
 * @return string
 */
$components = function (string $json): string {
    $data = json_decode($json, true);
    if (!is_array($data) || !$data) {
        return '';
    }
    $parts = [];
    foreach ($data as $key => $value) {
        if ($value === 0 || $value === '' || $value === null) {
            continue;
        }
        $label = get_string_manager()->string_exists('rep_comp_' . $key, 'local_ai_yared_course')
            ? get_string('rep_comp_' . $key, 'local_ai_yared_course') : $key;
        $parts[] = in_array($key, ['video', 'pdf'], true) ? $label : $label . ' ' . $value;
    }
    return implode(' · ', $parts);
};

$levelname = function (string $level): string {
    return $level === '' ? '' : get_string('level_' . $level, 'local_ai_yared_course');
};

// ---------------------------------------------------------------- Export.
if ($download) {
    require_sesskey();
    if (!in_array($dataformat, ['csv', 'excel'], true)) {
        $dataformat = 'csv';
    }
    $limit = 50000;
    if ($tab === 'errors') {
        [$w, $p] = $build('eventtype = :et', ['et' => usage_log::AIFAIL]);
        $columns = ['when' => 'Fecha', 'ai' => 'IA', 'slot' => 'Proveedor/clave', 'cat' => 'Categoria', 'http' => 'HTTP',
            'msg' => 'Mensaje', 'times' => 'Veces', 'teacher' => 'Docente', 'course' => 'Curso'];
        $rs = $DB->get_recordset_select(usage_log::TABLE, $w, $p, 'timelast DESC', '*', 0, $limit);
        $rowfn = function ($r) {
            return ['when' => userdate($r->timelast, '%Y-%m-%d %H:%M'), 'ai' => $r->provider . ' / ' . $r->model,
                'slot' => $r->title . ' #' . $r->keyhint, 'cat' => $r->errorcat, 'http' => $r->httpcode,
                'msg' => $r->errormsg, 'times' => $r->occurrences, 'teacher' => $r->username, 'course' => $r->coursecode];
        };
    } else {
        [$w, $p] = $build('eventtype <> :et', ['et' => usage_log::AIFAIL]);
        $columns = ['when' => 'Fecha', 'teacher' => 'Docente', 'course' => 'Curso', 'type' => 'Tipo', 'title' => 'Tema/detalle',
            'age' => 'Edad', 'level' => 'Nivel', 'ai' => 'IA', 'status' => 'Estado', 'detail' => 'Componentes',
            'error' => 'Error', 'sec' => 'Segundos', 'tin' => 'Tokens entrada', 'tout' => 'Tokens salida'];
        $rs = $DB->get_recordset_select(usage_log::TABLE, $w, $p, 'timecreated DESC', '*', 0, $limit);
        $rowfn = function ($r) use ($components, $levelname) {
            return ['when' => userdate($r->timecreated, '%Y-%m-%d %H:%M'), 'teacher' => $r->username,
                'course' => $r->coursecode, 'type' => $r->eventtype, 'title' => $r->title,
                'age' => $r->age ?: '', 'level' => $levelname($r->agelevel),
                'ai' => trim($r->provider . ' / ' . $r->model, ' /'), 'status' => $r->status,
                'detail' => $components((string) $r->components), 'error' => $r->errormsg,
                'sec' => round($r->durationms / 1000, 1), 'tin' => $r->tokensin, 'tout' => $r->tokensout];
        };
    }
    \core\dataformat::download_data('yared_ai_reporte_' . $tab . '_' . date('Ymd'), $dataformat, $columns, $rs,
        function ($record) use ($rowfn) {
            return $rowfn($record);
        });
    $rs->close();
    die();
}

// ---------------------------------------------------------------- Page.
$baseurl = new moodle_url('/local/ai_yared_course/report.php', ['tab' => $tab, 'days' => $days, 'teacher' => $teacher,
    'course' => $coursecode, 'provider' => $provider, 'type' => $type]);
$PAGE->set_url($baseurl);
$PAGE->requires->css(new moodle_url('/local/ai_yared_course/styles.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_title', 'local_ai_yared_course'));
echo html_writer::tag('p', get_string('report_intro', 'local_ai_yared_course', $maxdays), ['class' => 'text-muted']);

// Tabs.
$tabs = [];
foreach (['summary', 'events', 'errors'] as $name) {
    $tabs[] = new tabobject($name, new moodle_url($baseurl, ['tab' => $name]), get_string('rep_tab_' . $name, 'local_ai_yared_course'));
}
echo $OUTPUT->tabtree($tabs, $tab);

// Filters.
$dayoptions = [];
foreach ([1, 7, 14, 30, 60, 90] as $d) {
    if ($d <= $maxdays) {
        $dayoptions[$d] = get_string('rep_lastdays', 'local_ai_yared_course', $d);
    }
}
$dayoptions[$maxdays] = get_string('rep_lastdays', 'local_ai_yared_course', $maxdays);
$providers = $DB->get_fieldset_sql("SELECT DISTINCT provider FROM $T WHERE provider <> '' ORDER BY provider");
$provideroptions = ['' => get_string('rep_all', 'local_ai_yared_course')] + array_combine($providers, $providers);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/ai_yared_course/report.php'))->out(false),
    'class' => 'd-flex flex-wrap align-items-end mb-3', 'style' => 'gap:.75rem']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);
echo html_writer::div(html_writer::label(get_string('rep_period', 'local_ai_yared_course'), 'rep-days', false, ['class' => 'd-block small'])
    . html_writer::select($dayoptions, 'days', $days, false, ['id' => 'rep-days', 'class' => 'form-select']));
echo html_writer::div(html_writer::label(get_string('rep_teacher', 'local_ai_yared_course'), 'rep-teacher', false, ['class' => 'd-block small'])
    . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'teacher', 'id' => 'rep-teacher', 'value' => $teacher, 'class' => 'form-control']));
echo html_writer::div(html_writer::label(get_string('rep_course', 'local_ai_yared_course'), 'rep-course', false, ['class' => 'd-block small'])
    . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'course', 'id' => 'rep-course', 'value' => $coursecode, 'class' => 'form-control']));
echo html_writer::div(html_writer::label(get_string('rep_provider', 'local_ai_yared_course'), 'rep-provider', false, ['class' => 'd-block small'])
    . html_writer::select($provideroptions, 'provider', $provider, false, ['id' => 'rep-provider', 'class' => 'form-select']));
if ($tab === 'events') {
    $typeoptions = ['' => get_string('rep_all', 'local_ai_yared_course')];
    foreach (['topic', 'quiz', 'bank', 'import', 'build'] as $t) {
        $typeoptions[$t] = get_string('rep_type_' . $t, 'local_ai_yared_course');
    }
    echo html_writer::div(html_writer::label(get_string('rep_type', 'local_ai_yared_course'), 'rep-type', false, ['class' => 'd-block small'])
        . html_writer::select($typeoptions, 'type', $type, false, ['id' => 'rep-type', 'class' => 'form-select']));
}
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('rep_filter', 'local_ai_yared_course')]);
if ($tab !== 'summary') {
    foreach (['csv' => 'CSV', 'excel' => 'Excel'] as $format => $label) {
        echo html_writer::link(new moodle_url($baseurl, ['download' => 1, 'dataformat' => $format, 'sesskey' => sesskey()]),
            get_string('rep_export', 'local_ai_yared_course', $label), ['class' => 'btn btn-secondary']);
    }
}
echo html_writer::end_tag('form');

$statusbadge = function (string $status): string {
    $class = ['ok' => 'bg-success', 'warn' => 'bg-warning text-dark', 'error' => 'bg-danger'][$status] ?? 'bg-secondary';
    return html_writer::span(get_string('rep_status_' . $status, 'local_ai_yared_course'), 'badge ' . $class);
};

// ---------------------------------------------------------------- Summary.
if ($tab === 'summary') {
    [$w, $p] = $build('eventtype = :et', ['et' => usage_log::TOPIC]);
    $teachers = [];
    $levels = [];
    $providerstats = [];
    $totals = ['topics' => 0, 'ok' => 0, 'warn' => 0, 'error' => 0, 'questions' => 0, 'tin' => 0, 'tout' => 0];
    $rs = $DB->get_recordset_select(usage_log::TABLE, $w, $p, 'timecreated DESC', '*', 0, 20000);
    foreach ($rs as $r) {
        $key = (int) $r->userid;
        $teachers[$key] = $teachers[$key] ?? ['name' => $r->username, 'topics' => 0, 'error' => 0, 'courses' => [], 'agesum' => 0,
            'agecount' => 0, 'questions' => 0, 'tokens' => 0, 'last' => 0];
        $t =& $teachers[$key];
        $t['topics']++;
        $t['error'] += $r->status === 'error' ? 1 : 0;
        $t['courses'][$r->coursecode] = true;
        if ($r->age > 0) {
            $t['agesum'] += $r->age;
            $t['agecount']++;
        }
        $comp = json_decode((string) $r->components, true) ?: [];
        $t['questions'] += (int) ($comp['questions'] ?? 0);
        $t['tokens'] += $r->tokensin + $r->tokensout;
        $t['last'] = max($t['last'], $r->timecreated);
        unset($t);

        $levelkey = $r->agelevel !== '' ? $r->agelevel : '-';
        $levels[$levelkey] = ($levels[$levelkey] ?? 0) + 1;

        $pk = $r->provider !== '' ? $r->provider . ' / ' . $r->model : '(' . get_string('rep_noai', 'local_ai_yared_course') . ')';
        $providerstats[$pk] = $providerstats[$pk] ?? ['ok' => 0, 'warn' => 0, 'error' => 0, 'tokens' => 0];
        $providerstats[$pk][$r->status] = ($providerstats[$pk][$r->status] ?? 0) + 1;
        $providerstats[$pk]['tokens'] += $r->tokensin + $r->tokensout;

        $totals['topics']++;
        $totals[$r->status] = ($totals[$r->status] ?? 0) + 1;
        $totals['questions'] += (int) ($comp['questions'] ?? 0);
        $totals['tin'] += $r->tokensin;
        $totals['tout'] += $r->tokensout;
    }
    $rs->close();

    $failures = (int) $DB->get_field_sql("SELECT COALESCE(SUM(occurrences), 0) FROM $T WHERE eventtype = :et AND timelast >= :since",
        ['et' => usage_log::AIFAIL, 'since' => $since]);

    echo html_writer::start_div('d-flex flex-wrap mb-4', ['style' => 'gap:1rem']);
    $cards = [
        'rep_card_topics' => $totals['topics'],
        'rep_card_teachers' => count($teachers),
        'rep_card_questions' => $totals['questions'],
        'rep_card_errors' => $totals['error'],
        'rep_card_aifails' => $failures,
        'rep_card_tokens' => number_format($totals['tin'] + $totals['tout'], 0, ',', '.'),
    ];
    foreach ($cards as $key => $value) {
        echo html_writer::div(html_writer::div($value, 'h3 mb-0') . html_writer::div(get_string($key, 'local_ai_yared_course'), 'small text-muted'),
            'border rounded p-3', ['style' => 'min-width:150px']);
    }
    echo html_writer::end_div();

    echo $OUTPUT->heading(get_string('rep_by_teacher', 'local_ai_yared_course'), 4);
    if (!$teachers) {
        echo $OUTPUT->notification(get_string('rep_empty', 'local_ai_yared_course'), 'info');
    } else {
        uasort($teachers, function ($a, $b) {
            return $b['topics'] <=> $a['topics'];
        });
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [get_string('rep_teacher', 'local_ai_yared_course'), get_string('rep_card_topics', 'local_ai_yared_course'),
            get_string('rep_status_error', 'local_ai_yared_course'), get_string('rep_courses', 'local_ai_yared_course'),
            get_string('rep_avgage', 'local_ai_yared_course'), get_string('rep_card_questions', 'local_ai_yared_course'),
            get_string('rep_card_tokens', 'local_ai_yared_course'), get_string('rep_last', 'local_ai_yared_course')];
        foreach (array_slice($teachers, 0, 200) as $t) {
            $table->data[] = [s($t['name']), $t['topics'], $t['error'], s(implode(', ', array_slice(array_keys($t['courses']), 0, 6))),
                $t['agecount'] ? round($t['agesum'] / $t['agecount'], 1) : '-', $t['questions'],
                number_format($t['tokens'], 0, ',', '.'), userdate($t['last'], '%d-%m %H:%M')];
        }
        echo html_writer::table($table);
    }

    echo $OUTPUT->heading(get_string('rep_by_level', 'local_ai_yared_course'), 4);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = [get_string('rep_level', 'local_ai_yared_course'), get_string('rep_card_topics', 'local_ai_yared_course')];
    foreach ($levels as $key => $count) {
        $table->data[] = [$key === '-' ? '-' : $levelname($key), $count];
    }
    echo html_writer::table($table);

    echo $OUTPUT->heading(get_string('rep_by_provider', 'local_ai_yared_course'), 4);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = ['IA', get_string('rep_status_ok', 'local_ai_yared_course'), get_string('rep_status_warn', 'local_ai_yared_course'),
        get_string('rep_status_error', 'local_ai_yared_course'), get_string('rep_card_tokens', 'local_ai_yared_course')];
    foreach ($providerstats as $name => $st) {
        $table->data[] = [s($name), $st['ok'], $st['warn'], $st['error'], number_format($st['tokens'], 0, ',', '.')];
    }
    echo html_writer::table($table);
}

// ---------------------------------------------------------------- Events.
if ($tab === 'events') {
    [$w, $p] = $build('eventtype <> :et', ['et' => usage_log::AIFAIL]);
    $rows = $DB->get_records_select(usage_log::TABLE, $w, $p, 'timecreated DESC', '*', 0, 100);
    echo html_writer::tag('p', get_string('rep_last100', 'local_ai_yared_course'), ['class' => 'text-muted small']);
    if (!$rows) {
        echo $OUTPUT->notification(get_string('rep_empty', 'local_ai_yared_course'), 'info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [get_string('rep_when', 'local_ai_yared_course'), get_string('rep_teacher', 'local_ai_yared_course'),
            get_string('rep_course', 'local_ai_yared_course'), get_string('rep_type', 'local_ai_yared_course'),
            get_string('rep_topic', 'local_ai_yared_course'), get_string('rep_ageh', 'local_ai_yared_course'), 'IA',
            get_string('rep_state', 'local_ai_yared_course'), get_string('rep_created', 'local_ai_yared_course'),
            get_string('rep_duration', 'local_ai_yared_course')];
        foreach ($rows as $r) {
            $state = $statusbadge($r->status);
            if ($r->errormsg !== '') {
                $state .= html_writer::div(s($r->errormsg), 'small text-danger');
            }
            $table->data[] = [userdate($r->timecreated, '%d-%m %H:%M'), s($r->username), s($r->coursecode),
                get_string('rep_type_' . $r->eventtype, 'local_ai_yared_course'), s($r->title),
                $r->age ? $r->age . ' (' . s($levelname($r->agelevel)) . ')' : '', s(trim($r->provider . ' ' . $r->model)), $state,
                s($components((string) $r->components)), $r->durationms ? round($r->durationms / 1000) . ' s' : ''];
        }
        echo html_writer::table($table);
    }
}

// ---------------------------------------------------------------- Errors.
if ($tab === 'errors') {
    // Health of every provider key: failures, last failure and whether anything worked afterwards.
    $health = $DB->get_records_sql("SELECT MIN(id) AS id, provider, keyhint, MAX(title) AS slotlabel, SUM(occurrences) AS fails, MAX(timelast) AS lastfail
                                      FROM $T WHERE eventtype = :et AND timelast >= :since
                                  GROUP BY provider, keyhint ORDER BY fails DESC",
        ['et' => usage_log::AIFAIL, 'since' => $since]);
    echo $OUTPUT->heading(get_string('rep_health', 'local_ai_yared_course'), 4);
    echo html_writer::tag('p', get_string('rep_health_help', 'local_ai_yared_course', $threshold), ['class' => 'text-muted small']);
    if (!$health) {
        echo $OUTPUT->notification(get_string('rep_nofails', 'local_ai_yared_course'), 'success');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = ['IA', get_string('rep_slot', 'local_ai_yared_course'), get_string('rep_fails_window', 'local_ai_yared_course', $days),
            get_string('rep_fails_24', 'local_ai_yared_course'), get_string('rep_causes', 'local_ai_yared_course'),
            get_string('rep_lastfail', 'local_ai_yared_course'), get_string('rep_lastok', 'local_ai_yared_course'),
            get_string('rep_advice', 'local_ai_yared_course')];
        foreach ($health as $h) {
            $fails24 = (int) $DB->get_field_sql("SELECT COALESCE(SUM(occurrences), 0) FROM $T
                                                  WHERE eventtype = :et AND provider = :p AND keyhint = :k AND timelast >= :s",
                ['et' => usage_log::AIFAIL, 'p' => $h->provider, 'k' => $h->keyhint, 's' => time() - DAYSECS]);
            $lastok = (int) $DB->get_field_sql("SELECT MAX(timecreated) FROM $T
                                                 WHERE eventtype IN ('topic', 'bank') AND provider = :p AND status <> 'error'",
                ['p' => $h->provider]);
            $causes = $DB->get_records_sql("SELECT errorcat, SUM(occurrences) AS n FROM $T
                                             WHERE eventtype = :et AND provider = :p AND keyhint = :k AND timelast >= :s
                                          GROUP BY errorcat ORDER BY n DESC",
                ['et' => usage_log::AIFAIL, 'p' => $h->provider, 'k' => $h->keyhint, 's' => $since]);
            $causetext = [];
            foreach ($causes as $c) {
                $causetext[] = get_string('rep_cat_' . $c->errorcat, 'local_ai_yared_course') . ' ×' . $c->n;
            }
            $advice = '';
            if ($fails24 >= $threshold) {
                $advice = html_writer::span('⚠ ' . get_string('rep_advice_replace', 'local_ai_yared_course', $fails24), 'badge bg-danger');
            } else if ((int) $h->lastfail > $lastok && $fails24 >= 2) {
                $advice = html_writer::span('⚠ ' . get_string('rep_advice_nosuccess', 'local_ai_yared_course'), 'badge bg-warning text-dark');
            } else {
                $advice = html_writer::span(get_string('rep_advice_ok', 'local_ai_yared_course'), 'text-muted small');
            }
            $table->data[] = [s($h->provider), s($h->slotlabel) . ' #' . s($h->keyhint), (int) $h->fails, $fails24,
                s(implode(', ', $causetext)), userdate($h->lastfail, '%d-%m %H:%M'),
                $lastok ? userdate($lastok, '%d-%m %H:%M') : '-', $advice];
        }
        echo html_writer::table($table);
    }

    echo $OUTPUT->heading(get_string('rep_aifails', 'local_ai_yared_course'), 4);
    [$w, $p] = $build('eventtype = :et', ['et' => usage_log::AIFAIL]);
    $rows = $DB->get_records_select(usage_log::TABLE, $w, $p, 'timelast DESC', '*', 0, 100);
    if (!$rows) {
        echo $OUTPUT->notification(get_string('rep_nofails', 'local_ai_yared_course'), 'success');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [get_string('rep_when', 'local_ai_yared_course'), 'IA', get_string('rep_slot', 'local_ai_yared_course'),
            get_string('rep_cause', 'local_ai_yared_course'), 'HTTP', get_string('rep_message', 'local_ai_yared_course'),
            get_string('rep_times', 'local_ai_yared_course'), get_string('rep_teacher', 'local_ai_yared_course'),
            get_string('rep_course', 'local_ai_yared_course')];
        foreach ($rows as $r) {
            $table->data[] = [userdate($r->timelast, '%d-%m %H:%M'), s($r->provider . ' ' . $r->model), s($r->title) . ' #' . s($r->keyhint),
                get_string('rep_cat_' . $r->errorcat, 'local_ai_yared_course'), $r->httpcode ?: '-', s($r->errormsg), (int) $r->occurrences,
                s($r->username), s($r->coursecode)];
        }
        echo html_writer::table($table);
    }

    echo $OUTPUT->heading(get_string('rep_failedtopics', 'local_ai_yared_course'), 4);
    [$w, $p] = $build("eventtype <> :et AND status = 'error'", ['et' => usage_log::AIFAIL]);
    $rows = $DB->get_records_select(usage_log::TABLE, $w, $p, 'timecreated DESC', '*', 0, 100);
    if (!$rows) {
        echo $OUTPUT->notification(get_string('rep_nofails', 'local_ai_yared_course'), 'success');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [get_string('rep_when', 'local_ai_yared_course'), get_string('rep_teacher', 'local_ai_yared_course'),
            get_string('rep_course', 'local_ai_yared_course'), get_string('rep_type', 'local_ai_yared_course'),
            get_string('rep_topic', 'local_ai_yared_course'), get_string('rep_message', 'local_ai_yared_course')];
        foreach ($rows as $r) {
            $table->data[] = [userdate($r->timecreated, '%d-%m %H:%M'), s($r->username), s($r->coursecode),
                get_string('rep_type_' . $r->eventtype, 'local_ai_yared_course'), s($r->title), s($r->errormsg)];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
