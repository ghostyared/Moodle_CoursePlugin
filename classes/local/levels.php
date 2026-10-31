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
 * Maps an age to an education level and to the look of the generated content.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class levels {

    /** Minimum and maximum supported ages. */
    public const MIN_AGE = 3;
    public const MAX_AGE = 23;

    /**
     * Level key for an age.
     *
     * @param int $age Age in years.
     * @return string initial|primary|secondary|university
     */
    public static function key(int $age): string {
        if ($age <= 5) {
            return 'initial';
        }
        if ($age <= 12) {
            return 'primary';
        }
        if ($age <= 17) {
            return 'secondary';
        }
        return 'university';
    }

    /**
     * Gradient colours (start, end) used for the header of the topic.
     *
     * @param int $age Age in years.
     * @return string[]
     */
    public static function colors(int $age): array {
        switch (self::key($age)) {
            case 'initial':
                return ['#ef6c00', '#fbc02d'];
            case 'primary':
                return ['#1565c0', '#00acc1'];
            case 'secondary':
                return ['#00695c', '#3949ab'];
            default:
                return ['#283593', '#455a64'];
        }
    }

    /**
     * Single accent colour (used in the PDF).
     *
     * @param int $age Age in years.
     * @return string
     */
    public static function accent(int $age): string {
        return self::colors($age)[0];
    }

    /**
     * Emoji that decorates the header.
     *
     * @param int $age Age in years.
     * @return string
     */
    public static function emoji(int $age): string {
        $emojis = ['initial' => '🌈', 'primary' => '🚀', 'secondary' => '🔬', 'university' => '🎓'];
        return $emojis[self::key($age)];
    }

    /**
     * Base text size (CSS rem) of the web content.
     *
     * @param int $age Age in years.
     * @return float
     */
    public static function web_size(int $age): float {
        $sizes = ['initial' => 1.3, 'primary' => 1.15, 'secondary' => 1.05, 'university' => 1.0];
        return $sizes[self::key($age)];
    }

    /**
     * Base text size (points) of the PDF.
     *
     * @param int $age Age in years.
     * @return int
     */
    public static function pdf_size(int $age): int {
        $sizes = ['initial' => 18, 'primary' => 13, 'secondary' => 11, 'university' => 10];
        return $sizes[self::key($age)];
    }
}
