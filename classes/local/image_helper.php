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
 * Downloads and validates images that the AI (or the teacher) attaches to questions.
 *
 * Moodle's HTML cleaner removes data: URIs from question text, so images are stored as real
 * question files instead of being embedded as base64. This helper also blocks local and private
 * network addresses to avoid server-side request forgery.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class image_helper {

    /** Maximum image size in bytes. */
    public const MAX_BYTES = 2097152;

    /** Maximum image width or height in pixels. */
    public const MAX_DIMENSION = 10000;

    /** Maximum number of pixels in an image. */
    public const MAX_PIXELS = 40000000;

    /** Download timeout in seconds. */
    public const TIMEOUT = 8;

    /** User agent sent to image servers. */
    public const USER_AGENT = 'Moodle-local_ai_yared_course/0.9';

    /**
     * Check whether a URL can be shown in the preview (no DNS lookup is made here).
     *
     * @param string $url URL or data:image URI.
     * @return bool
     */
    public static function is_displayable_url(string $url): bool {
        $url = trim($url);
        if ($url === '' || strlen($url) > 3000000) {
            return false;
        }
        if (stripos($url, 'data:') === 0) {
            return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $url);
        }
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (substr($parts['host'], -1) === '.') {
            return false;
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /**
     * Check whether a remote URL is public and safe to download.
     *
     * @param string $url URL.
     * @return bool
     */
    public static function is_safe_url(string $url): bool {
        $url = trim($url);
        if (!self::is_displayable_url($url)) {
            return false;
        }
        if (stripos($url, 'data:') === 0) {
            return true;
        }

        $parts = parse_url($url);
        $host = self::normalise_host($parts['host'] ?? '');
        if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_public_ip($host);
        }
        return (bool) self::resolve_public_ips($host);
    }

    /**
     * Download and validate an image from an HTTP(S) URL or a base64 data: URI.
     *
     * @param string $url URL or data:image URI.
     * @return array Image data or an error message.
     */
    public static function fetch(string $url): array {
        $url = trim($url);
        if (!self::is_displayable_url($url)) {
            return ['error' => get_string('image_error_url', 'local_ai_yared_course')];
        }
        if (stripos($url, 'data:') === 0) {
            return self::fetch_data_uri($url);
        }
        if (!self::is_safe_url($url)) {
            return ['error' => get_string('image_error_url', 'local_ai_yared_course')];
        }

        $parts = parse_url($url);
        $host = self::normalise_host($parts['host'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port']
            : (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        $publicips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve_public_ips($host);
        if (!$publicips) {
            return ['error' => get_string('image_error_url', 'local_ai_yared_course')];
        }

        if (function_exists('curl_init')) {
            return self::fetch_curl($url, $host, $port, $publicips);
        }
        return self::fetch_stream($url, $host, $port, $publicips);
    }

    /**
     * Validate image bytes supplied directly (for example, a file uploaded by the teacher).
     *
     * @param string $data Image bytes.
     * @param string $source Original filename or identifier, used only for messages.
     * @return array Image data or an error message.
     */
    public static function validate_bytes(string $data, string $source = ''): array {
        return self::validate_image($data, $source);
    }

    /**
     * Check whether a Moodle draft area contains at least one uploaded file.
     *
     * Filepicker always returns a draft item id, even when the teacher did not choose a file, so
     * this check is needed before treating the image as present.
     *
     * @param int $itemid Draft item id.
     * @return bool
     */
    public static function draft_has_file(int $itemid): bool {
        global $USER;

        if ($itemid <= 0 || empty($USER->id)) {
            return false;
        }
        try {
            $context = \context_user::instance($USER->id);
            $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $itemid,
                'sortorder, filename', false);
            foreach ($files as $file) {
                if (!$file->is_directory()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            debugging('local_ai_yared_course: could not inspect the uploaded image: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
        return false;
    }

    /**
     * Read and validate the first image in a Moodle draft area.
     *
     * The teacher uploads the file with a filepicker; this method turns it into a data URI so the
     * existing question saver can store it as a question file without sending it to the AI.
     *
     * @param int $itemid Draft item id returned by the form.
     * @return array Image data (including datauri) or an error message.
     */
    public static function read_draft_file(int $itemid): array {
        global $USER;

        if ($itemid <= 0 || empty($USER->id)) {
            return ['error' => get_string('image_error_nofile', 'local_ai_yared_course')];
        }

        try {
            $context = \context_user::instance($USER->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'user', 'draft', $itemid,
                'sortorder, filename', false);
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                if ((int) $file->get_filesize() > self::MAX_BYTES) {
                    return ['error' => get_string('image_error_size', 'local_ai_yared_course')];
                }
                $result = self::validate_image($file->get_content(), $file->get_filename());
                if (isset($result['error'])) {
                    return $result;
                }
                $result['datauri'] = 'data:' . $result['mimetype'] . ';base64,'
                    . base64_encode($result['data']);
                unset($result['data']);
                return $result;
            }
        } catch (\Throwable $e) {
            debugging('local_ai_yared_course: could not read the uploaded image: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }

        return ['error' => get_string('image_error_nofile', 'local_ai_yared_course')];
    }

    /**
     * Download using cURL.
     *
     * @param string $url URL.
     * @param string $host Host name or IP.
     * @param int $port Port.
     * @param string[] $publicips Validated public addresses.
     * @return array
     */
    protected static function fetch_curl(string $url, string $host, int $port, array $publicips): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => get_string('image_error_download', 'local_ai_yared_course')];
        }

        $data = '';
        $toobig = false;
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: image/png,image/jpeg,image/webp,image/gif'],
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$data, &$toobig): int {
                if (strlen($data) + strlen($chunk) > self::MAX_BYTES) {
                    $toobig = true;
                    return 0;
                }
                $data .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        // Pin the validated address so a second DNS answer cannot point to a private host.
        if (!filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
            $pinned = '';
            foreach ($publicips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $pinned = $ip;
                    break;
                }
            }
            if ($pinned === '') {
                foreach ($publicips as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $pinned = '[' . $ip . ']';
                        break;
                    }
                }
            }
            if ($pinned !== '') {
                $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $pinned];
            }
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($toobig || (defined('CURLE_FILESIZE_EXCEEDED') && $errno === CURLE_FILESIZE_EXCEEDED)) {
            return ['error' => get_string('image_error_size', 'local_ai_yared_course')];
        }
        if ($result === false || $errno !== 0) {
            return ['error' => get_string('image_error_download', 'local_ai_yared_course')];
        }
        if ($status >= 300 && $status < 400) {
            return ['error' => get_string('image_error_redirect', 'local_ai_yared_course')];
        }
        if ($status !== 200) {
            return ['error' => get_string('image_error_status', 'local_ai_yared_course')];
        }
        if ($data === '') {
            return ['error' => get_string('image_error_download', 'local_ai_yared_course')];
        }
        return self::validate_image($data, $url);
    }

    /**
     * Download using PHP streams when cURL is not available.
     *
     * The stream is opened against the already validated IP address, with the original
     * host in the Host header and in the TLS certificate checks. This keeps the SSRF
     * protection even if the DNS answer changes between validation and download.
     *
     * @param string $url URL.
     * @param string $host Original host.
     * @param int $port Original port.
     * @param string[] $publicips Validated public addresses.
     * @return array
     */
    protected static function fetch_stream(string $url, string $host, int $port, array $publicips): array {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return ['error' => get_string('image_error_download', 'local_ai_yared_course')];
        }

        $ip = '';
        foreach ($publicips as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $candidate;
                break;
            }
        }
        if ($ip === '') {
            foreach ($publicips as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ip = $candidate;
                    break;
                }
            }
        }
        if ($ip === '') {
            return ['error' => get_string('image_error_url', 'local_ai_yared_course')];
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $defaultport = $scheme === 'https' ? 443 : 80;
        $ipurl = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;
        $target = $scheme . '://' . $ipurl . ($port === $defaultport ? '' : ':' . $port) . ($path === '' ? '/' : $path);

        $hostheader = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
        if ($port !== $defaultport) {
            $hostheader .= ':' . $port;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'user_agent' => self::USER_AGENT,
                'header' => "Host: {$hostheader}\r\nAccept: image/png,image/jpeg,image/webp,image/gif\r\n"
                    . "Connection: close\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ],
        ]);
        $data = @file_get_contents($target, false, $context, 0, self::MAX_BYTES + 1);
        $status = 0;
        if (!empty($http_response_header[0])
                && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }
        if ($status >= 300 && $status < 400) {
            return ['error' => get_string('image_error_redirect', 'local_ai_yared_course')];
        }
        if ($status !== 200) {
            return ['error' => get_string('image_error_status', 'local_ai_yared_course')];
        }
        if (!is_string($data)) {
            return ['error' => get_string('image_error_download', 'local_ai_yared_course')];
        }
        if (strlen($data) > self::MAX_BYTES) {
            return ['error' => get_string('image_error_size', 'local_ai_yared_course')];
        }
        return self::validate_image($data, $url);
    }

    /**
     * Decode a base64 image data: URI.
     *
     * @param string $url Data URI.
     * @return array
     */
    protected static function fetch_data_uri(string $url): array {
        if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $url, $matches)) {
            return ['error' => get_string('image_error_type', 'local_ai_yared_course')];
        }
        $encoded = preg_replace('/\s+/', '', $matches[2]);
        $data = base64_decode($encoded, true);
        if ($data === false || $data === '') {
            return ['error' => get_string('image_error_type', 'local_ai_yared_course')];
        }
        return self::validate_image($data, $url);
    }

    /**
     * Validate the image bytes and build a safe filename.
     *
     * @param string $data Image bytes.
     * @param string $url Original URL.
     * @return array
     */
    protected static function validate_image(string $data, string $url): array {
        if (strlen($data) > self::MAX_BYTES) {
            return ['error' => get_string('image_error_size', 'local_ai_yared_course')];
        }
        $info = @getimagesizefromstring($data);
        if (!$info || empty($info[2])) {
            return ['error' => get_string('image_error_type', 'local_ai_yared_course')];
        }
        $types = [
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
        ];
        if (defined('IMAGETYPE_WEBP')) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }
        if (!isset($types[$info[2]])) {
            return ['error' => get_string('image_error_type', 'local_ai_yared_course')];
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
                || ($width * $height) > self::MAX_PIXELS) {
            return ['error' => get_string('image_error_dimensions', 'local_ai_yared_course')];
        }
        $extension = $types[$info[2]];
        return [
            'data' => $data,
            'filename' => 'aiyared-' . substr(sha1($data), 0, 20) . '.' . $extension,
            'mimetype' => image_type_to_mime_type($info[2]),
            'url' => $url,
        ];
    }

    /**
     * Resolve a host to its public addresses. Returns an empty array if any answer is private.
     *
     * @param string $host Host name.
     * @return string[]
     */
    protected static function resolve_public_ips(string $host): array {
        $ips = [];
        if (function_exists('gethostbynamel')) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $ips = $ipv4;
            }
        }
        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $ipv6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($ipv6)) {
                foreach ($ipv6 as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if (!$ips) {
            return [];
        }
        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return [];
            }
        }
        return $ips;
    }

    /**
     * Check that an IP is a public unicast address.
     *
     * @param string $ip IPv4 or IPv6 address.
     * @return bool
     */
    protected static function is_public_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return false;
            }
            // Ranges that filter_var does not always classify as reserved/private.
            $blocked = [
                ['100.64.0.0', '100.127.255.255'], // Carrier-grade NAT.
                ['192.0.0.0', '192.0.0.255'],
                ['192.0.2.0', '192.0.2.255'], // Documentation TEST-NET-1.
                ['192.88.99.0', '192.88.99.255'], // Deprecated 6to4 relay anycast.
                ['198.18.0.0', '198.19.255.255'],
                ['198.51.100.0', '198.51.100.255'], // Documentation TEST-NET-2.
                ['203.0.113.0', '203.0.113.255'], // Documentation TEST-NET-3.
            ];
            foreach ($blocked as $range) {
                if ($long >= ip2long($range[0]) && $long <= ip2long($range[1])) {
                    return false;
                }
            }
            return true;
        }
        // IPv4-mapped/compatible IPv6 addresses (for example ::ffff:127.0.0.1).
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            if (substr($packed, 0, 10) === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff") {
                $mapped = @inet_ntop(substr($packed, 12, 4));
                return $mapped !== false && self::is_public_ip($mapped);
            }
            if (substr($packed, 0, 12) === str_repeat("\0", 12)) {
                return false;
            }
        }
        // Translation/tunnelling ranges that can embed an IPv4 address.
        if (strpos($ip, '::') === 0 || stripos($ip, '2002:') === 0 || stripos($ip, '64:ff9b:') === 0
                || stripos($ip, '2001:0') === 0) {
            return false;
        }
        // Documentation IPv6 range.
        return stripos($ip, '2001:db8:') !== 0;
    }

    /**
     * Remove brackets from an IPv6 host and normalise the case.
     *
     * @param string $host Host.
     * @return string
     */
    protected static function normalise_host(string $host): string {
        return rtrim(strtolower(trim(trim($host), '[]')), '.');
    }
}
