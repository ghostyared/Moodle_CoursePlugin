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
 * Tolerant JSON reader for model answers.
 *
 * Models usually return valid JSON, but they also add markdown fences, comments, unescaped
 * LaTeX backslashes, trailing commas or cut the answer off when they run out of tokens.
 * This class recovers as much usable data as possible.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_json {

    /**
     * Extract the JSON object from a model answer, repairing the common mistakes.
     *
     * @param string $raw Raw model output.
     * @param bool $math Whether LaTeX backslash repair is needed.
     * @return array|null Decoded data, or null if nothing usable was found.
     */
    public static function extract(string $raw, bool $math = false): ?array {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw); // UTF-8 BOM.
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Remove markdown code fences wherever they appear.
        $clean = preg_replace('/```(?:json)?/i', '', $raw);
        $clean = self::repair_escapes($clean, $math);

        $data = json_decode($clean, true);
        if (is_array($data)) {
            return $data;
        }

        // First balanced {...} block (tolerates text before or after the object).
        $object = self::first_balanced($clean, '{', '}');
        if ($object !== null) {
            $data = json_decode($object, true);
            if (is_array($data)) {
                return $data;
            }
            $data = json_decode(self::repair($object), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Last resort: rescue the complete objects from a truncated answer.
        return self::salvage($clean);
    }

    /**
     * Return the first balanced block delimited by $open/$close, respecting JSON strings.
     *
     * @param string $text Text to scan.
     * @param string $open Opening character.
     * @param string $close Closing character.
     * @return string|null The block (inclusive), or null if it never closes.
     */
    public static function first_balanced(string $text, string $open, string $close): ?string {
        $start = strpos($text, $open);
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $instring = false;
        $escaped = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                } else if ($ch === '\\') {
                    $escaped = true;
                } else if ($ch === '"') {
                    $instring = false;
                }
                continue;
            }
            if ($ch === '"') {
                $instring = true;
            } else if ($ch === $open) {
                $depth++;
            } else if ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    /**
     * Escape backslashes that models leave unescaped inside JSON strings.
     *
     * Models often write LaTeX as \sin instead of \\sin. That is invalid JSON, so this
     * method doubles backslashes that are not valid JSON escapes. In math mode it also
     * treats \n, \r, \t, \b and \f followed by a letter as LaTeX commands (for example
     * \theta or \neq) instead of JSON control characters.
     *
     * @param string $json JSON text.
     * @param bool $math Whether math-mode heuristics are enabled.
     * @return string JSON text with valid string escapes.
     */
    public static function repair_escapes(string $json, bool $math = false): string {
        $out = '';
        $instring = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if (!$instring) {
                if ($char === '"') {
                    $instring = true;
                }
                $out .= $char;
                continue;
            }
            if ($char === '"') {
                $instring = false;
                $out .= $char;
                continue;
            }
            if ($char !== '\\') {
                $out .= $char;
                continue;
            }

            $next = $i + 1 < $length ? $json[$i + 1] : '';
            if ($next === '\\' || $next === '"' || $next === '/' || $next === 'b'
                    || $next === 'f' || $next === 'n' || $next === 'r' || $next === 't') {
                if ($math && in_array($next, ['b', 'f', 'n', 'r', 't'], true)
                        && $i + 2 < $length && preg_match('/[a-zA-Z]/', $json[$i + 2])) {
                    // A newline followed by an uppercase letter is usually a real line break
                    // in feedback ("Paso 1:\nPaso 2"), not the LaTeX command \n....
                    if ($next === 'n' && ctype_upper($json[$i + 2])) {
                        $out .= $char . $next;
                        $i++;
                    } else {
                        $out .= '\\\\';
                    }
                } else {
                    $out .= $char . $next;
                    $i++;
                }
                continue;
            }
            if ($next === 'u' && preg_match('/^[0-9a-fA-F]{4}$/', substr($json, $i + 2, 4))) {
                $out .= $char . $next;
                $i++;
                continue;
            }
            // Invalid JSON escape: keep the backslash as a literal character.
            $out .= '\\\\';
        }
        return $out;
    }

    /**
     * Repair the small mistakes models make: trailing commas and stray control characters.
     *
     * @param string $json JSON text.
     * @return string Repaired JSON text.
     */
    public static function repair(string $json): string {
        // Remove trailing commas before a closing brace or bracket.
        $json = preg_replace('/,\s*([}\]])/', '$1', $json);
        // Remove control characters (raw newlines, tabs, etc.) that are invalid inside JSON strings.
        $json = preg_replace('/[\x00-\x1F]/', '', $json);
        return $json;
    }

    /**
     * Rescue complete objects from an answer that was cut off mid-JSON.
     *
     * @param string $text Raw text.
     * @return array|null ['questions' => [...]] when question-like objects were found, else null.
     */
    public static function salvage(string $text): ?array {
        $items = [];
        $offset = 0;
        $len = strlen($text);
        while ($offset < $len) {
            $pos = strpos($text, '{', $offset);
            if ($pos === false) {
                break;
            }
            $object = self::first_balanced(substr($text, $pos), '{', '}');
            if ($object === null) {
                $offset = $pos + 1;
                continue;
            }
            $offset = $pos + strlen($object);
            $decoded = json_decode($object, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(self::repair($object), true);
            }
            if (!is_array($decoded)) {
                continue;
            }
            // The wrapper that holds the list of questions or exercises.
            foreach (['questions', 'exercises'] as $wrapper) {
                if (isset($decoded[$wrapper]) && is_array($decoded[$wrapper])) {
                    return $decoded;
                }
            }
            // A single question/exercise object: it must look like one, not like an option.
            $lookslikeitem = (isset($decoded['text']) || isset($decoded['question']) || isset($decoded['statement']))
                && (isset($decoded['options']) || isset($decoded['answer']) || isset($decoded['type']));
            if ($lookslikeitem) {
                $items[] = $decoded;
            }
        }
        return $items ? ['items' => $items] : null;
    }
}
