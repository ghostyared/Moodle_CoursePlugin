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

use local_ai_yared_course\local\pptx_export;

/**
 * Tests for the PowerPoint export of the class presentation.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_yared_course\local\pptx_export
 */
final class pptx_export_test extends \advanced_testcase {

    /**
     * The file is a valid package with one slide (and notes) per deck slide, and text is escaped.
     */
    public function test_build(): void {
        $deck = ['title' => 'Tema', 'slides' => [
            ['layout' => 'title', 'title' => 'Hola & <adiós>', 'text' => 'Sub', 'bullets' => [], 'notes' => 'Nota', 'emoji' => '📐'],
            ['layout' => 'bullets', 'title' => 'Ideas', 'text' => '', 'bullets' => ['a', 'b'], 'notes' => '', 'emoji' => ''],
        ]];
        $content = pptx_export::build($deck);
        $tmp = tempnam(sys_get_temp_dir(), 'pt');
        file_put_contents($tmp, $content);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertNotFalse($zip->locateName('ppt/slides/slide1.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide2.xml'));
        $this->assertFalse($zip->locateName('ppt/slides/slide3.xml'));
        $this->assertStringContainsString('Hola &amp; &lt;adiós&gt;', $zip->getFromName('ppt/slides/slide1.xml'));
        $zip->close();
        unlink($tmp);
    }
}
