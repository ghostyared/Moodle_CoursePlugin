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
 * Crops the region of an exercise or figure that the AI located in a page image.
 *
 * The model returns normalised coordinates (0 = left/top, 1 = right/bottom). Coordinates are
 * validated before cropping, and the resulting image is resized and re-encoded so it stays
 * inside the limits accepted by the question bank.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crop_helper {

    /** Maximum size of a cropped image in bytes (same limit as question images). */
    public const MAX_BYTES = 2097152;

    /** Maximum width or height of a crop. */
    public const MAX_DIMENSION = 1600;

    /** Smallest accepted box side, as a fraction of the page. */
    public const MIN_SIDE = 0.02;

    /**
     * Validate and normalise a bounding box returned by the AI.
     *
     * Accepted shapes: [x0, y0, x1, y1], {"x0":..,"y0":..,"x1":..,"y1":..},
     * {"left":..,"top":..,"right":..,"bottom":..} and {"x":..,"y":..,"width":..,"height":..}.
     * All values must be fractions between 0 and 1.
     *
     * @param mixed $box Raw box.
     * @return float[]|null [x0, y0, x1, y1] or null when it is missing or unreliable.
     */
    public static function normalise_box($box): ?array {
        if (is_string($box)) {
            $parts = preg_split('/[\s,;]+/', trim($box));
            $box = array_filter($parts, 'strlen');
        }
        if (!is_array($box) || !$box) {
            return null;
        }

        $values = null;
        if (array_is_list($box) && count($box) >= 4) {
            $values = array_values($box);
        } else if (isset($box['x0'], $box['y0'], $box['x1'], $box['y1'])) {
            $values = [$box['x0'], $box['y0'], $box['x1'], $box['y1']];
        } else if (isset($box['left'], $box['top'], $box['right'], $box['bottom'])) {
            $values = [$box['left'], $box['top'], $box['right'], $box['bottom']];
        } else if (isset($box['x'], $box['y']) && (isset($box['width']) || isset($box['w']))) {
            $width = $box['width'] ?? $box['w'];
            $height = $box['height'] ?? $box['h'] ?? $width;
            $values = [$box['x'], $box['y'], (float) $box['x'] + (float) $width, (float) $box['y'] + (float) $height];
        }
        if ($values === null || count($values) < 4) {
            return null;
        }

        $numbers = [];
        foreach (array_slice($values, 0, 4) as $value) {
            if (is_bool($value) || !is_numeric($value)) {
                return null;
            }
            $number = (float) $value;
            // Some models answer with per-mille or percentage values. Reject anything that is
            // clearly not a fraction instead of cropping an arbitrary region.
            if ($number < -0.01 || $number > 1.01) {
                return null;
            }
            $numbers[] = max(0.0, min(1.0, $number));
        }

        [$x0, $y0, $x1, $y1] = $numbers;
        if ($x1 < $x0) {
            [$x0, $x1] = [$x1, $x0];
        }
        if ($y1 < $y0) {
            [$y0, $y1] = [$y1, $y0];
        }
        if (($x1 - $x0) < self::MIN_SIDE || ($y1 - $y0) < self::MIN_SIDE) {
            return null;
        }
        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Crop a region of an image and return a PNG or JPEG ready to be stored.
     *
     * @param string $imagedata Source image bytes.
     * @param float[] $box Normalised box [x0, y0, x1, y1].
     * @param float $padding Extra margin around the box, as a fraction of the page.
     * @return array|null ['data', 'mimetype', 'filename', 'width', 'height'] or null on failure.
     */
    public static function crop(string $imagedata, array $box, float $padding = 0.012): ?array {
        if (!isset($box[0], $box[1], $box[2], $box[3])
                || ($box[2] - $box[0]) < self::MIN_SIDE || ($box[3] - $box[1]) < self::MIN_SIDE) {
            return null;
        }
        $info = @getimagesizefromstring($imagedata);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return null;
        }
        $width = (int) $info[0];
        $height = (int) $info[1];

        [$x0, $y0, $x1, $y1] = $box;
        $x0 = max(0.0, $x0 - $padding);
        $y0 = max(0.0, $y0 - $padding);
        $x1 = min(1.0, $x1 + $padding);
        $y1 = min(1.0, $y1 + $padding);

        $left = (int) floor($x0 * $width);
        $top = (int) floor($y0 * $height);
        $right = (int) ceil($x1 * $width);
        $bottom = (int) ceil($y1 * $height);
        $cropwidth = max(1, $right - $left);
        $cropheight = max(1, $bottom - $top);
        if ($cropwidth < 16 || $cropheight < 16) {
            return null;
        }

        raise_memory_limit(MEMORY_EXTRA);
        $source = @imagecreatefromstring($imagedata);
        if (!$source) {
            return null;
        }

        $canvas = imagecreatetruecolor($cropwidth, $cropheight);
        if (!$canvas) {
            imagedestroy($source);
            return null;
        }
        // White background: transparent PNGs become readable instead of black.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $cropwidth, $cropheight, $white);
        imagecopy($canvas, $source, 0, 0, $left, $top, $cropwidth, $cropheight);
        imagedestroy($source);

        $canvas = self::scale_down($canvas, self::MAX_DIMENSION);

        $result = self::encode($canvas);
        imagedestroy($canvas);
        return $result;
    }

    /**
     * Resize an image so its longest side does not exceed $maxside.
     *
     * @param \GdImage $image Image.
     * @param int $maxside Maximum side in pixels.
     * @return \GdImage Resized image (the original is destroyed when it changes).
     */
    protected static function scale_down($image, int $maxside) {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= $maxside) {
            return $image;
        }
        $ratio = $maxside / $longest;
        $newwidth = max(1, (int) round($width * $ratio));
        $newheight = max(1, (int) round($height * $ratio));
        $resized = imagecreatetruecolor($newwidth, $newheight);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefilledrectangle($resized, 0, 0, $newwidth, $newheight, $white);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        imagedestroy($image);
        return $resized;
    }

    /**
     * Encode a crop as PNG, falling back to JPEG when the PNG is too large.
     *
     * @param \GdImage $image Image.
     * @return array|null ['data', 'mimetype', 'filename', 'width', 'height'] or null.
     */
    protected static function encode($image): ?array {
        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();
        imagepng($image, null, 6);
        $data = ob_get_clean();
        $mimetype = 'image/png';
        $extension = 'png';

        if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BYTES) {
            ob_start();
            imagejpeg($image, null, 90);
            $data = ob_get_clean();
            $mimetype = 'image/jpeg';
            $extension = 'jpg';
        }

        // Extremely detailed crops may still be too large: shrink and retry.
        $current = $image;
        while (is_string($data) && strlen($data) > self::MAX_BYTES && max($width, $height) > 600) {
            $current = self::scale_down($current, (int) round(max($width, $height) * 0.8));
            $width = imagesx($current);
            $height = imagesy($current);
            ob_start();
            imagejpeg($current, null, 88);
            $data = ob_get_clean();
            $mimetype = 'image/jpeg';
            $extension = 'jpg';
        }
        if ($current !== $image) {
            imagedestroy($current);
        }

        if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BYTES) {
            return null;
        }

        return [
            'data' => $data,
            'mimetype' => $mimetype,
            'filename' => 'aiyared-crop-' . substr(sha1($data), 0, 20) . '.' . $extension,
            'width' => $width,
            'height' => $height,
        ];
    }
}
