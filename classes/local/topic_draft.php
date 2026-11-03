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
 * Draft of a topic made by the Material Creator that the teacher previews before it is created in the course.
 *
 * It lives in the same private file area as the PDF imports (24 hours, deleted by the scheduled task), so it needs no
 * database table: a manifest with the input and the generated content, and the handout PDF as a file.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_draft {

    /** Origin recorded in the manifest. */
    public const ORIGIN = 'topic';

    /** Name of the stored handout. */
    public const PDF = 'handout.pdf';

    /**
     * Keep the content generated for a topic.
     *
     * @param int $userid Teacher.
     * @param int $courseid Course.
     * @param array $in Input of course_builder (title, age, language, section options...).
     * @param array $parts Result of course_builder::prepare_topic().
     * @return int Draft id.
     */
    public static function create(int $userid, int $courseid, array $in, array $parts): int {
        $pdf = $parts['pdf'] ?? null;
        unset($parts['pdf']);
        $parts['haspdf'] = $pdf !== null;
        $draftid = import_store::create($userid, [
            'version' => 1,
            'userid' => $userid,
            'courseid' => $courseid,
            'timecreated' => time(),
            'origin' => self::ORIGIN,
            'in' => $in,
            'parts' => $parts,
        ]);
        if ($pdf !== null) {
            import_store::add_file($draftid, $userid, self::PDF, $pdf, 'application/pdf');
        }
        return $draftid;
    }

    /**
     * Load a draft.
     *
     * @param int $draftid Draft id.
     * @param int $userid Teacher.
     * @param int $courseid Course.
     * @return array Manifest: in, parts (without the PDF bytes).
     * @throws \moodle_exception When it does not exist, expired, or is not a topic draft.
     */
    public static function load(int $draftid, int $userid, int $courseid): array {
        $manifest = import_store::load($draftid, $userid, $courseid);
        if (($manifest['origin'] ?? '') !== self::ORIGIN || !is_array($manifest['in'] ?? null)
                || !is_array($manifest['parts'] ?? null)) {
            throw new \moodle_exception('import_error_draftmissing', 'local_ai_yared_course');
        }
        return $manifest;
    }

    /**
     * Store the manifest again after the teacher's edits.
     *
     * @param int $draftid Draft id.
     * @param int $userid Teacher.
     * @param array $manifest Manifest.
     */
    public static function save(int $draftid, int $userid, array $manifest): void {
        import_store::save_manifest($draftid, $userid, $manifest);
    }

    /**
     * Parts with the handout bytes, ready for course_builder::create_topic().
     *
     * @param int $draftid Draft id.
     * @param int $userid Teacher.
     * @param array $manifest Manifest from load().
     * @return array
     */
    public static function parts_for_creation(int $draftid, int $userid, array $manifest): array {
        $parts = $manifest['parts'];
        $parts['pdf'] = !empty($parts['haspdf']) ? import_store::read_file($draftid, $userid, self::PDF) : null;
        return $parts;
    }

    /**
     * Delete a draft.
     *
     * @param int $draftid Draft id.
     * @param int $userid Teacher.
     */
    public static function delete(int $draftid, int $userid): void {
        import_store::delete($draftid, $userid);
    }

    /**
     * Apply the teacher's edits (texts of the header, extension, forum and questions) to a draft.
     *
     * @param array $manifest Manifest from load().
     * @param array $post Posted values: summary, objectives (one per line), forum_question, extension_title,
     *     extension_instructions, q[index] => [include, text, options[], correct], s[index] => slide fields.
     * @return array Updated manifest. Invalid values keep what the draft already had.
     */
    public static function apply_edits(array $manifest, array $post): array {
        $text = function ($value, int $max): string {
            $value = trim(preg_replace('/[ \t]+/u', ' ', strip_tags((string) $value)));
            return mb_substr($value, 0, $max);
        };
        $parts = $manifest['parts'];
        $overview = $parts['overview'];

        $summary = $text($post['summary'] ?? '', 900);
        if ($summary !== '') {
            $overview['summary'] = $summary;
        }
        if (array_key_exists('objectives', $post)) {
            $objectives = [];
            foreach (preg_split('/\R/u', (string) $post['objectives']) as $line) {
                $line = $text($line, 200);
                if ($line !== '') {
                    $objectives[] = $line;
                }
            }
            if ($objectives) {
                $overview['objectives'] = array_slice($objectives, 0, 8);
            }
        }
        if (array_key_exists('forum_question', $post)) {
            $overview['forum_question'] = $text($post['forum_question'], 500);
        }
        if (array_key_exists('extension_title', $post)) {
            $overview['extension']['title'] = $text($post['extension_title'], 120);
        }
        if (array_key_exists('extension_instructions', $post)) {
            $overview['extension']['instructions'] = $text($post['extension_instructions'], 800);
        }
        $parts['overview'] = $overview;

        if (isset($post['q']) && is_array($post['q'])) {
            $kept = [];
            foreach ((array) $parts['questions'] as $index => $question) {
                $edit = $post['q'][$index] ?? null;
                if (!is_array($edit) || empty($edit['include'])) {
                    continue;
                }
                $newtext = trim((string) ($edit['text'] ?? ''));
                if ($newtext !== '') {
                    $question['text'] = mb_substr($newtext, 0, 3000);
                }
                if (($question['type'] ?? '') === 'multichoice' && isset($edit['options']) && is_array($edit['options'])) {
                    foreach ($question['options'] as $j => $option) {
                        $newoption = trim((string) ($edit['options'][$j] ?? ''));
                        if ($newoption !== '') {
                            $question['options'][$j]['text'] = mb_substr($newoption, 0, 1000);
                        }
                    }
                    if (isset($edit['correct']) && isset($question['options'][(int) $edit['correct']])) {
                        foreach ($question['options'] as $j => $option) {
                            $question['options'][$j]['correct'] = ($j === (int) $edit['correct']);
                        }
                    }
                }
                $kept[] = $question;
            }
            $parts['questions'] = $kept;
        }
        if (!empty($parts['slides']['slides']) && isset($post['s']) && is_array($post['s']) && $post['s']) {
            $parts['slides'] = slides::apply_edits($parts['slides'], $post['s']);
        }
        $manifest['parts'] = $parts;
        return $manifest;
    }
}
