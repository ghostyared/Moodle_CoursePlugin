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
require_once($CFG->libdir . '/filelib.php');

/**
 * Finds a real, embeddable YouTube video with the YouTube Data API.
 *
 * Videos are searched with the official API and never invented by the AI, so the links always exist.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class youtube_finder {

    /** @var string */
    protected $apikey;
    /** @var string */
    protected $error = '';

    /**
     * Constructor.
     *
     * @param string|null $apikey API key (null = read from the plugin settings).
     */
    public function __construct(?string $apikey = null) {
        $this->apikey = trim($apikey ?? (string) get_config('local_ai_yared_course', 'youtubekey'));
    }

    /**
     * Whether an API key is configured.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    /**
     * Last error message ('' if none).
     *
     * @return string
     */
    public function get_error(): string {
        return $this->error;
    }

    /**
     * Search a video.
     *
     * @param string $query Search words.
     * @param string $lang Language code of the content (es, en...).
     * @param int $age Age of the audience (young children get short videos).
     * @return array|null ['id', 'url', 'title', 'channel'] or null if nothing was found.
     */
    public function find(string $query, string $lang, int $age): ?array {
        $this->error = '';
        if (!$this->is_configured()) {
            return null;
        }

        $params = [
            'part' => 'snippet',
            'type' => 'video',
            'maxResults' => 5,
            'q' => $query,
            'safeSearch' => 'strict',
            'videoEmbeddable' => 'true',
            'relevanceLanguage' => $lang,
            'key' => $this->apikey,
        ];
        if ($age <= 8) {
            $params['videoDuration'] = 'short';
        }

        $curl = new \curl();
        $response = $curl->get('https://www.googleapis.com/youtube/v3/search', $params, [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 15,
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
            $this->error = (string) ($data['error']['message'] ?? 'API error');
            return null;
        }

        foreach ($data['items'] ?? [] as $item) {
            $id = $item['id']['videoId'] ?? '';
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                continue;
            }
            if (($item['snippet']['liveBroadcastContent'] ?? 'none') !== 'none') {
                continue;
            }
            return [
                'id' => $id,
                'url' => 'https://www.youtube.com/watch?v=' . $id,
                'title' => html_entity_decode((string) ($item['snippet']['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'channel' => html_entity_decode((string) ($item['snippet']['channelTitle'] ?? ''), ENT_QUOTES, 'UTF-8'),
            ];
        }
        return null;
    }
}
