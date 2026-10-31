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

use local_ai_yared_course\local\commons_search;

/**
 * Tests for the Wikimedia Commons reference picture search (no network access is used).
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ai_yared_course\local\commons_search
 */
final class commons_search_test extends \advanced_testcase {

    /**
     * Only https pictures served by upload.wikimedia.org are accepted.
     */
    public function test_is_commons_image_url(): void {
        $this->assertTrue(commons_search::is_commons_image_url('https://upload.wikimedia.org/wikipedia/commons/a/a1/X.jpg'));
        $this->assertFalse(commons_search::is_commons_image_url('http://upload.wikimedia.org/a.jpg'));
        $this->assertFalse(commons_search::is_commons_image_url('https://example.org/a.jpg'));
        $this->assertFalse(commons_search::is_commons_image_url('https://upload.wikimedia.org.evil.test/a.jpg'));
        $this->assertFalse(commons_search::is_commons_image_url('https://user@upload.wikimedia.org/a.jpg'));
        $this->assertFalse(commons_search::is_commons_image_url('https://upload.wikimedia.org:8443/a.jpg'));
    }

    /**
     * Candidates need a free licence, a supported type and a Commons picture URL.
     */
    public function test_parse_filters_candidates(): void {
        $page = function (string $title, string $mime, string $license, string $thumb = 'https://upload.wikimedia.org/t.jpg') {
            return [
                'title' => 'File:' . $title . '.jpg',
                'index' => 1,
                'imageinfo' => [[
                    'mime' => $mime,
                    'thumburl' => $thumb,
                    'thumbwidth' => 800,
                    'thumbheight' => 600,
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:' . $title . '.jpg',
                    'extmetadata' => [
                        'LicenseShortName' => ['value' => $license],
                        'Artist' => ['value' => '<a href="#">Autor</a>'],
                    ],
                ]],
            ];
        };
        $data = ['query' => ['pages' => [
            $page('Good', 'image/jpeg', 'CC BY 4.0'),
            $page('Svg', 'image/svg+xml', 'CC BY 4.0'),
            $page('NoLicense', 'image/jpeg', ''),
            $page('FairUse', 'image/jpeg', 'Fair use'),
            $page('Elsewhere', 'image/jpeg', 'CC0', 'https://example.org/t.jpg'),
        ]]];
        $result = commons_search::parse($data, 8);
        $this->assertCount(1, $result);
        $this->assertSame('Good', $result[0]['title']);
        $this->assertSame('Autor', $result[0]['author']);
        $this->assertSame('CC BY 4.0', $result[0]['license']);
    }

    /**
     * The credit line contains author, licence and source.
     */
    public function test_credit(): void {
        $credit = commons_search::credit([
            'author' => 'Autor', 'license' => 'CC BY 4.0', 'licenseurl' => '',
            'page' => 'https://commons.wikimedia.org/wiki/File:X.jpg',
        ]);
        $this->assertStringContainsString('Autor', $credit);
        $this->assertStringContainsString('CC BY 4.0', $credit);
        $this->assertStringContainsString('Wikimedia Commons', $credit);
    }
}
