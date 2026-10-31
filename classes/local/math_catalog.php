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
 * Mathematics courses offered in the math question generator, with suggested topics and a writing guide for the AI.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class math_catalog {

    /**
     * Course definitions: key => [name, figures (whether figures are usual), topics, hint for the AI].
     *
     * @return array
     */
    public static function courses(): array {
        return [
            'razonamiento' => [
                'name' => 'Razonamiento Matemático',
                'figures' => false,
                'topics' => ['Sucesiones y series', 'Analogías y distribuciones numéricas', 'Planteo de ecuaciones',
                    'Edades', 'Móviles (distancia, velocidad y tiempo)', 'Operadores matemáticos', 'Fracciones y porcentajes',
                    'Orden de información', 'Conteo de figuras', 'Perímetros y áreas sombreadas'],
                'hint' => 'Contest-style numerical reasoning: short word problems that are solved with logic and arithmetic, '
                    . 'not with memorised formulas. Make the numbers work out exactly.',
            ],
            'aritmetica' => [
                'name' => 'Aritmética',
                'figures' => false,
                'topics' => ['Números naturales y enteros', 'Divisibilidad, MCD y MCM', 'Fracciones y decimales',
                    'Razones y proporciones', 'Regla de tres', 'Porcentajes', 'Promedios', 'Potenciación y radicación',
                    'Sistemas de numeración', 'Interés simple y compuesto'],
                'hint' => 'Arithmetic: exact results, no calculators, whole or simple fractional answers.',
            ],
            'algebra' => [
                'name' => 'Álgebra',
                'figures' => false,
                'topics' => ['Ecuaciones de primer grado', 'Ecuaciones de segundo grado', 'Sistemas de ecuaciones',
                    'Inecuaciones', 'Productos notables', 'Factorización', 'Leyes de exponentes', 'Polinomios',
                    'Funciones', 'Logaritmos', 'Valor absoluto'],
                'hint' => 'Algebra: the solution must be an exact value or expression; show the algebraic steps in the feedback.',
            ],
            'geometria' => [
                'name' => 'Geometría',
                'figures' => true,
                'topics' => ['Segmentos y ángulos', 'Rectas paralelas y secantes', 'Triángulos: propiedades',
                    'Congruencia de triángulos', 'Semejanza de triángulos', 'Teorema de Pitágoras', 'Cuadriláteros',
                    'Polígonos', 'Circunferencia', 'Áreas y perímetros', 'Geometría analítica', 'Sólidos geométricos'],
                'hint' => 'Plane geometry. Most questions depend on a figure: when the "figure" field is available, describe '
                    . 'the figure there with every number and mark the statement needs; otherwise describe the figure in '
                    . 'words inside the statement. The statement must always be solvable.',
            ],
            'trigonometria' => [
                'name' => 'Trigonometría',
                'figures' => true,
                'topics' => ['Razones trigonométricas de un ángulo agudo', 'Triángulos rectángulos notables',
                    'Ángulos de elevación y depresión', 'Identidades trigonométricas', 'Ecuaciones trigonométricas',
                    'Círculo trigonométrico', 'Ley de senos y cosenos', 'Funciones trigonométricas'],
                'hint' => 'Trigonometry. Use exact values of notable angles. When the "figure" field is available, '
                    . 'right-triangle problems should include a figure (a triangle with "legs" or "base_angles").',
            ],
            'estadistica' => [
                'name' => 'Estadística y Probabilidad',
                'figures' => false,
                'topics' => ['Tablas de frecuencia', 'Medidas de tendencia central', 'Medidas de dispersión',
                    'Conteo: permutaciones y combinaciones', 'Probabilidad básica', 'Probabilidad condicional',
                    'Distribución binomial', 'Distribución normal'],
                'hint' => 'Statistics and probability: give the data in the statement; results as exact fractions or '
                    . 'decimals with a fixed number of digits.',
            ],
            'calculo' => [
                'name' => 'Cálculo',
                'figures' => false,
                'topics' => ['Límites', 'Continuidad', 'Derivadas', 'Regla de la cadena', 'Aplicaciones de la derivada',
                    'Integrales indefinidas', 'Integrales definidas', 'Área bajo la curva'],
                'hint' => 'Calculus: closed-form answers that can be checked by hand.',
            ],
            'fisica' => [
                'name' => 'Física (con cálculo)',
                'figures' => true,
                'topics' => ['Cinemática', 'Dinámica', 'Trabajo y energía', 'Estática', 'Movimiento circular'],
                'hint' => 'Physics word problems with numerical answers and units; SI units.',
            ],
            'otro' => [
                'name' => 'Otro curso de matemática',
                'figures' => false,
                'topics' => [],
                'hint' => '',
            ],
        ];
    }

    /**
     * Options for the course selector.
     *
     * @return string[] key => name
     */
    public static function options(): array {
        $options = [];
        foreach (self::courses() as $key => $course) {
            $options[$key] = $course['name'];
        }
        return $options;
    }

    /**
     * Suggested topics of every course, for the page script.
     *
     * @return array key => string[]
     */
    public static function topics_map(): array {
        $map = [];
        foreach (self::courses() as $key => $course) {
            $map[$key] = $course['topics'];
        }
        return $map;
    }

    /**
     * Whether figures are normally needed in a course.
     *
     * @param string $key Course key.
     * @return bool
     */
    public static function uses_figures(string $key): bool {
        return !empty(self::courses()[$key]['figures']);
    }

    /**
     * Instruction line for the AI prompt about the course.
     *
     * @param string $key Course key.
     * @return string Empty when the key is unknown or generic.
     */
    public static function prompt_line(string $key): string {
        $course = self::courses()[$key] ?? null;
        if (!$course || $key === 'otro') {
            return '';
        }
        return 'Course: ' . $course['name'] . '. ' . $course['hint'];
    }

    /**
     * Name of a course.
     *
     * @param string $key Course key.
     * @return string
     */
    public static function name(string $key): string {
        return self::courses()[$key]['name'] ?? '';
    }
}
