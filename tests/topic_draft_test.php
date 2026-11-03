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

use local_ai_yared_course\local\topic_draft;

/**
 * Tests for the edits a teacher makes in the topic preview.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_yared_course\local\topic_draft
 */
final class topic_draft_test extends \advanced_testcase {

    /**
     * A manifest like the one the Material Creator stores.
     *
     * @return array
     */
    protected function manifest(): array {
        return ['in' => ['title' => 'T'], 'parts' => [
            'overview' => ['summary' => 'S', 'objectives' => ['a'], 'forum_question' => 'F',
                'extension' => ['title' => 'E', 'instructions' => 'I']],
            'questions' => [
                ['type' => 'multichoice', 'text' => 'Q1', 'options' => [
                    ['text' => 'x', 'correct' => true], ['text' => 'y', 'correct' => false]]],
                ['type' => 'multichoice', 'text' => 'Q2', 'options' => [
                    ['text' => 'x', 'correct' => true], ['text' => 'y', 'correct' => false]]],
            ],
            'slides' => ['slides' => [
                ['layout' => 'bullets', 'title' => 'A', 'text' => '', 'bullets' => ['uno'], 'notes' => '',
                    'image_query' => '', 'figure_svg' => null, 'image' => null],
                ['layout' => 'bullets', 'title' => 'B', 'text' => '', 'bullets' => [], 'notes' => '',
                    'image_query' => '', 'figure_svg' => null, 'image' => null],
            ]],
        ]];
    }

    /**
     * Texts, questions and slides are edited; unticked questions and slides are removed.
     */
    public function test_apply_edits(): void {
        $edited = topic_draft::apply_edits($this->manifest(), [
            'summary' => '  Nuevo <b>resumen</b> ',
            'objectives' => "uno\n\ndos",
            'q' => [
                0 => ['include' => 1, 'text' => 'Nueva', 'options' => ['x2', 'y2'], 'correct' => 1],
                1 => ['text' => 'sin marcar'],
            ],
            's' => [
                0 => ['keep' => 1, 'order' => 2, 'title' => 'A2', 'bullets' => "p\nq"],
                1 => ['keep' => 1, 'order' => 1, 'title' => 'B2'],
            ],
        ]);
        $parts = $edited['parts'];
        $this->assertSame('Nuevo resumen', $parts['overview']['summary']);
        $this->assertSame(['uno', 'dos'], $parts['overview']['objectives']);
        $this->assertCount(1, $parts['questions']);
        $this->assertSame('Nueva', $parts['questions'][0]['text']);
        $this->assertTrue($parts['questions'][0]['options'][1]['correct']);
        $this->assertSame('B2', $parts['slides']['slides'][0]['title']);
        $this->assertSame(['p', 'q'], $parts['slides']['slides'][1]['bullets']);
    }

    /**
     * Without edits for the slides, they stay as they were.
     */
    public function test_slides_untouched_without_post(): void {
        $edited = topic_draft::apply_edits($this->manifest(), ['summary' => 'S2']);
        $this->assertCount(2, $edited['parts']['slides']['slides']);
        $this->assertCount(2, $edited['parts']['questions']);
    }
}
