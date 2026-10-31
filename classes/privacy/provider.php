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
 * Privacy provider for local_ai_yared_course.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_yared_course\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_ai_yared_course\local\import_store;

defined('MOODLE_INTERNAL') || die();

/**
 * The plugin sends teacher content to external services and keeps pending PDF/image imports in the
 * user's own file area until they are saved, discarded or expire.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the data the plugin handles.
     *
     * @param collection $collection Collection to fill.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('aiprovider', [
            'topic' => 'privacy:metadata:aiprovider:topic',
            'questions' => 'privacy:metadata:aiprovider:questions',
            'files' => 'privacy:metadata:aiprovider:files',
        ], 'privacy:metadata:aiprovider');

        $collection->add_external_location_link('youtube', [
            'query' => 'privacy:metadata:youtube:query',
        ], 'privacy:metadata:youtube');

        // Wikimedia Commons: only the search words typed by the teacher (or deduced by the AI from the question) are sent;
        // no personal data. The chosen picture is downloaded from upload.wikimedia.org.
        $collection->add_external_location_link('wikimediacommons', [
            'query' => 'privacy:metadata:wikimediacommons:query',
        ], 'privacy:metadata:wikimediacommons');

        // CRM webhook configured by the administrator: build status notifications; no personal data of users is sent.
        $collection->add_external_location_link('crmwebhook', [
            'status' => 'privacy:metadata:crmwebhook:status',
            'courseurl' => 'privacy:metadata:crmwebhook:courseurl',
        ], 'privacy:metadata:crmwebhook');

        $collection->add_database_table('local_ai_yared_course_job', [
            'userid' => 'privacy:metadata:job:userid',
            'courseid' => 'privacy:metadata:job:courseid',
            'options' => 'privacy:metadata:job:options',
            'results' => 'privacy:metadata:job:results',
            'timecreated' => 'privacy:metadata:job:timecreated',
        ], 'privacy:metadata:job');

        $collection->add_database_table('local_ai_yared_course_build', [
            'teacherid' => 'privacy:metadata:build:teacherid',
            'courseid' => 'privacy:metadata:build:courseid',
            'payload' => 'privacy:metadata:build:payload',
            'timecreated' => 'privacy:metadata:build:timecreated',
        ], 'privacy:metadata:build');

        $collection->add_database_table('local_ai_yared_course_log', [
            'userid' => 'privacy:metadata:log:userid',
            'username' => 'privacy:metadata:log:username',
            'coursecode' => 'privacy:metadata:log:coursecode',
            'title' => 'privacy:metadata:log:title',
            'age' => 'privacy:metadata:log:age',
            'provider' => 'privacy:metadata:log:provider',
            'errormsg' => 'privacy:metadata:log:errormsg',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');

        return $collection;
    }

    /**
     * Contexts where the user has pending imports.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT DISTINCT ctx.id
               FROM {files} f
               JOIN {context} ctx ON ctx.id = f.contextid
              WHERE f.component = :component AND f.filearea = :filearea
                    AND ctx.contextlevel = :level AND ctx.instanceid = :userid",
            [
                'component' => import_store::COMPONENT,
                'filearea' => import_store::FILEAREA,
                'level' => CONTEXT_USER,
                'userid' => $userid,
            ]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_ai_yared_course_job} j
               JOIN {context} ctx ON ctx.instanceid = j.userid AND ctx.contextlevel = :level
              WHERE j.userid = :userid",
            ['level' => CONTEXT_USER, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_ai_yared_course_log} l
               JOIN {context} ctx ON ctx.instanceid = l.userid AND ctx.contextlevel = :level
              WHERE l.userid = :userid",
            ['level' => CONTEXT_USER, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with pending imports in a context.
     *
     * @param userlist $userlist User list to fill.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        if ($DB->record_exists('local_ai_yared_course_job', ['userid' => $context->instanceid])
                || $DB->record_exists('local_ai_yared_course_log', ['userid' => $context->instanceid])) {
            $userlist->add_user($context->instanceid);
        }
        if ($DB->record_exists('files', [
                'contextid' => $context->id,
                'component' => import_store::COMPONENT,
                'filearea' => import_store::FILEAREA,
            ])) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export the pending imports of a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== (int) $userid) {
                continue;
            }
            $itemids = $DB->get_fieldset_sql(
                "SELECT DISTINCT itemid FROM {files}
                  WHERE contextid = :contextid AND component = :component AND filearea = :filearea",
                [
                    'contextid' => $context->id,
                    'component' => import_store::COMPONENT,
                    'filearea' => import_store::FILEAREA,
                ]
            );
            foreach ($itemids as $itemid) {
                // One subfolder per import so the manifest files do not overwrite each other.
                writer::with_context($context)->export_area_files(
                    [get_string('privacy:importpath', 'local_ai_yared_course'), (string) $itemid],
                    import_store::COMPONENT, import_store::FILEAREA, (int) $itemid);
            }
        }
    }

    /**
     * Delete the pending imports of everyone in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_user) {
            get_file_storage()->delete_area_files($context->id, import_store::COMPONENT, import_store::FILEAREA);
            $DB->delete_records('local_ai_yared_course_job', ['userid' => $context->instanceid]);
            $DB->delete_records('local_ai_yared_course_log', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete the pending imports of one user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === (int) $userid) {
                $DB->delete_records('local_ai_yared_course_job', ['userid' => $userid]);
                $DB->delete_records('local_ai_yared_course_log', ['userid' => $userid]);
                get_file_storage()->delete_area_files($context->id, import_store::COMPONENT,
                    import_store::FILEAREA);
            }
        }
    }

    /**
     * Delete the pending imports of several users in one context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array((int) $context->instanceid, $userlist->get_userids())) {
            $DB->delete_records('local_ai_yared_course_job', ['userid' => $context->instanceid]);
            $DB->delete_records('local_ai_yared_course_log', ['userid' => $context->instanceid]);
        }
        if ($context instanceof \context_user && in_array((int) $context->instanceid, $userlist->get_userids())) {
            get_file_storage()->delete_area_files($context->id, import_store::COMPONENT, import_store::FILEAREA);
        }
    }
}
