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
 * Plugin strings in the language chosen for the generated content (not the interface language).
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class strings {

    /**
     * Get a string of the plugin in the content language.
     *
     * Only Spanish and English are translated; any other language falls back to English.
     *
     * @param string $identifier String identifier.
     * @param string $lang Content language code.
     * @param mixed $a Optional string parameter.
     * @return string
     */
    public static function get(string $identifier, string $lang, $a = null): string {
        $lang = in_array($lang, ['es', 'en'], true) ? $lang : 'en';
        return get_string_manager()->get_string($identifier, 'local_ai_yared_course', $a, $lang);
    }
}
