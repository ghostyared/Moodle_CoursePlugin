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
 * Course section labels for the placement selectors.
 *
 * Formato-Mariategui/Flexsections keep the parent of every subsection in the course format
 * options, so a flat select needs an explicit hierarchy: indentation plus the name of the
 * parent section tells the teacher which section is inside which one.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers to list the course sections with their hierarchy.
 */
class section_options {
    /**
     * Sections of a course as "section number => label", subsections included.
     *
     * Subsections are indented with non-breaking spaces and prefixed with "↳", and they
     * show the name of their parent section, so the hierarchy is readable in a plain select.
     *
     * @param \stdClass $course Course record.
     * @param bool $markempty Append "(empty)" to the sections that have no activities.
     * @return array Section number => label.
     */
    public static function for_select(\stdClass $course, bool $markempty = false): array {
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();

        $bynumber = [];
        foreach ($sections as $section) {
            $bynumber[(int) $section->section] = $section;
        }

        $options = [];
        foreach ($sections as $section) {
            $number = (int) $section->section;
            $depth = self::depth($section, $bynumber);

            $label = $number . ' · ';
            if ($depth > 0) {
                // Visual ladder: one step for every parent level.
                $label .= str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . '↳ ';
            }
            $label .= self::name($section);

            $parentnumber = (int) ($section->parent ?? 0);
            if ($depth > 0 && isset($bynumber[$parentnumber])) {
                $label .= ' (' . get_string('section_insection', 'local_ai_yared_course',
                    self::name($bynumber[$parentnumber])) . ')';
            }
            if ($markempty && $number > 0 && empty($modinfo->sections[$number])) {
                $label .= ' (' . get_string('section_empty', 'local_ai_yared_course') . ')';
            }

            $options[$number] = $label;
        }

        return $options;
    }

    /**
     * Display name of a course section.
     *
     * @param \section_info $section Section info.
     * @return string
     */
    public static function name($section): string {
        $number = (int) $section->section;
        if ($number === 0) {
            return get_string('general', 'moodle');
        }

        $name = trim(clean_text((string) $section->name));
        if ($name === '') {
            return get_string('section') . ' ' . $number;
        }

        return $name;
    }

    /**
     * Depth of a section in the FlexTiles/Flexsections hierarchy.
     *
     * @param \section_info $section Section info.
     * @param array $bynumber Section number => section info.
     * @return int
     */
    public static function depth($section, array $bynumber): int {
        $depth = 0;
        $seen = [(int) $section->section => true];
        $parent = (int) ($section->parent ?? 0);

        while ($parent > 0 && isset($bynumber[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $depth++;
            $parent = (int) ($bynumber[$parent]->parent ?? 0);
        }

        return $depth;
    }
}
