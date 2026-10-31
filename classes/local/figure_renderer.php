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
 * Draws geometry figures from a structured description.
 *
 * The AI never draws: it only describes the figure (shapes, angles, labels, relations) and this class
 * produces the SVG, so the numbers and marks on the picture are exactly the ones in the statement.
 * Nothing from the description reaches the SVG unless it is a number inside a safe range or a short
 * label made of whitelisted characters, so the result is safe to store as an image.
 *
 * Supported shapes: triangle, quad (rectangle, square, parallelogram, rhombus, trapezoid), circle,
 * segments (points on a line), parallel_lines (two parallel lines and a transversal) and axes
 * (Cartesian plane with points, polygon, lines and simple functions).
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class figure_renderer {

    /** @var int Width of the cell of each shape. */
    protected const CELL_W = 300;

    /** @var int Height of the cell of each shape. */
    protected const CELL_H = 240;

    /** @var int Maximum number of shapes in one figure. */
    protected const MAX_SHAPES = 3;

    /** @var string Stroke colour of the drawing. */
    protected const INK = '#111111';

    /** @var string[] Shape types the renderer knows. */
    public const SHAPES = ['triangle', 'quad', 'circle', 'segments', 'parallel_lines', 'axes'];

    /**
     * Render a figure.
     *
     * @param mixed $spec Decoded description: ['shapes' => [...]] (a single shape is also accepted).
     * @param string $alt Alternative text, used as the SVG title.
     * @return string|null SVG markup, or null when nothing could be drawn.
     */
    public static function render($spec, string $alt = ''): ?string {
        try {
            if (!is_array($spec)) {
                return null;
            }
            $shapes = $spec['shapes'] ?? (isset($spec['type']) ? [$spec] : []);
            if (!is_array($shapes)) {
                return null;
            }
            $body = '';
            $count = 0;
            foreach ($shapes as $shape) {
                if ($count >= self::MAX_SHAPES) {
                    break;
                }
                if (!is_array($shape) || !in_array($shape['type'] ?? '', self::SHAPES, true)) {
                    continue;
                }
                $method = 'draw_' . $shape['type'];
                $drawn = self::$method($shape);
                if ($drawn === null || $drawn === '') {
                    continue;
                }
                $body .= '<g transform="translate(' . ($count * self::CELL_W) . ' 0)">' . $drawn . '</g>';
                $count++;
            }
            if ($count === 0) {
                return null;
            }
            $width = $count * self::CELL_W;
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . self::CELL_H
                . '" width="' . $width . '" height="' . self::CELL_H . '" role="img" font-family="Arial, Helvetica, sans-serif">';
            $svg .= '<title>' . self::esc($alt !== '' ? $alt : 'Figure') . '</title>';
            $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>' . $body . '</svg>';
            return strlen($svg) > 60000 ? null : $svg;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Basic helpers.

    /**
     * Escape text for SVG.
     *
     * @param string $text Text.
     * @return string
     */
    protected static function esc(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Clean a label: short, with only letters, digits and common math characters.
     *
     * @param mixed $value Raw label.
     * @return string
     */
    protected static function label($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string) $value);
        $text = str_replace(['^2', '^3', '*', 'sqrt'], ['²', '³', '·', '√'], $text);
        $text = preg_replace('/[^\p{L}\p{N}\s\+\-=°\'\(\)\/\.,²³·√′π∠]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return function_exists('mb_substr') ? mb_substr((string) $text, 0, 18) : substr((string) $text, 0, 18);
    }

    /**
     * Clamp a number.
     *
     * @param mixed $value Raw value.
     * @param float $min Minimum.
     * @param float $max Maximum.
     * @param float $default Value when it is not numeric.
     * @return float
     */
    protected static function num($value, float $min, float $max, float $default): float {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (float) $value));
    }

    /**
     * SVG text element centred on a point.
     *
     * @param float $x X.
     * @param float $y Y.
     * @param string $text Text (already cleaned).
     * @param int $size Font size.
     * @param bool $italic Italic (for variables and Greek letters).
     * @return string
     */
    protected static function text(float $x, float $y, string $text, int $size = 17, bool $italic = false): string {
        if ($text === '') {
            return '';
        }
        return sprintf('<text x="%.1f" y="%.1f" font-size="%d" text-anchor="middle" dominant-baseline="central"%s fill="%s">%s</text>',
            $x, $y, $size, $italic ? ' font-style="italic"' : '', self::INK, self::esc($text));
    }

    /**
     * Line element.
     *
     * @param array $a Start [x, y].
     * @param array $b End [x, y].
     * @param float $width Stroke width.
     * @param string $extra Extra attributes.
     * @return string
     */
    protected static function line(array $a, array $b, float $width = 2.4, string $extra = ''): string {
        return sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="%.1f" stroke-linecap="round"%s/>',
            $a[0], $a[1], $b[0], $b[1], self::INK, $width, $extra);
    }

    /**
     * Dot.
     *
     * @param array $p Centre.
     * @param float $r Radius.
     * @return string
     */
    protected static function dot(array $p, float $r = 4): string {
        return sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s"/>', $p[0], $p[1], $r, self::INK);
    }

    /**
     * Unit vector from a to b.
     *
     * @param array $a From.
     * @param array $b To.
     * @return array
     */
    protected static function unit(array $a, array $b): array {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $n = hypot($dx, $dy);
        return $n < 1e-9 ? [1.0, 0.0] : [$dx / $n, $dy / $n];
    }

    /**
     * Build a function that maps raw coordinates (y up) into the drawing box of the cell, keeping proportions.
     *
     * @param array $points Raw points [[x, y], ...] that must fit.
     * @param array $box [x0, y0, x1, y1] on screen.
     * @return array [callable $map, float $scale]
     */
    protected static function fit(array $points, array $box): array {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $minx = min($xs);
        $maxx = max($xs);
        $miny = min($ys);
        $maxy = max($ys);
        $w = max($maxx - $minx, 1e-6);
        $h = max($maxy - $miny, 1e-6);
        $scale = min(($box[2] - $box[0]) / $w, ($box[3] - $box[1]) / $h);
        $offx = $box[0] + (($box[2] - $box[0]) - $w * $scale) / 2;
        $offy = $box[1] + (($box[3] - $box[1]) - $h * $scale) / 2;
        $map = function (array $p) use ($minx, $maxy, $scale, $offx, $offy): array {
            return [$offx + ($p[0] - $minx) * $scale, $offy + ($maxy - $p[1]) * $scale];
        };
        return [$map, $scale];
    }

    /**
     * Angle mark at a vertex: one or more arcs, a right-angle square, and a label inside.
     *
     * @param array $v Vertex (screen).
     * @param array $p1 A point on the first ray.
     * @param array $p2 A point on the second ray.
     * @param array $mark ['label' => , 'arcs' => , 'right' => ].
     * @return string
     */
    protected static function angle_mark(array $v, array $p1, array $p2, array $mark): string {
        $u1 = self::unit($v, $p1);
        $u2 = self::unit($v, $p2);
        $svg = '';
        if (!empty($mark['right'])) {
            $s = 15;
            $a = [$v[0] + $u1[0] * $s, $v[1] + $u1[1] * $s];
            $c = [$v[0] + $u2[0] * $s, $v[1] + $u2[1] * $s];
            $b = [$a[0] + $u2[0] * $s, $a[1] + $u2[1] * $s];
            $svg .= sprintf('<polyline points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="none" stroke="%s" stroke-width="1.6"/>',
                $a[0], $a[1], $b[0], $b[1], $c[0], $c[1], self::INK);
        } else {
            $arcs = (int) self::num($mark['arcs'] ?? 1, 0, 3, 1);
            $cross = $u1[0] * $u2[1] - $u1[1] * $u2[0];
            $dot = $u1[0] * $u2[0] + $u1[1] * $u2[1];
            $large = ($dot < -0.999) ? 0 : 0;
            for ($k = 0; $k < $arcs; $k++) {
                $r = 22 + 6 * $k;
                $q1 = [$v[0] + $u1[0] * $r, $v[1] + $u1[1] * $r];
                $q2 = [$v[0] + $u2[0] * $r, $v[1] + $u2[1] * $r];
                $svg .= sprintf('<path d="M%.1f,%.1f A%d,%d 0 %d %d %.1f,%.1f" fill="none" stroke="%s" stroke-width="1.6"/>',
                    $q1[0], $q1[1], $r, $r, $large, $cross > 0 ? 1 : 0, $q2[0], $q2[1], self::INK);
            }
        }
        $label = self::label($mark['label'] ?? '');
        if ($label !== '') {
            $bis = [$u1[0] + $u2[0], $u1[1] + $u2[1]];
            $bn = hypot($bis[0], $bis[1]);
            if ($bn < 1e-6) {
                $bis = [-$u1[1], $u1[0]];
                $bn = 1;
            }
            $svg .= self::text($v[0] + $bis[0] / $bn * 42, $v[1] + $bis[1] / $bn * 42, $label, 16, true);
        }
        return $svg;
    }

    /**
     * Draw a closed polygon with side labels, equal-side ticks, vertex names and angle marks.
     *
     * @param array $verts Vertices on screen, in order.
     * @param array $sidelabels Side index => label text.
     * @param array $ticks Side index => number of equal-length ticks.
     * @param array $names Vertex index => name.
     * @param array $marks Vertex index => angle mark description.
     * @return string
     */
    protected static function polygon(array $verts, array $sidelabels, array $ticks, array $names, array $marks): string {
        $n = count($verts);
        $cx = array_sum(array_column($verts, 0)) / $n;
        $cy = array_sum(array_column($verts, 1)) / $n;
        $pts = [];
        foreach ($verts as $v) {
            $pts[] = sprintf('%.1f,%.1f', $v[0], $v[1]);
        }
        $svg = '<polygon points="' . implode(' ', $pts) . '" fill="none" stroke="' . self::INK
            . '" stroke-width="2.6" stroke-linejoin="round"/>';
        for ($i = 0; $i < $n; $i++) {
            $a = $verts[$i];
            $b = $verts[($i + 1) % $n];
            $mid = [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
            $u = self::unit($a, $b);
            $nrm = [-$u[1], $u[0]];
            if (($mid[0] + $nrm[0] - $cx) ** 2 + ($mid[1] + $nrm[1] - $cy) ** 2 < ($mid[0] - $cx) ** 2 + ($mid[1] - $cy) ** 2) {
                $nrm = [-$nrm[0], -$nrm[1]];
            }
            $tickcount = (int) ($ticks[$i] ?? 0);
            for ($t = 0; $t < $tickcount; $t++) {
                $off = ($t - ($tickcount - 1) / 2) * 5;
                $c = [$mid[0] + $u[0] * $off, $mid[1] + $u[1] * $off];
                $svg .= self::line([$c[0] - $nrm[0] * 6, $c[1] - $nrm[1] * 6], [$c[0] + $nrm[0] * 6, $c[1] + $nrm[1] * 6], 1.8);
            }
            if (($sidelabels[$i] ?? '') !== '') {
                // Longer labels need more room when the side leans, so the text does not touch the line.
                $off = 13 + abs($nrm[0]) * 4.6 * mb_strlen($sidelabels[$i]) + abs($nrm[1]) * 7;
                $svg .= self::text($mid[0] + $nrm[0] * $off, $mid[1] + $nrm[1] * $off, $sidelabels[$i], 18);
            }
        }
        foreach ($names as $i => $name) {
            if ($name === '' || !isset($verts[$i])) {
                continue;
            }
            $u = self::unit([$cx, $cy], $verts[$i]);
            $svg .= self::text($verts[$i][0] + $u[0] * 15, $verts[$i][1] + $u[1] * 15, $name, 18, true);
        }
        foreach ($marks as $i => $mark) {
            if (!isset($verts[$i]) || !is_array($mark)) {
                continue;
            }
            $svg .= self::angle_mark($verts[$i], $verts[($i + 1) % $n], $verts[($i + $n - 1) % $n], $mark);
        }
        return $svg;
    }

    /**
     * Read side labels, ticks and angle marks of a shape description into the arrays polygon() expects.
     *
     * @param array $shape Description.
     * @param array $sidekeys Side key => index.
     * @param array $vertexkeys Vertex key => index.
     * @return array [$sidelabels, $ticks, $marks]
     */
    protected static function read_marks(array $shape, array $sidekeys, array $vertexkeys): array {
        $sidelabels = [];
        $ticks = [];
        foreach ((array) ($shape['labels'] ?? []) as $key => $value) {
            if (isset($sidekeys[$key])) {
                $sidelabels[$sidekeys[$key]] = self::label($value);
            }
        }
        foreach ((array) ($shape['ticks'] ?? []) as $key => $value) {
            if (isset($sidekeys[$key])) {
                $ticks[$sidekeys[$key]] = (int) self::num($value, 0, 3, 0);
            }
        }
        $marks = [];
        foreach ((array) ($shape['angles'] ?? []) as $key => $value) {
            if (isset($vertexkeys[$key]) && is_array($value)) {
                $marks[$vertexkeys[$key]] = $value;
            }
        }
        if (!empty($shape['right_angle']) && isset($vertexkeys[$shape['right_angle']])) {
            $marks[$vertexkeys[$shape['right_angle']]] = ['right' => true];
        }
        return [$sidelabels, $ticks, $marks];
    }

    // Shapes.

    /**
     * Triangle: by base angles, by sides [base, left, right], or right with two legs.
     *
     * @param array $s Description.
     * @return string|null
     */
    protected static function draw_triangle(array $s): ?string {
        $apex = null;
        $base = 1.0;
        if (!empty($s['sides']) && is_array($s['sides']) && count($s['sides']) === 3) {
            [$c, $b, $a] = array_map(fn($v) => self::num($v, 0.1, 1000, 0), array_values($s['sides']));
            if ($a <= 0 || $b <= 0 || $c <= 0 || $a + $b <= $c || $a + $c <= $b || $b + $c <= $a) {
                return null;
            }
            $x = ($c * $c + $b * $b - $a * $a) / (2 * $c);
            $h = sqrt(max($b * $b - $x * $x, 0));
            $base = $c;
            $apex = [$x, $h];
        } else if (!empty($s['legs']) && is_array($s['legs']) && count($s['legs']) === 2) {
            [$p, $q] = array_map(fn($v) => self::num($v, 0.1, 1000, 1), array_values($s['legs']));
            $base = $p;
            $apex = [0.0, $q];
            $s['right_angle'] = $s['right_angle'] ?? 'left';
        } else {
            [$ba, $bb] = array_map(fn($v) => self::num($v, 5, 150, 60), array_values($s['base_angles'] ?? [60, 60]) + [1 => 60]);
            if ($ba + $bb >= 175) {
                return null;
            }
            $ta = tan(deg2rad($ba));
            $tb = tan(deg2rad($bb));
            $x = $tb / ($ta + $tb);
            $apex = [$x, $x * $ta];
            $base = 1.0;
        }
        $down = ($s['orientation'] ?? 'up') === 'down';
        $sg = $down ? -1 : 1;
        $raw = [[0.0, 0.0], [$base, 0.0], [$apex[0], $sg * $apex[1]]];
        [$map] = self::fit($raw, [46, 38, 254, 206]);
        $verts = array_map($map, $raw);
        [$sidelabels, $ticks, $marks] = self::read_marks($s,
            ['base' => 0, 'right' => 1, 'left' => 2], ['left' => 0, 'right' => 1, 'apex' => 2]);
        $names = [];
        foreach (array_values((array) ($s['vertices'] ?? [])) as $i => $name) {
            if ($i < 3) {
                $names[$i] = self::label($name);
            }
        }
        $svg = self::polygon($verts, $sidelabels, $ticks, $names, $marks);
        if (!empty($s['base_dot'])) {
            $svg .= self::dot([($verts[0][0] + $verts[1][0]) / 2, ($verts[0][1] + $verts[1][1]) / 2], 4.6);
        }
        return $svg;
    }

    /**
     * Quadrilateral.
     *
     * @param array $s Description.
     * @return string|null
     */
    protected static function draw_quad(array $s): ?string {
        $kind = (string) ($s['kind'] ?? 'rectangle');
        $w = self::num($s['width'] ?? 4, 0.5, 1000, 4);
        $h = self::num($s['height'] ?? 2.5, 0.5, 1000, 2.5);
        if ($kind === 'square') {
            $h = $w;
        }
        $raw = [[0.0, 0.0], [$w, 0.0], [$w, $h], [0.0, $h]];
        if ($kind === 'parallelogram' || $kind === 'rhombus') {
            $skew = self::num($s['skew'] ?? $w * 0.3, 0, $w * 2, $w * 0.3);
            if ($kind === 'rhombus') {
                $skew = min($skew, $w * 0.9);
                $h = sqrt(max($w * $w - $skew * $skew, 0.01));
            }
            $raw = [[0.0, 0.0], [$w, 0.0], [$w + $skew, $h], [$skew, $h]];
        } else if ($kind === 'trapezoid') {
            $top = self::num($s['top_width'] ?? $w * 0.6, 0.2, $w * 3, $w * 0.6);
            $off = ($s['shape'] ?? '') === 'right' ? 0.0 : ($w - $top) / 2;
            $raw = [[0.0, 0.0], [$w, 0.0], [$off + $top, $h], [$off, $h]];
        } else if (!in_array($kind, ['rectangle', 'square'], true)) {
            return null;
        }
        [$map] = self::fit($raw, [46, 42, 254, 200]);
        $verts = array_map($map, $raw);
        [$sidelabels, $ticks, $marks] = self::read_marks($s,
            ['bottom' => 0, 'right' => 1, 'top' => 2, 'left' => 3],
            ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3]);
        if (!empty($s['right_angles'])) {
            foreach ([0, 1, 2, 3] as $i) {
                $marks[$i] = $marks[$i] ?? ['right' => true];
            }
        }
        $names = [];
        foreach (array_values((array) ($s['vertices'] ?? [])) as $i => $name) {
            if ($i < 4) {
                $names[$i] = self::label($name);
            }
        }
        $svg = self::polygon($verts, $sidelabels, $ticks, $names, $marks);
        if (!empty($s['diagonals'])) {
            $svg .= self::line($verts[0], $verts[2], 1.8, ' stroke-dasharray="6 4"');
            $svg .= self::line($verts[1], $verts[3], 1.8, ' stroke-dasharray="6 4"');
        }
        return $svg;
    }

    /**
     * Circle with points, radii, chords and a central angle.
     *
     * @param array $s Description.
     * @return string|null
     */
    protected static function draw_circle(array $s): ?string {
        $cx = 150.0;
        $cy = 120.0;
        $r = 82.0;
        $svg = sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="%s" stroke-width="2.6"/>', $cx, $cy, $r, self::INK);
        $center = [$cx, $cy];
        $named = [];
        foreach ((array) ($s['points'] ?? []) as $i => $p) {
            if ($i >= 8 || !is_array($p)) {
                continue;
            }
            $name = self::label($p['label'] ?? '');
            if ($name === '') {
                continue;
            }
            $ang = deg2rad(self::num($p['angle'] ?? 0, -360, 360, 0));
            $named[$name] = [$cx + $r * cos($ang), $cy - $r * sin($ang), $ang];
        }
        $centerlabel = self::label($s['center_label'] ?? '');
        if ($centerlabel !== '' || !empty($s['radii']) || !empty($s['central_angle'])) {
            $svg .= self::dot($center, 3.8);
            $svg .= self::text($cx + 2, $cy + 16, $centerlabel, 17, true);
        }
        foreach ((array) ($s['radii'] ?? []) as $i => $rad) {
            $to = is_array($rad) ? self::label($rad['to'] ?? '') : self::label($rad);
            if ($i >= 6 || !isset($named[$to])) {
                continue;
            }
            $svg .= self::line($center, $named[$to], 2.2);
            $lab = is_array($rad) ? self::label($rad['label'] ?? '') : '';
            if ($lab !== '') {
                $mx = ($cx + $named[$to][0]) / 2;
                $my = ($cy + $named[$to][1]) / 2;
                $u = self::unit($center, $named[$to]);
                $svg .= self::text($mx - $u[1] * 15, $my + $u[0] * 15, $lab, 17);
            }
        }
        foreach ((array) ($s['chords'] ?? []) as $i => $ch) {
            if ($i >= 6 || !is_array($ch)) {
                continue;
            }
            $from = self::label($ch['from'] ?? '');
            $to = self::label($ch['to'] ?? '');
            if (!isset($named[$from], $named[$to])) {
                continue;
            }
            $svg .= self::line($named[$from], $named[$to], 2.2);
            $lab = self::label($ch['label'] ?? '');
            if ($lab !== '') {
                $mx = ($named[$from][0] + $named[$to][0]) / 2;
                $my = ($named[$from][1] + $named[$to][1]) / 2;
                $u = self::unit($center, [$mx, $my]);
                $svg .= self::text($mx - $u[0] * 16, $my - $u[1] * 16, $lab, 17);
            }
        }
        $ca = $s['central_angle'] ?? null;
        if (is_array($ca)) {
            $from = self::label($ca['from'] ?? '');
            $to = self::label($ca['to'] ?? '');
            if (isset($named[$from], $named[$to])) {
                $svg .= self::angle_mark($center, $named[$from], $named[$to], ['label' => $ca['label'] ?? '', 'arcs' => 1]);
            }
        }
        foreach ($named as $name => $p) {
            $svg .= self::dot([$p[0], $p[1]], 3.8);
            $svg .= self::text($cx + ($r + 17) * cos($p[2]), $cy - ($r + 17) * sin($p[2]), (string) $name, 18, true);
        }
        return $svg;
    }

    /**
     * Points on a line with the length of each stretch written below.
     *
     * @param array $s Description.
     * @return string|null
     */
    protected static function draw_segments(array $s): ?string {
        $names = array_slice(array_map([self::class, 'label'], array_values((array) ($s['points'] ?? []))), 0, 7);
        if (count($names) < 2) {
            return null;
        }
        $gaps = count($names) - 1;
        $lengths = array_values((array) ($s['lengths'] ?? []));
        $ratios = array_values((array) ($s['ratios'] ?? []));
        $x = 30.0;
        $xs = [$x];
        $total = 0.0;
        $weights = [];
        for ($i = 0; $i < $gaps; $i++) {
            $weights[$i] = self::num($ratios[$i] ?? 1, 0.2, 100, 1);
            $total += $weights[$i];
        }
        $span = 240.0;
        for ($i = 0; $i < $gaps; $i++) {
            $x += $span * $weights[$i] / $total;
            $xs[] = $x;
        }
        $y = 100.0;
        $svg = self::line([$xs[0], $y], [$xs[$gaps], $y], 2.6);
        foreach ($xs as $i => $px) {
            $svg .= self::line([$px, $y - 7], [$px, $y + 7], 2.2);
            $svg .= self::dot([$px, $y], 3.6);
            $svg .= self::text($px, $y - 22, $names[$i], 18, true);
        }
        for ($i = 0; $i < $gaps; $i++) {
            $lab = self::label($lengths[$i] ?? '');
            if ($lab === '') {
                continue;
            }
            $a = $xs[$i] + 3;
            $b = $xs[$i + 1] - 3;
            $svg .= sprintf('<path d="M%.1f,%.1f v8 H%.1f v-8" fill="none" stroke="%s" stroke-width="1.6"/>', $a, $y + 16, $b, self::INK);
            $svg .= self::text(($a + $b) / 2, $y + 36, $lab, 17);
        }
        $totallabel = self::label($s['total'] ?? '');
        if ($totallabel !== '') {
            $svg .= sprintf('<path d="M%.1f,%.1f v8 H%.1f v-8" fill="none" stroke="%s" stroke-width="1.6"/>', $xs[0], $y + 62, $xs[$gaps], self::INK);
            $svg .= self::text(($xs[0] + $xs[$gaps]) / 2, $y + 82, $totallabel, 17);
        }
        return $svg;
    }

    /**
     * Two parallel lines cut by a transversal. Angles are numbered 1-4 at the upper crossing and 5-8 at the lower one,
     * each as: above-left, above-right, below-left, below-right of the crossing.
     *
     * @param array $s Description.
     * @return string|null
     */
    protected static function draw_parallel_lines(array $s): ?string {
        $theta = self::num($s['angle'] ?? 60, 20, 160, 60);
        $up = 70.0;
        $lowy = 160.0;
        $dx = ($lowy - $up) / tan(deg2rad($theta));
        $cxm = 150.0;
        // The transversal goes up and to the right at theta degrees.
        $p = [$cxm + $dx / 2, $up];
        $q = [$cxm - $dx / 2, $lowy];
        $svg = self::line([30, $up], [270, $up], 2.6) . self::line([30, $lowy], [270, $lowy], 2.6);
        $dirx = $p[0] - $q[0];
        $diry = $p[1] - $q[1];
        $n = hypot($dirx, $diry);
        $ex = $dirx / $n * 42;
        $ey = $diry / $n * 42;
        $svg .= self::line([$q[0] - $ex, $q[1] - $ey], [$p[0] + $ex, $p[1] + $ey], 2.6);
        // Arrowheads that mark the lines as parallel.
        foreach ([$up, $lowy] as $yy) {
            $svg .= sprintf('<polyline points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="none" stroke="%s" stroke-width="1.8"/>',
                236, $yy - 6, 244, $yy, 236, $yy + 6, self::INK);
        }
        $lines = array_values((array) ($s['lines'] ?? []));
        $svg .= self::text(22, $up - 16, self::label($lines[0] ?? ''), 17, true);
        $svg .= self::text(22, $lowy - 16, self::label($lines[1] ?? ''), 17, true);
        $svg .= self::text($p[0] + $ex + 10, $p[1] + $ey - 8, self::label($s['transversal_label'] ?? ''), 17, true);
        foreach ((array) ($s['points'] ?? []) as $i => $pt) {
            if ($i >= 2 || !is_array($pt)) {
                continue;
            }
            $where = $i === 0 ? $p : $q;
            $svg .= self::dot($where, 3.6);
            $svg .= self::text($where[0] + 14, $where[1] + ($i === 0 ? -14 : 16), self::label($pt['label'] ?? ($pt ?? '')), 17, true);
        }
        // Sector boundaries in degrees (y up): above-left, above-right, below-left, below-right.
        $sectors = [
            1 => [$theta, 180.0], 2 => [0.0, $theta], 3 => [180.0, 180.0 + $theta], 4 => [180.0 + $theta, 360.0],
        ];
        foreach ((array) ($s['angle_labels'] ?? []) as $key => $value) {
            $key = (int) $key;
            if ($key < 1 || $key > 8) {
                continue;
            }
            $vertex = $key <= 4 ? $p : $q;
            $sec = $sectors[(($key - 1) % 4) + 1];
            $r1 = deg2rad($sec[0]);
            $r2 = deg2rad($sec[1]);
            $p1 = [$vertex[0] + 40 * cos($r1), $vertex[1] - 40 * sin($r1)];
            $p2 = [$vertex[0] + 40 * cos($r2), $vertex[1] - 40 * sin($r2)];
            $svg .= self::angle_mark($vertex, $p1, $p2, is_array($value) ? $value : ['label' => $value, 'arcs' => 1]);
        }
        return $svg;
    }

    /**
     * Cartesian plane with points, a polygon, lines and simple functions.
     *
     * @param array $s Description.
     * @return string|null
     */
    protected static function draw_axes(array $s): ?string {
        $xr = array_values((array) ($s['xrange'] ?? [-5, 5]));
        $yr = array_values((array) ($s['yrange'] ?? [-5, 5]));
        $x0 = self::num($xr[0] ?? -5, -100, 0, -5);
        $x1 = self::num($xr[1] ?? 5, 1, 100, 5);
        $y0 = self::num($yr[0] ?? -5, -100, 0, -5);
        $y1 = self::num($yr[1] ?? 5, 1, 100, 5);
        $box = [24, 16, 276, 224];
        $scale = min(($box[2] - $box[0]) / ($x1 - $x0), ($box[3] - $box[1]) / ($y1 - $y0));
        $ox = $box[0] + (($box[2] - $box[0]) - ($x1 - $x0) * $scale) / 2;
        $oy = $box[1] + (($box[3] - $box[1]) - ($y1 - $y0) * $scale) / 2;
        $map = function (float $x, float $y) use ($x0, $y1, $scale, $ox, $oy): array {
            return [$ox + ($x - $x0) * $scale, $oy + ($y1 - $y) * $scale];
        };
        $step = max(1, (int) ceil(max($x1 - $x0, $y1 - $y0) / 10));
        $svg = '';
        for ($gx = (int) ceil($x0 / $step) * $step; $gx <= $x1; $gx += $step) {
            $a = $map($gx, $y0);
            $b = $map($gx, $y1);
            $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#d9dde3" stroke-width="1"/>', $a[0], $a[1], $b[0], $b[1]);
            if ($gx !== 0) {
                $svg .= self::text($map($gx, 0)[0], $map($gx, 0)[1] + 11, (string) $gx, 11);
            }
        }
        for ($gy = (int) ceil($y0 / $step) * $step; $gy <= $y1; $gy += $step) {
            $a = $map($x0, $gy);
            $b = $map($x1, $gy);
            $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#d9dde3" stroke-width="1"/>', $a[0], $a[1], $b[0], $b[1]);
            if ($gy !== 0) {
                $svg .= self::text($map(0, $gy)[0] - 10, $map(0, $gy)[1], (string) $gy, 11);
            }
        }
        $ax0 = $map($x0, 0);
        $ax1 = $map($x1, 0);
        $ay0 = $map(0, $y0);
        $ay1 = $map(0, $y1);
        $svg .= self::line($ax0, $ax1, 1.8) . self::line($ay0, $ay1, 1.8);
        $svg .= self::text($ax1[0] - 6, $ax1[1] - 9, 'x', 14, true) . self::text($ay1[0] + 11, $ay1[1] + 6, 'y', 14, true);

        $clip = function (float $x, float $y) use ($y0, $y1): bool {
            return $y >= $y0 && $y <= $y1;
        };
        foreach (array_slice((array) ($s['functions'] ?? []), 0, 3) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $type = $f['type'] ?? 'linear';
            $a = self::num($f['a'] ?? 1, -50, 50, 1);
            $b = self::num($f['b'] ?? ($type === 'linear' ? 0 : 0), -100, 100, 0);
            $c = self::num($f['c'] ?? 0, -100, 100, 0);
            $m = self::num($f['m'] ?? 1, -50, 50, 1);
            $path = '';
            $pen = false;
            for ($i = 0; $i <= 120; $i++) {
                $x = $x0 + ($x1 - $x0) * $i / 120;
                $y = $type === 'quadratic' ? $a * $x * $x + $b * $x + $c : $m * $x + $b;
                if (!$clip($x, $y)) {
                    $pen = false;
                    continue;
                }
                $pt = $map($x, $y);
                $path .= ($pen ? 'L' : 'M') . sprintf('%.1f,%.1f ', $pt[0], $pt[1]);
                $pen = true;
            }
            if ($path !== '') {
                $svg .= '<path d="' . trim($path) . '" fill="none" stroke="#1d4ed8" stroke-width="2.4"/>';
            }
        }
        $pts = [];
        foreach (array_slice((array) ($s['points'] ?? []), 0, 8) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = self::label($p['label'] ?? '');
            $pts[$name] = ['x' => self::num($p['x'] ?? 0, $x0, $x1, 0), 'y' => self::num($p['y'] ?? 0, $y0, $y1, 0)];
        }
        $poly = [];
        foreach ((array) ($s['polygon'] ?? []) as $name) {
            $name = self::label($name);
            if (isset($pts[$name])) {
                $poly[] = $map($pts[$name]['x'], $pts[$name]['y']);
            }
        }
        if (count($poly) >= 2) {
            $list = [];
            foreach ($poly as $pp) {
                $list[] = sprintf('%.1f,%.1f', $pp[0], $pp[1]);
            }
            $tag = count($poly) >= 3 ? 'polygon' : 'polyline';
            $svg .= '<' . $tag . ' points="' . implode(' ', $list) . '" fill="rgba(29,78,216,0.08)" stroke="'
                . self::INK . '" stroke-width="2.4" stroke-linejoin="round"/>';
        }
        foreach ($pts as $name => $p) {
            $pp = $map($p['x'], $p['y']);
            $svg .= self::dot($pp, 4);
            $svg .= self::text($pp[0] + 12, $pp[1] - 11, (string) $name, 16, true);
        }
        return $svg;
    }
}
