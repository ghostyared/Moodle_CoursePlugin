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
 * Exports a class presentation (see slides) as a PowerPoint file (.pptx).
 *
 * The file opens in PowerPoint, Google Slides (File > Import slides) and LibreOffice, where the teacher can edit it
 * with a familiar tool. It is built here from a small empty base file (assets/base.pptx); no external service or library
 * is used. Drawn figures (SVG) are not included, because PowerPoint needs a raster picture for them.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pptx_export {

    /** EMU per inch. */
    private const IN = 914400;

    /** Slide size (16:9, 13.333 x 7.5 in). */
    private const W = 12192000;

    /** Slide height. */
    private const H = 6858000;

    /** Namespaces of a slide part. */
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    /** @var int Next shape id inside the slide being built. */
    private $shapeid = 2;

    /**
     * Build the file.
     *
     * @param array $deck Deck (see slides).
     * @param callable|null $imagebytes Returns the bytes of a deck picture from its file name, or null.
     * @param string|null $basepath Path of the base file (tests only).
     * @return string Content of the .pptx file.
     * @throws \moodle_exception When the file cannot be created.
     */
    public static function build(array $deck, ?callable $imagebytes = null, ?string $basepath = null): string {
        $self = new self();
        return $self->create($deck, $imagebytes, $basepath ?? dirname(__DIR__, 2) . '/assets/base.pptx');
    }

    /**
     * Escape text for XML.
     *
     * @param string $text Text.
     * @return string
     */
    private static function x(string $text): string {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Create the package.
     *
     * @param array $deck Deck.
     * @param callable|null $imagebytes Picture loader.
     * @param string $basepath Base file.
     * @return string
     */
    private function create(array $deck, ?callable $imagebytes, string $basepath): string {
        $tmp = tempnam(sys_get_temp_dir(), 'aiy');
        if ($tmp === false || !copy($basepath, $tmp)) {
            throw new \moodle_exception('presentation_missing', 'local_ai_yared_course');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \moodle_exception('presentation_missing', 'local_ai_yared_course');
        }
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $prels = (string) $zip->getFromName('ppt/_rels/presentation.xml.rels');
        $pres = (string) $zip->getFromName('ppt/presentation.xml');
        $ids = '';
        $extra = '';
        $hasext = [];
        $slides = array_values((array) ($deck['slides'] ?? []));
        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
                . 'slideLayout" Target="../slideLayouts/slideLayout7.xml"/>';
            $picture = null;
            if (!empty($slide['image']['file']) && $imagebytes) {
                $picture = $this->prepare_picture((string) $imagebytes((string) $slide['image']['file']));
            }
            if ($picture) {
                $name = "image{$n}." . $picture['ext'];
                $zip->addFromString("ppt/media/$name", $picture['data']);
                $rels .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
                    . 'image" Target="../media/' . $name . '"/>';
                $hasext[$picture['ext']] = true;
            }
            $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
                . 'notesSlide" Target="../notesSlides/notesSlide' . $n . '.xml"/>';
            $zip->addFromString("ppt/slides/slide$n.xml", $this->slide_xml($slide, $picture));
            $zip->addFromString("ppt/slides/_rels/slide$n.xml.rels", $this->rels($rels));
            $zip->addFromString("ppt/notesSlides/notesSlide$n.xml", $this->notes_xml((string) ($slide['notes'] ?? '')));
            $zip->addFromString("ppt/notesSlides/_rels/notesSlide$n.xml.rels", $this->rels(
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
                . 'notesMaster" Target="../notesMasters/notesMaster1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
                . 'slide" Target="../slides/slide' . $n . '.xml"/>'));
            $prels = str_replace('</Relationships>', '<Relationship Id="rIdS' . $n . '" Type="http://schemas.'
                . 'openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $n . '.xml"/>'
                . '</Relationships>', $prels);
            $ids .= '<p:sldId id="' . (255 + $n) . '" r:id="rIdS' . $n . '"/>';
            $extra .= '<Override PartName="/ppt/slides/slide' . $n . '.xml" ContentType="application/vnd.openxmlformats-'
                . 'officedocument.presentationml.slide+xml"/><Override PartName="/ppt/notesSlides/notesSlide' . $n
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
        }
        foreach (array_keys($hasext) as $ext) {
            if (stripos($types, 'Extension="' . $ext . '"') === false) {
                $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
                $extra = '<Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . $extra;
            }
        }
        $types = str_replace('</Types>', $extra . '</Types>', $types);
        $pres = str_replace('<p:sldIdLst/>', '<p:sldIdLst>' . $ids . '</p:sldIdLst>', $pres);
        $pres = str_replace(' type="screen4x3"', '', $pres);
        if (strpos($pres, 'notesMasterIdLst') === false
                && preg_match('/Id="(rId\d+)"[^>]*relationships\/notesMaster"/', $prels, $m)) {
            $pres = str_replace('</p:sldMasterIdLst>', '</p:sldMasterIdLst><p:notesMasterIdLst><p:notesMasterId r:id="'
                . $m[1] . '"/></p:notesMasterIdLst>', $pres);
        }
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $prels);
        $zip->addFromString('ppt/presentation.xml', $pres);
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }

    /**
     * Wrap relationships.
     *
     * @param string $items Relationship elements.
     * @return string
     */
    private function rels(string $items): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/'
            . 'package/2006/relationships">' . $items . '</Relationships>';
    }

    /**
     * Prepare a picture for PowerPoint (JPEG or PNG; WebP is converted when GD can do it).
     *
     * @param string $bytes Picture bytes.
     * @return array|null ext, data, width, height.
     */
    private function prepare_picture(string $bytes): ?array {
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        if (!$info) {
            return null;
        }
        $type = $info[2];
        if ($type === IMAGETYPE_JPEG) {
            return ['ext' => 'jpg', 'data' => $bytes, 'width' => $info[0], 'height' => $info[1]];
        }
        if ($type === IMAGETYPE_PNG) {
            return ['ext' => 'png', 'data' => $bytes, 'width' => $info[0], 'height' => $info[1]];
        }
        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $image = @imagecreatefromstring($bytes);
            if ($image) {
                ob_start();
                imagepng($image);
                $png = (string) ob_get_clean();
                return ['ext' => 'png', 'data' => $png, 'width' => $info[0], 'height' => $info[1]];
            }
        }
        return null;
    }

    /**
     * Text box shape.
     *
     * @param string $name Shape name.
     * @param array $box x, y, w, h in inches.
     * @param string $paragraphs a:p elements.
     * @param string $anchor Vertical anchor (t, ctr, b).
     * @return string
     */
    private function textbox(string $name, array $box, string $paragraphs, string $anchor = 't'): string {
        $id = $this->shapeid++;
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . self::x($name) . '"/><p:cNvSpPr txBox="1"/><p:nvPr/>'
            . '</p:nvSpPr><p:spPr><a:xfrm><a:off x="' . (int) ($box[0] * self::IN) . '" y="' . (int) ($box[1] * self::IN)
            . '"/><a:ext cx="' . (int) ($box[2] * self::IN) . '" cy="' . (int) ($box[3] * self::IN) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr><p:txBody><a:bodyPr wrap="square" '
            . 'lIns="0" tIns="0" rIns="0" bIns="0" anchor="' . $anchor . '"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            . $paragraphs . '</p:txBody></p:sp>';
    }

    /**
     * Paragraph.
     *
     * @param string $text Text.
     * @param int $size Size in points.
     * @param string $color RGB.
     * @param bool $bold Bold.
     * @param string $align l, ctr.
     * @param bool $bullet Bullet.
     * @return string
     */
    private function para(string $text, int $size, string $color, bool $bold = false, string $align = 'l',
            bool $bullet = false): string {
        $ppr = $bullet
            ? '<a:pPr marL="342900" indent="-342900" algn="' . $align . '"><a:spcBef><a:spcPts val="900"/></a:spcBef>'
                . '<a:buFont typeface="Arial"/><a:buChar char="&#8226;"/></a:pPr>'
            : '<a:pPr algn="' . $align . '"><a:buNone/></a:pPr>';
        return '<a:p>' . $ppr . '<a:r><a:rPr lang="es-PE" sz="' . ($size * 100) . '"' . ($bold ? ' b="1"' : '')
            . ' dirty="0"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:rPr><a:t>' . self::x($text)
            . '</a:t></a:r></a:p>';
    }

    /**
     * XML of a slide.
     *
     * @param array $slide Slide.
     * @param array|null $picture Prepared picture.
     * @return string
     */
    private function slide_xml(array $slide, ?array $picture): string {
        $this->shapeid = 2;
        $layout = (string) ($slide['layout'] ?? 'bullets');
        $colors = ['title' => '0F4C81', 'bullets' => '0F4C81', 'example' => '8A4B00', 'summary' => '1B6E3A'];
        $titlecolor = $colors[$layout] ?? '0F4C81';
        $emoji = (string) ($slide['emoji'] ?? '');
        $hasmedia = $picture !== null || $emoji !== '';
        $shapes = '';
        $bullets = (array) ($slide['bullets'] ?? []);
        $text = (string) ($slide['text'] ?? '');
        $title = (string) ($slide['title'] ?? '');

        if ($layout === 'title') {
            $shapes .= $this->textbox('Title', [1, 2.1, 11.33, 1.6], $this->para($title, 48, $titlecolor, true, 'ctr'), 'b');
            if ($text !== '') {
                $shapes .= $this->textbox('Subtitle', [1.5, 3.95, 10.33, 1.2], $this->para($text, 26, '1D2125', false, 'ctr'));
            }
            if ($picture) {
                $shapes .= $this->picture($picture, [10.0, 0.4, 3.0, 2.0], 2);
            } else if ($emoji !== '') {
                $shapes .= $this->textbox('Icon', [5.17, 0.4, 3.0, 1.5], $this->para($emoji, 66, '1D2125', false, 'ctr'), 'ctr');
            }
        } else {
            $shapes .= $this->textbox('Title', [0.6, 0.4, 12.13, 1.1], $this->para($title, 36, $titlecolor, true), 'ctr');
            $width = $hasmedia ? 6.9 : 12.13;
            $paras = '';
            if ($text !== '') {
                $paras .= $this->para($text, 24, '1D2125');
            }
            $chars = mb_strlen($text);
            foreach ($bullets as $bullet) {
                $chars += mb_strlen((string) $bullet);
            }
            $size = $chars > 420 ? 18 : ($chars > 260 ? 20 : 24);
            foreach ($bullets as $bullet) {
                $paras .= $this->para((string) $bullet, $size, '1D2125', false, 'l', true);
            }
            if ($paras !== '') {
                $shapes .= $this->textbox('Content', [0.6, 1.8, $width, 5.0], $paras);
            }
            if ($picture) {
                $shapes .= $this->picture($picture, [7.9, 1.8, 4.83, 4.4], 2);
                $credit = (string) ($slide['image']['credit'] ?? '');
                if ($credit !== '') {
                    $shapes .= $this->textbox('Credit', [7.9, 6.3, 4.83, 0.7], $this->para($credit, 9, '5A6270'));
                }
            } else if ($emoji !== '') {
                $shapes .= $this->textbox('Icon', [8.2, 2.2, 4.3, 3.2], $this->para($emoji, 120, '1D2125', false, 'ctr'), 'ctr');
            }
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld ' . self::NS . '><p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" '
            . 'y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $shapes . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    /**
     * Picture shape, fitted inside a box keeping its proportions.
     *
     * @param array $picture Prepared picture.
     * @param array $box x, y, w, h in inches.
     * @param int $relnum Relationship number (rId{n+1}).
     * @return string
     */
    private function picture(array $picture, array $box, int $relnum): string {
        $scale = min($box[2] / max(1, $picture['width']), $box[3] / max(1, $picture['height']));
        $w = $picture['width'] * $scale;
        $h = $picture['height'] * $scale;
        $x = $box[0] + ($box[2] - $w) / 2;
        $y = $box[1] + ($box[3] - $h) / 2;
        $id = $this->shapeid++;
        return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Picture ' . $id . '" descr=""/><p:cNvPicPr>'
            . '<a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId'
            . ($relnum + 1) . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr><a:xfrm><a:off x="'
            . (int) ($x * self::IN) . '" y="' . (int) ($y * self::IN) . '"/><a:ext cx="' . (int) ($w * self::IN) . '" cy="'
            . (int) ($h * self::IN) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    /**
     * XML of the notes of a slide.
     *
     * @param string $notes Notes text.
     * @return string
     */
    private function notes_xml(string $notes): string {
        $paras = '';
        foreach (preg_split('/\R/u', $notes) ?: [] as $line) {
            $paras .= '<a:p><a:r><a:rPr lang="es-PE" dirty="0"/><a:t>' . self::x($line) . '</a:t></a:r></a:p>';
        }
        if ($paras === '') {
            $paras = '<a:p><a:endParaRPr lang="es-PE"/></a:p>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notes ' . self::NS . '><p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" '
            . 'y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image Placeholder 1"/><p:cNvSpPr><a:spLocks noGrp="1" '
            . 'noRot="1" noChangeAspect="1"/></p:cNvSpPr><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes Placeholder 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
            . '<p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>'
            . $paras . '</p:txBody></p:sp></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
    }
}
