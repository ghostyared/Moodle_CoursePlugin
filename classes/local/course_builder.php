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

use local_ai_yared_course\local\ai_client;
use local_ai_yared_course\local\category_helper;
use local_ai_yared_course\local\question_generator;
use local_ai_yared_course\local\question_saver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Generates the content of a topic with AI and creates the section and activities in the course.
 *
 * Created structure (in one section): header, support video, class PDF, AI question bank, graded practice
 * quiz and extension activity. Anything the teacher still has to finish is created hidden.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_builder {

    /** Value of the "section" option that means "create a new section". */
    public const NEWSECTION = -1;

    /** @var \stdClass */
    protected $course;
    /** @var \context_course */
    protected $context;
    /** @var ai_client */
    protected $client;
    /** @var \progress_trace */
    protected $trace;
    /** @var string[] */
    protected $warnings = [];
    /** @var array[] */
    protected $items = [];
    /** @var int */
    protected $sectionnum = 0;
    /** @var int Section number to link to in the report (the parent when a subsection is used). */
    protected $linknum = -1;
    /** @var bool Whether the topic was created as a subsection. */
    protected $usedsubsection = false;

    /**
     * Constructor.
     *
     * @param \stdClass $course Course record.
     * @param ai_client $client AI client.
     * @param \progress_trace|null $trace Progress output.
     */
    public function __construct(\stdClass $course, ai_client $client, ?\progress_trace $trace = null) {
        $this->course = $course;
        $this->context = \context_course::instance($course->id);
        $this->client = $client;
        $this->trace = $trace ?? new \null_progress_trace();
    }

    /**
     * Generate everything and create it in the course.
     *
     * @param array $in title, description, age, language (code), languagename, sectionnum, findvideo, questioncount,
     *     extensiontype (assign|quiz), hidesection.
     * @return array sectionnum, sectionid, newsection, items, warnings, video, questions, categoryid,
     *     provider, providername, model, profile.
     * @throws \moodle_exception If the main content could not be generated.
     */
    public function create_topic(array $in): array {
        $in += ['findvideo' => 1, 'questioncount' => 0, 'extensiontype' => 'assign', 'hidesection' => 1,
            'sectionnum' => self::NEWSECTION, 'quizopen' => strtotime('today'), 'quizclosedays' => 7,
            'quiztimelimit' => 60, 'quizattempts' => 2, 'quizgrade' => 20, 'quizquestions' => 0,
            'quizvisible' => 0, 'classdate' => 0,
            'createbank' => 1, 'createquiz' => 1, 'createassign' => 1, 'createpdf' => 1, 'createforum' => 1];
        // Keep fallback warnings for this topic only.
        $this->client->clear_fallback_messages();
        // The graded practice is always tied to the question bank: without a bank, or without questions,
        // there is nothing to link.
        if (empty($in['createbank']) || (int) $in['questioncount'] <= 0) {
            $in['createquiz'] = 0;
        }
        $title = $in['title'];
        $description = $in['description'];
        $age = (int) $in['age'];
        $lang = $in['language'];
        $langname = $in['languagename'];

        // 1. Overview (header, practice, extension). Without it nothing can be created.
        $this->log(get_string('step_content', 'local_ai_yared_course'));
        $generator = new topic_generator($this->client);
        $overview = $generator->generate_overview($title, $description, $age, $langname);

        // 2. Class handout (PDF).
        $this->log(get_string('step_pdf', 'local_ai_yared_course'));
        $pdf = null;
        $createpdf = !empty($in['createpdf']);
        if ($createpdf) {
            try {
                $content = $generator->generate_pdf($title, $description, $overview, $age, $langname);
                $pdf = pdf_builder::build($title, $overview, $content, $age, $lang);
            } catch (job_cancelled $e) {
                throw $e;
            } catch (\Throwable $e) {
                // The full handout failed (for example, because the AI answer was cut off). Build a shorter
                // PDF with the overview that was already generated, so the student never gets an empty file.
                try {
                    $pdf = pdf_builder::build_from_overview($title, $overview, $age, $lang);
                    $this->warnings[] = get_string('warn_pdf_overview', 'local_ai_yared_course', s($e->getMessage()));
                } catch (\Throwable $fallback) {
                    $this->warnings[] = get_string('warn_pdf', 'local_ai_yared_course', s($e->getMessage()));
                }
            }
        }

        // 3. Support video.
        $video = null;
        if (!empty($in['findvideo'])) {
            $this->log(get_string('step_video', 'local_ai_yared_course'));
            $finder = new youtube_finder();
            if (!$finder->is_configured()) {
                $this->warnings[] = get_string('warn_videokey', 'local_ai_yared_course');
            } else {
                $video = $finder->find($overview['video_query'], $lang, $age);
                if (!$video) {
                    $detail = $finder->get_error() !== '' ? ' (' . s($finder->get_error()) . ')' : '';
                    $this->warnings[] = get_string('warn_videonone', 'local_ai_yared_course') . $detail;
                }
            }
        }

        // 4. Section and activities.
        $this->log(get_string('step_build', 'local_ai_yared_course'));
        $newsection = ((int) $in['sectionnum'] === self::NEWSECTION);
        $section = $this->prepare_section($in);

        $hasvideo = $video !== null;
        $haspdf = $pdf !== null;
        $this->build_header($title, $overview, $age, $lang, (int) $in['classdate']);
        $this->build_video($title, $video, $age, $lang);
        if ($createpdf) {
            $this->build_material($title, $pdf, $age, $lang);
        }

        // 4a. Questions for the bank (optional). They are created before the practice quiz so they can be linked
        // to it (the quiz would otherwise be empty).
        $created = 0;
        $questionids = [];
        $categoryid = 0;
        if (!empty($in['createbank']) && (int) $in['questioncount'] > 0) {
            $this->log(get_string('step_questions', 'local_ai_yared_course'));
            $generated = $this->generate_questions($title, $description, (int) $in['questioncount'], $age, $langname,
                (string) ($in['qkind'] ?? 'auto'));
            $created = $generated['created'];
            $questionids = $generated['ids'];
            $categoryid = $generated['categoryid'];
        }

        // 4b. Practice block: bank link, graded quiz (with the questions) and extension activity.
        $this->build_practice($title, $overview, $in['extensiontype'], $age, $lang, $questionids, $in, $categoryid);

        // 4c. Discussion forum (optional), graded over 20 points.
        if (!empty($in['createforum'])) {
            $this->build_forum($title, $overview, $age, $lang);
        }

        // 4d. In an existing section many topics pile up: keep a clickable index at the top of the section.
        if (!$newsection && !$this->usedsubsection) {
            $this->try_add('toc', function () use ($lang) {
                $this->rebuild_toc($lang);
            });
        }

        // 5. Hide the new section so the teacher can review before publishing.
        if ($newsection && !empty($in['hidesection'])) {
            if (has_capability('moodle/course:sectionvisibility', $this->context)) {
                course_update_section($this->course, $section, ['visible' => 0]);
            } else {
                $this->warnings[] = get_string('warn_cannothide', 'local_ai_yared_course');
            }
        }

        // Tell the teacher when the AI provider changed during this topic.
        $fallbacks = $this->client->get_fallback_messages();
        if ($fallbacks) {
            $this->warnings[] = get_string('warn_fallback', 'local_ai_yared_course', s(implode(' | ', $fallbacks)));
        }

        return [
            'sectionnum' => $this->linknum >= 0 ? $this->linknum : $this->sectionnum,
            'sectionid' => (int) $section->id,
            'subsection' => $this->usedsubsection,
            'newsection' => $newsection,
            'hidden' => $newsection && !empty($in['hidesection']),
            'items' => $this->items,
            'warnings' => $this->warnings,
            'video' => $video,
            'haspdf' => $haspdf,
            'hasvideo' => $hasvideo,
            'questions' => $created,
            'categoryid' => $categoryid,
            'provider' => $this->client->get_provider(),
            'providername' => $this->client->get_provider_name(),
            'model' => $this->client->get_model(),
            'profile' => $this->client->get_active_profile_label(),
        ];
    }

    /**
     * Create the section (or subsection) of a topic with only its header, without calling the AI.
     *
     * Used by the external API when the CRM sends the syllabus but does not ask for generated content:
     * the teacher completes the topic later (or runs the material creator on it).
     *
     * @param array $in title, description (summary), age, language, sectionnum, usesubsection, subsectionmode,
     *     classdate, hidesection.
     * @return array Same keys as create_topic() that make sense without AI.
     */
    public function create_empty_topic(array $in): array {
        $in += ['sectionnum' => self::NEWSECTION, 'classdate' => 0, 'hidesection' => 1, 'age' => 12,
            'language' => 'es', 'description' => ''];
        $lang = (string) $in['language'];
        $age = (int) $in['age'];
        $title = (string) $in['title'];

        $newsection = ((int) $in['sectionnum'] === self::NEWSECTION);
        $section = $this->prepare_section($in);
        $summary = trim(strip_tags((string) $in['description']));
        $this->build_header($title, ['summary' => $summary === '' ? $title : $summary],
            $age, $lang, (int) $in['classdate']);

        if ($newsection && !empty($in['hidesection']) && has_capability('moodle/course:sectionvisibility', $this->context)) {
            course_update_section($this->course, $section, ['visible' => 0]);
        }

        return [
            'sectionnum' => $this->linknum >= 0 ? $this->linknum : $this->sectionnum,
            'sectionid' => (int) $section->id,
            'subsection' => $this->usedsubsection,
            'newsection' => $newsection,
            'hidden' => $newsection && !empty($in['hidesection']),
            'items' => $this->items,
            'warnings' => $this->warnings,
            'questions' => 0,
            'categoryid' => 0,
            'provider' => '',
            'providername' => '',
            'model' => '',
            'profile' => '',
        ];
    }

    /**
     * Create the new section, or load the chosen one.
     *
     * @param array $in Input.
     * @return \stdClass Section record.
     */
    protected function prepare_section(array $in): \stdClass {
        global $DB;
        $this->usedsubsection = false;
        $this->linknum = -1;

        if ((int) $in['sectionnum'] === self::NEWSECTION) {
            $section = course_create_section($this->course);
            course_update_section($this->course, $section, ['name' => \core_text::substr($in['title'], 0, 255)]);
        } else {
            $section = $DB->get_record('course_sections',
                ['course' => $this->course->id, 'section' => (int) $in['sectionnum']], '*', MUST_EXIST);
            $kind = !empty($in['usesubsection']) ? self::subsection_support($this->course) : '';
            // Section 0 (General) has no children: the topic is added at the end with the index.
            if ($kind !== '' && (int) $section->section > 0) {
                try {
                    $section = $this->create_subsection($kind, $section, $in);
                    $this->usedsubsection = true;
                } catch (\Throwable $e) {
                    // Without subsection the topic goes to the end of the chosen section (and gets the index).
                    $this->warnings[] = get_string('warn_subsection', 'local_ai_yared_course', s($e->getMessage()));
                }
            }
        }
        $this->sectionnum = (int) $section->section;
        return $section;
    }

    /**
     * Kind of subsections this site and course can use.
     *
     * @param \stdClass $course Course.
     * @return string 'moodle5' (core subsection activity), 'flextiles' (FlexTiles course format),
     *     'flexsections' (Flexsections course format) or ''.
     */
    public static function subsection_support(\stdClass $course): string {
        global $CFG;
        if ((string) $course->format === 'flextiles'
                && \core_component::get_plugin_directory('format', 'flextiles') !== null) {
            return 'flextiles';
        }
        if ((string) $course->format === 'flexsections'
                && \core_component::get_plugin_directory('format', 'flexsections') !== null) {
            return 'flexsections';
        }
        if ((int) $CFG->branch >= 500 && \core_component::get_plugin_directory('mod', 'subsection') !== null) {
            $format = course_get_format($course);
            if (method_exists($format, 'supports_components') && $format->supports_components()) {
                return 'moodle5';
            }
        }
        return '';
    }

    /**
     * Create a subsection for the topic inside a parent section.
     *
     * FlexTiles subsections use the accordion display by default. Flexsections
     * keeps its collapsed (link to its own page) behaviour.
     *
     * @param string $kind moodle5|flextiles|flexsections.
     * @param \stdClass $parent Parent section record.
     * @param array $in Input (title, classdate).
     * @return \stdClass Section record where the activities of the topic go.
     */
    protected function create_subsection(string $kind, \stdClass $parent, array $in): \stdClass {
        global $DB;

        $name = \core_text::substr((string) $in['title'], 0, 240);
        if ((int) ($in['classdate'] ?? 0) > 0) {
            $name .= ' — ' . userdate((int) $in['classdate'], '%d-%m-%Y');
        }

        if ($kind === 'flextiles' || $kind === 'flexsections') {
            $format = course_get_format($this->course);
            $formatname = (string) $this->course->format;
            $number = (int) $format->create_new_section((int) $parent->section);
            $child = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => $number],
                '*', MUST_EXIST);
            course_update_section($this->course, $child, ['name' => $name]);

            if ($kind === 'flextiles') {
                // Formato-Mariategui: the display mode of the subsection is mandatory.
                // Accordion renders the topic inside the parent section; mosaic shows a
                // tile that opens the subsection on its own page.
                $mode = strtolower(trim((string) ($in['subsectionmode'] ?? '')));
                if (!in_array($mode, ['accordion', 'mosaic'], true)) {
                    $mode = 'accordion';
                }
                $format->update_section_format_options(['id' => $child->id, 'displaymode' => $mode]);
                $this->linknum = $mode === 'accordion' ? (int) $parent->section : $number;
            } else {
                // Collapsed: the parent only shows the title as a link and the topic opens on its own page.
                $format->update_section_format_options(['id' => $child->id, 'collapsed' => 1]);
                $this->linknum = $number;
            }

            $actual = $DB->get_field('course_format_options', 'value', ['courseid' => $this->course->id,
                'format' => $formatname, 'sectionid' => $child->id, 'name' => 'parent']);
            if ((int) $actual !== (int) $parent->section) {
                // Do not leave an orphan section at the root when the format could not
                // place the new section inside the chosen parent.
                try {
                    course_delete_section($this->course, $child, true);
                } catch (\Throwable $cleanuperror) {
                    // The original error is more useful; keep it below.
                }
                throw new \moodle_exception('warn_subsection_parent', 'local_ai_yared_course');
            }
            return $DB->get_record('course_sections', ['id' => $child->id], '*', MUST_EXIST);
        }

        // Moodle 5: a subsection activity in the parent section; its delegated section holds the topic.
        $this->sectionnum = (int) $parent->section;
        $created = $this->add_module('subsection', $name, '', true);
        array_pop($this->items);
        $delegated = $DB->get_record('course_sections', ['course' => $this->course->id,
            'component' => 'mod_subsection', 'itemid' => (int) $created->instance], '*', MUST_EXIST);
        $this->linknum = (int) $parent->section;
        return $delegated;
    }

    /**
     * Header block.
     *
     * @param string $title Topic title.
     * @param array $overview Overview.
     * @param int $age Age.
     * @param string $lang Content language.
     * @param int $date Class date (timestamp), 0 to hide it.
     */
    protected function build_header(string $title, array $overview, int $age, string $lang, int $date = 0): void {
        $this->try_add('header', function () use ($title, $overview, $age, $lang, $date) {
            $this->add_module('label', '', html_builder::header($title, $overview, $age, $lang, $date), true);
        });
    }

    /**
     * Support video block: heading and YouTube link (hidden placeholder if no video was found).
     *
     * @param string $title Topic title.
     * @param array|null $video Video data or null.
     * @param int $age Age.
     * @param string $lang Content language.
     */
    protected function build_video(string $title, ?array $video, int $age, string $lang): void {
        $found = $video !== null;
        $this->try_add('video', function () use ($title, $video, $found, $age, $lang) {
            $this->add_module('label', '', html_builder::block_heading(
                strings::get('heading_video', $lang), strings::get('video_subtitle', $lang), $age), $found);

            if ($found) {
                $name = strings::get('video_name', $lang, $title);
                $intro = html_builder::paragraph($video['title'] . ($video['channel'] !== '' ? ' — ' . $video['channel'] : ''));
                $extra = ['externalurl' => $video['url'], 'display' => RESOURCELIB_DISPLAY_EMBED, 'printintro' => 1];
            } else {
                $name = strings::get('video_placeholder_name', $lang);
                $intro = html_builder::paragraph(strings::get('video_placeholder_intro', $lang));
                $extra = ['externalurl' => 'https://www.youtube.com/', 'display' => RESOURCELIB_DISPLAY_AUTO,
                    'printintro' => 1];
            }
            $this->add_module('url', $name, $intro, $found, $extra + ['showdescription' => 1]);
        });
    }

    /**
     * Learning material block: heading and PDF (hidden placeholder file if the PDF could not be made).
     *
     * @param string $title Topic title.
     * @param string|null $pdf PDF content or null.
     * @param int $age Age.
     * @param string $lang Content language.
     */
    protected function build_material(string $title, ?string $pdf, int $age, string $lang): void {
        $found = $pdf !== null;
        $this->try_add('pdf', function () use ($title, $pdf, $found, $age, $lang) {
            global $USER;

            $this->add_module('label', '', html_builder::block_heading(
                strings::get('heading_material', $lang), strings::get('material_subtitle', $lang), $age), $found);

            if (!$found) {
                $pdf = pdf_builder::placeholder($title, $lang);
            }
            $filename = clean_filename(\core_text::substr($title, 0, 80)) . '.pdf';

            $draftid = file_get_unused_draft_itemid();
            get_file_storage()->create_file_from_string([
                'contextid' => \context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => $filename,
            ], $pdf);

            $name = $found ? strings::get('pdf_name', $lang, $title) : strings::get('pdf_placeholder_name', $lang);
            $intro = html_builder::paragraph($found ? strings::get('pdf_intro', $lang)
                : strings::get('pdf_placeholder_intro', $lang));
            $this->add_module('resource', $name, $intro, $found, [
                'files' => $draftid,
                'display' => RESOURCELIB_DISPLAY_AUTO,
                'printintro' => 1,
                'showsize' => 1,
                'showtype' => 1,
                'showdate' => 0,
                'filterfiles' => 0,
                'showdescription' => 1,
            ]);
        });
    }

    /**
     * Practice block: bank link and graded quiz (hidden for the teacher to finish) and the extension task
     * (visible, because it already contains the instructions and accepts file submissions).
     *
     * @param string $title Topic title.
     * @param array $overview Overview.
     * @param string $extensiontype assign|quiz.
     * @param int $age Age.
     * @param string $lang Content language.
     * @param int[] $questionids Question ids to link into the graded quiz.
     * @param array $in Input (quiz settings).
     * @param int $categoryid Category of the generated questions (0 if none).
     */
    protected function build_practice(string $title, array $overview, string $extensiontype, int $age, string $lang,
            array $questionids = [], array $in = [], int $categoryid = 0): void {
        $createbank = !empty($in['createbank']);
        $createquiz = !empty($in['createquiz']);
        $createassign = !empty($in['createassign']);

        if ($createbank || $createquiz) {
            $this->try_add('practice', function () use ($overview, $age, $lang, $in) {
                $subtitle = $overview['practice_intro'] !== '' ? $overview['practice_intro']
                    : strings::get('practice_subtitle', $lang);
                $this->add_module('label', '', html_builder::block_heading(
                    strings::get('heading_practice', $lang), $subtitle, $age), !empty($in['quizvisible']));
            });
        }

        // Link to the course question bank, filtered by this topic's category when it has questions.
        if ($createbank) {
            $this->try_add('bank', function () use ($title, $lang, $categoryid) {
                $params = ['courseid' => $this->course->id];
                if ($categoryid > 0) {
                    $params['cat'] = $categoryid . ',' . $this->context->id;
                }
                $bankurl = new \moodle_url('/question/edit.php', $params);
                $this->add_module('url', strings::get('bank_name', $lang, $title),
                    html_builder::paragraph(strings::get('bank_intro', $lang)), false, [
                        'externalurl' => $bankurl->out(false),
                        'display' => RESOURCELIB_DISPLAY_OPEN,
                        'printintro' => 1,
                        'showdescription' => 1,
                    ]);
            });
        }

        if ($createquiz) {
            $this->try_add('quiz', function () use ($title, $lang, $questionids, $in) {
                $open = max(0, (int) ($in['quizopen'] ?? 0));
                $closedays = max(0, (int) ($in['quizclosedays'] ?? 7));
                $this->add_quiz(strings::get('quiz_name', $lang, $title),
                    html_builder::paragraph(strings::get('quiz_intro', $lang)), $questionids, [
                        'timeopen' => $open,
                        'timeclose' => $open > 0 ? $open + $closedays * DAYSECS : 0,
                        'timelimit' => ((int) ($in['quiztimelimit'] ?? 60)) * MINSECS,
                        'attempts' => (int) ($in['quizattempts'] ?? 2),
                        'grade' => (int) ($in['quizgrade'] ?? 20),
                        'visible' => !empty($in['quizvisible']),
                        'questions' => (int) ($in['quizquestions'] ?? 0),
                    ]);
            });
        }

        if ($createassign) {
            // Extension practice: a visible research assignment where the student uploads a PDF, PPTX or DOCX.
            $this->try_add('extensionheading', function () use ($overview, $extensiontype, $age, $lang) {
                $ext = $overview['extension'];
                $subtitle = $ext['title'] !== '' ? $ext['title'] : strings::get('extension_subtitle', $lang);
                $this->add_module('label', '', html_builder::block_heading(
                    strings::get('heading_extension', $lang), $subtitle, $age), $extensiontype !== 'quiz');
            });

            $this->try_add('extension', function () use ($title, $overview, $extensiontype, $lang, $in) {
                $ext = $overview['extension'];
                $name = strings::get('extension_name', $lang, $ext['title'] !== '' ? $ext['title'] : $title);
                $intro = html_builder::extension_intro($ext);
                if ($intro === '') {
                    $intro = html_builder::paragraph(strings::get('extension_default', $lang));
                }
                if ($extensiontype === 'quiz') {
                    $this->add_quiz($name, $intro);
                } else {
                    // The extension is a real task (not a placeholder), so it is visible as soon as the
                    // teacher publishes the section. It accepts PDF, PPT, PPTX, DOC and DOCX files.
                    $open = max(0, (int) ($in['quizopen'] ?? 0));
                    $closedays = max(0, (int) ($in['quizclosedays'] ?? 7));
                    $this->add_assign($name, $intro . html_builder::paragraph(
                            strings::get('extension_upload_note', $lang)), [
                        'allowsubmissionsfromdate' => $open,
                        'duedate' => $open > 0 ? $open + $closedays * DAYSECS : 0,
                    ]);
                }
            });
        }
    }

    /**
     * Discussion forum with a question suited to the age, graded by points over 20, plus the opening post.
     *
     * @param string $title Topic title.
     * @param array $overview Overview (uses forum_question).
     * @param int $age Age.
     * @param string $lang Content language.
     */
    protected function build_forum(string $title, array $overview, int $age, string $lang): void {
        $question = trim((string) ($overview['forum_question'] ?? ''));
        if ($question === '') {
            $question = strings::get('forum_default_question', $lang, $title);
        }

        $this->try_add('forumheading', function () use ($title, $age, $lang) {
            $this->add_module('label', '', html_builder::block_heading(
                strings::get('heading_forum', $lang), strings::get('forum_subtitle', $lang), $age), true);
        });

        $this->try_add('forum', function () use ($title, $question, $lang) {
            $intro = html_builder::paragraph($question) . html_builder::paragraph(strings::get('forum_note', $lang));
            $created = $this->add_module('forum', strings::get('forum_name', $lang, $title), $intro, true, [
                'type' => 'general', 'forcesubscribe' => 0, 'trackingtype' => 1, 'maxbytes' => 0,
                'maxattachments' => 1, 'displaywordcount' => 0, 'blockperiod' => 0, 'blockafter' => 0,
                'warnafter' => 0, 'lockdiscussionafter' => 0, 'rsstype' => 0, 'rssarticles' => 0,
                'assessed' => 0, 'scale' => 0, 'duedate' => 0, 'cutoffdate' => 0,
                // Whole forum grading: Point type, maximum grade 20.
                'grade_forum' => 20, 'grade_forum_notify' => 0, 'showdescription' => 1,
            ]);
            $this->post_forum_question((int) $created->instance, strings::get('forum_discussion_name', $lang, $title),
                $question);
        });
    }

    /**
     * Post the question as the first discussion so students can reply right away.
     *
     * If it cannot be posted, the question is still visible in the forum description.
     *
     * @param int $forumid Forum instance id.
     * @param string $name Discussion subject.
     * @param string $question Question text.
     */
    protected function post_forum_question(int $forumid, string $name, string $question): void {
        global $CFG, $DB;
        try {
            require_once($CFG->dirroot . '/mod/forum/lib.php');
            $forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
            $discussion = new \stdClass();
            $discussion->course = $this->course->id;
            $discussion->forum = $forum->id;
            $discussion->name = \core_text::substr($name, 0, 250);
            $discussion->message = html_builder::paragraph($question);
            $discussion->messageformat = FORMAT_HTML;
            $discussion->messagetrust = 1;
            $discussion->attachments = null;
            $discussion->mailnow = 0;
            $discussion->groupid = -1;
            forum_add_discussion($discussion);
        } catch (\Throwable $e) {
            $this->warnings[] = get_string('warn_forumpost', 'local_ai_yared_course', s($e->getMessage()));
        }
    }

    /**
     * Create a quiz, optionally with a set of questions, using sensible defaults and graded over 20.
     *
     * @param string $name Quiz name.
     * @param string $intro Description HTML.
     * @param int[] $questionids Question ids to add to the quiz (in order). Empty for a quiz without questions.
     * @param array $settings timeopen, timeclose (timestamps), timelimit (seconds), attempts (0 = unlimited),
     *     grade, visible, questions (max questions to add, 0 = all).
     */
    protected function add_quiz(string $name, string $intro, array $questionids = [], array $settings = []): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $settings += ['timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'attempts' => 0,
            'grade' => 20, 'visible' => false, 'questions' => 0];

        $fields = [
            'timeopen' => max(0, (int) $settings['timeopen']),
            'timeclose' => max(0, (int) $settings['timeclose']),
            'preferredbehaviour' => 'deferredfeedback', 'attempts' => max(0, (int) $settings['attempts']),
            'attemptonlast' => 0, 'grademethod' => QUIZ_GRADEHIGHEST, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
            'questionsperpage' => 0, 'shuffleanswers' => 1, 'sumgrades' => 0,
            'grade' => max(1, (int) $settings['grade']), 'timelimit' => max(0, (int) $settings['timelimit']),
            'overduehandling' => 'autosubmit', 'graceperiod' => 86400, 'quizpassword' => '', 'subnet' => '',
            'browsersecurity' => '', 'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0,
            'navmethod' => QUIZ_NAVMETHOD_FREE, 'showdescription' => 1,
        ];
        // Review options: what the student sees during, right after, later and after closing.
        foreach (['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer',
                'overallfeedback'] as $option) {
            $fields[$option . 'during'] = ($option === 'overallfeedback') ? 0 : 1;
            $fields[$option . 'immediately'] = 1;
            $fields[$option . 'open'] = 1;
            $fields[$option . 'closed'] = 1;
        }
        $created = $this->add_module('quiz', $name, $intro, !empty($settings['visible']), $fields);

        if (!$questionids) {
            return;
        }

        // Link the generated questions to the quiz and spread the grade evenly among them.
        $quiz = $DB->get_record('quiz', ['id' => $created->instance], '*', MUST_EXIST);
        $wanted = (int) $settings['questions'];
        if ($wanted > 0) {
            $questionids = array_slice($questionids, 0, $wanted);
        }
        $mark = round($quiz->grade / max(1, count($questionids)), 5);
        foreach ($questionids as $questionid) {
            quiz_add_quiz_question((int) $questionid, $quiz, 0, $mark);
        }

        // All the questions on one page (New page: "Never, all questions on one page").
        if ($DB->count_records_sql('SELECT COUNT(DISTINCT page) FROM {quiz_slots} WHERE quizid = ?', [$quiz->id]) > 1) {
            $DB->set_field('quiz_slots', 'page', 1, ['quizid' => $quiz->id]);
        }

        // Recompute the quiz total (sumgrades) so the gradebook stays consistent (Moodle 4.5 API).
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    }

    /**
     * Create an assignment with online text and file submission, graded over 20.
     *
     * @param string $name Assignment name.
     * @param string $intro Description HTML.
     * @param array $settings visible, allowsubmissionsfromdate, duedate (timestamps), filetypes (comma separated).
     */
    protected function add_assign(string $name, string $intro, array $settings = []): void {
        $settings += ['visible' => true, 'allowsubmissionsfromdate' => 0, 'duedate' => 0,
            'filetypes' => 'pdf,ppt,pptx,doc,docx'];

        $this->add_module('assign', $name, $intro, !empty($settings['visible']), [
            'alwaysshowdescription' => 1, 'submissiondrafts' => 1, 'requiresubmissionstatement' => 0,
            'sendnotifications' => 0, 'sendstudentnotifications' => 1, 'sendlatenotifications' => 0,
            'duedate' => max(0, (int) $settings['duedate']),
            'allowsubmissionsfromdate' => max(0, (int) $settings['allowsubmissionsfromdate']),
            'grade' => 20, 'cutoffdate' => 0, 'gradingduedate' => 0,
            'teamsubmission' => 0, 'requireallteammemberssubmit' => 0, 'teamsubmissiongroupingid' => 0,
            'blindmarking' => 0, 'attemptreopenmethod' => 'untilpass', 'maxattempts' => 1, 'markingworkflow' => 0,
            'markingallocation' => 0, 'markinganonymous' => 0, 'activityformat' => 0, 'timelimit' => 0,
            'submissionattachments' => 0, 'showdescription' => 1,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 0,
            'assignsubmission_onlinetext_wordlimit_enabled' => 0,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 3,
            'assignsubmission_file_maxsizebytes' => (int) get_config('assignsubmission_file', 'maxbytes'),
            'assignsubmission_file_filetypes' => (string) $settings['filetypes'],
            'assignfeedback_comments_enabled' => 1,
            'assignfeedback_comments_commentinline' => 0,
        ]);
    }

    /**
     * Generate questions with the AI and save them in a bank category named after the topic.
     *
     * @param string $title Topic title.
     * @param string $description Topic description.
     * @param int $count Number of questions.
     * @param int $age Age.
     * @param string $langname Language name.
     * @param string $qkind Kind of questions: auto (mathematics is detected from the topic), general, math or math:<course>.
     * @return array ['created' => int, 'ids' => int[], 'categoryid' => int] Questions saved, their ids and the category.
     */
    protected function generate_questions(string $title, string $description, int $count, int $age, string $langname,
            string $qkind = 'auto'): array {
        global $DB;

        try {
            $topic = $title . ($description !== '' ? ' — ' . \core_text::substr($description, 0, 300) : '');
            // Mathematics topics (algebra, trigonometry...) get exercises with LaTeX and figures drawn by the plugin;
            // any other topic keeps the general, theoretical questions.
            $mathcourse = math_catalog::resolve($qkind, $title . ' ' . \core_text::substr($description, 0, 300));
            $mathmode = $mathcourse !== '';
            $instructions = $mathmode ? math_catalog::prompt_line($mathcourse) : '';
            if ($mathmode) {
                $this->log(get_string('step_questions_math', 'local_ai_yared_course', math_catalog::name($mathcourse)));
            }
            $generator = new question_generator($this->client, $this->client->get_batchsize(), $mathmode, $mathmode);
            $questions = $generator->generate([$topic], [], $count, $age, 'multichoice', $langname, $instructions);
            foreach ($generator->get_warnings() as $warning) {
                $this->warnings[] = $warning;
            }

            $name = \core_text::substr($title, 0, 255);
            $top = question_get_top_category($this->context->id, true);
            $existing = $DB->get_records('question_categories',
                ['contextid' => $this->context->id, 'parent' => $top->id, 'name' => $name], 'id ASC', '*', 0, 1);
            $category = $existing ? reset($existing) : category_helper::create($this->context, $name);

            $result = question_saver::save($questions, $category, false);
            if ($result['failed'] > 0) {
                $this->warnings[] = get_string('warn_questionsfailed', 'local_ai_yared_course', $result['failed']);
            }
            if ($mathmode && $result['created'] > 0 && !question_saver::math_filter_active($this->context)) {
                $this->warnings[] = get_string('warn_mathjax', 'local_ai_yared_course');
            }
            if ($result['created'] < $count) {
                $this->warnings[] = get_string('warn_questionsfewer', 'local_ai_yared_course',
                    (object) ['created' => $result['created'], 'requested' => $count]);
            }
            return ['created' => (int) $result['created'], 'ids' => array_map('intval', $result['ids']),
                'categoryid' => (int) $category->id];
        } catch (job_cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->warnings[] = get_string('warn_questions', 'local_ai_yared_course', s($e->getMessage()));
            return ['created' => 0, 'ids' => [], 'categoryid' => 0];
        }
    }

    /**
     * Add one module to the section.
     *
     * @param string $modname Module name (label, url, resource, quiz, assign).
     * @param string $name Activity name ('' for labels: it is derived from the text).
     * @param string $intro Description (HTML).
     * @param bool $visible Whether students can see it once the section is shown.
     * @param array $fields Extra module fields.
     * @return \stdClass Module info returned by add_moduleinfo().
     */
    protected function add_module(string $modname, string $name, string $intro, bool $visible, array $fields = []) {
        global $DB;

        $info = (object) ($fields + [
            'modulename' => $modname,
            'module' => $DB->get_field('modules', 'id', ['name' => $modname], MUST_EXIST),
            'course' => $this->course->id,
            'section' => $this->sectionnum,
            'name' => \core_text::substr($name, 0, 255),
            'intro' => $intro,
            'introformat' => FORMAT_HTML,
            'visible' => $visible ? 1 : 0,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'availability' => null,
            'completion' => 0,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
            'conditiongradegroup' => [],
            'conditionfieldgroup' => [],
            'conditioncompletiongroup' => [],
        ]);

        $created = add_moduleinfo($info, $this->course);

        $this->items[] = [
            'cmid' => (int) $created->coursemodule,
            'modname' => $modname,
            'name' => $modname === 'label' ? '' : $info->name,
            'visible' => $visible,
        ];
        return $created;
    }

    /**
     * Create or refresh the index of topics at the top of the current section.
     *
     * The index is rebuilt from the topic headers found in the section, so it also lists the topics created
     * before this feature existed, and it stays correct if the teacher deletes or reorders topics. Each line is
     * a link that jumps straight to the topic, so reaching the last of 20 topics takes two clicks.
     *
     * @param string $lang Content language.
     */
    protected function rebuild_toc(string $lang): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $section = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $this->sectionnum], '*', MUST_EXIST);
        $order = array_flip(array_filter(explode(',', (string) $section->sequence)));

        $labels = $DB->get_records_sql(
            "SELECT cm.id AS cmid, l.id AS labelid, l.intro
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'label'
               JOIN {label} l ON l.id = cm.instance
              WHERE cm.section = :section AND cm.course = :course AND cm.deletioninprogress = 0",
            ['section' => $section->id, 'course' => $this->course->id]);

        $entries = [];
        $toc = null;
        foreach ($labels as $label) {
            $intro = (string) $label->intro;
            if (strpos($intro, 'aiyared-toc') !== false) {
                $toc = $label;
                continue;
            }
            // Topic header: banner with an h2 title (see html_builder::header).
            if (strpos($intro, 'letter-spacing:.1em') === false
                    || !preg_match('~<h2[^>]*>(.*?)</h2>~s', $intro, $title)) {
                continue;
            }
            $date = preg_match('~\b(\d{2}-\d{2}-\d{4})\b~', $intro, $d) ? $d[1] : '';
            $entries[(int) ($order[$label->cmid] ?? PHP_INT_MAX)] = [(int) $label->cmid,
                trim(html_entity_decode(strip_tags($title[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $date];
        }
        if (!$entries) {
            return;
        }
        ksort($entries);

        $items = '';
        foreach ($entries as [$cmid, $name, $date]) {
            $url = new \moodle_url('/course/view.php', ['id' => $this->course->id], 'module-' . $cmid);
            $items .= '<li><a href="' . s($url->out(false)) . '">' . s($name) . '</a>'
                . ($date !== '' ? ' <span style="opacity:.75">— ' . s($date) . '</span>' : '') . '</li>';
        }
        $html = '<div class="aiyared-toc" style="border:1px solid rgba(0,0,0,.12);border-radius:12px;'
            . 'padding:.8rem 1rem;margin:0 0 .5rem"><p style="margin:0 0 .4rem"><strong>'
            . s(strings::get('toc_title', $lang, count($entries))) . '</strong></p>'
            . '<ol style="margin:0;padding-left:1.4rem">' . $items . '</ol></div>';

        if ($toc) {
            $DB->update_record('label', (object) ['id' => $toc->labelid, 'intro' => $html,
                'introformat' => FORMAT_HTML, 'timemodified' => time()]);
            rebuild_course_cache($this->course->id, true);
            return;
        }

        $created = $this->add_module('label', '', $html, true);
        array_pop($this->items); // The index is not a topic activity.

        // Move it to the top of the section.
        $section = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);
        foreach (array_filter(explode(',', (string) $section->sequence)) as $cmid) {
            if ((int) $cmid === (int) $created->coursemodule) {
                continue;
            }
            $first = $DB->get_record('course_modules', ['id' => (int) $cmid], '*', IGNORE_MISSING);
            if ($first) {
                $cm = get_coursemodule_from_id('label', $created->coursemodule, $this->course->id, false, MUST_EXIST);
                moveto_module($cm, $section, $first);
            }
            break;
        }
    }

    /**
     * Run a creation step; a failure becomes a warning and the rest of the topic is still created.
     *
     * @param string $what Name of the step.
     * @param callable $callback Work to do.
     */
    protected function try_add(string $what, callable $callback): void {
        try {
            $callback();
        } catch (job_cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->warnings[] = get_string('warn_module', 'local_ai_yared_course',
                (object) ['what' => $what, 'error' => s($e->getMessage())]);
        }
    }

    /**
     * Write a progress line.
     *
     * @param string $message Message.
     */
    protected function log(string $message): void {
        $this->trace->output($message);
    }
}
