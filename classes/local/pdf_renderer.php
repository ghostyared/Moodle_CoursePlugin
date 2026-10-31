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
 * Converts the pages of a PDF into images so the importer can detect and crop exercises.
 *
 * Rendering needs an external helper because Moodle does not include a PDF rasteriser. The helper
 * is a small Python script that uses PyMuPDF. When Python or PyMuPDF is not available the importer
 * falls back to sending the PDF directly to Gemini, which keeps the text but cannot crop figures.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_renderer {

    /** Maximum number of pages rendered from one PDF. */
    public const MAX_PAGES = 15;

    /** Longest side of a rendered page, in pixels. */
    public const MAX_SIDE = 2200;

    /** Seconds allowed for the external renderer. */
    public const TIMEOUT = 120;

    /** @var array|false|null Cached Python command, false when none was found. */
    protected static $python = null;

    /** @var string[] Pythons that run but cannot import PyMuPDF (for the error detail). */
    protected static $pythontried = [];

    /** @var string Last error code, empty when there was no error. */
    protected static $lasterror = '';

    /** @var string Technical detail of the last error (helper message or stderr), for administrators. */
    protected static $lastdetail = '';

    /** @var string[] Error codes that can be transient (load, timing) and deserve one retry. */
    protected const RETRYABLE = ['timeout', 'render', 'exception', 'nopages', 'tempdir', 'tempfile'];

    /**
     * Whether PHP is running inside a container (Docker, Podman, Kubernetes).
     *
     * @return bool
     */
    public static function is_docker(): bool {
        if (DIRECTORY_SEPARATOR === '\\') {
            return false;
        }
        if (@file_exists('/.dockerenv') || @file_exists('/run/.containerenv')) {
            return true;
        }
        $cgroup = @file_get_contents('/proc/1/cgroup');
        return is_string($cgroup) && (bool) preg_match('/docker|kubepods|containerd|libpod/i', $cgroup);
    }

    /**
     * Report what the PDF conversion needs and what this server has, for the administrator.
     *
     * @return array docker, python (command or null), pythonpath, pythonversion, pymupdf (bool), pymupdfversion.
     */
    public static function get_status(): array {
        $status = ['docker' => self::is_docker(), 'python' => null, 'pythonpath' => '', 'pythonversion' => '',
            'pymupdf' => false, 'pymupdfversion' => ''];
        $python = self::find_python();
        if ($python === null) {
            return $status;
        }
        $status['python'] = implode(' ', $python);
        if (preg_match('/Python\s+([0-9.]+)/i', self::probe_output($python, ['--version']), $match)) {
            $status['pythonversion'] = $match[1];
        }
        $path = trim(self::probe_output($python, ['-c', 'import sys; print(sys.executable)']));
        $status['pythonpath'] = strlen($path) < 300 ? $path : '';
        $code = "try:\n import pymupdf\nexcept Exception:\n import fitz as pymupdf\n"
            . "print('PYMUPDF_OK ' + str(getattr(pymupdf, '__version__', '?')))";
        if (preg_match('/PYMUPDF_OK\s+(\S+)/', self::probe_output($python, ['-c', $code]), $match)) {
            $status['pymupdf'] = true;
            $status['pymupdfversion'] = $match[1];
        }
        return $status;
    }

    /**
     * Installation commands for PyMuPDF, for a container image or for a regular server.
     *
     * @param bool $docker Whether Moodle runs in a container.
     * @return string Plain text, to show inside a <pre> block.
     */
    public static function install_commands(bool $docker): string {
        $check = '# ' . get_string('pdfhelp_check', 'local_ai_yared_course') . "\n";
        if ($docker) {
            return "# Dockerfile\n"
                . "RUN apt-get update && apt-get install -y python3-venv \\\n"
                . " && python3 -m venv /opt/pymupdf && /opt/pymupdf/bin/pip install PyMuPDF\n\n"
                . "# docker compose build && docker compose up -d\n"
                . $check
                . "docker exec -u www-data <contenedor> /opt/pymupdf/bin/python -c "
                . "\"import pymupdf; print(pymupdf.__version__)\"";
        }
        return "sudo apt install -y python3-venv\n"
            . "sudo python3 -m venv /opt/pymupdf\n"
            . "sudo /opt/pymupdf/bin/pip install PyMuPDF\n\n"
            . $check
            . "sudo -u www-data /opt/pymupdf/bin/python -c \"import pymupdf; print(pymupdf.__version__)\"";
    }

    /**
     * HTML with the installation steps for the detected environment.
     *
     * @param bool $docker Whether Moodle runs in a container.
     * @return string
     */
    public static function install_help_html(bool $docker): string {
        $component = 'local_ai_yared_course';
        return \html_writer::tag('p', get_string($docker ? 'pdfhelp_docker' : 'pdfhelp_host', $component))
            . \html_writer::tag('pre', s(self::install_commands($docker)))
            . \html_writer::tag('p', get_string('pdfhelp_setting', $component));
    }

    /**
     * Warn the administrator, right after installing or upgrading, when PDFs cannot be converted to images.
     */
    public static function notify_if_missing(): void {
        try {
            $status = self::get_status();
        } catch (\Throwable $e) {
            return;
        }
        if (!$status['pymupdf']) {
            \core\notification::warning(get_string('pdfinstall_notice', 'local_ai_yared_course',
                (new \moodle_url('/admin/settings.php', ['section' => 'local_ai_yared_course']))->out(false))
                . self::install_help_html($status['docker']));
        }
    }

    /**
     * Whether the external renderer can be used.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return self::find_python() !== null && is_file(self::script_path());
    }

    /**
     * Last error produced by render(), as a short code.
     *
     * @return string
     */
    public static function get_last_error(): string {
        return self::$lasterror;
    }

    /**
     * Technical detail of the last error (message of the helper or the start of stderr).
     *
     * @return string
     */
    public static function get_last_detail(): string {
        return self::$lastdetail;
    }

    /**
     * Render every page of a PDF as a JPEG image.
     *
     * @param string $pdf PDF bytes.
     * @param int $maxpages Maximum number of pages to render.
     * @return array[] [['data' => bytes, 'mimetype' => ..., 'width' => ..., 'height' => ..., 'page' => ...], ...]
     */
    public static function render(string $pdf, int $maxpages = self::MAX_PAGES): array {
        $pages = self::render_once($pdf, $maxpages);
        if (!$pages && in_array(self::$lasterror, self::RETRYABLE, true)) {
            // The same PDF can fail once when the server is busy (slow process start, memory pressure).
            debugging('local_ai_yared_course: PDF renderer failed with "' . self::$lasterror . '", retrying once.',
                DEBUG_DEVELOPER);
            sleep(2);
            $pages = self::render_once($pdf, $maxpages);
        }
        return $pages;
    }

    /**
     * One rendering attempt.
     *
     * @param string $pdf PDF bytes.
     * @param int $maxpages Maximum number of pages to render.
     * @return array[] Pages, empty on failure (see get_last_error()).
     */
    protected static function render_once(string $pdf, int $maxpages): array {
        self::$lasterror = '';
        self::$lastdetail = '';
        if ($pdf === '') {
            self::$lasterror = 'empty';
            return [];
        }
        $python = self::find_python();
        if ($python === null) {
            self::$lasterror = 'python';
            return [];
        }
        $script = self::script_path();
        if (!is_file($script)) {
            self::$lasterror = 'script';
            return [];
        }

        $workdir = null;
        try {
            // A unique directory per request, created and cleaned up by Moodle: two teachers can never share it.
            $workdir = make_request_directory();
            if (!is_dir($workdir)) {
                self::$lasterror = 'tempdir';
                return [];
            }
            $pdfpath = $workdir . '/source.pdf';
            $outdir = $workdir . '/pages';
            if (!mkdir($outdir, 0777, true) && !is_dir($outdir)) {
                self::$lasterror = 'tempdir';
                return [];
            }
            if (file_put_contents($pdfpath, $pdf) === false) {
                self::$lasterror = 'tempfile';
                return [];
            }

            $command = array_merge($python, [
                $script,
                $pdfpath,
                $outdir,
                (string) max(1, min(self::MAX_PAGES, $maxpages)),
                (string) self::MAX_SIDE,
            ]);
            [$exitcode, $stdout, $stderr] = self::run_process($command);
            $data = self::parse_output($stdout);
            if (!is_array($data) || empty($data['ok'])) {
                if (is_array($data) && !empty($data['error'])) {
                    // The helper may append a detail after the code ("open_failed: ...").
                    self::$lasterror = trim(explode(':', (string) $data['error'], 2)[0]);
                } else if ($exitcode === -1) {
                    self::$lasterror = 'timeout';
                } else {
                    self::$lasterror = 'render';
                }
                $rawerror = is_array($data) && !empty($data['error']) ? (string) $data['error']
                    : ($stderr !== '' ? $stderr : $stdout);
                $detail = trim(preg_replace('/^' . preg_quote(self::$lasterror, '/') . ':?\s*/', '', trim($rawerror)));
                $interpreters = (self::$lasterror === 'pymupdf_not_installed' && self::$pythontried)
                    ? implode(', ', self::$pythontried) : implode(' ', $python);
                self::$lastdetail = \core_text::substr('env=' . (self::is_docker() ? 'docker' : 'host')
                    . ' | python=' . $interpreters
                    . ($detail !== '' ? ' | ' . $detail : ''), 0, 300);
                debugging('local_ai_yared_course: PDF renderer failed: ' . self::$lasterror
                    . ($stderr !== '' ? ' | ' . \core_text::substr($stderr, 0, 400) : ''), DEBUG_DEVELOPER);
                return [];
            }

            $pages = [];
            foreach (($data['pages'] ?? []) as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $filename = basename((string) ($page['file'] ?? ''));
                if (!preg_match('/^page-[0-9]+\.(?:jpg|jpeg|png)$/i', $filename)) {
                    continue;
                }
                $path = $outdir . '/' . $filename;
                if (!is_file($path)) {
                    continue;
                }
                $content = file_get_contents($path);
                if (!is_string($content) || $content === '') {
                    continue;
                }
                $info = @getimagesizefromstring($content);
                if (!$info || empty($info[0]) || empty($info[1])) {
                    continue;
                }
                $pages[] = [
                    'data' => $content,
                    'mimetype' => image_type_to_mime_type($info[2]),
                    'width' => (int) $info[0],
                    'height' => (int) $info[1],
                    'page' => max(1, (int) ($page['page'] ?? (count($pages) + 1))),
                ];
            }
            if (!$pages) {
                self::$lasterror = 'nopages';
            }
            return $pages;
        } catch (\Throwable $e) {
            self::$lasterror = 'exception';
            debugging('local_ai_yared_course: PDF renderer exception: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        } finally {
            if (is_string($workdir) && is_dir($workdir)) {
                remove_dir($workdir, true);
            }
        }
    }

    /**
     * Decode the JSON object printed by the helper, ignoring any other text around it.
     *
     * Libraries sometimes print warnings to stdout; the JSON is the last line that starts with "{".
     *
     * @param string $stdout Raw stdout.
     * @return array|null
     */
    protected static function parse_output(string $stdout): ?array {
        $data = json_decode(trim($stdout), true);
        if (is_array($data)) {
            return $data;
        }
        $lines = preg_split('/\R/', trim($stdout));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && $line[0] === '{') {
                $data = json_decode($line, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        return null;
    }

    /**
     * Find a working Python executable.
     *
     * @return string[]|null Command parts, or null when Python is not available.
     */
    protected static function find_python(): ?array {
        if (self::$python !== null) {
            return self::$python === false ? null : self::$python;
        }

        $candidates = [];
        $configured = trim((string) get_config('local_ai_yared_course', 'pdfpython'));
        if ($configured !== '') {
            $candidates[] = [$configured];
        }
        $candidates[] = ['python'];
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = ['py'];
        }
        $candidates[] = ['python3'];
        if (DIRECTORY_SEPARATOR !== '\\') {
            // The web server often runs with a short PATH, so also try the usual absolute locations.
            foreach (['/usr/bin/python3', '/usr/local/bin/python3', '/opt/venv/bin/python'] as $path) {
                $candidates[] = [$path];
            }
        }

        // Prefer a Python that can really import PyMuPDF: the machine may have several Pythons and
        // only one of them (or none, for the web server user) has the library.
        $fallback = null;
        $tried = [];
        foreach ($candidates as $candidate) {
            if (!self::command_works($candidate)) {
                continue;
            }
            if (self::has_pymupdf($candidate)) {
                self::$python = $candidate;
                return $candidate;
            }
            $tried[] = implode(' ', $candidate);
            $fallback = $fallback ?? $candidate;
        }
        self::$pythontried = $tried;
        if ($fallback !== null) {
            // Python exists but PyMuPDF does not: keep it so the error says exactly what is missing.
            self::$python = $fallback;
            return $fallback;
        }
        self::$python = false;
        return null;
    }

    /**
     * Run a short probe command and return what it printed (stdout and stderr).
     *
     * @param string[] $command Command parts.
     * @param string[] $args Extra arguments.
     * @return string Output, empty when the command could not run.
     */
    protected static function probe_output(array $command, array $args): string {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(array_merge($command, $args), $descriptors, $pipes);
        if (!is_resource($process)) {
            return '';
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $start = microtime(true);
        while (true) {
            $status = proc_get_status($process);
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            // Starting Python can be slow when an endpoint protection agent scans it or the server is busy.
            if (microtime(true) - $start > 20) {
                @proc_terminate($process);
                break;
            }
            usleep(50000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * Check whether a command can be executed and is a real Python 3.
     *
     * @param string[] $command Command parts.
     * @return bool
     */
    protected static function command_works(array $command): bool {
        // Require a real version line: the Windows Store stub also prints the word "Python".
        return (bool) preg_match('/\bPython\s+3\.\d+/i', self::probe_output($command, ['--version']));
    }

    /**
     * Check whether this Python can import PyMuPDF.
     *
     * @param string[] $command Command parts.
     * @return bool
     */
    protected static function has_pymupdf(array $command): bool {
        $code = "try:\n import pymupdf\nexcept Exception:\n import fitz\nprint('PYMUPDF_OK')";
        return strpos(self::probe_output($command, ['-c', $code]), 'PYMUPDF_OK') !== false;
    }

    /**
     * Run a command and capture its output.
     *
     * @param string[] $command Command parts.
     * @return array [exit code (-1 on timeout), stdout, stderr]
     */
    protected static function run_process(array $command): array {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [-1, '', 'proc_open failed'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedout = false;
        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $start > self::TIMEOUT) {
                $timedout = true;
                @proc_terminate($process);
                break;
            }
            usleep(100000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$timedout ? -1 : 0, $stdout, $stderr];
    }

    /**
     * Absolute path of the bundled Python helper.
     *
     * @return string
     */
    protected static function script_path(): string {
        return dirname(__DIR__, 2) . '/scripts/render_pdf.py';
    }
}
