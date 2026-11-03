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
 * Status check: the Moodle server can reach Wikimedia Commons (picture search).
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\check;

use core\check\check;
use core\check\result;
use local_ai_yared_course\local\commons_search;

defined('MOODLE_INTERNAL') || die();

/**
 * Tells the administrator whether slide pictures can be searched (outgoing HTTPS to Wikimedia Commons).
 */
class commons extends check {

    /**
     * Name of the check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('commonscheck_name', 'local_ai_yared_course');
    }

    /**
     * Result of the check.
     *
     * @return result
     */
    public function get_result(): result {
        if (!commons_search::is_enabled()) {
            return new result(result::INFO, get_string('commonscheck_off', 'local_ai_yared_course'));
        }
        $search = new commons_search();
        if ($search->search('cat', 1) === null) {
            return new result(result::WARNING,
                get_string('commonscheck_fail', 'local_ai_yared_course', s($search->get_error())));
        }
        return new result(result::OK, get_string('commonscheck_ok', 'local_ai_yared_course'));
    }
}
