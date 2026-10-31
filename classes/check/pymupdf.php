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
 * Status check: PyMuPDF available to convert PDFs.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\check;

use core\check\check;
use core\check\result;
use local_ai_yared_course\local\pdf_renderer;

defined('MOODLE_INTERNAL') || die();

/**
 * Warns the administrator when PDFs cannot be turned into images (Python 3 + PyMuPDF missing).
 */
class pymupdf extends check {

    /**
     * Name of the check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('pdfcheck_name', 'local_ai_yared_course');
    }

    /**
     * Link to the plugin settings, where the status and the installation steps are shown.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(new \moodle_url('/admin/settings.php', ['section' => 'local_ai_yared_course']),
            get_string('pdfcheck_more', 'local_ai_yared_course'));
    }

    /**
     * Result of the check.
     *
     * @return result
     */
    public function get_result(): result {
        $status = pdf_renderer::get_status();
        $env = get_string($status['docker'] ? 'pdfenv_docker' : 'pdfenv_host', 'local_ai_yared_course');
        if ($status['pymupdf']) {
            return new result(result::OK, get_string('pdfcheck_ok', 'local_ai_yared_course', s($status['pymupdfversion'])),
                s($env));
        }
        $details = get_string($status['python'] === null ? 'pdfstatus_nopython' : 'pdfstatus_nopymupdf',
            'local_ai_yared_course', (object) ['python' => s((string) $status['python']), 'env' => s($env)]);
        $details = \html_writer::tag('p', $details) . pdf_renderer::install_help_html($status['docker']);
        return new result(result::WARNING, get_string('pdfcheck_warning', 'local_ai_yared_course'), $details);
    }
}
