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
 * Event triggered when the AI generates the content of a topic.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\event;

defined('MOODLE_INTERNAL') || die();

/**
 * A topic was generated with AI.
 */
class topic_generated extends \core\event\base {

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ai_yared_course_topic';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_topic_generated', 'local_ai_yared_course');
    }

    /**
     * Description for the logs.
     *
     * @return string
     */
    public function get_description() {
        $other = (array) $this->other;
        return "The user with id '{$this->userid}' generated the topic with id '{$this->objectid}' with AI "
            . "(provider '" . ($other['provider'] ?? '') . "', model '" . ($other['model'] ?? '')
            . "', questions " . (int) ($other['questions'] ?? 0) . ") in the course with id '{$this->courseid}'.";
    }
}
