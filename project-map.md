# Project Map: Olama Exam Engine

## Directory Structure

- `/admin`
  - Handles administrative views and management logic. Includes `class-exam-admin.php` for menu setup and assets enqueuing, and the `views/` subfolder for the exam creation/listing forms.
- `/assets`
  - Contains CSS, JS, and image assets for both admin and frontend.
  - `/css`: Includes `exam-admin.css` (primary UI redesign styles) and `exam-student.css`.
  - `/js`: Includes `exam-admin.js` for form logic and `exam-engine.js` for student attempt tracking.
- `/includes`
  - Core logic and class files. Includes `class-exam-db.php` for schema and migrations, `class-exam-ajax.php` for all server-side interactions, and specialized classes for grading (`class-exam-grader`) and question management.
- `/languages`
  - Translation files. Currently uses PHP-based translation maps (`olama-exam-engine-ar.php`) via the `olama_exam_translate()` helper.
- `/templates`
  - Frontend display templates for student-facing exam modules.

## Core Files

- `olama-exam-engine.php`: Main plugin entry point, defines constants (`OLAMA_EXAM_VERSION`), and handles plugin initialization/activation hooks.

## Modules

- [Module Name]: [Description & Responsibilities]
- [Module Name]: [Description & Responsibilities]
