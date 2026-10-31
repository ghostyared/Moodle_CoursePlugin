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
 * Question importer: reads exercises from PDFs and photos, lets the teacher review them and
 * saves them in the course question bank.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

use local_ai_yared_course\form\generate_form;
use local_ai_yared_course\form\import_form;
use local_ai_yared_course\local\ai_factory;
use local_ai_yared_course\local\bank_draft;
use local_ai_yared_course\local\commons_search;
use local_ai_yared_course\local\image_helper;
use local_ai_yared_course\local\category_helper;
use local_ai_yared_course\local\crop_helper;
use local_ai_yared_course\local\exercise_extractor;
use local_ai_yared_course\local\import_store;
use local_ai_yared_course\local\pdf_renderer;
use local_ai_yared_course\local\question_generator;
use local_ai_yared_course\local\question_saver;
use local_ai_yared_course\local\source_reader;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);
require_capability('local/ai_yared_course:use', $context);
require_capability('moodle/question:add', $context);

$PAGE->set_url('/local/ai_yared_course/import.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('import_title', 'local_ai_yared_course'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/ai_yared_course/styles.css'));

$client = ai_factory::create();
\local_ai_yared_course\local\usage_log::set_course((int) $course->id);
if (!$client->is_configured()) {
    echo $OUTPUT->header();
    if (has_capability('moodle/site:config', context_system::instance())) {
        $url = new moodle_url('/admin/settings.php', ['section' => 'local_ai_yared_course']);
        echo $OUTPUT->notification(get_string('notconfigured_admin', 'local_ai_yared_course', $url->out()), 'warning');
    } else {
        echo $OUTPUT->notification(get_string('notconfigured_teacher', 'local_ai_yared_course'), 'warning');
    }
    echo $OUTPUT->footer();
    exit;
}

$cancreatecategory = has_capability('moodle/question:managecategory', $context);
$categories = category_helper::get_options($context);
$bankurl = new moodle_url('/local/ai_yared_course/bank.php', ['id' => $course->id]);
$action = optional_param('action', '', PARAM_ALPHA);
$errors = [];
$warnings = [];
$manifest = null;
$posted = null;
$formdata = null;
$showform = true;
$reviewdraft = 0;

// Preview of one question of the review screen, rendered like the saved question (LaTeX included).
if ($action === 'preview') {
    require_sesskey();
    $scalar = function ($value): string {
        return is_scalar($value) ? (string) $value : '';
    };
    $limit = function (string $value, int $max): string {
        $value = trim(clean_param($value, PARAM_RAW));
        return \core_text::strlen($value) > $max ? \core_text::substr($value, 0, $max) : $value;
    };
    $previewtype = $scalar($_POST['type'] ?? '');
    if (!in_array($previewtype, exercise_extractor::TYPES, true)) {
        $previewtype = 'multichoice';
    }
    $previewq = [
        'type' => $previewtype,
        'text' => $limit($scalar($_POST['text'] ?? ''), exercise_extractor::MAX_TEXT),
        'feedback' => $limit($scalar($_POST['feedback'] ?? ''), 4000),
        'math' => true,
        'options' => [],
        'answer' => null,
        'answers' => [],
    ];
    if ($previewtype === 'shortanswer') {
        foreach (preg_split('/\R/u', $scalar($_POST['answers'] ?? '')) as $line) {
            $line = trim(clean_param($line, PARAM_TEXT));
            if ($line !== '') {
                $previewq['answers'][] = $line;
            }
        }
    } else if ($previewtype === 'essay') {
        $previewq['options'] = [];
    } else if ($previewtype === 'truefalse') {
        $answerraw = $scalar($_POST['answer'] ?? '');
        $previewq['answer'] = in_array($answerraw, ['0', '1'], true) ? $answerraw === '1' : null;
    } else {
        $correctindex = (int) $scalar($_POST['correct'] ?? '-1');
        $correctmulti = [];
        foreach ((array) ($_POST['correctm'] ?? []) as $chosenindex) {
            $correctmulti[(int) $scalar($chosenindex)] = true;
        }
        $postedpreview = is_array($_POST['options'] ?? null) ? $_POST['options'] : [];
        foreach ($postedpreview as $index => $optiontext) {
            $optiontext = $limit($scalar($optiontext), 1000);
            if ($optiontext === '') {
                continue;
            }
            $isright = $previewtype === 'multiselect' ? isset($correctmulti[(int) $index])
                : ((int) $index === $correctindex);
            $previewq['options'][] = ['text' => $optiontext, 'correct' => $isright];
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(question_saver::render_preview($previewq, $context), JSON_UNESCAPED_UNICODE);
    die();
}

// Reference picture: list candidate pictures of Wikimedia Commons for one question.
if ($action === 'imgsearch') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');
    $reply = ['ok' => false, 'error' => ''];
    try {
        if (!commons_search::is_enabled()) {
            throw new \moodle_exception('import_img_err_disabled', 'local_ai_yared_course');
        }
        $draftid = required_param('draft', PARAM_INT);
        $index = required_param('index', PARAM_INT);
        $manifest = import_store::load($draftid, $USER->id, $course->id);
        if (!isset($manifest['exercises'][$index])) {
            throw new \moodle_exception('import_fmt_err_request', 'local_ai_yared_course');
        }
        $ex = $manifest['exercises'][$index];
        \core\session\manager::write_close();
        $rawquery = $_POST['query'] ?? '';
        $query = is_scalar($rawquery) ? trim(clean_param((string) $rawquery, PARAM_TEXT)) : '';
        $query = \core_text::substr($query, 0, 120);
        if ($query === '') {
            // The teacher left it empty: the AI says what picture the question is about.
            $source = $ex['original'] ?? $ex;
            $lines = ['Question: ' . \core_text::substr((string) ($source['text'] ?? $ex['text']), 0, 600)];
            foreach (array_slice((array) ($source['options'] ?? []), 0, 8) as $option) {
                $lines[] = '- ' . \core_text::substr((string) ($option['text'] ?? ''), 0, 120);
            }
            $lines[] = 'Return the best search query for ONE reference picture that this question is about or that would '
                . 'illustrate it (a portrait of a person, a painting, a map, an animal, a landmark, an object). Use 2 to 5 '
                . 'words: the proper name or the most specific term, in the language most likely used on Wikimedia '
                . 'Commons. If nothing can be illustrated, return an empty query.';
            $lines[] = 'Reply with JSON: {"query": "..."}';
            $raw = $client->complete('You help teachers find reference pictures. You always reply with one valid JSON '
                . 'object and nothing else.', implode("\n", $lines));
            $data = \local_ai_yared_course\local\ai_json::extract($raw, false);
            $query = is_array($data) && is_scalar($data['query'] ?? null)
                ? \core_text::substr(trim(clean_param((string) $data['query'], PARAM_TEXT)), 0, 120) : '';
            if ($query === '') {
                throw new \moodle_exception('import_img_err_query', 'local_ai_yared_course');
            }
        }
        $finder = new commons_search();
        $results = $finder->search($query, 8);
        if ($results === null) {
            debugging('local_ai_yared_course: Commons search failed: ' . $finder->get_error(), DEBUG_DEVELOPER);
            throw new \moodle_exception('import_img_err_network', 'local_ai_yared_course');
        }
        $reply = ['ok' => true, 'query' => $query, 'results' => $results];
    } catch (\moodle_exception $e) {
        $reply = ['ok' => false, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        debugging('local_ai_yared_course: imgsearch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        $reply = ['ok' => false, 'error' => get_string('import_fmt_err_request', 'local_ai_yared_course')];
    }
    echo json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    die();
}

// Second phase: rewrite ONE question in another format (or another variant) and return its new card.
if ($action === 'reformulate') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');
    $reply = ['ok' => false, 'error' => ''];
    $lock = null;
    $slot = null;
    try {
        $draftid = required_param('draft', PARAM_INT);
        $index = required_param('index', PARAM_INT);
        $mode = required_param('mode', PARAM_ALPHA);
        $target = required_param('target', PARAM_ALPHANUMEXT);
        if (!in_array($mode, ['format', 'variant', 'other', 'restore', 'image'], true)
                || !in_array($target, exercise_extractor::TYPES, true)) {
            throw new \moodle_exception('import_fmt_err_request', 'local_ai_yared_course');
        }
        $manifest = import_store::load($draftid, $USER->id, $course->id);
        if (!isset($manifest['exercises'][$index])) {
            throw new \moodle_exception('import_fmt_err_request', 'local_ai_yared_course');
        }
        $ex = $manifest['exercises'][$index];
        \core\session\manager::write_close();

        // The crop the teacher adjusted on this card: keep it, so it survives every rewrite, and show it to the AI.
        $scalarvalue = function (string $name, string $default = '') {
            $raw = $_POST[$name] ?? $default;
            return is_scalar($raw) ? (string) $raw : $default;
        };
        $useimage = $scalarvalue('useimage', '0') === '1';
        $postedalt = \core_text::substr(trim(clean_param($scalarvalue('imagealt'), PARAM_TEXT)), 0, 250);
        $postedbox = crop_helper::normalise_box(json_decode($scalarvalue('box'), true));
        $cropupdate = null;
        $samebox = false;
        if ($postedbox !== null && is_array($ex['box'] ?? null) && count($ex['box']) === 4) {
            $samebox = true;
            foreach ($postedbox as $position => $coordinate) {
                $samebox = $samebox && abs((float) $coordinate - (float) $ex['box'][$position]) < 0.003;
            }
        }
        if ($postedbox !== null && !empty($ex['sourcefile']) && (empty($ex['image']) || !$samebox)) {
            $pagebytes = import_store::read_file($draftid, $USER->id, $ex['sourcefile']);
            $newcrop = $pagebytes !== null ? crop_helper::crop($pagebytes, $postedbox) : null;
            if ($newcrop !== null) {
                $extension = $newcrop['mimetype'] === 'image/png' ? 'png' : 'jpg';
                $cropupdate = [
                    'image' => import_store::add_file($draftid, $USER->id,
                        'q' . ((int) $index + 1) . '-crop-' . time() . '.' . $extension, $newcrop['data'],
                        $newcrop['mimetype']),
                    'box' => $postedbox,
                    'bytes' => $newcrop['data'],
                ];
            }
        }

        // A reference picture chosen from Wikimedia Commons: downloaded now, credited, and shown to the AI.
        $refupdate = null;
        if ($mode === 'image') {
            if (!commons_search::is_enabled()) {
                throw new \moodle_exception('import_img_err_disabled', 'local_ai_yared_course');
            }
            $imgurl = $scalarvalue('imgurl');
            if (!commons_search::is_commons_image_url($imgurl)) {
                throw new \moodle_exception('import_img_err_download', 'local_ai_yared_course');
            }
            $fetched = image_helper::fetch($imgurl);
            if (!empty($fetched['error'])) {
                throw new \moodle_exception('import_img_err_download', 'local_ai_yared_course');
            }
            $clean = function (string $name, int $max) use ($scalarvalue): string {
                return \core_text::substr(trim(clean_param($scalarvalue($name), PARAM_TEXT)), 0, $max);
            };
            $licenseurl = $scalarvalue('imglicenseurl');
            $pageurl = $scalarvalue('imgpage');
            $candidate = [
                'author' => $clean('imgauthor', 150),
                'license' => $clean('imglicense', 60),
                'licenseurl' => preg_match('#^https?://#i', $licenseurl) ? \core_text::substr($licenseurl, 0, 300) : '',
                'page' => preg_match('#^https://commons\.wikimedia\.org/#i', $pageurl) ? \core_text::substr($pageurl, 0, 400) : '',
            ];
            $refupdate = [
                'image' => import_store::add_file($draftid, $USER->id,
                    'q' . ((int) $index + 1) . '-ref-' . substr(sha1($fetched['data']), 0, 8) . '.'
                    . pathinfo($fetched['filename'], PATHINFO_EXTENSION), $fetched['data'], $fetched['mimetype']),
                'bytes' => $fetched['data'],
                'mime' => $fetched['mimetype'],
                'title' => $clean('imgtitle', 150),
                'credit' => commons_search::credit($candidate),
            ];
        }

        $fresh = null;
        $notice = '';
        if ($mode === 'restore') {
            $fresh = $ex['auto'] ?? ($ex['original'] ?? null);
            if (!$fresh) {
                throw new \moodle_exception('import_fmt_err_request', 'local_ai_yared_course');
            }
        } else if ($target === 'essay' && $mode === 'format') {
            // An open question needs no AI: it keeps the statement and drops the alternatives.
            $source = $ex['original'] ?? $ex;
            $fresh = [
                'type' => 'essay', 'text' => (string) $source['text'], 'answer' => null, 'answers' => [],
                'options' => [], 'feedback' => (string) ($source['feedback'] ?? ''), 'needsreview' => true,
                'math' => !empty($source['math']), 'imagealt' => '', 'rewritten' => false, 'ungradable' => false,
            ];
        } else {
            core_php_time_limit::raise(300);
            // A teacher can run a few rewrites at once; more than that must wait (the page queues them too).
            $slot = \local_ai_yared_course\local\ai_guard::acquire_user_slot('reformulate', (int) $USER->id, 3);
            if (!$slot) {
                throw new \moodle_exception('import_fmt_err_toomany', 'local_ai_yared_course');
            }
            // The picture that goes with the question lets the model ask about what it shows. It is the crop the
            // teacher sees on the card (adjusted or not), and nothing when the teacher unticked the image.
            $image = null;
            $imagebytes = null;
            if ($useimage || $scalarvalue('useimage', '') === '') {
                $imagebytes = $cropupdate['bytes'] ?? (!empty($ex['image'])
                    ? import_store::read_file($draftid, $USER->id, $ex['image']) : null);
                if ($imagebytes === null && !empty($ex['needsfigure']) && !empty($ex['exercise'])
                        && !empty($ex['sourcefile'])) {
                    $pagebytes = import_store::read_file($draftid, $USER->id, $ex['sourcefile']);
                    $crop = $pagebytes !== null ? crop_helper::crop($pagebytes, $ex['exercise']) : null;
                    $imagebytes = $crop['data'] ?? null;
                }
            }
            if ($refupdate !== null) {
                $imagebytes = $refupdate['bytes'];
            }
            if ($imagebytes !== null && strncmp(ltrim($imagebytes), '<svg', 4) === 0) {
                // A figure drawn by the plugin cannot be sent as a picture; the statement and the alt text describe it.
                $imagebytes = null;
            }
            if ($imagebytes !== null) {
                $info = @getimagesizefromstring($imagebytes);
                $image = [
                    'mime' => $info ? image_type_to_mime_type($info[2]) : 'image/jpeg',
                    'data' => base64_encode($imagebytes),
                ];
            }
            $language = (string) ($manifest['gen']['language'] ?? '')
                ?: (import_form::LANGUAGE_NAMES[$manifest['language'] ?? 'es'] ?? import_form::LANGUAGE_NAMES['es']);
            $isvariant = $mode === 'image' ? false : ($mode !== 'format' || $target === $ex['type']);
            $client->set_temperature($isvariant ? 0.7 : 0.3);
            // "Another model" only makes sense when more than one provider is configured.
            if ($mode === 'other' && !empty($ex['profilelabel']) && $client->count_usable() > 1) {
                $client->avoid_profile((string) $ex['profilelabel']);
            }
            $extractor = new exercise_extractor($client, true);
            $fresh = $extractor->reformulate($ex, $target, $language, (int) ($manifest['age'] ?? 12), $image,
                $isvariant ? (string) $ex['text'] : '', $refupdate['title'] ?? '');
            if ($fresh === null) {
                throw new \moodle_exception('import_fmt_err_empty', 'local_ai_yared_course');
            }
            $fresh['rewritten'] = true;
            $fresh['ungradable'] = false;
            $ex['profilelabel'] = $client->get_active_profile_label();
            $notice = implode(' ', $extractor->get_warnings());
            if ($fresh['type'] !== $target) {
                $notice = trim(get_string('import_fmt_notsame', 'local_ai_yared_course',
                    get_string('qtype_' . $fresh['type'], 'local_ai_yared_course')) . ' ' . $notice);
            }
        }

        // Reload under a lock so a second request for another question does not overwrite this change.
        $lock = \core\lock\lock_config::get_lock_factory('local_ai_yared_course_import')
            ->get_lock('draft_' . $draftid, 30);
        if (!$lock) {
            throw new \moodle_exception('import_fmt_err_busy', 'local_ai_yared_course');
        }
        $manifest = import_store::load($draftid, $USER->id, $course->id);
        $current = $manifest['exercises'][$index];
        foreach (['type', 'text', 'answer', 'answers', 'options', 'feedback', 'needsreview', 'math'] as $key) {
            $current[$key] = $fresh[$key] ?? ($key === 'needsreview' ? false : ($current[$key] ?? null));
        }
        $current['rewritten'] = !empty($fresh['rewritten']);
        $current['ungradable'] = !empty($fresh['ungradable']);
        // The crop and image settings of the card are the teacher's, and stay as they left them.
        if ($cropupdate !== null) {
            $current['image'] = $cropupdate['image'];
            $current['box'] = $cropupdate['box'];
        }
        if ($scalarvalue('useimage', '') !== '') {
            $current['useimage'] = $useimage;
        }
        if ($postedalt !== '') {
            $current['imagealt'] = $postedalt;
        } else if (($fresh['imagealt'] ?? '') !== '' && empty($current['image'])) {
            $current['imagealt'] = $fresh['imagealt'];
        }
        if ($refupdate !== null) {
            $current['image'] = $refupdate['image'];
            $current['useimage'] = true;
            // The alt text stays neutral: a title such as "Portrait of X" would give the answer away.
            $current['imagealt'] = '';
            $current['imagecredit'] = $refupdate['credit'];
        }
        $current['reformulated'] = $mode !== 'restore';
        if (!empty($ex['profilelabel'])) {
            $current['profilelabel'] = $ex['profilelabel'];
        }
        $manifest['exercises'][$index] = $current;
        import_store::save_manifest($draftid, $USER->id, $manifest);
        $lock->release();
        $lock = null;

        ob_start();
        local_ai_yared_course_render_card((int) $index, $current, null, $draftid, $manifest);
        $reply = [
            'ok' => true,
            'html' => ob_get_clean(),
            'open' => in_array($current['type'], exercise_extractor::OPEN_TYPES, true),
            'notice' => $notice,
        ];
    } catch (\moodle_exception $e) {
        $reply = ['ok' => false, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        debugging('local_ai_yared_course: reformulate failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        $reply = ['ok' => false, 'error' => get_string('import_fmt_err_request', 'local_ai_yared_course')];
    } finally {
        if ($lock) {
            $lock->release();
        }
        if ($slot) {
            $slot->release();
        }
    }
    echo json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    die();
}

// Discard a pending import.
if ($action === 'discard') {
    require_sesskey();
    $draftid = required_param('draft', PARAM_INT);
    import_store::delete($draftid, $USER->id);
    redirect($bankurl, get_string('import_discarded', 'local_ai_yared_course'), null,
        \core\output\notification::NOTIFY_INFO);
}

// Analyse the uploaded files.
if ($action === 'extract') {
    $mform = new import_form($PAGE->url->out(false), [
        'courseid' => $course->id,
        'categories' => $categories,
        'cancreatecategory' => $cancreatecategory,
    ]);
    if ($mform->is_cancelled()) {
        redirect($bankurl);
    }
    $formdata = $mform->get_data();
    if ($formdata) {
        require_sesskey();
        core_php_time_limit::raise(900);
        raise_memory_limit(MEMORY_EXTRA);
        // Release the session lock during the long AI call.
        \core\session\manager::write_close();

        $draftid = null;
        try {
            $sources = source_reader::read_draft_files((int) $formdata->sources);
            $language = import_form::LANGUAGE_NAMES[$formdata->language] ?? import_form::LANGUAGE_NAMES['es'];
            $age = generate_form::resolve_age($formdata->level, (int) $formdata->age);
            $instructions = trim((string) ($formdata->instructions ?? ''));
            $attachimage = !empty($formdata->attachimage);
            $categoryid = (int) $formdata->categoryid;
            $newcategory = $categoryid === 0 ? trim((string) ($formdata->newcategory ?? '')) : '';
            if ($categoryid === 0 && $newcategory === '') {
                throw new \moodle_exception('err_newcategory', 'local_ai_yared_course');
            }

            $draftid = import_store::create($USER->id, [
                'version' => 2,
                'userid' => $USER->id,
                'courseid' => $course->id,
                'timecreated' => time(),
                'categoryid' => $categoryid,
                'newcategory' => $newcategory,
                'language' => $formdata->language,
                'level' => $formdata->level,
                'age' => $age,
                'math' => true,
                'attachimage' => $attachimage,
                'sources' => [],
                'exercises' => [],
                'warnings' => [],
            ]);

            // A low temperature keeps the transcription faithful to the page.
            $client->set_temperature(0.2);
            $extractor = new exercise_extractor($client, true);
            $exercises = [];
            $seen = [];
            $sourcesummary = [];
            $duplicates = 0;
            $noimage = 0;
            $limited = false;
            $closedcount = 0;
            $overcap = 0;
            $page = 0;

            // Add the exercises of one page, removing duplicates and preparing its crops.
            $additems = function(array $items, ?array $prepared, ?string $sourcefile) use (
                &$exercises, &$seen, &$duplicates, &$noimage, &$limited, &$closedcount, &$overcap, $attachimage,
                $draftid, $USER, $client, $extractor, $language, $age
            ): void {
                foreach ($items as $item) {
                    if (count($exercises) >= exercise_extractor::MAX_PER_PDF) {
                        $limited = true;
                        break;
                    }
                    $key = sha1(\core_text::strtolower(trim((string) preg_replace('/\s+/u', ' ', $item['text']))));
                    if (isset($seen[$key])) {
                        $duplicates++;
                        continue;
                    }
                    $seen[$key] = true;

                    $item['image'] = null;
                    $item['useimage'] = false;
                    // The image of a question is its figure (a photo, a map, a drawing the statement refers to). The whole
                    // exercise is cropped only when it depends on a picture the model could not enclose; an exercise that is
                    // only text never gets an image of itself. The exercise box stays as the starting point if the teacher
                    // decides to draw a crop.
                    // Mathematical exercises keep the crop of the whole exercise (formulas and layouts read better as an
                    // image); language and other text-only exercises do not.
                    if (!empty($item['math']) && !empty($item['exercise'])) {
                        $box = $item['exercise'];
                    } else {
                        $box = !empty($item['figure']) ? $item['figure']
                            : (!empty($item['needsfigure']) ? ($item['exercise'] ?? null) : null);
                    }
                    $item['sourcefile'] = $sourcefile;
                    $item['box'] = $box ?: ($item['exercise'] ?? null);
                    // What the AI read first, so the teacher can go back after trying other formats.
                    $item['original'] = [
                        'type' => $item['type'],
                        'text' => $item['text'],
                        'answer' => $item['answer'],
                        'answers' => $item['answers'],
                        'options' => $item['options'],
                        'feedback' => $item['feedback'],
                        'imagealt' => $item['imagealt'],
                        'needsreview' => $item['needsreview'],
                        'math' => $item['math'],
                    ];
                    $item['reformulated'] = false;
                    $item['profilelabel'] = $client->get_active_profile_label();
                    if ($prepared !== null && $attachimage && $box) {
                        $crop = crop_helper::crop($prepared['data'], $box);
                        if ($crop !== null) {
                            $extension = $crop['mimetype'] === 'image/png' ? 'png' : 'jpg';
                            $filename = 'q' . (count($exercises) + 1) . '-'
                                . ($box === ($item['figure'] ?? null) ? 'figure' : 'exercise') . '.' . $extension;
                            $item['image'] = import_store::add_file($draftid, $USER->id, $filename,
                                $crop['data'], $crop['mimetype']);
                            $item['useimage'] = true;
                            if ($item['imagealt'] === '') {
                                $item['imagealt'] = get_string('image_default_alt', 'local_ai_yared_course');
                            }
                        } else {
                            $noimage++;
                        }
                    } else if ($prepared !== null && $attachimage && !$box && !empty($item['needsfigure'])) {
                        $noimage++;
                    }

                    // Short answer and essay are never produced automatically: close them as multiple choice or
                    // true/false (one extra request each, with the picture when there is one).
                    if (in_array($item['type'], exercise_extractor::OPEN_TYPES, true) && empty($item['ungradable'])) {
                        if ($closedcount < exercise_extractor::MAX_CLOSE_PASSES) {
                            $closedcount++;
                            $picture = null;
                            $picturebytes = !empty($item['image'])
                                ? import_store::read_file($draftid, $USER->id, $item['image']) : null;
                            if ($picturebytes !== null) {
                                $pictureinfo = @getimagesizefromstring($picturebytes);
                                $picture = [
                                    'mime' => $pictureinfo ? image_type_to_mime_type($pictureinfo[2]) : 'image/jpeg',
                                    'data' => base64_encode($picturebytes),
                                ];
                            }
                            $item = $extractor->close_open($item, $language, $age, $picture);
                            $item['profilelabel'] = $client->get_active_profile_label();
                        } else {
                            $overcap++;
                        }
                    }
                    // The version the AI proposed automatically, to come back to it after trying other formats.
                    $item['auto'] = [
                        'type' => $item['type'],
                        'text' => $item['text'],
                        'answer' => $item['answer'],
                        'answers' => $item['answers'],
                        'options' => $item['options'],
                        'feedback' => $item['feedback'],
                        'imagealt' => $item['imagealt'],
                        'needsreview' => $item['needsreview'],
                        'math' => $item['math'],
                        'rewritten' => !empty($item['rewritten']),
                        'ungradable' => !empty($item['ungradable']),
                    ];
                    // An exercise that cannot be graded automatically is not saved unless the teacher decides so.
                    $item['include'] = empty($item['ungradable']);
                    $exercises[] = $item;
                }
            };

            foreach ($sources as $source) {
                if (count($exercises) >= exercise_extractor::MAX_PER_PDF) {
                    $limited = true;
                    break;
                }
                try {
                    if ($source['type'] === 'pdf' && !$client->supports_pdf()) {
                        $warnings[] = get_string('import_warn_nopdf', 'local_ai_yared_course', s($source['filename']));
                        continue;
                    }

                    if ($source['type'] === 'pdf') {
                        // Convert the PDF pages to images so the exercises can be cropped too.
                        $rendered = pdf_renderer::render($source['content']);
                        if ($rendered) {
                            $summary = [
                                'filename' => $source['filename'],
                                'type' => 'pdf',
                                'pages' => count($rendered),
                                'pagefile' => null,
                                'pagenumber' => 0,
                            ];
                            foreach ($rendered as $paged) {
                                if (count($exercises) >= exercise_extractor::MAX_PER_PDF) {
                                    $limited = true;
                                    break;
                                }
                                try {
                                    $page++;
                                    $prepared = source_reader::prepare_image($paged['data']);
                                    // Keep the working page so the teacher can adjust the crop during the review.
                                    $sourcefile = import_store::add_file($draftid, $USER->id, 'source-' . $page . '.jpg',
                                        $prepared['data'], $prepared['mimetype']);
                                    if ($summary['pagefile'] === null) {
                                        $summary['pagefile'] = $sourcefile;
                                        $summary['pagenumber'] = $page;
                                    }
                                    $items = $extractor->extract_from_image($prepared, $page, $language, $age,
                                        $instructions);
                                    $warnings = array_merge($warnings, $extractor->get_warnings());
                                    $additems($items, $prepared, $sourcefile);
                                } catch (\moodle_exception $e) {
                                    $warnings[] = get_string('import_warn_pagefailed', 'local_ai_yared_course', (object) [
                                        'file' => s($source['filename']),
                                        'page' => $page,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                                unset($prepared);
                            }
                            $sourcesummary[] = $summary;
                            continue;
                        }

                        // No rasteriser: keep the old text-only PDF path and warn about the missing crops.
                        if (pdf_renderer::get_last_error() !== '') {
                            $renderdetail = pdf_renderer::get_last_error();
                            if (pdf_renderer::get_last_detail() !== ''
                                    && has_capability('moodle/site:config', context_system::instance())) {
                                $renderdetail .= ': ' . pdf_renderer::get_last_detail();
                            }
                            $warnings[] = get_string('import_warn_pdfrender', 'local_ai_yared_course', s($renderdetail));
                        }
                        $items = $extractor->extract_from_pdf($source['content'], $language, $age, $instructions);
                        $warnings = array_merge($warnings, $extractor->get_warnings());
                        $sourcesummary[] = [
                            'filename' => $source['filename'],
                            'type' => 'pdf',
                            'pages' => (int) ($source['pages'] ?? 0),
                            'pagefile' => null,
                            'pagenumber' => 0,
                        ];
                        $additems($items, null, null);
                        $page += max(1, (int) ($source['pages'] ?? 0));
                        continue;
                    }

                    $prepared = source_reader::prepare_image($source['content']);
                    $page++;
                    // Keep the working page so the teacher can adjust or redraw crops during the review.
                    $sourcefile = import_store::add_file($draftid, $USER->id, 'source-' . $page . '.jpg',
                        $prepared['data'], $prepared['mimetype']);
                    $items = $extractor->extract_from_image($prepared, $page, $language, $age, $instructions);
                    $warnings = array_merge($warnings, $extractor->get_warnings());
                    $sourcesummary[] = [
                        'filename' => $source['filename'],
                        'type' => 'image',
                        'pages' => 1,
                        'pagefile' => $sourcefile,
                        'pagenumber' => $page,
                    ];
                    $additems($items, $prepared, $sourcefile);
                    unset($prepared);
                } catch (\moodle_exception $e) {
                    $warnings[] = get_string('import_warn_filefailed', 'local_ai_yared_course', (object) [
                        'file' => s($source['filename']),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if ($limited) {
                $warnings[] = get_string('import_warn_total', 'local_ai_yared_course', exercise_extractor::MAX_PER_PDF);
            }
            if ($duplicates > 0) {
                $warnings[] = get_string('import_warn_duplicates', 'local_ai_yared_course', $duplicates);
            }
            if ($noimage > 0) {
                $warnings[] = get_string('import_warn_noimage', 'local_ai_yared_course', $noimage);
            }
            if ($overcap > 0) {
                $warnings[] = get_string('import_warn_closecap', 'local_ai_yared_course', $overcap);
            }
            $ungradable = 0;
            foreach ($exercises as $listed) {
                $ungradable += !empty($listed['ungradable']) ? 1 : 0;
            }
            if ($ungradable > 0) {
                $warnings[] = get_string('import_warn_ungradable', 'local_ai_yared_course', $ungradable);
            }
            if (!$exercises) {
                throw new \moodle_exception('import_error_noexercises', 'local_ai_yared_course');
            }

            $manifest = [
                'version' => 2,
                'userid' => $USER->id,
                'courseid' => $course->id,
                'timecreated' => time(),
                'categoryid' => $categoryid,
                'newcategory' => $newcategory,
                'language' => $formdata->language,
                'level' => $formdata->level,
                'age' => $age,
                'math' => true,
                'attachimage' => $attachimage,
                'sources' => $sourcesummary,
                'exercises' => $exercises,
                'warnings' => array_values(array_unique($warnings)),
            ];
            import_store::save_manifest($draftid, $USER->id, $manifest);
            $fallbacks = $client->get_fallback_messages();
            if ($fallbacks) {
                $warnings[] = get_string('warn_fallback', 'local_ai_yared_course', s(implode(' | ', $fallbacks)));
            }
            redirect(new moodle_url('/local/ai_yared_course/import.php', [
                'id' => $course->id,
                'draft' => $draftid,
            ]));
        } catch (\moodle_exception $e) {
            if ($draftid !== null) {
                import_store::delete($draftid, $USER->id);
            }
            $errors[] = $e->getMessage();
            $mform->set_data($formdata);
        }
    }
}

// Save the reviewed questions.
if ($action === 'save') {
    require_sesskey();
    $draftid = required_param('draft', PARAM_INT);
    $posted = $_POST['items'] ?? null;
    try {
        $manifest = import_store::load($draftid, $USER->id, $course->id);
    } catch (\moodle_exception $e) {
        $errors[] = $e->getMessage();
    }

    if ($manifest !== null) {
        $showform = false;
        $questions = [];
        $scalar = function ($value): string {
            return is_scalar($value) ? (string) $value : '';
        };
        foreach ($manifest['exercises'] as $i => $ex) {
            $input = is_array($posted) && isset($posted[$i]) && is_array($posted[$i]) ? $posted[$i] : null;
            if (!$input || empty($input['include'])) {
                continue;
            }
            $label = $ex['number'] !== '' ? $ex['number'] : (string) ($i + 1);
            $text = trim(clean_param($scalar($input['text'] ?? ''), PARAM_RAW));
            if ($text === '') {
                $errors[] = get_string('import_err_text', 'local_ai_yared_course', s($label));
                continue;
            }
            if (\core_text::strlen($text) > exercise_extractor::MAX_TEXT) {
                $text = \core_text::substr($text, 0, exercise_extractor::MAX_TEXT);
            }
            $feedback = trim(clean_param($scalar($input['feedback'] ?? ''), PARAM_RAW));
            if (\core_text::strlen($feedback) > 4000) {
                $feedback = \core_text::substr($feedback, 0, 4000);
            }

            $question = [
                'type' => $ex['type'],
                'text' => $text,
                'feedback' => $feedback,
                'image' => null,
                'imagealt' => '',
                'math' => false,
                'answers' => [],
                'options' => [],
            ];

            if ($ex['type'] === 'essay') {
                $question['options'] = [];
            } else if ($ex['type'] === 'shortanswer') {
                $accepted = [];
                foreach (preg_split('/\R/u', $scalar($input['answers'] ?? '')) as $line) {
                    $line = trim(clean_param($line, PARAM_TEXT));
                    if ($line !== '' && !in_array($line, $accepted, true) && count($accepted) < 5) {
                        $accepted[] = \core_text::substr($line, 0, 200);
                    }
                }
                if (!$accepted) {
                    $errors[] = get_string('import_err_answers', 'local_ai_yared_course', s($label));
                    continue;
                }
                $question['answers'] = $accepted;
            } else if ($ex['type'] === 'truefalse') {
                $answer = $scalar($input['answer'] ?? '');
                if (!in_array($answer, ['0', '1'], true)) {
                    $errors[] = get_string('import_err_answer', 'local_ai_yared_course', s($label));
                    continue;
                }
                $question['answer'] = $answer === '1';
                $question['options'] = [];
            } else if (in_array($ex['type'], exercise_extractor::GAP_TYPES, true)) {
                // Missing words: empty choices are dropped and the [[n]] placeholders are renumbered to match.
                $choices = [];
                $map = [];
                foreach ($ex['options'] as $j => $option) {
                    $choicetext = '';
                    if (is_array($input['options'] ?? null) && isset($input['options'][$j]['text'])) {
                        $choicetext = trim(clean_param($scalar($input['options'][$j]['text']), PARAM_TEXT));
                    }
                    if ($choicetext === '') {
                        continue;
                    }
                    $choices[] = \core_text::substr($choicetext, 0, 200);
                    $map[$j + 1] = count($choices);
                }
                $numbers = exercise_extractor::placeholders($text);
                $badgaps = count($choices) < 2 || !$numbers;
                foreach ($numbers as $number) {
                    if (!isset($map[$number])) {
                        $badgaps = true;
                    }
                }
                if ($badgaps) {
                    $errors[] = get_string('import_err_gaps', 'local_ai_yared_course', s($label));
                    continue;
                }
                $question['text'] = preg_replace_callback('/\[\[(\d+)\]\]/u', function ($match) use ($map) {
                    return '[[' . $map[(int) $match[1]] . ']]';
                }, $text);
                foreach ($choices as $choice) {
                    $question['options'][] = ['text' => $choice, 'correct' => false];
                }
            } else {
                $options = [];
                $ismulti = $ex['type'] === 'multiselect';
                $correctset = [];
                if ($ismulti) {
                    foreach ((array) ($input['correctm'] ?? []) as $chosenindex) {
                        $correctset[(int) $scalar($chosenindex)] = true;
                    }
                }
                $correct = isset($input['correct']) ? (int) $input['correct'] : -1;
                $iscorrectvalid = true;
                foreach ($ex['options'] as $j => $option) {
                    $optiontext = '';
                    if (is_array($input['options'] ?? null) && isset($input['options'][$j]['text'])) {
                        $optiontext = trim(clean_param($scalar($input['options'][$j]['text']), PARAM_RAW));
                    }
                    if (\core_text::strlen($optiontext) > 1000) {
                        $optiontext = \core_text::substr($optiontext, 0, 1000);
                    }
                    $isright = $ismulti ? isset($correctset[(int) $j]) : ($correct === (int) $j);
                    if ($optiontext === '') {
                        if ($isright) {
                            $iscorrectvalid = false;
                        }
                        continue;
                    }
                    $options[] = ['text' => $optiontext, 'correct' => $isright];
                }
                if (count($options) < 2) {
                    $errors[] = get_string('import_err_options', 'local_ai_yared_course', s($label));
                    continue;
                }
                $correctcount = 0;
                foreach ($options as $option) {
                    $correctcount += $option['correct'] ? 1 : 0;
                }
                if ($ismulti ? ($correctcount < 1 || !$iscorrectvalid) : ($correctcount !== 1 || !$iscorrectvalid)) {
                    $errors[] = get_string($ismulti ? 'import_err_correctmulti' : 'import_err_correct',
                        'local_ai_yared_course', s($label));
                    continue;
                }
                $question['options'] = $options;
            }

            if (!empty($input['useimage'])) {
                $imagedata = null;
                $rawbox = $scalar($input['box'] ?? '');
                $box = null;
                if ($rawbox !== '') {
                    $decodedbox = json_decode($rawbox, true);
                    $box = crop_helper::normalise_box($decodedbox);
                }
                if ($box !== null && !empty($ex['sourcefile'])) {
                    $sourcedata = import_store::read_file($draftid, $USER->id, $ex['sourcefile']);
                    if ($sourcedata !== null) {
                        $crop = crop_helper::crop($sourcedata, $box);
                        if ($crop !== null) {
                            $imagedata = $crop['data'];
                        } else {
                            $warnings[] = get_string('import_warn_cropfailed', 'local_ai_yared_course', s($label));
                        }
                    }
                }
                // Keep the stored crop when the teacher did not change the box or the new crop failed.
                if ($imagedata === null && !empty($ex['image'])) {
                    $imagedata = import_store::read_file($draftid, $USER->id, $ex['image']);
                }
                if ($imagedata !== null) {
                    $alt = trim(clean_param($scalar($input['imagealt'] ?? ''), PARAM_TEXT));
                    if (strncmp(ltrim($imagedata), '<svg', 4) === 0) {
                        // A figure drawn by the plugin: question_saver stores it as an SVG file after checking it.
                        $question['figure_svg'] = $imagedata;
                    } else {
                        $info = @getimagesizefromstring($imagedata);
                        $mimetype = $info ? image_type_to_mime_type($info[2]) : 'image/png';
                        $question['image'] = 'data:' . $mimetype . ';base64,' . base64_encode($imagedata);
                    }
                    $question['imagealt'] = \core_text::substr($alt, 0, 250);
                    if (!empty($ex['imagecredit'])) {
                        // The credit goes in the general feedback (shown after answering), so it does not give the answer away.
                        $question['feedback'] = trim($question['feedback'] . "\n\n"
                            . get_string('import_ref_creditline', 'local_ai_yared_course', s($ex['imagecredit'])));
                    }
                }
            }

            // LaTeX is rendered only where the teacher's final text still has it.
            $alltexts = [$question['text'], $question['feedback']];
            foreach ($question['options'] as $keptoption) {
                $alltexts[] = $keptoption['text'];
            }
            foreach ($alltexts as $oneText) {
                $question['math'] = $question['math'] || \local_ai_yared_course\local\latex_cleaner::has_latex($oneText);
            }

            $questions[] = $question;
        }

        if (!$errors && !$questions) {
            $errors[] = get_string('import_err_none', 'local_ai_yared_course');
        }
        // Short-answer and essay questions are saved last, after the ones that grade themselves.
        usort($questions, function ($x, $y) {
            return (int) in_array($x['type'], exercise_extractor::OPEN_TYPES, true)
                <=> (int) in_array($y['type'], exercise_extractor::OPEN_TYPES, true);
        });

        if (!$errors) {
            try {
                if ((int) $manifest['categoryid'] === 0) {
                    require_capability('moodle/question:managecategory', $context);
                    $category = category_helper::create($context, (string) $manifest['newcategory']);
                } else {
                    $category = $DB->get_record('question_categories', [
                        'id' => (int) $manifest['categoryid'],
                        'contextid' => $context->id,
                    ], '*', MUST_EXIST);
                }

                $result = question_saver::save($questions, $category, !empty($_POST['draftstatus']));
                if ($result['created'] === 0) {
                    throw new \moodle_exception('import_error_save', 'local_ai_yared_course');
                }
                $frombank = ($manifest['origin'] ?? '') === bank_draft::ORIGIN;
                \local_ai_yared_course\local\usage_log::event(
                    $frombank ? \local_ai_yared_course\local\usage_log::BANK : \local_ai_yared_course\local\usage_log::IMPORT,
                    (int) $course->id, '', (int) ($manifest['age'] ?? 0),
                    ['created' => (int) $result['created'], 'failed' => (int) $result['failed']]
                        + ($frombank ? ['review' => 1] : []),
                    $result['failed'] > 0 ? 'warn' : 'ok');
                import_store::delete($draftid, $USER->id);
                $message = get_string('import_saved', 'local_ai_yared_course', (object) [
                    'created' => $result['created'],
                    'category' => format_string($category->name, true, ['context' => $context]),
                ]);
                if ($result['failed'] > 0) {
                    $message .= ' ' . get_string('failed', 'local_ai_yared_course', $result['failed']);
                }
                $target = new moodle_url('/question/edit.php', [
                    'courseid' => $course->id,
                    'cat' => $category->id . ',' . $category->contextid,
                ]);
                redirect($target, $message, null, $result['failed'] > 0
                    ? \core\output\notification::NOTIFY_WARNING
                    : \core\output\notification::NOTIFY_SUCCESS);
            } catch (\moodle_exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Review a pending import.
if ($action === '' && !$manifest) {
    $draftid = optional_param('draft', 0, PARAM_INT);
    if ($draftid) {
        try {
            $manifest = import_store::load($draftid, $USER->id, $course->id);
            $showform = false;
            $reviewdraft = $draftid;
        } catch (\moodle_exception $e) {
            $errors[] = $e->getMessage();
        }
    } else {
        import_store::cleanup_old($USER->id);
    }
} else if ($action === 'save' && $manifest) {
    // Validation errors: keep the review visible with the teacher's edits.
    $showform = false;
    $reviewdraft = (int) ($_POST['draft'] ?? 0);
}

$mform = $mform ?? new import_form($PAGE->url->out(false), [
    'courseid' => $course->id,
    'categories' => $categories,
    'cancreatecategory' => $cancreatecategory,
]);

echo $OUTPUT->header();
$frombankdraft = $manifest && !$showform && ($manifest['origin'] ?? '') === bank_draft::ORIGIN;
echo $OUTPUT->heading(get_string($frombankdraft ? 'bank_review_heading' : 'import_heading', 'local_ai_yared_course'));

foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'error');
}
foreach ($warnings as $warning) {
    echo $OUTPUT->notification($warning, 'warning');
}

if ($manifest && !$showform) {
    local_ai_yared_course_render_review($manifest, $reviewdraft, $posted, $context);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('import_intro', 'local_ai_yared_course'), ['class' => 'text-muted']);
if (!$client->supports_pdf()) {
    echo $OUTPUT->notification(get_string('import_nopdfwarning', 'local_ai_yared_course'), 'info');
}
echo html_writer::div(
    $OUTPUT->single_button($bankurl, get_string('import_backtobank', 'local_ai_yared_course'), 'get'),
    'mb-3'
);

echo html_writer::start_div('aiyared-import-steps');
foreach ([1, 2, 3] as $step) {
    echo html_writer::div(
        html_writer::span((string) $step, 'aiyared-import-step-number') .
        html_writer::span(get_string('import_step' . $step, 'local_ai_yared_course')),
        'aiyared-import-step'
    );
}
echo html_writer::end_div();

$mform->display();

$messages = [
    get_string('import_wait_prepare', 'local_ai_yared_course'),
    get_string('import_wait_reading', 'local_ai_yared_course'),
    get_string('import_wait_splitting', 'local_ai_yared_course'),
    get_string('import_wait_cropping', 'local_ai_yared_course'),
    get_string('import_wait_review', 'local_ai_yared_course'),
];
local_ai_yared_course_render_wait($messages, get_string('import_wait_title', 'local_ai_yared_course'),
    get_string('import_wait_intro', 'local_ai_yared_course'));

echo $OUTPUT->footer();

/**
 * Render the waiting overlay and its rotation script.
 *
 * @param string[] $messages Messages shown in order.
 * @param string $title Overlay title.
 * @param string $intro Overlay introduction.
 */
function local_ai_yared_course_render_wait(array $messages, string $title, string $intro): void {
    global $PAGE;

    echo html_writer::start_div('yared-bank-wait', [
        'id' => 'yared-bank-wait',
        'hidden' => 'hidden',
        'role' => 'dialog',
        'aria-modal' => 'true',
        'aria-labelledby' => 'yared-bank-wait-title',
        'aria-describedby' => 'yared-bank-wait-intro',
        'aria-hidden' => 'true',
        'tabindex' => '-1',
    ]);
    echo html_writer::start_div('yared-bank-wait-card');
    echo html_writer::div('', 'yared-bank-wait-spinner', ['aria-hidden' => 'true']);
    echo html_writer::tag('h2', $title, ['id' => 'yared-bank-wait-title', 'class' => 'h4 mb-2']);
    echo html_writer::tag('p', $intro, ['id' => 'yared-bank-wait-intro', 'class' => 'text-muted']);
    echo html_writer::tag('p', '', [
        'id' => 'yared-bank-wait-message',
        'class' => 'yared-bank-wait-message',
        'aria-live' => 'polite',
    ]);
    echo html_writer::div(
        html_writer::span(get_string('bank_generating_elapsed', 'local_ai_yared_course'),
            'yared-bank-wait-elapsed-label') . ' ' .
        html_writer::tag('strong', '00:00', ['id' => 'yared-bank-wait-time']),
        'yared-bank-wait-elapsed'
    );
    echo html_writer::end_div();
    echo html_writer::end_div();

    $json = json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $js = <<<JS
(function() {
    'use strict';

    function init() {
        var button = document.getElementById('id_submitbutton');
        var form = button ? button.closest('form') : document.querySelector('form.mform');
        if (!button && form) {
            button = form.querySelector('button[type="submit"], input[type="submit"]');
        }
        var overlay = document.getElementById('yared-bank-wait');
        if (!form || !button || !overlay) {
            return;
        }
        var message = document.getElementById('yared-bank-wait-message');
        var time = document.getElementById('yared-bank-wait-time');
        var messages = {$json};
        var started = 0;
        var tick = null;
        var rotate = null;
        var index = 0;
        var submitting = false;

        function pad(value) {
            return (value < 10 ? '0' : '') + value;
        }
        function updateTime() {
            if (!started || !time) { return; }
            var elapsed = Math.max(0, Math.floor((Date.now() - started) / 1000));
            var hours = Math.floor(elapsed / 3600);
            var minutes = Math.floor((elapsed % 3600) / 60);
            var seconds = elapsed % 60;
            time.textContent = (hours > 0 ? hours + ':' : '') + pad(minutes) + ':' + pad(seconds);
        }
        function hide() {
            submitting = false;
            overlay.hidden = true;
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('yared-bank-waiting');
            if (tick) { window.clearInterval(tick); tick = null; }
            if (rotate) { window.clearInterval(rotate); rotate = null; }
            button.removeAttribute('aria-disabled');
        }
        function show() {
            if (submitting) { return; }
            submitting = true;
            index = 0;
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('yared-bank-waiting');
            if (message) { message.textContent = messages[0]; }
            started = Date.now();
            updateTime();
            tick = window.setInterval(updateTime, 1000);
            rotate = window.setInterval(function() {
                index = (index + 1) % messages.length;
                if (message) { message.textContent = messages[index]; }
            }, 5000);
            button.setAttribute('aria-disabled', 'true');
            try { overlay.focus(); } catch (e) { /* Ignore. */ }
        }

        form.addEventListener('submit', function(event) {
            if (submitting) { event.preventDefault(); return; }
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }
            show();
        });
        form.addEventListener('invalid', hide, true);
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) { hide(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
    $PAGE->requires->js_init_code($js);
}

/**
 * Whether more than one AI provider is configured ("another model" needs a second one).
 *
 * @return bool
 */
function local_ai_yared_course_multi_provider(): bool {
    static $multi = null;
    if ($multi === null) {
        $multi = ai_factory::create()->count_usable() > 1;
    }
    return $multi;
}

/**
 * Options of a format selector, grouped: the formats that grade themselves, then the ones that need manual review.
 *
 * @return string HTML of the optgroups.
 */
function local_ai_yared_course_format_options(): string {
    $html = '';
    foreach ([
        get_string('import_fmt_group_closed', 'local_ai_yared_course') => array_values(array_diff(
            exercise_extractor::TYPES, exercise_extractor::OPEN_TYPES)),
        get_string('import_fmt_group_open', 'local_ai_yared_course') => exercise_extractor::OPEN_TYPES,
    ] as $groupname => $grouptypes) {
        $html .= html_writer::start_tag('optgroup', ['label' => $groupname]);
        foreach ($grouptypes as $grouptype) {
            $html .= html_writer::tag('option', get_string('qtype_' . $grouptype, 'local_ai_yared_course'),
                ['value' => $grouptype]);
        }
        $html .= html_writer::end_tag('optgroup');
    }
    return $html;
}

/**
 * Render the controls that change the format of one question (second phase of the import).
 *
 * @param array $ex Exercise of the manifest.
 */
function local_ai_yared_course_render_format_controls(array $ex): void {
    $current = $ex['type'];
    $viable = (array) ($ex['viable'] ?? []);
    $recommended = (string) ($ex['recommended'] ?? '');
    $groups = [
        get_string('import_fmt_group_closed', 'local_ai_yared_course') => array_values(array_diff(
            exercise_extractor::TYPES, exercise_extractor::OPEN_TYPES)),
        get_string('import_fmt_group_open', 'local_ai_yared_course') => exercise_extractor::OPEN_TYPES,
    ];
    $select = html_writer::start_tag('select', [
        'class' => 'form-select form-select-sm w-auto aiyared-format-select',
        'aria-label' => get_string('import_fmt_label', 'local_ai_yared_course'),
    ]);
    foreach ($groups as $groupname => $types) {
        $select .= html_writer::start_tag('optgroup', ['label' => $groupname]);
        foreach ($types as $type) {
            $name = get_string('qtype_' . $type, 'local_ai_yared_course');
            if ($type === $recommended) {
                $name .= ' ' . get_string('import_fmt_recommended', 'local_ai_yared_course');
            } else if (in_array($type, $viable, true)) {
                $name .= ' ' . get_string('import_fmt_viable', 'local_ai_yared_course');
            }
            $select .= html_writer::tag('option', $name, [
                'value' => $type,
                'selected' => $type === $current ? 'selected' : null,
            ]);
        }
        $select .= html_writer::end_tag('optgroup');
    }
    $select .= html_writer::end_tag('select');

    $button = function (string $mode, string $stringkey, string $class) {
        return html_writer::tag('button', get_string($stringkey, 'local_ai_yared_course'), [
            'type' => 'button',
            'class' => 'btn btn-sm ' . $class . ' aiyared-reformat',
            'data-mode' => $mode,
            'title' => get_string($stringkey . '_help', 'local_ai_yared_course'),
        ]);
    };
    echo html_writer::start_div('aiyared-format-row d-flex flex-wrap gap-2 align-items-center mb-3 p-2 border rounded');
    echo html_writer::tag('span', get_string('import_fmt_label', 'local_ai_yared_course'), ['class' => 'fw-bold small']);
    echo $select;
    echo $button('format', 'import_fmt_apply', 'btn-outline-primary');
    echo $button('variant', 'import_fmt_variant', 'btn-outline-secondary');
    if (local_ai_yared_course_multi_provider()) {
        echo $button('other', 'import_fmt_other', 'btn-outline-secondary');
    }
    if (!empty($ex['reformulated'])) {
        echo $button('restore', 'import_fmt_restore', 'btn-link');
    }
    echo html_writer::span('', 'aiyared-format-status small text-muted', ['role' => 'status', 'aria-live' => 'polite']);
    if (in_array($current, exercise_extractor::OPEN_TYPES, true)) {
        echo html_writer::span(get_string('import_open_note', 'local_ai_yared_course'), 'aiyared-open-note small');
    }
    echo html_writer::end_div();
}

/**
 * Render the card of one exercise of the review screen.
 *
 * @param int $i Index of the exercise in the manifest.
 * @param array $ex Exercise.
 * @param array|null $value Submitted values for this exercise, when the save had validation errors.
 * @param int $draftid Draft id.
 * @param array $manifest Stored manifest.
 */
function local_ai_yared_course_render_card(int $i, array $ex, ?array $value, int $draftid, array $manifest): void {
    global $USER;

    $label = $ex['number'] !== '' ? $ex['number'] : (string) ($i + 1);
    $included = $value !== null ? !empty($value['include']) : ($ex['include'] ?? true);
    $text = $value !== null ? (string) ($value['text'] ?? '') : $ex['text'];
    $feedback = $value !== null ? (string) ($value['feedback'] ?? '') : $ex['feedback'];
    $useimage = $value !== null ? !empty($value['useimage']) : !empty($ex['useimage']);
    $imagealt = $value !== null ? (string) ($value['imagealt'] ?? '') : (string) $ex['imagealt'];
    $hasimage = !empty($ex['image']);
    $imageurl = $hasimage ? \local_ai_yared_course\local\import_store::file_url($draftid, $USER->id,
        $ex['image'])->out(false) : '';
    $sourcefile = (string) ($ex['sourcefile'] ?? '');
    $sourceurl = $sourcefile !== '' ? \local_ai_yared_course\local\import_store::file_url($draftid, $USER->id,
        $sourcefile)->out(false) : '';
    $currentbox = $ex['box'] ?? $ex['figure'] ?? $ex['exercise'] ?? null;
    if ($value !== null && array_key_exists('box', $value)) {
        $postedbox = crop_helper::normalise_box(json_decode((string) $value['box'], true));
        if ($postedbox !== null) {
            $currentbox = $postedbox;
        }
    }
    $boxjson = $currentbox ? json_encode($currentbox, JSON_UNESCAPED_SLASHES) : '';
    $figurejson = !empty($ex['figure']) ? json_encode($ex['figure'], JSON_UNESCAPED_SLASHES) : '';
    $exercisejson = !empty($ex['exercise']) ? json_encode($ex['exercise'], JSON_UNESCAPED_SLASHES) : '';

    $isopen = in_array($ex['type'], exercise_extractor::OPEN_TYPES, true);
    echo html_writer::start_div('aiyared-import-card card mb-3', [
        'id' => 'aiyared-card-' . $i,
        'data-index' => $i,
        'data-type' => $ex['type'],
        'data-label' => $label,
        'data-open' => $isopen ? '1' : '0',
    ]);
    echo html_writer::start_div('card-body');

    $badges = html_writer::span(get_string('import_exercise', 'local_ai_yared_course', s($label)),
        'badge text-bg-primary me-1');
    $typekey = in_array($ex['type'], exercise_extractor::TYPES, true) ? $ex['type'] : 'multichoice';
    $badges .= html_writer::span(get_string('qtype_' . $typekey, 'local_ai_yared_course'),
        'badge text-bg-secondary me-1');
    if ($isopen) {
        $badges .= html_writer::span(get_string('import_manual_badge', 'local_ai_yared_course'),
            'badge text-bg-warning me-1');
    }
    if (!empty($ex['ungradable'])) {
        $badges .= html_writer::span(get_string('import_ungradable_badge', 'local_ai_yared_course'),
            'badge text-bg-danger me-1');
    } else if (!empty($ex['rewritten'])) {
        $badges .= html_writer::span(get_string('import_rewritten_badge', 'local_ai_yared_course'),
            'badge text-bg-info me-1', ['title' => get_string('import_rewritten_help', 'local_ai_yared_course')]);
    }
    if (!empty($ex['page'])) {
        $badges .= html_writer::span(get_string('import_page', 'local_ai_yared_course', (int) $ex['page']),
            'badge text-bg-light me-1');
    }
    if (!empty($ex['needsreview'])) {
        $badges .= html_writer::span(get_string('import_needsreview', 'local_ai_yared_course'),
            'badge text-bg-warning me-1');
    }
    if (!empty($ex['needsfigure'])) {
        $badges .= html_writer::span(get_string('import_needsfigure', 'local_ai_yared_course'),
            'badge text-bg-warning me-1');
    }

    echo html_writer::start_div('d-flex justify-content-between align-items-start mb-2');
    echo html_writer::tag('div', $badges, ['class' => 'aiyared-import-badges']);
    echo html_writer::tag('label',
        html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'items[' . $i . '][include]',
            'value' => '1',
            'class' => 'form-check-input me-1 aiyared-import-include',
            'checked' => $included ? 'checked' : null,
        ]) . get_string('import_include', 'local_ai_yared_course'),
        ['class' => 'form-check-label text-nowrap']);
    echo html_writer::end_div();

    local_ai_yared_course_render_format_controls($ex);

    echo html_writer::start_div('row g-3');
    echo html_writer::start_div('col-lg-7');

    $textid = 'aiyared-text-' . $i;
    echo html_writer::tag('label', get_string('import_statement', 'local_ai_yared_course'),
        ['for' => $textid, 'class' => 'form-label fw-bold']);
    echo html_writer::tag('textarea', s($text), [
        'name' => 'items[' . $i . '][text]',
        'id' => $textid,
        'class' => 'form-control mb-2',
        'rows' => max(4, min(12, substr_count($text, "\n") + 3)),
    ]);

    if ($ex['type'] === 'essay') {
        echo html_writer::tag('p', get_string('import_essay_note', 'local_ai_yared_course'),
            ['class' => 'text-muted small mb-2']);
    } else if ($ex['type'] === 'shortanswer') {
        $answerstext = $value !== null ? (string) ($value['answers'] ?? '') : implode("\n", (array) ($ex['answers'] ?? []));
        $answersid = 'aiyared-answers-' . $i;
        echo html_writer::tag('label', get_string('import_answers', 'local_ai_yared_course'),
            ['for' => $answersid, 'class' => 'form-label fw-bold']);
        echo html_writer::tag('textarea', s($answerstext), [
            'name' => 'items[' . $i . '][answers]',
            'id' => $answersid,
            'class' => 'form-control mb-1',
            'rows' => 2,
        ]);
        echo html_writer::tag('p', get_string('import_answers_help', 'local_ai_yared_course'),
            ['class' => 'text-muted small mb-2']);
    } else if ($ex['type'] === 'truefalse') {
        $answer = $value !== null ? (string) ($value['answer'] ?? '') : ($ex['answer'] ? '1' : '0');
        echo html_writer::tag('div',
            html_writer::tag('label',
                html_writer::empty_tag('input', [
                    'type' => 'radio',
                    'name' => 'items[' . $i . '][answer]',
                    'value' => '1',
                    'class' => 'form-check-input me-1',
                    'checked' => $answer === '1' ? 'checked' : null,
                ]) . get_string('true', 'qtype_truefalse'),
                ['class' => 'form-check-label me-3']) .
            html_writer::tag('label',
                html_writer::empty_tag('input', [
                    'type' => 'radio',
                    'name' => 'items[' . $i . '][answer]',
                    'value' => '0',
                    'class' => 'form-check-input me-1',
                    'checked' => $answer === '0' ? 'checked' : null,
                ]) . get_string('false', 'qtype_truefalse'),
                ['class' => 'form-check-label']),
            ['class' => 'mb-2']);
    } else if (in_array($ex['type'], exercise_extractor::GAP_TYPES, true)) {
        $postedoptions = $value !== null && is_array($value['options'] ?? null) ? $value['options'] : [];
        $table = new html_table();
        $table->attributes['class'] = 'table table-sm align-middle aiyared-import-options mb-1';
        $table->head = [
            get_string('import_gap_number', 'local_ai_yared_course'),
            get_string('import_gap_choice', 'local_ai_yared_course'),
        ];
        foreach ($ex['options'] as $j => $option) {
            $otext = $postedoptions ? (string) ($postedoptions[$j]['text'] ?? '') : $option['text'];
            $input = html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'items[' . $i . '][options][' . $j . '][text]',
                'value' => $otext,
                'class' => 'form-control form-control-sm',
            ]);
            $table->data[] = [html_writer::span('[[' . ($j + 1) . ']]', 'badge text-bg-light'), $input];
        }
        echo html_writer::table($table);
        echo html_writer::tag('p', get_string('import_gap_help', 'local_ai_yared_course'),
            ['class' => 'text-muted small mb-2']);
    } else {
        $postedoptions = $value !== null && is_array($value['options'] ?? null) ? $value['options'] : [];
        $correct = $value !== null ? (int) ($value['correct'] ?? -1) : -1;
        $ismulti = $ex['type'] === 'multiselect';
        $postedmulti = [];
        if ($value !== null && is_array($value['correctm'] ?? null)) {
            foreach ($value['correctm'] as $chosenindex) {
                $postedmulti[(int) (is_scalar($chosenindex) ? $chosenindex : -1)] = true;
            }
        }
        $table = new html_table();
        $table->attributes['class'] = 'table table-sm align-middle aiyared-import-options mb-2';
        $table->head = [
            get_string($ismulti ? 'import_correct_many' : 'import_correct', 'local_ai_yared_course'),
            get_string('import_option', 'local_ai_yared_course'),
        ];
        foreach ($ex['options'] as $j => $option) {
            $otext = $postedoptions ? (string) ($postedoptions[$j]['text'] ?? '') : $option['text'];
            if ($ismulti) {
                $ischecked = $value !== null ? isset($postedmulti[(int) $j]) : !empty($option['correct']);
            } else {
                $ischecked = $value !== null ? ($correct === (int) $j) : !empty($option['correct']);
            }
            $radio = html_writer::empty_tag('input', [
                'type' => $ismulti ? 'checkbox' : 'radio',
                'name' => 'items[' . $i . '][' . ($ismulti ? 'correctm][' : 'correct') . ']',
                'value' => $j,
                'class' => 'form-check-input',
                'checked' => $ischecked ? 'checked' : null,
                'aria-label' => get_string('import_correct', 'local_ai_yared_course') . ' ' . ($j + 1),
            ]);
            $input = html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'items[' . $i . '][options][' . $j . '][text]',
                'value' => $otext,
                'class' => 'form-control form-control-sm',
            ]);
            $table->data[] = [html_writer::div($radio, 'form-check'), $input];
        }
        echo html_writer::table($table);
    }

    $feedbackid = 'aiyared-feedback-' . $i;
    echo html_writer::tag('label', get_string('import_feedback', 'local_ai_yared_course'),
        ['for' => $feedbackid, 'class' => 'form-label fw-bold']);
    echo html_writer::tag('textarea', s($feedback), [
        'name' => 'items[' . $i . '][feedback]',
        'id' => $feedbackid,
        'class' => 'form-control',
        'rows' => 2,
    ]);
    echo html_writer::tag('button', get_string('import_preview', 'local_ai_yared_course'), [
        'type' => 'button',
        'class' => 'btn btn-sm btn-outline-primary mt-2 aiyared-preview-btn',
        'data-index' => $i,
        'data-type' => $ex['type'],
        'data-original' => json_encode([
            'text' => (string) ($ex['original']['text'] ?? ''),
            'options' => array_values(array_map(function ($option) {
                return (string) ($option['text'] ?? '');
            }, (array) ($ex['original']['options'] ?? []))),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    echo html_writer::end_div(); // Column.

    echo html_writer::start_div('col-lg-5');
    $canadjust = $sourceurl !== '';
    if ($hasimage || $canadjust) {
        echo html_writer::start_div('aiyared-import-image' . ($useimage || !$hasimage ? '' : ' opacity-50'));
        echo html_writer::empty_tag('img', [
            'src' => $hasimage ? $imageurl : null,
            'alt' => s($imagealt),
            'class' => 'img-fluid rounded border mb-2' . ($hasimage ? '' : ' d-none'),
            'loading' => 'lazy',
            'id' => 'aiyared-image-' . $i,
            'data-original-src' => $hasimage ? $imageurl : '',
        ]);
        if (!$hasimage) {
            echo html_writer::tag('p', get_string('import_noimage', 'local_ai_yared_course'),
                ['class' => 'text-muted small aiyared-noimage', 'id' => 'aiyared-noimage-' . $i]);
        }
        echo html_writer::end_div();
        if ($hasimage) {
            echo html_writer::tag('label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'items[' . $i . '][useimage]',
                    'value' => '1',
                    'class' => 'form-check-input me-1 aiyared-import-useimage',
                    'checked' => $useimage ? 'checked' : null,
                    'data-index' => $i,
                ]) . get_string('import_useimage', 'local_ai_yared_course'),
                ['class' => 'form-check-label d-block mb-2']);
        } else if ($canadjust) {
            echo html_writer::tag('label',
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'items[' . $i . '][useimage]',
                    'value' => '1',
                    'class' => 'form-check-input me-1 aiyared-import-useimage',
                    'checked' => $useimage ? 'checked' : null,
                    'data-index' => $i,
                ]) . get_string('import_usecrop', 'local_ai_yared_course'),
                ['class' => 'form-check-label d-block mb-2']);
        }
        if ($canadjust) {
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'items[' . $i . '][box]',
                'value' => $boxjson,
                'id' => 'aiyared-box-' . $i,
                'class' => 'aiyared-import-box',
            ]);
            echo html_writer::tag('button',
                html_writer::tag('i', '', ['class' => 'fa fa-crop me-1', 'aria-hidden' => 'true']) .
                get_string($hasimage ? 'import_adjustcrop' : 'import_makecrop', 'local_ai_yared_course'),
                [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-outline-primary aiyared-adjustcrop mb-2',
                    'data-index' => $i,
                    'data-source' => $sourceurl,
                    'data-box' => $boxjson,
                    'data-figure' => $figurejson,
                    'data-exercise' => $exercisejson,
                    'data-preview' => 'aiyared-image-' . $i,
                    'data-label' => s($label),
                    'aria-label' => get_string('import_adjustcrop_aria', 'local_ai_yared_course', s($label)),
                ]);
            echo html_writer::tag('p', get_string('import_adjustcrop_help', 'local_ai_yared_course'),
                ['class' => 'text-muted small mb-2']);
        }
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'items[' . $i . '][imagealt]',
            'value' => $imagealt,
            'class' => 'form-control form-control-sm',
            'placeholder' => get_string('import_imagealt', 'local_ai_yared_course'),
            'aria-label' => get_string('import_imagealt', 'local_ai_yared_course'),
        ]);
    } else {
        // Explain it once; repeating the long text under every question only adds noise.
        static $explainednoimage = false;
        echo html_writer::tag('p', get_string($explainednoimage ? 'import_noimage_pdf_short'
            : 'import_noimage_pdf', 'local_ai_yared_course'), ['class' => 'text-muted small']);
        $explainednoimage = true;
    }
    if (!empty($ex['imagecredit']) && $hasimage) {
        echo html_writer::tag('p', get_string('import_ref_credit', 'local_ai_yared_course', s($ex['imagecredit'])),
            ['class' => 'text-muted small mb-2 aiyared-ref-credit']);
    }
    if (commons_search::is_enabled()) {
        echo html_writer::start_tag('details', ['class' => 'aiyared-refimage mt-3']);
        echo html_writer::tag('summary', get_string('import_ref_title', 'local_ai_yared_course'));
        echo html_writer::tag('p', get_string('import_ref_help', 'local_ai_yared_course'), ['class' => 'text-muted small my-2']);
        echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-center mb-2');
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'class' => 'form-control form-control-sm w-auto flex-grow-1 aiyared-ref-query',
            'placeholder' => get_string('import_ref_query', 'local_ai_yared_course'),
            'aria-label' => get_string('import_ref_query', 'local_ai_yared_course'),
            'maxlength' => 120,
        ]);
        echo html_writer::tag('button', get_string('import_ref_search', 'local_ai_yared_course'), [
            'type' => 'button', 'class' => 'btn btn-sm btn-outline-primary aiyared-ref-search']);
        echo html_writer::end_div();
        echo html_writer::span('', 'aiyared-ref-status small text-muted d-block mb-2', ['role' => 'status', 'aria-live' => 'polite']);
        echo html_writer::div('', 'aiyared-ref-results mb-2');
        echo html_writer::tag('button', get_string('import_ref_apply', 'local_ai_yared_course'), [
            'type' => 'button', 'class' => 'btn btn-sm btn-primary aiyared-ref-apply', 'disabled' => 'disabled',
            'title' => get_string('import_ref_apply_help', 'local_ai_yared_course')]);
        echo html_writer::end_tag('details');
    }
    echo html_writer::end_div(); // Column.

    echo html_writer::end_div(); // Row.
    echo html_writer::end_div(); // Card body.
    echo html_writer::end_div(); // Card.
}

/**
 * Render the review page with one editable card per detected exercise.
 *
 * @param array $manifest Stored manifest.
 * @param int $draftid Draft id.
 * @param array|null $posted Submitted values, when the save had validation errors.
 * @param \context $context Course context.
 */
function local_ai_yared_course_render_review(array $manifest, int $draftid, ?array $posted, $context): void {
    global $OUTPUT, $PAGE, $DB, $USER;

    $count = count($manifest['exercises']);
    $categoryname = '';
    if ((int) $manifest['categoryid'] > 0) {
        $categoryname = (string) $DB->get_field('question_categories', 'name',
            ['id' => (int) $manifest['categoryid']], IGNORE_MISSING);
        $categoryname = format_string($categoryname, true, ['context' => $context]);
    } else {
        $categoryname = format_string((string) $manifest['newcategory'], true, ['context' => $context]);
    }

    $frombank = ($manifest['origin'] ?? '') === bank_draft::ORIGIN;
    echo html_writer::div(
        html_writer::tag('strong', get_string($frombank ? 'bank_review_title' : 'import_review_title',
            'local_ai_yared_course', (object) ['count' => $count])) . ' ' .
        html_writer::tag('span', get_string('import_review_category', 'local_ai_yared_course',
            (object) ['category' => $categoryname]), ['class' => 'text-muted']),
        'alert alert-info'
    );

    foreach ($manifest['warnings'] ?? [] as $warning) {
        echo $OUTPUT->notification($warning, 'warning');
    }

    // Makes Moodle load the LaTeX filter on this page, so the previews loaded later can be typeset.
    echo html_writer::div(format_text('\\(x\\)', FORMAT_HTML, ['context' => $context]), 'd-none');

    $formurl = new moodle_url('/local/ai_yared_course/import.php');
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'class' => 'aiyared-import-form',
        'id' => 'aiyared-import-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $manifest['courseid']]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'draft', 'value' => $draftid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);

    if (!$frombank) {
        $sources = [];
        foreach ($manifest['sources'] as $source) {
            $sources[] = s($source['filename']);
        }
        echo html_writer::tag('p', get_string('import_review_sources', 'local_ai_yared_course',
            (object) ['files' => implode(', ', $sources)]), ['class' => 'text-muted small']);
    }

    echo html_writer::start_div('aiyared-import-toolbar d-flex flex-wrap gap-3 align-items-center mb-3');
    echo html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'aiyared-selectall', 'checked' => 'checked',
            'class' => 'form-check-input me-1']) . get_string('import_selectall', 'local_ai_yared_course'),
        ['class' => 'form-check-label']);
    echo html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'draftstatus', 'value' => '1',
            'class' => 'form-check-input me-1']) . get_string('import_draftstatus', 'local_ai_yared_course'),
        ['class' => 'form-check-label']);
    echo html_writer::end_div();

    // Apply one format to every selected question.
    $bulkoptions = '';
    foreach ([
        get_string('import_fmt_group_closed', 'local_ai_yared_course') => array_values(array_diff(
            exercise_extractor::TYPES, exercise_extractor::OPEN_TYPES)),
        get_string('import_fmt_group_open', 'local_ai_yared_course') => exercise_extractor::OPEN_TYPES,
    ] as $groupname => $grouptypes) {
        $bulkoptions .= html_writer::start_tag('optgroup', ['label' => $groupname]);
        foreach ($grouptypes as $grouptype) {
            $bulkoptions .= html_writer::tag('option', get_string('qtype_' . $grouptype, 'local_ai_yared_course'),
                ['value' => $grouptype]);
        }
        $bulkoptions .= html_writer::end_tag('optgroup');
    }
    echo html_writer::div(
        html_writer::tag('label', get_string('import_bulk_label', 'local_ai_yared_course'),
            ['for' => 'aiyared-bulk-format', 'class' => 'small fw-bold mb-0']) .
        html_writer::tag('select', $bulkoptions, ['id' => 'aiyared-bulk-format',
            'class' => 'form-select form-select-sm w-auto']) .
        html_writer::tag('button', get_string('import_bulk_apply', 'local_ai_yared_course'), [
            'type' => 'button', 'id' => 'aiyared-bulk-apply', 'class' => 'btn btn-sm btn-outline-primary']) .
        html_writer::span('', 'small text-muted', ['id' => 'aiyared-bulk-status', 'role' => 'status',
            'aria-live' => 'polite']),
        'd-flex flex-wrap gap-2 align-items-center mb-3');
    $legend = html_writer::tag('summary', get_string('import_legend_title', 'local_ai_yared_course'));
    if ($frombank) {
        $items = [
            ['import_fmt_apply', 'bank_fmt_apply_help'],
            ['import_fmt_variant', 'import_fmt_variant_help'],
        ];
        if (local_ai_yared_course_multi_provider()) {
            $items[] = ['import_fmt_other', 'import_fmt_other_help'];
        }
        $items[] = ['import_fmt_restore', 'bank_fmt_restore_help'];
    } else {
        $items = [
            ['import_fmt_apply', 'import_fmt_apply_help'],
            ['import_fmt_variant', 'import_fmt_variant_help'],
        ];
        if (local_ai_yared_course_multi_provider()) {
            $items[] = ['import_fmt_other', 'import_fmt_other_help'];
        }
        $items[] = ['import_fmt_restore', 'import_fmt_restore_help'];
    }
    if (commons_search::is_enabled()) {
        $items[] = ['import_ref_title', 'import_ref_legend_help'];
    }
    $list = '';
    foreach ($items as $legenditem) {
        $list .= html_writer::tag('li', html_writer::tag('strong', get_string($legenditem[0], 'local_ai_yared_course'))
            . ': ' . get_string($legenditem[1], 'local_ai_yared_course'));
    }
    $list .= html_writer::tag('li', html_writer::tag('strong', get_string($frombank ? 'bank_legend_figure'
            : 'import_legend_crop', 'local_ai_yared_course'))
        . ': ' . get_string($frombank ? 'bank_legend_figure_help' : 'import_legend_crop_help', 'local_ai_yared_course'));
    $list .= html_writer::tag('li', html_writer::tag('strong', get_string('import_legend_open', 'local_ai_yared_course'))
        . ': ' . get_string('import_legend_open_help', 'local_ai_yared_course'));
    echo html_writer::tag('details', $legend . html_writer::tag('ul', $list, ['class' => 'mb-0 mt-2 small']), [
        'class' => 'aiyared-legend mb-3', 'open' => 'open',
    ]);

    // Questions that depend on exact spelling or manual grading go last, as they are saved.
    $order = range(0, max(0, $count - 1));
    if ($count) {
        usort($order, function ($x, $y) use ($manifest) {
            $ox = in_array($manifest['exercises'][$x]['type'], exercise_extractor::OPEN_TYPES, true) ? 1 : 0;
            $oy = in_array($manifest['exercises'][$y]['type'], exercise_extractor::OPEN_TYPES, true) ? 1 : 0;
            return $ox <=> $oy ?: $x <=> $y;
        });
    }
    echo html_writer::start_div('', ['id' => 'aiyared-cards']);
    foreach ($order as $i) {
        if (!isset($manifest['exercises'][$i])) {
            continue;
        }
        $value = is_array($posted) && isset($posted[$i]) && is_array($posted[$i]) ? $posted[$i] : null;
        local_ai_yared_course_render_card($i, $manifest['exercises'][$i], $value, $draftid, $manifest);
    }
    echo html_writer::end_div();

    echo html_writer::start_div('d-flex flex-wrap gap-2 mb-4');
    echo html_writer::tag('button', get_string('import_save', 'local_ai_yared_course'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'id' => 'aiyared-import-save',
    ]);
    $discard = new moodle_url('/local/ai_yared_course/import.php', [
        'id' => $manifest['courseid'],
        'draft' => $draftid,
        'action' => 'discard',
        'sesskey' => sesskey(),
    ]);
    echo html_writer::link($discard, get_string('import_discard', 'local_ai_yared_course'),
        ['class' => 'btn btn-outline-secondary', 'data-aiyared-confirm' => '1']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // Crop editor: the teacher draws the exact region of the page that will accompany the question.
    echo html_writer::start_tag('dialog', [
        'id' => 'aiyared-crop-dialog',
        'class' => 'aiyared-crop-dialog',
        'aria-labelledby' => 'aiyared-crop-title',
    ]);
    echo html_writer::start_div('aiyared-crop-card');
    echo html_writer::start_div('d-flex justify-content-between align-items-start gap-2 mb-2');
    echo html_writer::tag('h2', get_string('import_crop_title', 'local_ai_yared_course'),
        ['id' => 'aiyared-crop-title', 'class' => 'h4 mb-0']);
    echo html_writer::tag('button', '&times;', [
        'type' => 'button',
        'class' => 'btn-close',
        'id' => 'aiyared-crop-close',
        'aria-label' => get_string('import_crop_close', 'local_ai_yared_course'),
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('import_crop_intro', 'local_ai_yared_course'),
        ['class' => 'text-muted']);
    echo html_writer::tag('p', '', [
        'id' => 'aiyared-crop-label',
        'class' => 'fw-bold small mb-2',
    ]);
    echo html_writer::start_div('aiyared-crop-stage', ['id' => 'aiyared-crop-stage']);
    echo html_writer::empty_tag('img', [
        'id' => 'aiyared-crop-image',
        'class' => 'aiyared-crop-image',
        'alt' => get_string('import_crop_imagealt', 'local_ai_yared_course'),
    ]);
    echo html_writer::start_div('aiyared-crop-selection', ['id' => 'aiyared-crop-selection', 'hidden' => 'hidden']);
    echo html_writer::tag('span', '', ['class' => 'aiyared-crop-handle aiyared-crop-handle-se',
        'data-handle' => 'se']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('import_crop_hint', 'local_ai_yared_course'),
        ['class' => 'text-muted small mt-2']);
    echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-center');
    echo html_writer::tag('button', get_string('import_crop_figure', 'local_ai_yared_course'), [
        'type' => 'button',
        'class' => 'btn btn-sm btn-outline-secondary',
        'id' => 'aiyared-crop-figure',
        'hidden' => 'hidden',
    ]);
    echo html_writer::tag('button', get_string('import_crop_exercise', 'local_ai_yared_course'), [
        'type' => 'button',
        'class' => 'btn btn-sm btn-outline-secondary',
        'id' => 'aiyared-crop-exercise',
        'hidden' => 'hidden',
    ]);
    echo html_writer::tag('span', '', ['class' => 'flex-grow-1']);
    echo html_writer::tag('button', get_string('cancel'), [
        'type' => 'button',
        'class' => 'btn btn-outline-secondary',
        'id' => 'aiyared-crop-cancel',
    ]);
    echo html_writer::tag('button', get_string('import_crop_apply', 'local_ai_yared_course'), [
        'type' => 'button',
        'class' => 'btn btn-primary',
        'id' => 'aiyared-crop-apply',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('dialog');

    // Preview of one question in a modal window.
    echo html_writer::start_tag('dialog', [
        'id' => 'aiyared-preview-dialog',
        'class' => 'aiyared-crop-dialog aiyared-preview-dialog',
        'aria-labelledby' => 'aiyared-preview-title',
        'style' => 'width: min(96vw, 46rem);',
    ]);
    echo html_writer::start_div('aiyared-crop-card');
    echo html_writer::start_div('d-flex justify-content-between align-items-start gap-2 mb-2');
    echo html_writer::tag('h2', '', ['id' => 'aiyared-preview-title', 'class' => 'h4 mb-0']);
    echo html_writer::tag('button', '&times;', [
        'type' => 'button',
        'class' => 'btn-close',
        'id' => 'aiyared-preview-x',
        'aria-label' => get_string('import_crop_close', 'local_ai_yared_course'),
    ]);
    echo html_writer::end_div();
    echo html_writer::div('', 'aiyared-preview-body', ['id' => 'aiyared-preview-body', 'aria-live' => 'polite']);
    echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-center mt-3');
    echo html_writer::tag('label', get_string('import_fmt_label', 'local_ai_yared_course'), [
        'for' => 'aiyared-preview-format', 'class' => 'small fw-bold mb-0']);
    echo html_writer::tag('select', local_ai_yared_course_format_options(), [
        'id' => 'aiyared-preview-format', 'class' => 'form-select form-select-sm w-auto']);
    echo html_writer::tag('button', get_string('import_fmt_apply', 'local_ai_yared_course'), [
        'type' => 'button', 'class' => 'btn btn-sm btn-outline-primary aiyared-preview-act', 'data-mode' => 'format',
        'title' => get_string(($manifest['origin'] ?? '') === bank_draft::ORIGIN
            ? 'bank_fmt_apply_help' : 'import_fmt_apply_help', 'local_ai_yared_course')]);
    echo html_writer::tag('button', get_string('import_fmt_variant', 'local_ai_yared_course'), [
        'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary aiyared-preview-act', 'data-mode' => 'variant']);
    if (local_ai_yared_course_multi_provider()) {
        echo html_writer::tag('button', get_string('import_fmt_other', 'local_ai_yared_course'), [
            'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary aiyared-preview-act',
            'data-mode' => 'other', 'title' => get_string('import_fmt_other_help', 'local_ai_yared_course')]);
    }
    echo html_writer::tag('span', '', ['class' => 'flex-grow-1']);
    echo html_writer::tag('button', get_string('import_crop_close', 'local_ai_yared_course'), [
        'type' => 'button', 'class' => 'btn btn-primary', 'id' => 'aiyared-preview-close']);
    echo html_writer::end_div();
    echo html_writer::div(get_string('import_preview_opennote', 'local_ai_yared_course'),
        'aiyared-pv-opennote small mt-2 d-none', ['id' => 'aiyared-preview-opennote', 'role' => 'note']);
    echo html_writer::end_div();
    echo html_writer::end_tag('dialog');

    $noselection = json_encode(get_string('import_err_noselection', 'local_ai_yared_course'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $saving = json_encode(get_string('import_saving', 'local_ai_yared_course'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $confirm = json_encode(get_string('import_discardconfirm', 'local_ai_yared_course'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $croprequired = json_encode(get_string('import_crop_required', 'local_ai_yared_course'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $croplabel = json_encode(get_string('import_crop_label', 'local_ai_yared_course', '__LABEL__'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $previewlabels = json_encode([
        'title' => get_string('import_preview_title', 'local_ai_yared_course', '__LABEL__'),
        'show' => get_string('import_preview', 'local_ai_yared_course'),
        'hide' => get_string('import_preview_hide', 'local_ai_yared_course'),
        'loading' => get_string('import_preview_loading', 'local_ai_yared_course'),
        'error' => get_string('import_preview_error', 'local_ai_yared_course'),
        'statement' => get_string('import_preview_statement', 'local_ai_yared_course'),
        'options' => get_string('import_preview_options', 'local_ai_yared_course'),
        'feedback' => get_string('import_preview_feedback', 'local_ai_yared_course'),
        'correct' => get_string('import_preview_correct', 'local_ai_yared_course'),
        'answer' => get_string('import_preview_answer', 'local_ai_yared_course'),
        'nomath' => get_string('import_preview_nomath', 'local_ai_yared_course'),
        'noanswer' => get_string('import_preview_noanswer', 'local_ai_yared_course'),
        'original' => get_string('import_preview_original', 'local_ai_yared_course'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $fmtlabels = json_encode([
        'working' => get_string('import_fmt_working', 'local_ai_yared_course'),
        'done' => get_string('import_fmt_done', 'local_ai_yared_course'),
        'error' => get_string('import_fmt_err_request', 'local_ai_yared_course'),
        'progress' => get_string('import_bulk_progress', 'local_ai_yared_course', (object) ['n' => '__N__', 't' => '__T__']),
        'none' => get_string('import_bulk_none', 'local_ai_yared_course'),
        'finished' => get_string('import_bulk_finished', 'local_ai_yared_course'),
        'confirm' => get_string('import_bulk_confirm', 'local_ai_yared_course'),
        'queued' => get_string('import_fmt_queued', 'local_ai_yared_course'),
        'ready' => get_string('import_fmt_ready', 'local_ai_yared_course'),
        'searching' => get_string('import_ref_searching', 'local_ai_yared_course'),
        'found' => get_string('import_ref_found', 'local_ai_yared_course', '__N__'),
        'none_found' => get_string('import_ref_none', 'local_ai_yared_course'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $js = <<<JS
(function() {
    'use strict';
    function init() {
        var form = document.getElementById('aiyared-import-form');
        if (!form) { return; }

        // Preview of each question: statement, image, options and feedback, as Moodle will show them.
        var PL = {$previewlabels};
        function heading(text) {
            var h = document.createElement('div');
            h.className = 'fw-bold small text-uppercase text-muted mt-2';
            h.textContent = text;
            return h;
        }
        function box(html) {
            var d = document.createElement('div');
            d.innerHTML = html;
            return d;
        }
        function typeset(node) {
            try {
                require(['jquery', 'core_filters/events'], function($, events) {
                    events.notifyFilterContentUpdated($(node));
                }, function() { /* The LaTeX filter is not available: the text stays as written. */ });
            } catch (e) { /* Ignore. */ }
        }
        function section(panel, title, className) {
            var wrap = document.createElement('section');
            wrap.className = 'aiyared-pv-section' + (className ? ' ' + className : '');
            var h = document.createElement('h3');
            h.className = 'aiyared-pv-heading';
            h.textContent = title;
            wrap.appendChild(h);
            panel.appendChild(wrap);
            return wrap;
        }
        function renderPreview(panel, index, data) {
            panel.textContent = '';
            var card = document.getElementById('aiyared-card-' + index);
            var typebadge = card ? card.querySelector('.aiyared-import-badges .text-bg-secondary') : null;
            if (typebadge) {
                var meta = document.createElement('div');
                meta.className = 'aiyared-pv-meta';
                meta.textContent = typebadge.textContent;
                panel.appendChild(meta);
            }
            var statement = section(panel, PL.statement);
            var statementbox = box(data.text);
            statementbox.className = 'aiyared-pv-statement';
            statement.appendChild(statementbox);
            var useimage = form.querySelector('input[name="items[' + index + '][useimage]"]');
            var image = document.getElementById('aiyared-image-' + index);
            if (image && image.getAttribute('src') && !image.classList.contains('d-none')
                    && (!useimage || useimage.checked)) {
                var figure = document.createElement('figure');
                figure.className = 'aiyared-pv-figure';
                var copy = document.createElement('img');
                copy.src = image.getAttribute('src');
                copy.alt = image.alt || '';
                figure.appendChild(copy);
                statement.appendChild(figure);
            }
            if (data.options && data.options.length) {
                var optionsection = section(panel, PL.options);
                var list = document.createElement('div');
                list.className = 'aiyared-pv-options';
                data.options.forEach(function(option, position) {
                    var row = document.createElement('div');
                    row.className = 'aiyared-pv-option' + (option.correct ? ' is-correct' : '');
                    var letter = document.createElement('span');
                    letter.className = 'aiyared-pv-letter';
                    letter.textContent = String.fromCharCode(65 + position);
                    row.appendChild(letter);
                    var body = box(option.html);
                    body.className = 'aiyared-pv-optiontext';
                    row.appendChild(body);
                    if (option.correct) {
                        var mark = document.createElement('span');
                        mark.className = 'aiyared-pv-mark';
                        mark.textContent = PL.correct;
                        row.appendChild(mark);
                    }
                    list.appendChild(row);
                });
                optionsection.appendChild(list);
                if (!data.options.some(function(option) { return option.correct; })) {
                    var warning = document.createElement('div');
                    warning.className = 'aiyared-pv-warning';
                    warning.textContent = PL.noanswer;
                    optionsection.appendChild(warning);
                }
            } else if (data.answer) {
                var answersection = section(panel, PL.answer);
                var answer = document.createElement('div');
                answer.className = 'aiyared-pv-answer';
                answer.textContent = data.answer;
                answersection.appendChild(answer);
            }
            if (data.feedback) {
                var feedbacksection = section(panel, PL.feedback, 'aiyared-pv-feedback');
                feedbacksection.appendChild(box(data.feedback));
            }
            var originalbutton = card ? card.querySelector('.aiyared-preview-btn') : null;
            var original = null;
            try { original = originalbutton ? JSON.parse(originalbutton.getAttribute('data-original') || 'null') : null; } catch (e) { original = null; }
            if (original && original.text) {
                var details = document.createElement('details');
                details.className = 'aiyared-pv-original';
                var summary = document.createElement('summary');
                summary.textContent = PL.original;
                details.appendChild(summary);
                var originaltext = document.createElement('div');
                originaltext.className = 'aiyared-pv-originaltext';
                originaltext.textContent = original.text;
                details.appendChild(originaltext);
                if (original.options && original.options.length) {
                    var originallist = document.createElement('ol');
                    originallist.className = 'aiyared-pv-originallist';
                    originallist.type = 'A';
                    original.options.forEach(function(text) {
                        var li = document.createElement('li');
                        li.textContent = text;
                        originallist.appendChild(li);
                    });
                    details.appendChild(originallist);
                }
                panel.appendChild(details);
            }
            if (!data.mathactive) {
                var note = document.createElement('div');
                note.className = 'aiyared-pv-note';
                note.textContent = PL.nomath;
                panel.appendChild(note);
            }
            typeset(panel);
        }
        var previewDialog = document.getElementById('aiyared-preview-dialog');
        var previewBody = document.getElementById('aiyared-preview-body');
        var previewIndex = null;
        function closePreview() {
            if (!previewDialog) { return; }
            if (previewDialog.close) {
                if (previewDialog.open) { previewDialog.close(); }
            } else {
                previewDialog.removeAttribute('open');
            }
        }
        function togglePreview(button) {
            var index = button.getAttribute('data-index');
            var panel = previewBody;
            if (!panel || !previewDialog) { return; }
            previewIndex = index;
            var card = document.getElementById('aiyared-card-' + index);
            if (card) { card.classList.remove('aiyared-ready'); }
            var modalformat = document.getElementById('aiyared-preview-format');
            var cardformat = card ? card.querySelector('.aiyared-format-select') : null;
            if (modalformat && cardformat) {
                modalformat.value = cardformat.value;
                modalformat.dispatchEvent(new Event('change'));
            }
            document.getElementById('aiyared-preview-title').textContent =
                PL.title.replace('__LABEL__', card ? card.getAttribute('data-label') : String(Number(index) + 1));
            var field = function(name) { return form.querySelector('[name="items[' + index + '][' + name + ']"]'); };
            var body = new URLSearchParams();
            body.append('sesskey', form.querySelector('input[name="sesskey"]').value);
            body.append('id', form.querySelector('input[name="id"]').value);
            body.append('action', 'preview');
            body.append('type', button.getAttribute('data-type'));
            body.append('text', field('text') ? field('text').value : '');
            body.append('feedback', field('feedback') ? field('feedback').value : '');
            var answer = form.querySelector('input[name="items[' + index + '][answer]"]:checked');
            if (answer) { body.append('answer', answer.value); }
            var correct = form.querySelector('input[name="items[' + index + '][correct]"]:checked');
            if (correct) { body.append('correct', correct.value); }
            form.querySelectorAll('input[name="items[' + index + '][correctm][]"]:checked').forEach(function(box) {
                body.append('correctm[]', box.value);
            });
            var answers = field('answers');
            if (answers) { body.append('answers', answers.value); }
            form.querySelectorAll('input[name^="items[' + index + '][options]"]').forEach(function(input) {
                var match = input.name.match(/\[options\]\[(\d+)\]\[text\]$/);
                if (match) { body.append('options[' + match[1] + ']', input.value); }
            });
            panel.textContent = PL.loading;
            if (previewDialog.showModal) {
                if (!previewDialog.open) { previewDialog.showModal(); }
            } else {
                previewDialog.setAttribute('open', 'open');
            }
            fetch(form.getAttribute('action'), {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function(response) {
                    if (!response.ok) { throw new Error('http'); }
                    return response.json();
                })
                .then(function(data) { renderPreview(panel, index, data); })
                .catch(function() { panel.textContent = PL.error; });
        }
        form.addEventListener('click', function(event) {
            var button = event.target.closest ? event.target.closest('.aiyared-preview-btn') : null;
            if (button) { togglePreview(button); }
        });
        if (previewDialog) {
            document.getElementById('aiyared-preview-close').addEventListener('click', closePreview);
            document.getElementById('aiyared-preview-x').addEventListener('click', closePreview);
            previewDialog.addEventListener('click', function(event) {
                if (event.target === previewDialog) { closePreview(); }
            });
            var previewformat = document.getElementById('aiyared-preview-format');
            var previewnote = document.getElementById('aiyared-preview-opennote');
            if (previewformat) {
                previewformat.addEventListener('change', function() {
                    if (previewnote) {
                        previewnote.classList.toggle('d-none', previewformat.value !== 'shortanswer'
                            && previewformat.value !== 'essay');
                    }
                });
            }
            previewDialog.querySelectorAll('.aiyared-preview-act').forEach(function(action) {
                action.addEventListener('click', function() {
                    var card = document.getElementById('aiyared-card-' + previewIndex);
                    if (action.getAttribute('data-mode') === 'format' && card && previewformat) {
                        var cardselect = card.querySelector('.aiyared-format-select');
                        if (cardselect) { cardselect.value = previewformat.value; }
                    }
                    closePreview();
                    var trigger = card ? card.querySelector('.aiyared-reformat[data-mode="' +
                        action.getAttribute('data-mode') + '"]') : null;
                    if (trigger) { trigger.click(); }
                });
            });
        }
        var all = document.getElementById('aiyared-selectall');
        if (all) {
            all.addEventListener('change', function() {
                form.querySelectorAll('input[name$="[include]"]').forEach(function(box) {
                    box.checked = all.checked;
                });
            });
        }
        form.addEventListener('change', function(event) {
            var box = event.target;
            if (!box.classList || !box.classList.contains('aiyared-import-useimage')) { return; }
            var card = box.closest('.aiyared-import-card');
            var image = card ? card.querySelector('.aiyared-import-image') : null;
            if (image) { image.classList.toggle('opacity-50', !box.checked); }
        });

        // Second phase: every question is rewritten by its own background request. Up to MAXPAR run at the
        // same time; the rest wait their turn. Only the card of the question is replaced, never moved.
        var FL = {$fmtlabels};
        var MAXPAR = 3;
        var running = 0;
        var waiting = [];
        function cardByIndex(index) { return document.getElementById('aiyared-card-' + index); }
        function setBusy(card, busy, message) {
            card.querySelectorAll('.aiyared-reformat, .aiyared-format-select, .aiyared-ref-search, .aiyared-ref-apply').forEach(function(control) {
                control.disabled = busy;
            });
            card.classList.toggle('aiyared-busy', busy);
            var overlay = card.querySelector('.aiyared-card-loading');
            if (busy) {
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'aiyared-card-loading';
                    overlay.setAttribute('role', 'status');
                    var spinner = document.createElement('span');
                    spinner.className = 'aiyared-spinner';
                    spinner.setAttribute('aria-hidden', 'true');
                    var label = document.createElement('span');
                    label.className = 'aiyared-card-loading-text';
                    overlay.appendChild(spinner);
                    overlay.appendChild(label);
                    card.appendChild(overlay);
                }
                overlay.querySelector('.aiyared-card-loading-text').textContent = message || FL.working;
            } else if (overlay) {
                overlay.remove();
            }
            var status = card.querySelector('.aiyared-format-status');
            if (status) { status.textContent = busy ? '' : (message || ''); }
        }
        function pump() {
            while (running < MAXPAR && waiting.length) {
                (function(item) {
                    running++;
                    item.start().then(function(result) {
                        running--;
                        item.resolve(result);
                        pump();
                    });
                })(waiting.shift());
            }
        }
        function schedule(start) {
            return new Promise(function(resolve) {
                waiting.push({start: start, resolve: resolve});
                pump();
            });
        }
        function request(index, mode, target, openPreview, extra) {
            var card = cardByIndex(index);
            if (!card || card.classList.contains('aiyared-busy')) { return Promise.resolve(false); }
            setBusy(card, true, FL.queued);
            return schedule(function() {
                return new Promise(function(resolve) {
                    var current = cardByIndex(index);
                    if (!current) { resolve(false); return; }
                    var included = current.querySelector('.aiyared-import-include');
                    var wasIncluded = included ? included.checked : true;
                    setBusy(current, true, FL.working);
                    var body = new URLSearchParams();
                    body.append('sesskey', form.querySelector('input[name="sesskey"]').value);
                    body.append('id', form.querySelector('input[name="id"]').value);
                    body.append('draft', form.querySelector('input[name="draft"]').value);
                    body.append('action', 'reformulate');
                    body.append('index', index);
                    body.append('mode', mode);
                    body.append('target', target);
                    // The crop the teacher adjusted on the card travels with the request, so it is kept.
                    var boxfield = current.querySelector('input[name="items[' + index + '][box]"]');
                    var usefield = current.querySelector('input[name="items[' + index + '][useimage]"]');
                    var altfield = current.querySelector('input[name="items[' + index + '][imagealt]"]');
                    if (boxfield && boxfield.value) { body.append('box', boxfield.value); }
                    if (usefield) { body.append('useimage', usefield.checked ? '1' : '0'); }
                    if (altfield && altfield.value) { body.append('imagealt', altfield.value); }
                    if (extra) {
                        Object.keys(extra).forEach(function(key) { body.append(key, extra[key]); });
                    }
                    fetch(form.getAttribute('action'), {method: 'POST', body: body, credentials: 'same-origin'})
                        .then(function(response) {
                            if (!response.ok) { throw new Error('http'); }
                            return response.json();
                        })
                        .then(function(data) {
                            if (!data.ok) { throw new Error(data.error || FL.error); }
                            var holder = document.createElement('div');
                            holder.innerHTML = data.html;
                            var fresh = holder.firstElementChild;
                            current.parentNode.replaceChild(fresh, current);
                            var box = fresh.querySelector('.aiyared-import-include');
                            if (box) { box.checked = wasIncluded; }
                            var status = fresh.querySelector('.aiyared-format-status');
                            var previewbutton = fresh.querySelector('.aiyared-preview-btn');
                            if (openPreview && previewbutton && previewDialog && !previewDialog.open) {
                                if (status) { status.textContent = data.notice || FL.done; }
                                previewbutton.click();
                            } else {
                                // Another preview is open (or this was a batch): flag the card instead of interrupting.
                                fresh.classList.add('aiyared-ready');
                                if (status) { status.textContent = (data.notice ? data.notice + ' ' : '') + FL.ready; }
                            }
                            resolve(true);
                        })
                        .catch(function(error) {
                            var message = error && error.message && error.message !== 'http' ? error.message : FL.error;
                            var failed = cardByIndex(index);
                            if (failed) { setBusy(failed, false, message); }
                            resolve(false);
                        });
                });
            });
        }
        form.addEventListener('click', function(event) {
            var button = event.target.closest ? event.target.closest('.aiyared-reformat') : null;
            if (!button || button.disabled) { return; }
            var card = button.closest('.aiyared-import-card');
            if (!card) { return; }
            var index = card.getAttribute('data-index');
            var mode = button.getAttribute('data-mode');
            var select = card.querySelector('.aiyared-format-select');
            var target = (mode === 'variant' || mode === 'restore' || mode === 'other' && !select || !select)
                ? card.getAttribute('data-type') : select.value;
            request(index, mode, target, true);
        });
        // Reference picture: search Wikimedia Commons, let the teacher choose, then attach it and rewrite the question.
        form.addEventListener('click', function(event) {
            var search = event.target.closest ? event.target.closest('.aiyared-ref-search') : null;
            if (!search || search.disabled) { return; }
            var card = search.closest('.aiyared-import-card');
            var box = search.closest('.aiyared-refimage');
            if (!card || !box) { return; }
            var index = card.getAttribute('data-index');
            var status = box.querySelector('.aiyared-ref-status');
            var results = box.querySelector('.aiyared-ref-results');
            var apply = box.querySelector('.aiyared-ref-apply');
            var queryfield = box.querySelector('.aiyared-ref-query');
            results.textContent = '';
            apply.disabled = true;
            status.textContent = FL.searching;
            search.disabled = true;
            var body = new URLSearchParams();
            body.append('sesskey', form.querySelector('input[name="sesskey"]').value);
            body.append('id', form.querySelector('input[name="id"]').value);
            body.append('draft', form.querySelector('input[name="draft"]').value);
            body.append('action', 'imgsearch');
            body.append('index', index);
            body.append('query', queryfield.value);
            fetch(form.getAttribute('action'), {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function(response) {
                    if (!response.ok) { throw new Error('http'); }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.ok) { throw new Error(data.error || FL.error); }
                    if (!queryfield.value) { queryfield.value = data.query; }
                    if (!data.results.length) { status.textContent = FL.none_found; return; }
                    status.textContent = FL.found.replace('__N__', data.results.length);
                    data.results.forEach(function(item, position) {
                        var label = document.createElement('label');
                        label.className = 'aiyared-ref-item';
                        var radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.name = 'aiyared-ref-' + index;
                        radio.className = 'aiyared-ref-radio';
                        radio.setAttribute('data-candidate', JSON.stringify(item));
                        var picture = document.createElement('img');
                        picture.src = item.thumb;
                        picture.alt = '';
                        picture.loading = 'lazy';
                        var caption = document.createElement('span');
                        caption.className = 'aiyared-ref-caption';
                        caption.textContent = (item.title || '') + (item.license ? ' \u00b7 ' + item.license : '');
                        label.appendChild(radio);
                        label.appendChild(picture);
                        label.appendChild(caption);
                        results.appendChild(label);
                    });
                })
                .catch(function(error) {
                    status.textContent = error && error.message && error.message !== 'http' ? error.message : FL.error;
                })
                .then(function() { search.disabled = false; });
        });
        form.addEventListener('keydown', function(event) {
            // Enter in the search box searches; it must never submit (save) the whole form.
            if (event.key === 'Enter' && event.target.classList && event.target.classList.contains('aiyared-ref-query')) {
                event.preventDefault();
                var box = event.target.closest('.aiyared-refimage');
                var button = box ? box.querySelector('.aiyared-ref-search') : null;
                if (button) { button.click(); }
            }
        });
        form.addEventListener('change', function(event) {
            var radio = event.target;
            if (!radio.classList || !radio.classList.contains('aiyared-ref-radio')) { return; }
            var box = radio.closest('.aiyared-refimage');
            var apply = box ? box.querySelector('.aiyared-ref-apply') : null;
            if (apply) { apply.disabled = false; }
            box.querySelectorAll('.aiyared-ref-item').forEach(function(item) {
                item.classList.toggle('is-chosen', item.contains(radio) && radio.checked);
            });
        });
        form.addEventListener('click', function(event) {
            var apply = event.target.closest ? event.target.closest('.aiyared-ref-apply') : null;
            if (!apply || apply.disabled) { return; }
            var card = apply.closest('.aiyared-import-card');
            var chosen = card ? card.querySelector('.aiyared-ref-radio:checked') : null;
            if (!chosen) { return; }
            var item = {};
            try { item = JSON.parse(chosen.getAttribute('data-candidate') || '{}'); } catch (e) { item = {}; }
            request(card.getAttribute('data-index'), 'image', card.getAttribute('data-type'), true, {
                imgurl: item.url || '',
                imgtitle: item.title || '',
                imgauthor: item.author || '',
                imglicense: item.license || '',
                imglicenseurl: item.licenseurl || '',
                imgpage: item.page || ''
            });
        });
        var bulkbutton = document.getElementById('aiyared-bulk-apply');
        if (bulkbutton) {
            bulkbutton.addEventListener('click', function() {
                var target = document.getElementById('aiyared-bulk-format').value;
                var status = document.getElementById('aiyared-bulk-status');
                var indexes = [];
                form.querySelectorAll('.aiyared-import-card').forEach(function(card) {
                    var included = card.querySelector('.aiyared-import-include');
                    if (included && included.checked && card.getAttribute('data-type') !== target
                            && !card.classList.contains('aiyared-busy')) {
                        indexes.push(card.getAttribute('data-index'));
                    }
                });
                if (!indexes.length) { status.textContent = FL.none; return; }
                if (!window.confirm(FL.confirm)) { return; }
                var done = 0;
                bulkbutton.disabled = true;
                status.textContent = FL.progress.replace('__N__', 0).replace('__T__', indexes.length);
                Promise.all(indexes.map(function(index) {
                    return request(index, 'format', target, false).then(function() {
                        done++;
                        status.textContent = FL.progress.replace('__N__', done).replace('__T__', indexes.length);
                    });
                })).then(function() {
                    status.textContent = FL.finished;
                    bulkbutton.disabled = false;
                });
            });
        }
        form.addEventListener('submit', function(event) {
            var selected = form.querySelectorAll('input[name$="[include]"]:checked').length;
            if (selected === 0) {
                event.preventDefault();
                window.alert({$noselection});
                return;
            }
            var button = document.getElementById('aiyared-import-save');
            if (button) {
                button.disabled = true;
                button.textContent = {$saving};
            }
        }, true);
        document.querySelectorAll('[data-aiyared-confirm]').forEach(function(link) {
            link.addEventListener('click', function(event) {
                if (!window.confirm({$confirm})) {
                    event.preventDefault();
                }
            });
        });

        // Crop editor.
        var dialog = document.getElementById('aiyared-crop-dialog');
        var stage = document.getElementById('aiyared-crop-stage');
        var image = document.getElementById('aiyared-crop-image');
        var selection = document.getElementById('aiyared-crop-selection');
        var label = document.getElementById('aiyared-crop-label');
        var apply = document.getElementById('aiyared-crop-apply');
        var figurebutton = document.getElementById('aiyared-crop-figure');
        var exercisebutton = document.getElementById('aiyared-crop-exercise');
        var active = null;
        var drag = null;
        var MINSIDE = 0.02;
        var PADDING = 0.012;

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        function parseBox(value) {
            if (!value) { return null; }
            var box;
            try { box = JSON.parse(value); } catch (e) { return null; }
            if (!Array.isArray(box) || box.length < 4) { return null; }
            var result = [];
            for (var i = 0; i < 4; i++) {
                var number = Number(box[i]);
                if (!isFinite(number)) { return null; }
                result.push(clamp(number, 0, 1));
            }
            if (result[2] - result[0] < MINSIDE || result[3] - result[1] < MINSIDE) {
                return null;
            }
            return result;
        }

        function setSelection(box) {
            if (!box) {
                selection.hidden = true;
                return;
            }
            selection.hidden = false;
            selection.style.left = (box[0] * 100) + '%';
            selection.style.top = (box[1] * 100) + '%';
            selection.style.width = ((box[2] - box[0]) * 100) + '%';
            selection.style.height = ((box[3] - box[1]) * 100) + '%';
        }

        function point(event) {
            var rect = stage.getBoundingClientRect();
            if (!rect.width || !rect.height) {
                return [0, 0];
            }
            return [
                clamp((event.clientX - rect.left) / rect.width, 0, 1),
                clamp((event.clientY - rect.top) / rect.height, 0, 1)
            ];
        }

        function normaliseBox(box) {
            if (!box) { return null; }
            var result = [
                Math.min(box[0], box[2]),
                Math.min(box[1], box[3]),
                Math.max(box[0], box[2]),
                Math.max(box[1], box[3])
            ];
            if (result[2] - result[0] < MINSIDE || result[3] - result[1] < MINSIDE) {
                return null;
            }
            return result;
        }

        function startDrag(event, mode) {
            event.preventDefault();
            drag = {
                mode: mode,
                start: point(event),
                box: active.box ? active.box.slice() : null
            };
            window.addEventListener('pointermove', moveDrag);
            window.addEventListener('pointerup', endDrag);
            try { stage.setPointerCapture(event.pointerId); } catch (e) { /* Ignore. */ }
        }

        function moveDrag(event) {
            if (!drag || !active) { return; }
            var p = point(event);
            var box;
            if (drag.mode === 'draw') {
                box = [drag.start[0], drag.start[1], p[0], p[1]];
            } else if (drag.mode === 'move') {
                var dx = p[0] - drag.start[0];
                var dy = p[1] - drag.start[1];
                box = [
                    drag.box[0] + dx,
                    drag.box[1] + dy,
                    drag.box[2] + dx,
                    drag.box[3] + dy
                ];
                if (box[0] < 0) { box[2] -= box[0]; box[0] = 0; }
                if (box[1] < 0) { box[3] -= box[1]; box[1] = 0; }
                if (box[2] > 1) { box[0] -= (box[2] - 1); box[2] = 1; }
                if (box[3] > 1) { box[1] -= (box[3] - 1); box[3] = 1; }
            } else {
                box = [
                    drag.box[0],
                    drag.box[1],
                    Math.max(drag.box[0] + MINSIDE, p[0]),
                    Math.max(drag.box[1] + MINSIDE, p[1])
                ];
            }
            var normal = normaliseBox(box);
            if (normal) {
                active.box = normal;
                setSelection(normal);
            }
        }

        function endDrag() {
            window.removeEventListener('pointermove', moveDrag);
            window.removeEventListener('pointerup', endDrag);
            if (drag && active && !active.box) {
                selection.hidden = true;
            }
            drag = null;
        }

        stage.addEventListener('pointerdown', function(event) {
            if (!active || event.button !== 0) { return; }
            if (event.target && event.target.getAttribute && event.target.getAttribute('data-handle') === 'se') {
                startDrag(event, 'resize');
                return;
            }
            if (!selection.hidden && selection.contains(event.target)) {
                startDrag(event, 'move');
                return;
            }
            startDrag(event, 'draw');
        });

        function openEditor(button) {
            active = button;
            var index = button.getAttribute('data-index');
            var input = document.getElementById('aiyared-box-' + index);
            var box = parseBox(input ? input.value : '') || parseBox(button.getAttribute('data-box'));
            active.index = index;
            active.box = box;
            active.source = button.getAttribute('data-source');
            active.preview = button.getAttribute('data-preview');
            active.figure = parseBox(button.getAttribute('data-figure'));
            active.exercise = parseBox(button.getAttribute('data-exercise'));
            label.textContent = {$croplabel}.replace('__LABEL__', button.getAttribute('data-label') || '');
            if (active.figure) {
                figurebutton.hidden = false;
                figurebutton.setAttribute('data-box', JSON.stringify(active.figure));
            } else {
                figurebutton.hidden = true;
                figurebutton.removeAttribute('data-box');
            }
            if (active.exercise) {
                exercisebutton.hidden = false;
                exercisebutton.setAttribute('data-box', JSON.stringify(active.exercise));
            } else {
                exercisebutton.hidden = true;
                exercisebutton.removeAttribute('data-box');
            }
            selection.hidden = true;
            image.onload = function() { setSelection(active ? active.box : null); };
            image.src = active.source;
            if (dialog.showModal) {
                if (!dialog.open) { dialog.showModal(); }
            } else {
                dialog.setAttribute('open', 'open');
            }
            if (image.complete && image.naturalWidth) {
                setSelection(active.box);
            }
        }

        function closeEditor() {
            if (!dialog) { return; }
            if (dialog.close) {
                if (dialog.open) { dialog.close(); }
            } else {
                dialog.removeAttribute('open');
            }
            active = null;
            drag = null;
        }

        function previewCrop(preview, source, box, index) {
            if (!preview) { return; }
            var loader = new Image();
            loader.onload = function() {
                var x0 = Math.max(0, box[0] - PADDING);
                var y0 = Math.max(0, box[1] - PADDING);
                var x1 = Math.min(1, box[2] + PADDING);
                var y1 = Math.min(1, box[3] + PADDING);
                var sx = loader.naturalWidth;
                var sy = loader.naturalHeight;
                var cw = Math.max(1, Math.round((x1 - x0) * sx));
                var ch = Math.max(1, Math.round((y1 - y0) * sy));
                var scale = Math.min(1, 1600 / Math.max(cw, ch));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(cw * scale));
                canvas.height = Math.max(1, Math.round(ch * scale));
                var context = canvas.getContext('2d');
                if (!context) { return; }
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(loader, x0 * sx, y0 * sy, cw, ch, 0, 0, canvas.width, canvas.height);
                preview.src = canvas.toDataURL('image/jpeg', 0.9);
                preview.classList.remove('d-none');
                preview.removeAttribute('hidden');
                var wrap = preview.closest('.aiyared-import-image');
                if (wrap) { wrap.classList.remove('opacity-50'); }
                var note = document.getElementById('aiyared-noimage-' + index);
                if (note) { note.classList.add('d-none'); }
            };
            loader.src = source;
        }

        form.addEventListener('click', function(event) {
            var button = event.target.closest ? event.target.closest('.aiyared-adjustcrop') : null;
            if (button) { openEditor(button); }
        });
        if (dialog) {
            document.getElementById('aiyared-crop-close').addEventListener('click', closeEditor);
            document.getElementById('aiyared-crop-cancel').addEventListener('click', closeEditor);
            dialog.addEventListener('click', function(event) {
                if (event.target === dialog) { closeEditor(); }
            });
            [figurebutton, exercisebutton].forEach(function(button) {
                button.addEventListener('click', function() {
                    if (!active) { return; }
                    var box = parseBox(button.getAttribute('data-box'));
                    if (box) {
                        active.box = box;
                        setSelection(box);
                    }
                });
            });
            apply.addEventListener('click', function() {
                if (!active || !active.box) {
                    window.alert({$croprequired});
                    return;
                }
                var box = active.box.map(function(value) {
                    return Math.round(value * 10000) / 10000;
                });
                var input = document.getElementById('aiyared-box-' + active.index);
                if (input) { input.value = JSON.stringify(box); }
                var use = form.querySelector('input[name="items[' + active.index + '][useimage]"]');
                if (use) { use.checked = true; }
                previewCrop(document.getElementById(active.preview), active.source, box, active.index);
                closeEditor();
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
    $PAGE->requires->js_init_code($js);
}
