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
require_once($CFG->libdir . '/pdflib.php');

/**
 * Generates the class handout as a PDF with Moodle's bundled TCPDF.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_builder {

    /**
     * Build the class handout.
     *
     * @param string $title Topic title.
     * @param array $overview Overview from topic_generator.
     * @param array $content Document content from topic_generator.
     * @param int $age Audience age.
     * @param string $lang Content language.
     * @return string PDF file content.
     */
    public static function build(string $title, array $overview, array $content, int $age, string $lang): string {
        $size = levels::pdf_size($age);
        $accent = levels::accent($age);
        $pt = function (float $factor) use ($size): string {
            return round($size * $factor, 1) . 'pt';
        };

        $html = '<h1 style="font-size:' . $pt(2) . ';color:' . $accent . '">' . self::e($title) . '</h1>';
        if ($content['subtitle'] !== '') {
            $html .= '<p style="font-size:' . $pt(1.1) . ';color:#555555">' . self::e($content['subtitle']) . '</p>';
        }
        if ($content['intro'] !== '') {
            $html .= '<p>' . self::e($content['intro']) . '</p>';
        }

        if (!empty($overview['objectives'])) {
            $html .= self::box(strings::get('pdf_objectives', $lang), self::bullets($overview['objectives']),
                '#EAF2FB', $accent, $pt(1.15));
        }

        foreach ($content['sections'] as $i => $section) {
            if ($section['heading'] !== '') {
                $html .= '<h2 style="font-size:' . $pt(1.4) . ';color:' . $accent . '">' . ($i + 1) . '. '
                    . self::e($section['heading']) . '</h2>';
            }
            foreach ($section['paragraphs'] as $paragraph) {
                $html .= '<p style="text-align:justify">' . self::e($paragraph) . '</p>';
            }
            if (!empty($section['bullets'])) {
                $html .= self::bullets($section['bullets']);
            }
        }

        if ($content['example'] !== '') {
            $html .= self::box(strings::get('pdf_example', $lang), '<p>' . self::e($content['example']) . '</p>',
                '#FFF6DD', '#B26A00', $pt(1.15));
        }

        if (!empty($content['glossary'])) {
            // The heading is a row of the table (nobr) so it is never left alone at the bottom of a page.
            $html .= '<table nobr="true" cellpadding="5" border="0.5" bordercolor="#BBBBBB" width="100%">';
            $html .= '<tr><td colspan="2" border="0" style="font-size:' . $pt(1.4) . ';color:' . $accent . '"><b>'
                . self::e(strings::get('pdf_glossary', $lang)) . '</b></td></tr>';
            foreach ($content['glossary'] as $entry) {
                $html .= '<tr><td width="30%" bgcolor="#F2F2F2"><b>' . self::e($entry['term']) . '</b></td><td width="70%">'
                    . self::e($entry['definition']) . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!empty($content['summary_points'])) {
            $html .= '<h2 style="font-size:' . $pt(1.4) . ';color:' . $accent . '">'
                . self::e(strings::get('pdf_summary', $lang)) . '</h2>' . self::bullets($content['summary_points']);
        }

        if (!empty($content['review_questions'])) {
            $html .= '<h2 style="font-size:' . $pt(1.4) . ';color:' . $accent . '">'
                . self::e(strings::get('pdf_review', $lang)) . '</h2><ol>';
            foreach ($content['review_questions'] as $question) {
                $html .= '<li>' . self::e($question) . '</li>';
            }
            $html .= '</ol>';
        }

        return self::render($title, $html, $size);
    }

    /**
     * Build a handout from the overview when the full document could not be generated.
     *
     * It always contains the summary, the objectives, the key ideas, the curiosity and the extension
     * activity, so the student never finds an empty file.
     *
     * @param string $title Topic title.
     * @param array $overview Overview from topic_generator.
     * @param int $age Audience age.
     * @param string $lang Content language.
     * @return string PDF file content.
     */
    public static function build_from_overview(string $title, array $overview, int $age, string $lang): string {
        $size = levels::pdf_size($age);
        $accent = levels::accent($age);
        $pt = function (float $factor) use ($size): string {
            return round($size * $factor, 1) . 'pt';
        };

        $html = '<h1 style="font-size:' . $pt(2) . ';color:' . $accent . '">' . self::e($title) . '</h1>';
        if (($overview['summary'] ?? '') !== '') {
            $html .= '<p style="text-align:justify">' . self::e($overview['summary']) . '</p>';
        }

        if (!empty($overview['objectives'])) {
            $html .= self::box(strings::get('pdf_objectives', $lang), self::bullets($overview['objectives']),
                '#EAF2FB', $accent, $pt(1.15));
        }

        if (!empty($overview['key_points'])) {
            $html .= '<h2 style="font-size:' . $pt(1.4) . ';color:' . $accent . '">'
                . self::e(strings::get('header_keypoints', $lang)) . '</h2>';
            foreach ($overview['key_points'] as $point) {
                $html .= '<p><b>' . self::e($point['title']) . '</b><br />' . self::e($point['text']) . '</p>';
            }
        }

        if (($overview['fun_fact'] ?? '') !== '') {
            $html .= self::box(strings::get('header_funfact', $lang), '<p>' . self::e($overview['fun_fact']) . '</p>',
                '#FFF6DD', '#B26A00', $pt(1.15));
        }

        $extension = $overview['extension'] ?? [];
        if (!empty($extension['instructions'])) {
            $body = '<p>' . self::e($extension['instructions']) . '</p>';
            if (!empty($extension['steps'])) {
                $body .= self::bullets($extension['steps']);
            }
            $html .= self::box(strings::get('heading_extension', $lang), $body, '#EAF7EE', '#1E7B3C', $pt(1.15));
        }

        return self::render($title, $html, $size);
    }

    /**
     * Placeholder PDF that the teacher replaces with the real handout.
     *
     * @param string $title Topic title.
     * @param string $lang Content language.
     * @return string PDF file content.
     */
    public static function placeholder(string $title, string $lang): string {
        $html = '<h1 style="font-size:24pt;color:#B26A00">' . self::e($title) . '</h1>'
            . '<p style="font-size:14pt">' . self::e(strings::get('pdf_placeholder_text', $lang)) . '</p>';
        return self::render($title, $html, 12);
    }

    /**
     * Render HTML to a PDF string.
     *
     * @param string $title Document title.
     * @param string $html Body HTML.
     * @param int $size Base font size in points.
     * @return string
     */
    protected static function render(string $title, string $html, int $size): string {
        $doc = new \pdf();
        $doc->SetCreator('Moodle');
        $doc->SetTitle(self::plain($title));
        $doc->setPrintHeader(false);
        $doc->setPrintFooter(true);
        $doc->SetMargins(18, 18, 18);
        $doc->SetAutoPageBreak(true, 18);
        $doc->SetFont('freesans', '', $size);
        $doc->AddPage();
        $doc->writeHTML($html, true, false, true, false, '');
        return $doc->Output('class.pdf', 'S');
    }

    /**
     * Coloured box with a heading.
     *
     * @param string $heading Heading.
     * @param string $bodyhtml Body HTML.
     * @param string $background Background colour.
     * @param string $color Heading colour.
     * @param string $fontsize Heading font size.
     * @return string
     */
    protected static function box(string $heading, string $bodyhtml, string $background, string $color,
            string $fontsize): string {
        return '<table cellpadding="8" width="100%" bgcolor="' . $background . '"><tr><td>'
            . '<b style="font-size:' . $fontsize . ';color:' . $color . '">' . self::e($heading) . '</b>'
            . $bodyhtml . '</td></tr></table><br />';
    }

    /**
     * Bullet list.
     *
     * @param string[] $items Plain text items.
     * @return string
     */
    protected static function bullets(array $items): string {
        $html = '<ul>';
        foreach ($items as $item) {
            $html .= '<li>' . self::e($item) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * Remove characters the bundled PDF font cannot draw (emojis and pictographs).
     *
     * @param string $text Text.
     * @return string
     */
    protected static function plain(string $text): string {
        return trim(preg_replace('/[\x{1F000}-\x{1FFFF}\x{2190}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $text));
    }

    /**
     * Escape text for the PDF HTML.
     *
     * @param string $text Plain text.
     * @return string
     */
    protected static function e(string $text): string {
        return htmlspecialchars(self::plain($text), ENT_QUOTES, 'UTF-8');
    }
}
