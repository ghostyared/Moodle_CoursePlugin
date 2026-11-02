# Yared.AI Material Creator (local_ai_yared_course)

Local plugin for Moodle that uses AI to build the content of a course topic (header, support video, handout PDF, graded practice and extension task), to generate questions for the course question bank, to import questions from PDFs or photos, and to build standard Moodle quizzes from those banks. It adds items to the course **More** menu and **does not require any additional activity module**.

- Component: `local_ai_yared_course`
- Maturity: beta
- Release: 0.25.0
- Licence: GNU GPL v3 or later (see `LICENSE`)
- Copyright: 2026 GhostYared Corp

## Moodle versions

| Moodle | Status |
| --- | --- |
| 4.5.7 | **Tested** |
| Other 4.5.x and later (5.x) | **Not tested** – please report issues |

The plugin requires Moodle 4.5 or later (`$plugin->requires = 2024100700`).

## Installation
1. Upload the plugin ZIP in *Site administration → Plugins → Install plugins*, or copy the plugin to `local/ai_yared_course`.
2. Log in as administrator to run the upgrade and purge caches.
3. Configure at least one AI provider in *Site administration → Plugins → Local plugins → Yared.AI Material Creator*.

Optional: cropping figures from PDFs needs **Python 3 + PyMuPDF** on the same server as PHP (see *Status of the PDF conversion* in the plugin settings). Without them the plugin still works, but PDFs are analysed as text only and no crops are generated.

## Features
- **Material Creator:** creates, in one batch, a section per topic with header, YouTube support video, PDF handout, graded practice (hidden quiz tied to a question bank) and an extension task. Mathematics topics (algebra, trigonometry, geometry…) get exercises with LaTeX and figures instead of theory questions (automatic detection, or chosen by the teacher).
- **Question Creator:** classic mode (multiple choice, multiple selection, true/false, missing word, drag and drop text, and, only when the teacher chooses them, short answer and essay), mathematics mode with LaTeX and figures drawn by the plugin as SVG, and import from PDF or images.
- **Review before saving:** the teacher can edit the statement, options, correct answer and feedback of every generated or imported question, ask for a rewrite, another variant or another model, and optionally attach a free reference picture from Wikimedia Commons (the credit is added to the feedback).
- **Quiz Creator:** builds a standard Moodle quiz from one or more question banks.
- **Multi-provider AI:** one main provider and up to five automatic fall-backs (Gemini, OpenAI, Grok, DeepSeek, Qwen, Kimi, Mistral, Groq, OpenRouter, Zhipu GLM or any OpenAI-compatible server).
- **External API (web services):** functions to create courses and topics from an external system, with signed webhook notifications.

Short answer and essay questions are never generated automatically; they need manual review by the teacher.

## Capabilities
`local/ai_yared_course:use`, `:manage`, `:apibuild` and `:viewreport`. Teachers (editing teacher) and managers can use the tools by default.

## External services and privacy
The plugin sends data to external services **only when a teacher or the administrator uses the related feature**, and declares them in its privacy provider:

| Service | Data sent | When |
| --- | --- | --- |
| AI provider chosen by the administrator | Topic titles/descriptions, question text and instructions typed by the teacher, PDFs and images uploaded for import | Generating content or questions |
| YouTube Data API | Search words derived from the topic title | Searching a support video |
| Wikimedia Commons (`commons.wikimedia.org`, `upload.wikimedia.org`) | Search words typed by the teacher or deduced from the question | Using the reference picture search (can be disabled in the settings) |
| CRM webhook configured by the administrator | Build status, CRM reference, course id and URL (no personal data of users) | A course build finishes |

The server needs outbound HTTPS access to the services in use. The plugin stores temporary drafts in the user's own file area (deleted after saving, discarding or 24 hours) and a log of generation jobs; both are covered by the Moodle privacy API (export and deletion).

## Documentation
Change history: `CHANGELOG.md`. A detailed manual in Spanish (deployment requirements, configuration, usage and release notes) is in `README_es.md`.

## Bug reports
Please use the issue tracker of the repository: https://github.com/ghostyared/Moodle_CoursePlugin/issues
