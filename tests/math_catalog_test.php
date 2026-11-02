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

use local_ai_yared_course\local\math_catalog;

/**
 * Tests for the mathematics course catalogue.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_yared_course\local\math_catalog
 */
final class math_catalog_test extends \advanced_testcase {

    /**
     * Every course has a name and the selector lists them all.
     */
    public function test_options(): void {
        $options = math_catalog::options();
        $this->assertArrayHasKey('geometria', $options);
        $this->assertArrayHasKey('otro', $options);
        foreach (math_catalog::courses() as $key => $course) {
            $this->assertNotSame('', math_catalog::name($key));
        }
    }

    /**
     * Figures are expected only in the courses that normally need them.
     */
    public function test_uses_figures(): void {
        $this->assertTrue(math_catalog::uses_figures('geometria'));
        $this->assertTrue(math_catalog::uses_figures('trigonometria'));
        $this->assertFalse(math_catalog::uses_figures('algebra'));
        $this->assertFalse(math_catalog::uses_figures('desconocido'));
    }

    /**
     * The generic course and unknown keys add nothing to the prompt.
     */
    public function test_prompt_line(): void {
        $this->assertSame('', math_catalog::prompt_line('otro'));
        $this->assertSame('', math_catalog::prompt_line('desconocido'));
        $this->assertStringContainsString('Geometría', math_catalog::prompt_line('geometria'));
    }

    /**
     * Mathematics topics are detected from their title and description.
     */
    public function test_detect(): void {
        $this->assertSame('algebra', math_catalog::detect('Ecuaciones de segundo grado'));
        $this->assertSame('trigonometria', math_catalog::detect('Razones trigonométricas de un ángulo agudo'));
        $this->assertSame('geometria', math_catalog::detect('Teorema de Pitágoras y triángulos'));
        $this->assertSame('calculo', math_catalog::detect('Derivadas e integrales'));
        $this->assertSame('otro', math_catalog::detect('Matemáticas básicas'));
        $this->assertSame('', math_catalog::detect('La Revolución Francesa'));
        $this->assertSame('', math_catalog::detect('Partes de la computadora'));
    }

    /**
     * The teacher's choice decides; automatic mode falls back to detection.
     */
    public function test_resolve(): void {
        $this->assertSame('', math_catalog::resolve('general', 'Álgebra'));
        $this->assertSame('algebra', math_catalog::resolve('auto', 'Álgebra lineal'));
        $this->assertSame('', math_catalog::resolve('auto', 'Historia del Perú'));
        $this->assertSame('geometria', math_catalog::resolve('math:geometria', 'Historia del Perú'));
        $this->assertSame('otro', math_catalog::resolve('math', 'Historia del Perú'));
        $this->assertSame('trigonometria', math_catalog::resolve('math', 'Funciones trigonométricas'));
        $this->assertTrue(math_catalog::valid_choice('math:algebra'));
        $this->assertFalse(math_catalog::valid_choice('math:inventado'));
        $this->assertFalse(math_catalog::valid_choice('cualquiera'));
    }
}
