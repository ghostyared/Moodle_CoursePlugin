# Changelog

All notable changes of `local_ai_yared_course`. Versions follow `release` in `version.php`.

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
