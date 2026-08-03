<?php
/**
 * Native OEE JSON import with validation and math preview.
 */
if (!defined('ABSPATH')) {
    exit;
}

$target = array(
    'unit_id' => intval($_GET['unit_id'] ?? 0),
    'lesson_id' => intval($_GET['lesson_id'] ?? 0),
    'profession_id' => intval($_GET['profession_id'] ?? 0),
    'grade_level_id' => intval($_GET['grade_level_id'] ?? 0),
    'category_id' => intval($_GET['category_id'] ?? 0),
);
$has_target = $target['unit_id'] > 0 || $target['profession_id'] > 0 || $target['grade_level_id'] > 0;
?>

<div class="wrap olama-exam-wrap">
    <div class="olama-exam-page-header">
        <div>
            <h1>∑ <?php echo olama_exam_translate('Import Math / OEE JSON'); ?></h1>
            <p><?php echo olama_exam_translate('Import a validated, lossless question bank with LaTeX mathematics.'); ?></p>
        </div>
        <a class="olama-exam-btn olama-exam-btn-outline" href="<?php echo esc_url(admin_url('admin.php?page=olama-exam')); ?>">
            ← <?php echo olama_exam_translate('Question Bank'); ?>
        </a>
    </div>

    <div class="olama-exam-card" style="padding:20px;">
        <h3 style="margin-top:0;"><?php echo olama_exam_translate('Import Target'); ?></h3>
        <?php if (!$has_target): ?>
            <div class="notice notice-error inline"><p><?php echo olama_exam_translate('Open this importer from a selected question-bank unit, profession, or grade level.'); ?></p></div>
        <?php else: ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach ($target as $key => $value): ?>
                    <?php if ($value > 0): ?>
                        <span class="olama-exam-badge"><?php echo esc_html($key . ': ' . $value); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="olama-exam-card" style="padding:20px; margin-top:16px;">
        <div class="olama-exam-form-group">
            <label><?php echo olama_exam_translate('OEE JSON or supported TeX File'); ?></label>
            <input type="file" id="oee-json-file" accept=".json,.oee.json,.tex,application/json,text/x-tex">
            <p style="font-size:12px; color:#64748b;">
                <?php echo olama_exam_translate('Maximum size: 2 MB.'); ?>
                <a href="<?php echo esc_url(OLAMA_EXAM_URL . 'templates/questions-oee-template.json'); ?>" download>
                    <?php echo olama_exam_translate('Download template'); ?>
                </a>
            </p>
        </div>
        <div class="olama-exam-form-group">
            <label><?php echo olama_exam_translate('File Format'); ?></label>
            <select id="oee-import-format">
                <option value="json">OEE JSON</option>
                <option value="tex">LaTeX — Olama exam template</option>
            </select>
        </div>
        <div class="olama-exam-form-group">
            <label><?php echo olama_exam_translate('Or paste source'); ?></label>
            <textarea id="oee-json-content" rows="10" dir="ltr" spellcheck="false"
                placeholder='{"format":"olama-exam-question-bank","version":1,"questions":[]}'></textarea>
        </div>
        <div style="display:flex; gap:10px;">
            <button id="oee-json-preview" class="olama-exam-btn olama-exam-btn-outline">👁 <?php echo olama_exam_translate('Validate & Preview'); ?></button>
            <button id="oee-json-import" class="olama-exam-btn olama-exam-btn-primary"
                data-label="<?php echo esc_attr(olama_exam_translate('Import All')); ?>" disabled>
                <?php echo olama_exam_translate('Import All'); ?>
            </button>
        </div>
        <div id="oee-json-review-status" class="notice notice-warning inline" style="display:none; margin:12px 0 0;" aria-live="polite"><p></p></div>
    </div>

    <div id="oee-json-feedback" style="display:none; margin-top:16px;"></div>

    <div id="oee-json-preview-card" class="olama-exam-card" style="display:none; margin-top:16px;">
        <div class="olama-exam-card-header">
            <h3><?php echo olama_exam_translate('Preview'); ?> (<span id="oee-json-count">0</span>)</h3>
        </div>
        <div style="overflow:auto;">
            <table class="olama-exam-table">
                <thead><tr>
                    <th>#</th>
                    <th><?php echo olama_exam_translate('Question Type'); ?></th>
                    <th><?php echo olama_exam_translate('Question Text'); ?></th>
                    <th><?php echo olama_exam_translate('Answers'); ?></th>
                </tr></thead>
                <tbody id="oee-json-preview-body"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function ($) {
    'use strict';
    const target = <?php echo wp_json_encode($target); ?>;
    const hasTarget = <?php echo $has_target ? 'true' : 'false'; ?>;
    let previewValid = false;
    let previewQuestions = [];
    let previewErrors = [];
    let previewFormat = 'json';
    const importButton = $('#oee-json-import');
    const importLabel = importButton.data('label') || 'Import All';

    function invalidatePreview() {
        previewValid = false;
        previewQuestions = [];
        previewErrors = [];
        importButton.prop('disabled', true).text(importLabel).removeAttr('title');
        $('#oee-json-review-status').hide().find('p').empty();
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    $('#oee-json-file').on('change', function () {
        invalidatePreview();
        const file = this.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            ExamAdmin.toast('The JSON file exceeds 2 MB.', 'error');
            this.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function (event) { $('#oee-json-content').val(event.target.result); };
        reader.readAsText(file, 'UTF-8');
        $('#oee-import-format').val(/\.tex$/i.test(file.name) ? 'tex' : 'json');
    });

    $('#oee-json-content').on('input', invalidatePreview);
    $('#oee-import-format').on('change', invalidatePreview);

    function post(mode, done) {
        const format = $('#oee-import-format').val();
        const review = collectReview();
        $.post(olamaExam.ajaxUrl, Object.assign({
            action: format === 'tex' ? 'olama_exam_import_tex' : 'olama_exam_import_json',
            nonce: olamaExam.nonce,
            mode: mode,
            json_content: format === 'json' ? $('#oee-json-content').val() : '',
            tex_content: format === 'tex' ? $('#oee-json-content').val() : '',
            review_json: JSON.stringify(review)
        }, target), done);
    }

    function collectReview() {
        const review = {};
        $('#oee-json-preview-body tr[data-source-number]').each(function () {
            const number = $(this).data('source-number');
            const selected = $(this).find('.oee-correct-choice:checked').val();
            review[number] = {
                correct: selected === undefined ? -1 : parseInt(selected, 10),
                ack_media: $(this).find('.oee-media-ack').is(':checked')
            };
        });
        return review;
    }

    function updateImportState() {
        const canImport = hasTarget && previewErrors.length === 0 && previewQuestions.length > 0;
        let complete = canImport;
        let missingCorrect = 0;
        let missingMedia = 0;

        if (previewFormat === 'tex') {
            $('#oee-json-preview-body tr[data-source-number]').each(function () {
                const row = $(this);
                const needsCorrect = !row.find('.oee-correct-choice:checked').length;
                const needsMedia = row.find('.oee-media-ack').length && !row.find('.oee-media-ack').is(':checked');
                if (needsCorrect) missingCorrect++;
                if (needsMedia) missingMedia++;
                row.toggleClass('oee-review-missing', needsCorrect || needsMedia);
            });
            complete = complete && missingCorrect === 0 && missingMedia === 0;
        }

        previewValid = complete;
        importButton.prop('disabled', !complete);

        const status = $('#oee-json-review-status');
        if (previewFormat !== 'tex' || !previewQuestions.length || !canImport) {
            status.hide().find('p').empty();
            importButton.text(importLabel).removeAttr('title');
            return;
        }

        if (!complete) {
            const requirements = [];
            if (missingCorrect) requirements.push(missingCorrect + ' correct answer' + (missingCorrect === 1 ? '' : 's'));
            if (missingMedia) requirements.push(missingMedia + ' diagram acknowledgement' + (missingMedia === 1 ? '' : 's'));
            const message = 'Review required before import: select ' + requirements.join(' and ') + '.';
            status.removeClass('notice-success').addClass('notice-warning').show().find('p').text(message);
            const remaining = missingCorrect + missingMedia;
            importButton.text(importLabel + ' (' + remaining + ' review item' + (remaining === 1 ? '' : 's') + ' remaining)').attr('title', message);
            return;
        }

        status.removeClass('notice-warning').addClass('notice-success').show().find('p')
            .text('Review complete. All ' + previewQuestions.length + ' questions are ready to import.');
        importButton.text(importLabel).removeAttr('title');
    }

    function showErrors(errors) {
        if (!errors || !errors.length) {
            $('#oee-json-feedback').hide().empty();
            return;
        }
        const list = errors.map(function (error) {
            return '<li>' + escapeHtml(error.message || error) + '</li>';
        }).join('');
        $('#oee-json-feedback').html('<div class="notice notice-error inline"><p><strong>Validation failed</strong></p><ul>' + list + '</ul></div>').show();
    }

    $('#oee-json-preview').on('click', function () {
        const button = $(this);
        previewValid = false;
        importButton.prop('disabled', true).text(importLabel);
        button.prop('disabled', true).text('⏳ Validating...');
        post('preview', function (response) {
            button.prop('disabled', false).text('👁 Validate & Preview');
            if (!response.success) {
                ExamAdmin.toast(response.data.message || 'Validation failed.', 'error');
                return;
            }

            const data = response.data;
            previewQuestions = data.questions || [];
            previewErrors = data.errors || [];
            previewFormat = (data.metadata && data.metadata.source === 'tex') ? 'tex' : 'json';
            showErrors(data.errors);
            $('#oee-json-count').text(data.count);
            let html = '';
            data.questions.forEach(function (question, index) {
                const answers = JSON.parse(question.answers_json || '{}');
                let answerText = '';
                let answerHtml = '';
                if (question.type === 'mcq') answerText = (answers.choices || []).join(' · ');
                else if (question.type === 'matching') answerText = (answers.pairs || []).map(p => p.left + ' ↔ ' + p.right).join(' · ');
                else if (question.type === 'ordering') answerText = (answers.items || []).join(' → ');
                else if (answers.answers) answerText = answers.answers.join(' · ');
                else if (question.type === 'tf') answerText = answers.correct ? 'True' : 'False';

                if (previewFormat === 'tex') {
                    answerHtml = '<div style="display:grid; gap:6px;">' + (answers.choices || []).map(function (choice, choiceIndex) {
                        const checked = parseInt(answers.correct, 10) === choiceIndex ? ' checked' : '';
                        return '<label style="display:flex; gap:6px; align-items:flex-start;">' +
                            '<input class="oee-correct-choice" type="radio" name="correct-' + question.source_number + '" value="' + choiceIndex + '"' + checked + '>' +
                            '<span>' + escapeHtml(choice) + '</span></label>';
                    }).join('') + '</div>';
                    if (question.needs_image) {
                        const acknowledged = question.media_acknowledged ? ' checked' : '';
                        answerHtml += '<label style="display:block; margin-top:10px; color:#b45309; font-size:12px;">' +
                            '<input type="checkbox" class="oee-media-ack"' + acknowledged + '> TikZ diagram detected — I will attach the exported image after import.</label>';
                    }
                } else {
                    answerHtml = escapeHtml(answerText);
                }

                html += '<tr class="oee-math"' + (question.source_number ? ' data-source-number="' + question.source_number + '"' : '') + '>' +
                    '<td>' + (index + 1) + '</td>' +
                    '<td>' + escapeHtml(question.type.toUpperCase()) + '</td>' +
                    '<td class="oee-question-text">' + escapeHtml(question.question_text) + '</td>' +
                    '<td>' + answerHtml + '</td>' +
                    '</tr>';
            });
            const previewBody = document.getElementById('oee-json-preview-body');
            if (window.OlamaExamMath) window.OlamaExamMath.clear(previewBody);
            $('#oee-json-preview-body').html(html);
            $('#oee-json-preview-card').show();
            if (window.OlamaExamMath) window.OlamaExamMath.typeset(previewBody);

            updateImportState();
        });
    });

    $(document).on('change', '.oee-correct-choice, .oee-media-ack', updateImportState);

    $('#oee-json-import').on('click', function () {
        if (!previewValid) return;
        const button = $(this).prop('disabled', true).text('Importing...');
        post('import', function (response) {
            if (!response.success) {
                ExamAdmin.toast(response.data.message || 'Import failed.', 'error');
                updateImportState();
                return;
            }
            const data = response.data;
            if (data.errors && data.errors.length) {
                showErrors(data.errors);
                updateImportState();
                return;
            }
            previewValid = false;
            button.text(importLabel).prop('disabled', true);
            $('#oee-json-review-status').hide();
            ExamAdmin.toast(data.imported + ' question(s) imported.');
            $('#oee-json-feedback').html('<div class="notice notice-success inline"><p>' + data.imported + ' question(s) imported successfully.</p></div>').show();
        });
    });
})(jQuery);
</script>
