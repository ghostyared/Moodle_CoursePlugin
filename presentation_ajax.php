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
 * AJAX endpoint of the presentation editor: picture search in Wikimedia Commons, choosing and removing a slide picture.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_ai_yared_course\local\commons_search;
use local_ai_yared_course\local\image_helper;
use local_ai_yared_course\local\slides;

$courseid = required_param('id', PARAM_INT);
$itemid = required_param('p', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($course->id);
if (!slides::can_edit($context)) {
    throw new required_capability_exception($context, 'local/ai_yared_course:use', 'nopermissions', '');
}

$reply = function (array $data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    die();
};
$fail = function (string $identifier) use ($reply) {
    $reply(['ok' => false, 'error' => get_string($identifier, 'local_ai_yared_course')]);
};

$deck = slides::load($context, $itemid);
if (!$deck) {
    $fail('presentation_missing');
}
$index = optional_param('index', -1, PARAM_INT);

if ($action === 'imgsearch') {
    if (!commons_search::is_enabled()) {
        $fail('import_img_err_disabled');
    }
    $query = trim(optional_param('query', '', PARAM_TEXT));
    if ($query === '') {
        $fail('import_img_err_query');
    }
    $searcher = new commons_search();
    $results = $searcher->search(core_text::substr($query, 0, 120), 8);
    if ($results === null) {
        $reply(['ok' => false, 'nonet' => true,
            'error' => get_string('slide_image_nonet', 'local_ai_yared_course', s($searcher->get_error()))]);
    }
    $reply(['ok' => true, 'results' => $results]);
}

if (!isset($deck['slides'][$index])) {
    $fail('presentation_missing');
}

if ($action === 'upload') {
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')
            || (int) ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $fail('slide_image_notchosen');
    }
    $bytes = (string) file_get_contents($_FILES['file']['tmp_name']);
    $checked = image_helper::validate_bytes($bytes, (string) $_FILES['file']['name']);
    if (!empty($checked['error'])) {
        $reply(['ok' => false, 'error' => $checked['error']]);
    }
    $filename = slides::add_image($context, $itemid, $checked['data'], pathinfo($checked['filename'], PATHINFO_EXTENSION));
    $deck['slides'][$index]['image'] = ['file' => $filename, 'credit' => '',
        'alt' => core_text::substr(clean_param(pathinfo((string) $_FILES['file']['name'], PATHINFO_FILENAME), PARAM_TEXT), 0, 150)];
    $deck['slides'][$index]['figure_svg'] = null;
    slides::save($context, $itemid, $deck);
    slides::prune_images($context, $itemid, $deck);
    $reply(['ok' => true, 'url' => slides::image_url($context, $itemid, $filename), 'credit' => '']);
}

if ($action === 'setimage') {
    $imgurl = optional_param('imgurl', '', PARAM_RAW_TRIMMED);
    if (!commons_search::is_enabled() || !commons_search::is_commons_image_url($imgurl)) {
        $fail('import_img_err_download');
    }
    $fetched = image_helper::fetch($imgurl);
    if (!empty($fetched['error'])) {
        $reply(['ok' => false, 'error' => get_string('import_img_err_download', 'local_ai_yared_course') . ' ' .
            $fetched['error']]);
    }
    $licenseurl = optional_param('imglicenseurl', '', PARAM_RAW_TRIMMED);
    $pageurl = optional_param('imgpage', '', PARAM_RAW_TRIMMED);
    $candidate = [
        'author' => core_text::substr(trim(clean_param(optional_param('imgauthor', '', PARAM_RAW), PARAM_TEXT)), 0, 150),
        'license' => core_text::substr(trim(clean_param(optional_param('imglicense', '', PARAM_RAW), PARAM_TEXT)), 0, 60),
        'licenseurl' => preg_match('#^https?://#i', $licenseurl) ? core_text::substr($licenseurl, 0, 300) : '',
        'page' => preg_match('#^https://commons\.wikimedia\.org/#i', $pageurl) ? core_text::substr($pageurl, 0, 400) : '',
    ];
    $filename = slides::add_image($context, $itemid, $fetched['data'], pathinfo($fetched['filename'], PATHINFO_EXTENSION));
    $deck['slides'][$index]['image'] = [
        'file' => $filename,
        'credit' => commons_search::credit($candidate),
        'alt' => core_text::substr(trim(clean_param(optional_param('imgtitle', '', PARAM_RAW), PARAM_TEXT)), 0, 150),
    ];
    // A picture replaces a drawn figure on that slide.
    $deck['slides'][$index]['figure_svg'] = null;
    slides::save($context, $itemid, $deck);
    slides::prune_images($context, $itemid, $deck);
    $reply(['ok' => true, 'url' => slides::image_url($context, $itemid, $filename),
        'credit' => $deck['slides'][$index]['image']['credit']]);
}

if ($action === 'removeimage') {
    $deck['slides'][$index]['image'] = null;
    slides::save($context, $itemid, $deck);
    slides::prune_images($context, $itemid, $deck);
    $reply(['ok' => true]);
}

$fail('presentation_missing');
