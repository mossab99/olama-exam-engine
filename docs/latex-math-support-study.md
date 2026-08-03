# LaTeX mathematics support study

Date: 2026-08-03

Implementation status: delivered in plugin version 1.2.0. Browser rendering, math-safe GIFT parsing, native OEE JSON import/export, and the constrained reviewed TeX adapter described below are implemented. TikZ remains a media attachment workflow and is deliberately not compiled inside WordPress.

## Decision

Add browser-side TeX mathematics rendering to the existing question fields, and add a versioned native JSON import/export format (`.oee.json`) as the primary structured exchange format.

Do not treat arbitrary `.tex` documents as a reliable question-bank format. A `.tex` document describes page layout, not the question semantics the engine requires. A constrained `.tex` adapter can be added for known templates, including the supplied scholarship exam, but it must use an editable preview and require missing correct answers before import.

Recommended order:

1. Render TeX in all question, choice, explanation, preview, exam, and result surfaces.
2. Make the existing GIFT path math-safe and document its supported TeX subset.
3. Add native `.oee.json` import/export.
4. Add a constrained `.tex` adapter for the supplied exam family if direct document ingestion is still required.
5. Add Moodle XML later if Moodle interoperability is a real requirement; defer QTI until broader LMS interoperability is a product goal.

## What the current engine already supports

No database migration is required for basic math support:

- `question_text` and `explanation` are `TEXT` fields.
- `answers_json` is `LONGTEXT`, and choices, matching values, ordering items, and accepted answers are already stored as strings.
- Exam attempts snapshot question data, so preserved TeX source naturally travels with an attempt.

Relevant code:

- `includes/class-exam-db.php:49-64`
- `includes/class-exam-questions.php:103-164`
- `includes/class-exam-engine.php:139-157`

The representation should remain source text, for example:

```text
إذا كان \(f(x)=\dfrac{1}{2}-0.3(0.3)^{x+2}\)، فأوجد \(f(-2)\).
```

Do not store MathJax-generated HTML in the database. Store TeX source and render it at display time.

## Renderer choice

Use a locally bundled, pinned MathJax 4 build with TeX input and CommonHTML output.

Why MathJax:

- The supplied material uses `\dfrac`, `\sqrt`, roots, exponents, intervals, trigonometric functions, and AMS-style notation.
- MathJax supports explicit re-typesetting of AJAX-inserted content with `typesetPromise()`.
- The safe extension is intended for user-supplied mathematics.
- MathJax is a TeX math renderer, not a complete LaTeX compiler. That limitation is desirable here because the plugin should not execute uploaded TeX.

KaTeX is fast, but it supports a smaller TeX surface and its Arabic-numeral support relies on an external plugin. It is a reasonable alternative only if the product adopts a deliberately small, tested math command set.

Official references:

- [MathJax TeX and LaTeX support](https://docs.mathjax.org/en/latest/input/tex/index.html)
- [MathJax dynamic content](https://docs.mathjax.org/en/latest/advanced/typeset.html)
- [MathJax safe extension](https://docs.mathjax.org/en/latest/options/safe.html)
- [KaTeX supported functions](https://katex.org/docs/supported)

### Delimiters

Use `\(...\)` for inline math and `\[...\]` for display math as the canonical stored notation. Accept `$...$` and `$$...$$` during import because the supplied file uses dollar delimiters, but normalize them when it can be done safely.

Explicit delimiters avoid interpreting prices or ordinary dollar signs as equations. Math should remain inside its delimiters; Arabic prose should remain normal HTML text so browser RTL shaping continues to work.

### Loading and dynamic rendering

Add a small shared `assets/js/exam-math.js` wrapper that:

- loads only on Olama Exam admin screens and exam-facing pages;
- starts MathJax with automatic whole-page typesetting disabled;
- typesets only marked engine containers such as `.oee-math`;
- serializes/debounces `typesetPromise()` calls so overlapping AJAX updates do not race;
- clears previously typeset nodes before replacing dynamic preview content;
- exposes one method such as `OlamaExamMath.typeset(root)`.

Call the wrapper after dynamic DOM insertion in at least these paths:

- student exam rendering in `assets/js/exam-engine.js`;
- question-bank rows and an authoring preview in `admin/views/question-bank.php`;
- GIFT and CSV previews;
- exam creation question lists;
- result/review views, teacher previews, essay grading, and acceptance-result views.

Static PHP views may be handled once after DOM ready. AJAX-rendered views must explicitly request another typeset.

### Security and data handling

- Never run `xelatex`, `pdflatex`, shell escape, or arbitrary uploaded TeX on the WordPress server.
- Enable MathJax's `ui/safe` extension and restrict URLs, CSS classes, IDs, and styles.
- Bundle MathJax locally rather than depending on a runtime CDN.
- Continue sanitizing allowed question HTML with WordPress KSES, but preserve ordinary TeX characters.
- Apply `wp_unslash()` exactly once to question text and explanations received through WordPress POST. The manual save handler currently unslashes `answers_json` but not `question_text`; that must be corrected and covered by a round-trip test for `\frac`, quotes, and backslashes.
- Escape text before inserting it into HTML. MathJax reads TeX from text nodes; TeX does not require raw HTML insertion.
- Reject oversized expressions and files with explicit limits, and report a visible rendering error without preventing the rest of the exam from loading.

## Current importer problems with mathematics

### GIFT

The current GIFT parser locates the answer section by taking the first `{` and the last `}` in a block (`includes/class-exam-gift-parser.php:79-91`). This fails as soon as the question contains ordinary TeX such as `\dfrac{1}{2}`: the first TeX argument brace is mistaken for the GIFT answer block.

The current MCQ parser also splits answers on every `=` or `~` (`includes/class-exam-gift-parser.php:219-237`). That corrupts expressions such as `\(x=2\)`.

Required change: replace the regular-expression approach with a state-aware tokenizer that tracks:

- GIFT block depth;
- TeX `{...}` depth;
- inline/display math delimiters;
- escaped GIFT control characters;
- answer markers only at the GIFT answer level, preferably also supporting one marker per line.

Add fixtures for nested fractions, sets, absolute values, equations containing `=`, Arabic text, feedback markers, and malformed/unclosed delimiters.

GIFT can remain supported, but it should not be the engine's lossless internal interchange format.

### CSV

The current CSV answer fields use `|` to separate choices and answers (`includes/class-exam-csv-parser.php:180-236`). This is ambiguous for mathematics. The supplied exam contains the choices `9|x|` and `9|x^2|`, which the current parser would split into multiple choices.

CSV is useful for simple spreadsheets, but it should be documented as a limited convenience format. Do not add more escaping rules to make it the canonical math format; that would become difficult for teachers to author and debug.

## Recommended new format: `.oee.json`

JSON maps directly to the plugin's current model, preserves Unicode and backslashes, supports nested question types without delimiter collisions, and can be validated and versioned.

Example:

```json
{
  "format": "olama-exam-question-bank",
  "version": 1,
  "metadata": {
    "title": "اختبار المنح مادة الرياضيات",
    "language": "ar"
  },
  "questions": [
    {
      "external_id": "grant-2026-q1",
      "type": "mcq",
      "question": "إذا كان \\(f(x)=\\dfrac{1}{2}-0.3(0.3)^{x+2}\\)، فإن قيمة \\(f(-2)\\) تساوي:",
      "choices": [
        { "id": "a", "text": "\\(0.2\\)", "correct": true },
        { "id": "b", "text": "\\(-0.2\\)", "correct": false },
        { "id": "c", "text": "\\(-0.5\\)", "correct": false },
        { "id": "d", "text": "\\(0.5\\)", "correct": false }
      ],
      "difficulty": "medium",
      "language": "ar",
      "explanation": ""
    }
  ]
}
```

The importer should validate the whole file before committing anything, preview all questions, identify duplicates by `external_id`, and import in a database transaction or an all-or-nothing staged operation. Target grade, subject, unit, and lesson may continue to come from the import screen rather than being trusted from the file.

If portable media is later needed, extend this to an `.oee.zip` package containing `manifest.json` and a `media/` directory. Do not embed large base64 images in ordinary JSON.

## Other format options

| Format | Recommendation | Reason |
|---|---|---|
| Native `.oee.json` | Build first | Lossless mapping to this engine, easy validation, math-safe, versionable. |
| Moodle XML | Build second if Moodle exchange matters | Comprehensive Moodle import/export format, supports rich question types and embedded base64 images. |
| QTI 3 package | Defer | Best open interoperability target, but it is a large assessment, packaging, response-processing, media, and conformance project rather than a small importer. |
| Constrained `.oee.tex` | Optional adapter | Familiar to TeX authors, but only reliable with engine-specific question and correct-choice commands. |
| Arbitrary `.tex` | Do not promise | Layout programs, custom macros, packages, and missing answer keys make semantic extraction unreliable. |
| Markdown plus YAML | Optional authoring convenience | Human-readable and math-friendly, but would be another Olama-specific dialect unless carefully specified. |
| Aiken | Do not prioritize | Primarily simple MCQ text and less capable than the formats already supported. |
| DOCX/PDF | Review-assisted ingestion only | These are presentation documents; extraction loses question semantics and often math structure. |

Moodle describes Moodle XML as a comprehensive import/export format and documents embedded image support. 1EdTech defines QTI for exchanging items, tests, results, and related resources across assessment systems.

- [Moodle XML format](https://docs.moodle.org/401/en/Moodle_XML_format)
- [1EdTech QTI specification documents](https://www.1edtech.org/standards/qti/index)

## Supplied `امتحان_المنحة.tex` assessment

The file contains:

- 30 multiple-choice questions;
- 27 uses of a custom four-choice `\choices` macro;
- 3 questions whose choices are manually laid out in `tabularx` rather than the macro;
- 2 TikZ diagrams;
- Arabic prose mixed with inline TeX mathematics;
- no machine-readable answer key or correct-choice markers.

Most question and choice text is convertible. The two TikZ figures are not browser math and MathJax will not render them. They should become SVG/PNG question images, which the existing `image_filename` field can already reference.

A converter for this file can extract the 30 stems and four choices with balanced-brace parsing, but it must:

1. show all extracted questions in a review screen;
2. flag the two diagram questions for attached media;
3. require an administrator to choose the correct answer for every question;
4. refuse final import while any MCQ has no correct answer;
5. report unsupported macros/environments rather than silently dropping them.

The converter should be described as support for an Olama template/subset, not general LaTeX import.

## Delivery phases and acceptance criteria

### Phase 1: rendering foundation

- Locally bundle pinned MathJax and the safe extension.
- Add the scoped typesetting wrapper and math-specific CSS for RTL cards, overflow, and mobile display equations.
- Add live preview beneath question and answer inputs.
- Fix POST slash handling.
- Typeset every display surface found in the rendering audit.

Acceptance:

- The expressions used by all 30 supplied questions render on desktop and mobile.
- Source survives create, edit, duplicate, import, exam snapshot, autosave/resume, submission, grading, and results without added or lost backslashes.
- A broken expression affects only itself and exposes a useful admin warning.
- Non-exam WordPress content is not scanned or changed.

### Phase 2: importer hardening and native JSON

- Replace GIFT regex parsing with a state-aware tokenizer.
- Add `.oee.json` schema validation, preview, duplicate policy, and import/export.
- Keep CSV but label its delimiter limitations.

Acceptance:

- Math fixtures containing nested braces, `=`, `~`, and `|x|` round-trip without corruption.
- Invalid files produce line/question-specific errors and import nothing.
- Export followed by import preserves every supported question type and all TeX source.

### Phase 3: supplied-TeX adapter

- Parse the known `\choices` and tabular patterns with balanced braces.
- Convert/attach TikZ output as trusted pre-rendered media rather than compiling uploads in WordPress.
- Add mandatory answer-key completion in preview.

Acceptance:

- The supplied file yields exactly 30 reviewed MCQs and four choices per question.
- Questions 8 and 17 are visibly flagged until their figures are attached.
- No question can be imported with an assumed default correct choice.

## Test matrix

Cover at least:

- inline and display math;
- `\frac`, `\dfrac`, `\sqrt[n]`, exponents, subscripts, intervals, sets, absolute values, vectors, degrees, `\sin`, `\cos`, `\tan`, and `\infty`;
- Arabic before and after math, mixed RTL/LTR punctuation, and Arabic/English digits;
- math in MCQ choices, matching sides, ordering items, accepted answers, explanations, and correct-answer review;
- malformed TeX, very long expressions, unsupported commands, and hostile URL/style commands;
- initial PHP page rendering and every AJAX insertion path;
- print layout and small screens with horizontally scrollable display equations rather than clipped content.

## Bottom line

The engine does not need a new database model to support mathematics. It needs a safe renderer, a complete rendering-surface audit, exact slash preservation, and math-aware importers.

For new files, use `.oee.json`. Keep GIFT for Moodle-style text exchange, keep CSV for simple spreadsheet cases, and treat `.tex` as a constrained, review-assisted adapter—not the canonical question format.
