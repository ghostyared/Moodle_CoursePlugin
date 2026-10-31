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

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Helpers to list and create question categories in the course question bank.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_helper {

    /**
     * Categories of the course question bank as an indented tree.
     *
     * @param \context $context Course context.
     * @return string[] Category id => label.
     */
    public static function get_options(\context $context): array {
        global $DB;

        $top = question_get_top_category($context->id, true);
        $cats = $DB->get_records('question_categories', ['contextid' => $context->id], 'sortorder, name');

        $children = [];
        foreach ($cats as $cat) {
            if ((int) $cat->id === (int) $top->id) {
                continue;
            }
            $children[(int) $cat->parent][] = $cat;
        }

        $options = [];
        $walk = function (int $parentid, int $depth) use (&$walk, &$children, &$options, $context) {
            foreach ($children[$parentid] ?? [] as $cat) {
                $options[$cat->id] = str_repeat('- ', $depth) . format_string($cat->name, true, ['context' => $context]);
                $walk((int) $cat->id, $depth + 1);
            }
        };
        $walk((int) $top->id, 0);

        return $options;
    }

    /**
     * Create a top-level category in the course question bank.
     *
     * @param \context $context Course context.
     * @param string $name Category name.
     * @return \stdClass The new category row.
     */
    public static function create(\context $context, string $name): \stdClass {
        global $DB;

        $top = question_get_top_category($context->id, true);

        $cat = new \stdClass();
        $cat->name = \core_text::substr($name, 0, 255);
        $cat->contextid = $context->id;
        $cat->info = '';
        $cat->infoformat = FORMAT_HTML;
        $cat->stamp = make_unique_id_code();
        $cat->parent = $top->id;
        $cat->sortorder = 999;
        $cat->idnumber = null;
        $cat->id = $DB->insert_record('question_categories', $cat);

        return $cat;
    }
}
