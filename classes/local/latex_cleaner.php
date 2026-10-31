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
 * Removes LaTeX that the AI wrapped around plain text.
 *
 * Vision models tend to put \( ... \) around words, years and ordinary numbers of theory exercises.
 * The text then renders in a math font and looks wrong. This class keeps the delimiters only where
 * there is real mathematical notation.
 *
 * Rules, applied to all the texts of one exercise (statement, options, feedback) together:
 * - A delimiter that only holds words (two or more letters, no digits or symbols) is always unwrapped.
 * - If the exercise has no real mathematics at all, every delimiter is unwrapped, including numbers
 *   and single letters.
 * - If the exercise has real mathematics, short symbols such as \(x\) or \(2\) stay as the AI wrote them.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class latex_cleaner {

    /** Pattern of an inline or display delimiter and its content. */
    protected const DELIMITED = '/\\\\\((.*?)\\\\\)|\\\\\[(.*?)\\\\\]/su';

    /**
     * Clean several texts of the same exercise.
     *
     * @param string[] $texts Texts (any keys).
     * @param string $mode auto|theory|math. In "theory" every delimiter is removed; in "math" only
     *     the words-only ones are removed.
     * @return string[] Same keys, cleaned.
     */
    public static function clean_all(array $texts, string $mode = 'auto'): array {
        $hasmath = false;
        if ($mode === 'math') {
            $hasmath = true;
        } else if ($mode === 'auto') {
            foreach ($texts as $text) {
                if (self::has_real_math((string) $text)) {
                    $hasmath = true;
                    break;
                }
            }
        }

        $out = [];
        foreach ($texts as $key => $text) {
            $out[$key] = self::unwrap((string) $text, $mode === 'theory', $hasmath);
        }
        return $out;
    }

    /**
     * Whether a text still contains LaTeX delimiters.
     *
     * @param string $text Text.
     * @return bool
     */
    public static function has_latex(string $text): bool {
        return (bool) preg_match(self::DELIMITED, $text) || strpos($text, '$$') !== false;
    }

    /**
     * Whether a text contains at least one delimiter with real mathematical notation.
     *
     * @param string $text Text.
     * @return bool
     */
    protected static function has_real_math(string $text): bool {
        if (!preg_match_all(self::DELIMITED, $text, $matches, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($matches as $match) {
            $inner = self::inner($match);
            if (self::kind($inner) === 'math') {
                return true;
            }
        }
        return false;
    }

    /**
     * Content of a delimiter match.
     *
     * @param array $match preg match.
     * @return string
     */
    protected static function inner(array $match): string {
        return trim((string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')));
    }

    /**
     * Classify the content of a delimiter.
     *
     * @param string $inner Content.
     * @return string words|light|math. "words" is plain text with words, "light" is a number or a single
     *     letter, "math" is real notation.
     */
    protected static function kind(string $inner): string {
        // \text{...}, \mathrm{...} and \textbf{...} around everything are just plain text.
        $plain = preg_replace('/^\\\\(?:text|mathrm|textbf|textit|mathbf)\{(.*)\}$/su', '$1', $inner);
        if ($plain === '' || $plain === null) {
            return 'light';
        }
        if (preg_match('/[\\\\^_{}=+*<>|\/~]/u', $plain) || preg_match('/\p{L}\d|\d\p{L}/u', $plain)) {
            return 'math';
        }
        if (!preg_match('/^[\p{L}\p{M}\d\s.,;:!?¿¡\'"«»()%°ºª\-–—]+$/u', $plain)) {
            return 'math';
        }
        if (preg_match('/\p{L}{2,}/u', $plain)) {
            return 'words';
        }
        return 'light';
    }

    /**
     * Text of a delimiter once unwrapped.
     *
     * @param string $inner Content.
     * @return string
     */
    protected static function plain_text(string $inner): string {
        $plain = preg_replace('/^\\\\(?:text|mathrm|textbf|textit|mathbf)\{(.*)\}$/su', '$1', $inner);
        return trim((string) $plain);
    }

    /**
     * Unwrap the delimiters that do not hold real mathematics.
     *
     * @param string $text Text.
     * @param bool $all Remove every delimiter that is not notation, whatever its size.
     * @param bool $keeplight Keep numbers and single letters wrapped (the exercise has real mathematics).
     * @return string
     */
    protected static function unwrap(string $text, bool $all, bool $keeplight): string {
        return (string) preg_replace_callback(self::DELIMITED, function (array $match) use ($all, $keeplight) {
            $inner = self::inner($match);
            $kind = self::kind($inner);
            if ($kind === 'math' && !$all) {
                return $match[0];
            }
            if ($kind === 'math' && $all) {
                // Theory mode with real notation: leave the formula, the teacher asked for no LaTeX but a
                // formula cannot be turned into plain text without losing it.
                return $match[0];
            }
            if ($kind === 'light' && $keeplight && !$all) {
                return $match[0];
            }
            return self::plain_text($inner);
        }, $text);
    }
}
