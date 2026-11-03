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


namespace local_ai_yared_course;

use local_ai_yared_course\local\slides;

/**
 * Tests for the class presentation (slides): cleaning, editing and the viewer markup.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_yared_course\local\slides
 */
final class slides_test extends \advanced_testcase {

    /**
     * An AI answer becomes a clean deck: tags removed, empty slides dropped, layouts checked.
     */
    public function test_from_ai(): void {
        $deck = slides::from_ai([
            'title' => 'Fotosíntesis',
            'slides' => [
                ['layout' => 'title', 'title' => '<b>La fotosíntesis</b>', 'text' => 'Cómo las plantas hacen su alimento'],
                ['layout' => 'raro', 'title' => 'Ideas', 'bullets' => ['- Luz', '2. Agua', '', '• CO2'], 'notes' => 'Explicar'],
                ['layout' => 'bullets', 'title' => '', 'text' => '', 'bullets' => []],
                'no es un arreglo',
            ],
        ], 'Tema', 'es');
        $this->assertNotNull($deck);
        $this->assertCount(2, $deck['slides']);
        $this->assertSame('La fotosíntesis', $deck['slides'][0]['title']);
        $this->assertSame('bullets', $deck['slides'][1]['layout']);
        $this->assertSame(['Luz', 'Agua', 'CO2'], $deck['slides'][1]['bullets']);
        $this->assertSame('es', $deck['language']);
        $this->assertNull(slides::from_ai(['slides' => []], 'Tema', 'es'));
    }

    /**
     * The teacher can remove slides, reorder them and add a blank one; pictures are kept.
     */
    public function test_apply_edits(): void {
        $deck = ['slides' => [
            slides::blank('title'),
            ['image' => ['file' => 'img-1.jpg', 'credit' => 'X', 'alt' => ''], 'title' => 'B'] + slides::blank(),
            ['title' => 'C'] + slides::blank(),
        ]];
        $deck['slides'][0]['title'] = 'A';
        $edited = slides::apply_edits($deck, [
            0 => ['keep' => 1, 'order' => 3, 'title' => 'A2', 'layout' => 'title'],
            1 => ['keep' => 1, 'order' => 1, 'title' => 'B2', 'bullets' => "uno\ndos", 'layout' => 'example'],
            2 => ['title' => 'C2'],
        ]);
        $this->assertCount(2, $edited['slides']);
        $this->assertSame('B2', $edited['slides'][0]['title']);
        $this->assertSame(['uno', 'dos'], $edited['slides'][0]['bullets']);
        $this->assertSame('img-1.jpg', $edited['slides'][0]['image']['file']);
        $this->assertSame('A2', $edited['slides'][1]['title']);

        $added = slides::apply_edits($edited, [], true);
        $this->assertCount(3, $added['slides']);
        $this->assertSame('', $added['slides'][2]['title']);
    }

    /**
     * Text from forms or the AI never keeps HTML.
     */
    public function test_clean(): void {
        $this->assertSame('Hola mundo', slides::clean("<b>Hola</b>\n  mundo ", 50));
        $this->assertSame(10, mb_strlen(slides::clean(str_repeat('ñ', 30), 10)));
        $this->assertSame('', slides::clean(['a'], 10));
        $rules = slides::size_rules(6);
        $this->assertLessThan(slides::size_rules(20)['slides'], $rules['slides']);
    }

    /**
     * The viewer prints every slide, escapes the text and hides nothing in the markup of the notes.
     */
    public function test_render_viewer(): void {
        $deck = ['slides' => [
            ['layout' => 'bullets', 'title' => '<i>T</i>', 'text' => '', 'bullets' => ['a<b'], 'notes' => 'nota "x"',
                'figure_svg' => null, 'image' => null],
            ['layout' => 'summary', 'title' => 'Fin', 'text' => '', 'bullets' => [], 'notes' => '', 'figure_svg' => null,
                'image' => null],
        ]];
        $html = slides::render_viewer($deck);
        $this->assertSame(2, substr_count($html, '<section'));
        $this->assertStringNotContainsString('<i>T</i>', $html);
        $this->assertStringContainsString('a&lt;b', $html);
        $this->assertStringContainsString('1 / 2', $html);
    }
}
