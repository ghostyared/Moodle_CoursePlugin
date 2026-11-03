# Changelog

All notable changes of `local_ai_yared_course`. Versions follow `release` in `version.php`.

## 0.26.0 – 2026-11-03
- Material Creator: **preview before publishing**. With the new option (on by default) the AI generates the topic and the teacher reviews it as the students will see it (activities, dates, hidden/visible), edits the texts and the questions, and only then creates it in the course. Drafts expire after 24 hours.
- **Class presentation (slides):** the AI writes the slides of each topic (about 6 to 12 by age; figures drawn by the plugin for geometry/trigonometry). A link in the section opens a viewer with full screen, keyboard and swipe navigation, and teacher notes. Teachers can edit text and order, add or remove slides, and add free pictures from Wikimedia Commons with their credit. Students only see it while its activity is visible. Nothing is sent to external services except the optional picture search.
- New option "Class presentation" in the topic form.

## 0.25.0 – 2026-11-02
- Material Creator: the question bank of a topic is now mathematical (LaTeX formulas, numeric exercises and figures drawn by the plugin) when the topic is mathematics. The plugin detects it from the title and description (algebra, trigonometry, geometry, calculus, arithmetic, statistics, physics…); a new "Kind of questions" selector lets the teacher force general, or a specific mathematics course, for the whole batch. Available in the topic form and in the topic designer.
- Warning when the MathJax/TeX filter is not active in the course.

## 0.24.1 – 2026-10-30
Plugin directory review fixes. No functional or database change.
- Added `LICENSE` (GNU GPL v3).
- Privacy provider declares Wikimedia Commons and the CRM webhook as external locations.
- English `README.md` with tested Moodle versions (4.5.7); Spanish manual kept in `README_es.md`.
- Copyright holder in all file headers: GhostYared Corp.
- Added PHPUnit tests, GitHub Actions workflow (moodle-plugin-ci) and this changelog.

## 0.24.0 – 2026-10-27
- Optional reference picture from Wikimedia Commons in the review screen ("Process and rewrite"); the credit is added to the feedback when saving. New admin setting `imagesearch`.

## 0.23.0 – 2026-10-27
- Classic generator with every question format (multiple choice, multiple selection, true/false, missing word, drag and drop, mixed; short answer and essay only when chosen by the teacher).
- Review panel: rewrite, another variant, another model, restore the suggested question; legend and manual-review notice.

## 0.22.0 – 2026-10-27
- "Review before saving" screen for generated questions (edit statement, options, answer; "another question"; figure as optional picture).

## 0.21.0 – 2026-10-26
- Mathematics course selector with suggested topics; figures drawn by the plugin as SVG from a structured description written by the AI.

## 0.20.0 – 2026-10-25
- Open question types never automatic; crop persistence in the import review; legend.

Earlier versions (0.1 – 0.19.x): see the release notes in `README_es.md`.
