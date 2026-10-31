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
 * Reads and prepares the images and PDFs uploaded by the teacher.
 *
 * Photos taken with a phone are rotated, resized and re-encoded before they are sent to the
 * vision model: smaller images cost fewer tokens and are easier for the model to read.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_reader {

    /** Maximum number of source files per batch (one image per question works too). */
    public const MAX_FILES = 10;

    /** Maximum size of one image in bytes. */
    public const MAX_IMAGE_BYTES = 10485760;

    /** Maximum size of one PDF in bytes (Gemini inline limit is 20 MB). */
    public const MAX_PDF_BYTES = 12582912;

    /** Maximum total size of the batch in bytes. */
    public const MAX_TOTAL_BYTES = 41943040;

    /** Maximum number of PDF pages accepted per run. */
    public const MAX_PDF_PAGES = 15;

    /** Longest side of the working image sent to the AI. */
    public const WORKING_SIDE = 2200;

    /** Maximum accepted image dimension, in pixels. */
    public const MAX_DIMENSION = 10000;

    /** Maximum accepted number of pixels. */
    public const MAX_PIXELS = 40000000;

    /**
     * Read every file from a Moodle draft area.
     *
     * @param int $itemid Draft item id returned by the form.
     * @return array[] [['filename', 'type' => 'image'|'pdf', 'mimetype', 'content', 'size'], ...]
     * @throws \moodle_exception When there are no files, too many, or one is invalid.
     */
    public static function read_draft_files(int $itemid): array {
        global $USER;

        if ($itemid <= 0 || empty($USER->id)) {
            throw new \moodle_exception('import_error_nofile', 'local_ai_yared_course');
        }

        $context = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $itemid,
            'sortorder, filename', false);

        $sources = [];
        $total = 0;
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $filename = $file->get_filename();
            $size = (int) $file->get_filesize();
            $content = $file->get_content();
            $total += $size;

            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \moodle_exception('import_error_totalsize', 'local_ai_yared_course');
            }

            $source = self::classify($filename, $size, $content);
            $sources[] = $source;
        }

        if (!$sources) {
            throw new \moodle_exception('import_error_nofile', 'local_ai_yared_course');
        }
        if (count($sources) > self::MAX_FILES) {
            throw new \moodle_exception('import_error_toomanyfiles', 'local_ai_yared_course', self::MAX_FILES);
        }

        return $sources;
    }

    /**
     * Decide whether a file is a supported image or PDF and validate its basic properties.
     *
     * @param string $filename File name.
     * @param int $size File size.
     * @param string $content File bytes.
     * @return array Source description.
     * @throws \moodle_exception When the file is not supported.
     */
    protected static function classify(string $filename, int $size, string $content): array {
        $error = (object) ['file' => s($filename), 'error' => ''];

        if (substr($content, 0, 5) === '%PDF-') {
            if ($size > self::MAX_PDF_BYTES) {
                $error->error = get_string('import_error_pdfsize', 'local_ai_yared_course',
                    round(self::MAX_PDF_BYTES / 1048576, 1));
                throw new \moodle_exception('import_error_file', 'local_ai_yared_course', $error);
            }
            $pages = self::pdf_page_count($content);
            if ($pages > self::MAX_PDF_PAGES) {
                throw new \moodle_exception('import_error_pdfpages', 'local_ai_yared_course', (object) [
                    'file' => s($filename),
                    'pages' => $pages,
                    'max' => self::MAX_PDF_PAGES,
                ]);
            }
            return [
                'filename' => $filename,
                'type' => 'pdf',
                'mimetype' => 'application/pdf',
                'content' => $content,
                'size' => $size,
                'pages' => $pages,
            ];
        }

        if ($size > self::MAX_IMAGE_BYTES) {
            $error->error = get_string('import_error_imagesize', 'local_ai_yared_course',
                round(self::MAX_IMAGE_BYTES / 1048576, 1));
            throw new \moodle_exception('import_error_file', 'local_ai_yared_course', $error);
        }

        $info = @getimagesizefromstring($content);
        $allowed = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF];
        if (defined('IMAGETYPE_WEBP')) {
            $allowed[] = IMAGETYPE_WEBP;
        }
        if (!$info || empty($info[2]) || !in_array($info[2], $allowed, true)) {
            $error->error = get_string('import_error_imagetype', 'local_ai_yared_course');
            throw new \moodle_exception('import_error_file', 'local_ai_yared_course', $error);
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
                || ($width * $height) > self::MAX_PIXELS) {
            $error->error = get_string('import_error_imagedimensions', 'local_ai_yared_course');
            throw new \moodle_exception('import_error_file', 'local_ai_yared_course', $error);
        }

        return [
            'filename' => $filename,
            'type' => 'image',
            'mimetype' => image_type_to_mime_type($info[2]),
            'content' => $content,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Rotate, flatten and resize an image for the vision model.
     *
     * @param string $data Original image bytes.
     * @return array ['data', 'mimetype', 'width', 'height']
     * @throws \moodle_exception When the image cannot be processed.
     */
    public static function prepare_image(string $data): array {
        raise_memory_limit(MEMORY_EXTRA);
        $image = @imagecreatefromstring($data);
        if (!$image) {
            throw new \moodle_exception('import_error_imageprocess', 'local_ai_yared_course');
        }

        $image = self::apply_orientation($image, $data);

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest > self::WORKING_SIDE) {
            $ratio = self::WORKING_SIDE / $longest;
            $newwidth = max(1, (int) round($width * $ratio));
            $newheight = max(1, (int) round($height * $ratio));
            $resized = imagecreatetruecolor($newwidth, $newheight);
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefilledrectangle($resized, 0, 0, $newwidth, $newheight, $white);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newwidth;
            $height = $newheight;
        }

        // Flatten transparency over a white background: black backgrounds make text unreadable.
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        ob_start();
        imagejpeg($canvas, null, 90);
        $output = ob_get_clean();
        imagedestroy($canvas);

        if (!is_string($output) || $output === '') {
            throw new \moodle_exception('import_error_imageprocess', 'local_ai_yared_course');
        }

        return [
            'data' => $output,
            'mimetype' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Apply the EXIF orientation of a phone photo.
     *
     * @param \GdImage $image Image.
     * @param string $data Original bytes (needed to read EXIF metadata).
     * @return \GdImage Rotated image.
     */
    protected static function apply_orientation($image, string $data) {
        global $CFG;

        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $temp = tempnam($CFG->tempdir, 'aiyared');
        if ($temp === false) {
            return $image;
        }
        if (file_put_contents($temp, $data) === false) {
            @unlink($temp);
            return $image;
        }
        $exif = @exif_read_data($temp);
        @unlink($temp);

        $orientation = (int) ($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle === 0) {
            return $image;
        }
        $rotated = @imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);
        return $rotated;
    }

    /**
     * Estimate the number of pages in a PDF.
     *
     * The estimate only needs to catch documents that are too long. Compressed page trees may
     * hide the page objects; in that case the page count is unknown and the document is allowed.
     *
     * @param string $data PDF bytes.
     * @return int Number of pages (0 when it cannot be determined).
     */
    public static function pdf_page_count(string $data): int {
        $count = preg_match_all('~/Type\s*/Page[^s]~', $data);
        if ($count > 0) {
            return (int) $count;
        }
        if (preg_match('~/Count\s+(\d+)~', $data, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}
