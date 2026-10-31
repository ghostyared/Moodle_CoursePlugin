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
 * Temporary storage for a PDF/image import that is waiting for the teacher's review.
 *
 * The pages are analysed and the crops are stored as files in the user's context, together with
 * a JSON manifest. The teacher then reviews the exercises on a second page and the questions are
 * saved in the bank. The area is deleted after saving or discarding the batch.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_store {

    /** Component that owns the files. */
    public const COMPONENT = 'local_ai_yared_course';

    /** File area used for the pending imports. */
    public const FILEAREA = 'import';

    /** Manifest filename. */
    public const MANIFEST = 'manifest.json';

    /** Seconds after which an abandoned import is deleted. */
    public const LIFETIME = 86400;

    /**
     * Create a new import area and store its manifest.
     *
     * @param int $userid Owner.
     * @param array $manifest Manifest data.
     * @return int Draft id.
     * @throws \moodle_exception When the area cannot be created.
     */
    public static function create(int $userid, array $manifest): int {
        global $DB;

        $context = \context_user::instance($userid);
        $fs = get_file_storage();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $draftid = random_int(100000000, 2000000000);
            if ($DB->record_exists('files', [
                'contextid' => $context->id,
                'component' => self::COMPONENT,
                'filearea' => self::FILEAREA,
                'itemid' => $draftid,
            ])) {
                continue;
            }
            self::save_manifest($draftid, $userid, $manifest);
            return $draftid;
        }
        throw new \moodle_exception('import_error_draftsave', 'local_ai_yared_course');
    }

    /**
     * Store (or replace) the manifest of an import.
     *
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @param array $manifest Manifest data.
     */
    public static function save_manifest(int $draftid, int $userid, array $manifest): void {
        $context = \context_user::instance($userid);
        $fs = get_file_storage();
        $old = $fs->get_file($context->id, self::COMPONENT, self::FILEAREA, $draftid, '/', self::MANIFEST);
        if ($old) {
            $old->delete();
        }
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => self::MANIFEST,
            'mimetype' => 'application/json',
            'userid' => $userid,
        ], json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Store one file (normally a crop) in the import area.
     *
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @param string $filename Safe filename.
     * @param string $data File bytes.
     * @param string $mimetype MIME type.
     * @return string Stored filename.
     */
    public static function add_file(int $draftid, int $userid, string $filename, string $data,
            string $mimetype): string {
        $context = \context_user::instance($userid);
        $filename = self::safe_filename($filename);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => $mimetype,
            'userid' => $userid,
        ], $data);
        return $filename;
    }

    /**
     * Read the manifest of an import.
     *
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @param int $courseid Course the import belongs to.
     * @return array Manifest data.
     * @throws \moodle_exception When the import does not exist, expired or belongs to someone else.
     */
    public static function load(int $draftid, int $userid, int $courseid): array {
        $context = \context_user::instance($userid);
        $file = get_file_storage()->get_file($context->id, self::COMPONENT, self::FILEAREA, $draftid,
            '/', self::MANIFEST);
        if (!$file) {
            throw new \moodle_exception('import_error_draftmissing', 'local_ai_yared_course');
        }
        $manifest = json_decode($file->get_content(), true);
        if (!is_array($manifest)) {
            throw new \moodle_exception('import_error_draftmissing', 'local_ai_yared_course');
        }
        if ((int) ($manifest['userid'] ?? 0) !== $userid || (int) ($manifest['courseid'] ?? 0) !== $courseid) {
            throw new \moodle_exception('import_error_draftmissing', 'local_ai_yared_course');
        }
        if ((int) ($manifest['timecreated'] ?? 0) < time() - self::LIFETIME) {
            self::delete($draftid, $userid);
            throw new \moodle_exception('import_error_draftexpired', 'local_ai_yared_course');
        }
        return $manifest;
    }

    /**
     * Read one file of the import area.
     *
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @param string $filename Filename.
     * @return string|null File bytes or null when it does not exist.
     */
    public static function read_file(int $draftid, int $userid, string $filename): ?string {
        $context = \context_user::instance($userid);
        $filename = self::safe_filename($filename);
        $file = get_file_storage()->get_file($context->id, self::COMPONENT, self::FILEAREA, $draftid, '/', $filename);
        return $file ? $file->get_content() : null;
    }

    /**
     * Public URL of one file of the import area.
     *
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     * @param string $filename Filename.
     * @return \moodle_url
     */
    public static function file_url(int $draftid, int $userid, string $filename): \moodle_url {
        $context = \context_user::instance($userid);
        return \moodle_url::make_pluginfile_url($context->id, self::COMPONENT, self::FILEAREA, $draftid,
            '/', self::safe_filename($filename));
    }

    /**
     * Delete every file of an import.
     *
     * @param int $draftid Draft id.
     * @param int $userid Owner.
     */
    public static function delete(int $draftid, int $userid): void {
        $context = \context_user::instance($userid);
        get_file_storage()->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $draftid);
    }

    /**
     * Delete abandoned imports of a user.
     *
     * @param int $userid Owner.
     */
    public static function cleanup_old(int $userid): void {
        $context = \context_user::instance($userid);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA, false, 'itemid, filename', false);
        $items = [];
        foreach ($files as $file) {
            if ($file->get_filename() === self::MANIFEST) {
                $items[(int) $file->get_itemid()] = (int) $file->get_timemodified();
            }
        }
        $limit = time() - self::LIFETIME;
        foreach ($items as $itemid => $timemodified) {
            if ($timemodified < $limit) {
                $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $itemid);
            }
        }
    }

    /**
     * Delete the abandoned imports of every user (used by the scheduled task).
     *
     * @return int Number of imports deleted.
     */
    public static function cleanup_all_old(): int {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT f.id, f.contextid, f.itemid
               FROM {files} f
              WHERE f.component = :component AND f.filearea = :filearea
                    AND f.filename = :manifest AND f.timemodified < :limit",
            [
                'component' => self::COMPONENT,
                'filearea' => self::FILEAREA,
                'manifest' => self::MANIFEST,
                'limit' => time() - self::LIFETIME,
            ]
        );
        $fs = get_file_storage();
        $deleted = 0;
        foreach ($rows as $row) {
            $fs->delete_area_files((int) $row->contextid, self::COMPONENT, self::FILEAREA, (int) $row->itemid);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * Keep only a plain filename.
     *
     * @param string $filename Filename.
     * @return string
     */
    protected static function safe_filename(string $filename): string {
        $filename = clean_filename(basename($filename));
        return $filename === '' ? 'file' : $filename;
    }
}
