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
 * Builds the AI client from the settings of this plugin.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_factory {

    /**
     * Create the AI client.
     *
     * @return ai_client
     */
    public static function create(): ai_client {
        return ai_client::from_config();
    }

    /**
     * Create a client for one configured provider slot (used by the test connection page).
     *
     * @param int $profile 1 = main, 2..6 = backups 1..5.
     * @return ai_client
     */
    public static function create_profile(int $profile): ai_client {
        return ai_client::from_config_profile($profile);
    }
}
