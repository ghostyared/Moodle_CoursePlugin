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
 * Searches reference pictures in Wikimedia Commons (free licences, with author and licence recorded).
 *
 * The teacher always chooses the picture; the plugin only lists candidates and keeps the credit of the chosen one.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class commons_search {

    /** API endpoint. */
    public const API = 'https://commons.wikimedia.org/w/api.php';

    /** Hosts the pictures are downloaded from. */
    public const IMAGE_HOST = 'upload.wikimedia.org';

    /** Width of the picture that is downloaded, in pixels. */
    public const WIDTH = 800;

    /** @var string Last error ('' if none). */
    protected $error = '';

    /**
     * Whether the teacher-facing feature is enabled by the administrator (default: yes).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $value = get_config('local_ai_yared_course', 'imagesearch');
        return $value === false || $value === null || (string) $value !== '0';
    }

    /**
     * Last error message.
     *
     * @return string
     */
    public function get_error(): string {
        return $this->error;
    }

    /**
     * Whether a URL is a picture of Wikimedia Commons that this feature may download.
     *
     * @param string $url URL.
     * @return bool
     */
    public static function is_commons_image_url(string $url): bool {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === self::IMAGE_HOST
            && empty($parts['user']) && empty($parts['port'])
            && strlen($url) < 600;
    }

    /**
     * Search pictures.
     *
     * @param string $query Search words.
     * @param int $limit Maximum number of candidates.
     * @return array[]|null Candidates (see parse()) or null on a network error (see get_error()).
     */
    public function search(string $query, int $limit = 8): ?array {
        global $CFG;
        $this->error = '';
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if ($query === '') {
            return [];
        }
        require_once($CFG->libdir . '/filelib.php');
        $params = [
            'action' => 'query',
            'generator' => 'search',
            'gsrnamespace' => 6,
            'gsrsearch' => $query . ' filetype:bitmap',
            'gsrlimit' => max(1, min(20, $limit * 2)),
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime|size|extmetadata',
            'iiextmetadatafilter' => 'Artist|LicenseShortName|LicenseUrl|ImageDescription|Restrictions',
            'iiurlwidth' => self::WIDTH,
            'format' => 'json',
            'formatversion' => 2,
        ];
        $curl = new \curl();
        $response = $curl->get(self::API, $params, [
            'CURLOPT_TIMEOUT' => 20,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_USERAGENT' => 'local_ai_yared_course (Moodle plugin; ' . $CFG->wwwroot . ')',
        ]);
        if ($curl->get_errno()) {
            $this->error = (string) $curl->error;
            return null;
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $this->error = 'invalid response';
            return null;
        }
        if (!empty($data['error'])) {
            $this->error = (string) ($data['error']['info'] ?? 'API error');
            return null;
        }
        return self::parse($data, $limit);
    }

    /**
     * Turn an API answer into the list of candidates.
     *
     * @param array $data Decoded answer.
     * @param int $limit Maximum number of candidates.
     * @return array[] Each: title, thumb (shown), url (downloaded), page, author, license, licenseurl, width, height.
     */
    public static function parse(array $data, int $limit = 8): array {
        $pages = $data['query']['pages'] ?? [];
        if (!is_array($pages)) {
            return [];
        }
        $pages = array_values($pages);
        usort($pages, function ($x, $y) {
            return (int) ($x['index'] ?? 0) <=> (int) ($y['index'] ?? 0);
        });
        $out = [];
        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!is_array($info)) {
                continue;
            }
            if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                continue;
            }
            $thumb = (string) ($info['thumburl'] ?? $info['url'] ?? '');
            if (!self::is_commons_image_url($thumb)) {
                continue;
            }
            $width = (int) ($info['thumbwidth'] ?? $info['width'] ?? 0);
            if ($width > 0 && $width < 200) {
                continue;
            }
            $meta = (array) ($info['extmetadata'] ?? []);
            $value = function (string $key) use ($meta): string {
                $raw = is_array($meta[$key] ?? null) ? (string) ($meta[$key]['value'] ?? '') : '';
                $text = html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8');
                return trim((string) preg_replace('/\s+/u', ' ', $text));
            };
            $license = $value('LicenseShortName');
            if ($license === '' || stripos($license, 'fair use') !== false) {
                // Without a clear free licence the picture is not offered.
                continue;
            }
            $title = (string) ($page['title'] ?? '');
            $title = (string) preg_replace('/^File:/i', '', $title);
            $title = trim((string) preg_replace('/\.(jpe?g|png|webp)$/i', '', str_replace('_', ' ', $title)));
            $licenseurl = $value('LicenseUrl');
            if (!preg_match('#^https?://#i', $licenseurl)) {
                $licenseurl = '';
            }
            $pageurl = (string) ($info['descriptionurl'] ?? '');
            if (!preg_match('#^https://commons\.wikimedia\.org/#i', $pageurl)) {
                $pageurl = '';
            }
            $out[] = [
                'title' => mb_substr($title, 0, 150),
                'thumb' => $thumb,
                'url' => $thumb,
                'page' => $pageurl,
                'author' => mb_substr($value('Artist'), 0, 150),
                'license' => mb_substr($license, 0, 60),
                'licenseurl' => $licenseurl,
                'description' => mb_substr($value('ImageDescription'), 0, 200),
                'width' => $width,
                'height' => (int) ($info['thumbheight'] ?? $info['height'] ?? 0),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * One-line credit of a picture, in the form the licences ask for.
     *
     * @param array $candidate Candidate as returned by parse().
     * @return string Plain text: "Author, Licence, via Wikimedia Commons (page)".
     */
    public static function credit(array $candidate): string {
        $parts = [];
        if (($candidate['author'] ?? '') !== '') {
            $parts[] = $candidate['author'];
        }
        if (($candidate['license'] ?? '') !== '') {
            $parts[] = $candidate['license'] . (($candidate['licenseurl'] ?? '') !== '' ? ' (' . $candidate['licenseurl'] . ')' : '');
        }
        $parts[] = 'Wikimedia Commons' . (($candidate['page'] ?? '') !== '' ? ' (' . $candidate['page'] . ')' : '');
        return implode(', ', $parts);
    }
}
