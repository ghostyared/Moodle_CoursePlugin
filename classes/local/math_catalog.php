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
 * @copyright  2026 GhostYared Corp
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
    /**
     * Keywords (lower case, no accents) that reveal a mathematics course in a topic title or description.
     *
     * @return array key => string[]
     */
    protected static function keywords(): array {
        return [
            'trigonometria' => ['trigonometr', 'seno ', 'coseno', 'tangente', 'razones trigonometricas', 'identidades trigonometricas'],
            'geometria' => ['geometr', 'triangulo', 'circunferencia', 'pitagoras', 'poligono', 'cuadrilatero', 'angulos',
                'teorema de thales', 'semejanza'],
            'calculo' => ['calculo', 'derivada', 'derivadas', 'integral', 'integrales', 'limites', 'continuidad'],
            'algebra' => ['algebra', 'ecuacion', 'ecuaciones', 'polinomio', 'factorizacion', 'inecuacion', 'logaritmo',
                'productos notables', 'sistemas de ecuaciones', 'exponentes', 'radicales', 'funcion cuadratica'],
            'estadistica' => ['estadistica', 'probabilidad', 'combinatoria', 'permutacion', 'desviacion estandar',
                'distribucion normal', 'distribucion binomial'],
            'aritmetica' => ['aritmetica', 'divisibilidad', ' mcd', ' mcm', 'regla de tres', 'porcentaje', 'fracciones',
                'razones y proporciones', 'numeros enteros'],
            'razonamiento' => ['razonamiento matematico', 'razonamiento logico matematico', 'sucesiones', 'planteo de ecuaciones'],
            'fisica' => ['fisica', 'cinematica', 'movimiento rectilineo', 'caida libre', 'leyes de newton'],
            // English.
            'ingles' => ['trigonometry', 'geometry', 'calculus', 'algebra', 'statistics', 'arithmetic', 'derivative', 'equation'],
        ];
    }

    /**
     * Lower case text without accents, for keyword matching.
     *
     * @param string $text Text.
     * @return string
     */
    protected static function fold(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        return ' ' . strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']) . ' ';
    }

    /**
     * Guess the mathematics course of a topic from its title and description.
     *
     * The title counts double. Several courses can match: the one with most hits wins and, on a tie, the more specific
     * one (the order of keywords). A generic mention of "matemática" gives the generic mathematics course.
     *
     * @param string $text Title and description.
     * @return string Course key, or '' when the topic does not look like mathematics.
     */
    public static function detect(string $text): string {
        $folded = self::fold($text);
        $scores = [];
        foreach (self::keywords() as $key => $words) {
            $hits = 0;
            foreach ($words as $word) {
                $hits += substr_count($folded, $word);
            }
            if ($hits > 0) {
                // The English list is folded into the matching Spanish course.
                $target = $key === 'ingles' ? self::english_course($folded) : $key;
                $scores[$target] = ($scores[$target] ?? 0) + $hits;
            }
        }
        if ($scores) {
            arsort($scores);
            return (string) array_key_first($scores);
        }
        if (preg_match('/\b(matematica|matematicas|mathematics|maths?)\b/', $folded)) {
            return 'otro';
        }
        return '';
    }

    /**
     * Course of an English topic.
     *
     * @param string $folded Folded text.
     * @return string
     */
    protected static function english_course(string $folded): string {
        $map = ['trigonometry' => 'trigonometria', 'geometry' => 'geometria', 'calculus' => 'calculo', 'derivative' => 'calculo',
            'statistics' => 'estadistica', 'arithmetic' => 'aritmetica', 'algebra' => 'algebra', 'equation' => 'algebra'];
        foreach ($map as $word => $key) {
            if (strpos($folded, $word) !== false) {
                return $key;
            }
        }
        return 'algebra';
    }

    /**
     * Course to use for a topic given the teacher's choice.
     *
     * @param string $choice 'auto', 'general', 'math' or 'math:<course key>'.
     * @param string $text Topic title and description (used to detect the course).
     * @return string Course key (mathematics mode) or '' (general, theoretical questions).
     */
    public static function resolve(string $choice, string $text): string {
        $choice = trim($choice);
        if ($choice === 'general') {
            return '';
        }
        if (strpos($choice, 'math:') === 0) {
            $key = substr($choice, 5);
            if (isset(self::courses()[$key])) {
                return $key;
            }
        }
        if ($choice === 'math') {
            return self::detect($text) ?: 'otro';
        }
        return self::detect($text);
    }

    /**
     * Whether a value is an allowed "kind of questions" choice.
     *
     * @param string $choice Value.
     * @return bool
     */
    public static function valid_choice(string $choice): bool {
        if (in_array($choice, ['auto', 'general', 'math'], true)) {
            return true;
        }
        return strpos($choice, 'math:') === 0 && isset(self::courses()[substr($choice, 5)]);
    }

    /**
     * Options of the "kind of questions" selector: value => label.
     *
     * @return string[]
     */
    public static function choices(): array {
        $options = [
            'auto' => get_string('qkind_auto', 'local_ai_yared_course'),
            'general' => get_string('qkind_general', 'local_ai_yared_course'),
        ];
        foreach (self::courses() as $key => $course) {
            $options['math:' . $key] = get_string('qkind_math', 'local_ai_yared_course', $course['name']);
        }
        return $options;
    }
}
