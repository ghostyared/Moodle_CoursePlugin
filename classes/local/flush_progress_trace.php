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
 * Progress trace that sends every line to the browser immediately.
 *
 * Moodle keeps the page buffered until the script finishes, which leaves the teacher looking at a blank
 * page while the AI writes a topic. This trace flushes each step so the progress is visible.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flush_progress_trace extends \progress_trace {

    /** @var bool Whether the wrapping container was already opened. */
    protected $started = false;

    /**
     * Print one progress line and flush it.
     *
     * @param string $message Message.
     * @param int $depth Indentation depth.
     */
    public function output($message, $depth = 0) {
        if (!$this->started) {
            echo \html_writer::start_div('aiyared-progress border rounded p-2 mb-3 bg-light');
            $this->started = true;
        }
        echo \html_writer::div(
            \html_writer::span(str_repeat('&nbsp;&nbsp;', max(0, (int) $depth))) . \s((string) $message),
            'aiyared-progress-line'
        );
        self::flush_now();
    }

    /**
     * Close the container.
     *
     * @return bool
     */
    public function finished() {
        if ($this->started) {
            echo \html_writer::end_div();
            $this->started = false;
            self::flush_now();
        }
        return true;
    }

    /**
     * Send everything printed so far to the browser.
     */
    public static function flush_now(): void {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
