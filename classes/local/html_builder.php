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
 * Builds the HTML shown in the course page (labels and activity descriptions).
 *
 * All text coming from the AI or from the teacher is escaped. Styles are inline so the blocks look the same
 * in any theme and survive when the teacher edits them.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class html_builder {

    /**
     * Escape text for HTML.
     *
     * @param string $text Plain text.
     * @return string
     */
    protected static function e(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Header of the topic: title, summary, objectives, key ideas and a curiosity.
     *
     * @param string $title Topic title.
     * @param array $overview Overview from topic_generator.
     * @param int $age Audience age.
     * @param string $lang Content language.
     * @param int $date Class date (timestamp), 0 to hide it.
     * @return string HTML.
     */
    public static function header(string $title, array $overview, int $age, string $lang, int $date = 0): string {
        [$c1, $c2] = levels::colors($age);
        $size = levels::web_size($age);
        $emoji = levels::emoji($age);
        $card = 'flex:1 1 210px;border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:.8rem 1rem;'
            . 'background:rgba(127,127,127,.06)';

        $html = '<div style="border:1px solid rgba(0,0,0,.12);border-radius:16px;overflow:hidden;margin:0 0 .5rem">';

        // Banner with the title.
        $html .= '<div style="background:' . $c1 . ';background:linear-gradient(135deg,' . $c1 . ',' . $c2 . ');'
            . 'color:#ffffff;padding:1.3rem 1.6rem">';
        $html .= '<div style="font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;opacity:.92">'
            . $emoji . ' ' . self::e(strings::get('header_topic', $lang)) . '</div>';
        $html .= '<h2 style="margin:.25rem 0 0;color:#ffffff;font-weight:700;font-size:' . round($size * 1.7, 2) . 'rem">'
            . self::e($title) . '</h2>';
        if ($date > 0) {
            $html .= '<div style="margin-top:.4rem;font-size:.95rem;opacity:.95">🚀 '
                . self::e(strings::get('header_date', $lang)) . ': ' . userdate($date, '%d-%m-%Y') . '</div>';
        }
        $html .= '</div>';

        $html .= '<div style="padding:1.2rem 1.6rem;font-size:' . $size . 'rem;line-height:1.55">';

        // Summary and objectives, under the "What you will learn" heading.
        $html .= '<p style="margin:0 0 .3rem"><strong>' . self::e(strings::get('header_objectives', $lang))
            . '</strong></p>';
        $html .= '<p style="margin:0 0 1rem">' . self::e($overview['summary']) . '</p>';
        if (!empty($overview['objectives'])) {
            $html .= '<ul style="margin:0 0 1rem;padding-left:1.4rem">';
            foreach ($overview['objectives'] as $objective) {
                $html .= '<li>' . self::e($objective) . '</li>';
            }
            $html .= '</ul>';
        }

        // Key ideas as cards.
        if (!empty($overview['key_points'])) {
            $html .= '<p style="margin:0 0 .5rem"><strong>' . self::e(strings::get('header_keypoints', $lang))
                . '</strong></p>';
            $html .= '<div style="display:flex;flex-wrap:wrap;gap:.75rem;margin:0 0 1rem">';
            foreach ($overview['key_points'] as $point) {
                $html .= '<div style="' . $card . '">';
                $html .= '<div style="font-size:' . ($age <= 8 ? '2.2' : '1.6') . 'rem;line-height:1.1">'
                    . self::e($point['emoji']) . '</div>';
                if ($point['title'] !== '') {
                    $html .= '<div style="font-weight:700;margin:.2rem 0">' . self::e($point['title']) . '</div>';
                }
                if ($point['text'] !== '') {
                    $html .= '<div style="font-size:.95em">' . self::e($point['text']) . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Curiosity.
        if ($overview['fun_fact'] !== '') {
            $html .= '<div style="background:rgba(255,193,7,.16);border-left:5px solid #ffb300;border-radius:8px;'
                . 'padding:.8rem 1rem"><strong>💡 ' . self::e(strings::get('header_funfact', $lang)) . '</strong> '
                . self::e($overview['fun_fact']) . '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Heading bar of a block (video, material, practice) with an optional short sentence.
     *
     * @param string $text Heading text, emoji included.
     * @param string $subtitle Short sentence below the heading.
     * @param int $age Audience age.
     * @return string HTML.
     */
    public static function block_heading(string $text, string $subtitle, int $age): string {
        $color = levels::accent($age);
        $html = '<div style="border-left:6px solid ' . $color . ';background:rgba(127,127,127,.08);'
            . 'border-radius:0 10px 10px 0;padding:.6rem 1rem;margin:.5rem 0">';
        $html .= '<h3 style="margin:0;font-size:' . round(levels::web_size($age) * 1.3, 2) . 'rem;font-weight:700">'
            . self::e($text) . '</h3>';
        if ($subtitle !== '') {
            $html .= '<p style="margin:.2rem 0 0;opacity:.85">' . self::e($subtitle) . '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Description of the extension activity (instructions and steps).
     *
     * @param array $extension Extension data from topic_generator.
     * @return string HTML.
     */
    public static function extension_intro(array $extension): string {
        $html = '';
        if ($extension['instructions'] !== '') {
            $html .= '<p>' . self::e($extension['instructions']) . '</p>';
        }
        if (!empty($extension['steps'])) {
            $html .= '<ol>';
            foreach ($extension['steps'] as $step) {
                $html .= '<li>' . self::e($step) . '</li>';
            }
            $html .= '</ol>';
        }
        return $html;
    }

    /**
     * Simple paragraph used as the description of an activity.
     *
     * @param string $text Plain text.
     * @return string HTML.
     */
    public static function paragraph(string $text): string {
        return '<p>' . self::e($text) . '</p>';
    }
}
