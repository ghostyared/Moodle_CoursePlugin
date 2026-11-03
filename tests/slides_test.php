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
 * Tests for the class presentation (slides): cleaning of the AI answer and the slide icon.
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
     * The icon of a slide must be a short symbol, never text or markup.
     */
    public function test_emoji(): void {
        $this->assertSame('📘', slides::clean_emoji(' 📘 '));
        $this->assertSame('', slides::clean_emoji('<b>x</b>'));
        $this->assertSame('', slides::clean_emoji('hola mundo'));
        $this->assertSame('', slides::clean_emoji('12'));
        $deck = slides::from_ai(['slides' => [['title' => 'A', 'emoji' => '💡', 'bullets' => ['x']]]], 'T', 'es');
        $this->assertSame('💡', $deck['slides'][0]['emoji']);
    }

}
