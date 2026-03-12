<?php
/**
 * Admin View: Question Bank
 * Unit-based question management UI with grade/subject/unit selectors + completion ratio.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Get active year and semester from the SIS
$active_year = Olama_School_Academic::get_active_year();
$active_semester = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}olama_semesters WHERE is_active = 1 LIMIT 1");
$grades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_grades WHERE is_active = 1 ORDER BY grade_name ASC");

$type_labels = array(
    'mcq' => olama_exam_translate('Multiple Choice'),
    'tf' => olama_exam_translate('True / False'),
    'short' => olama_exam_translate('Short Answer'),
    'matching' => olama_exam_translate('Matching'),
    'ordering' => olama_exam_translate('Ordering'),
    'fill_blank' => olama_exam_translate('Fill in the Blank'),
    'essay' => olama_exam_translate('Essay'),
);

$diff_labels = array(
    'easy' => olama_exam_translate('Easy'),
    'medium' => olama_exam_translate('Medium'),
    'hard' => olama_exam_translate('Hard'),
);
?>
<div class="olama-exam-wrap">
    <!-- Header -->
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Question Bank'); ?></h1>
        </div>
        <div class="actions">
            <button class="olama-exam-btn olama-exam-btn-primary olama-exam-add-question" data-id="0" style="display:none;">
                + <?php echo olama_exam_translate('Add Question'); ?>
            </button>
        </div>
    </div>

    <!-- Academic Context (read-only) + Grade/Subject Selectors -->
    <div class="olama-exam-card">
        <div class="olama-exam-card-header">
            <h3>📚 <?php echo olama_exam_translate('Select Grade & Subject'); ?></h3>
        </div>
        <div style="padding:20px;">
            <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label><?php echo olama_exam_translate('Academic Year'); ?></label>
                    <input type="text" value="<?php echo esc_attr($active_year->year_name ?? '—'); ?>" readonly
                        style="background:#f1f5f9; cursor:not-allowed;">
                </div>
                <div class="olama-exam-form-group">
                    <label><?php echo olama_exam_translate('Semester'); ?></label>
                    <input type="text" value="<?php echo esc_attr($active_semester->semester_name ?? '—'); ?>"
                        readonly style="background:#f1f5f9; cursor:not-allowed;">
                </div>
                <div class="olama-exam-form-group">
                    <label><?php echo olama_exam_translate('Grade'); ?></label>
                    <select id="qb-grade-select">
                        <option value="0">— <?php echo olama_exam_translate('Select'); ?> —</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?php echo $g->id; ?>"><?php echo esc_html($g->grade_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-exam-form-group">
                    <label><?php echo olama_exam_translate('Subject'); ?></label>
                    <select id="qb-subject-select" disabled>
                        <option value="0">— <?php echo olama_exam_translate('Select Grade First'); ?> —</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Completion Ratio Bar -->
    <div id="qb-completion-bar" class="olama-exam-card" style="display:none;">
        <div style="padding:16px 20px; display:flex; align-items:center; gap:16px;">
            <span style="font-weight:600; white-space:nowrap;">
                📊 <?php echo olama_exam_translate('Coverage'); ?>:
                <span id="qb-coverage-text" style="color:#6366f1;">0 / 0</span>
            </span>
            <div style="flex:1; background:#e2e8f0; border-radius:99px; height:12px; overflow:hidden;">
                <div id="qb-coverage-fill"
                    style="height:100%; background:linear-gradient(90deg,#6366f1,#818cf8); border-radius:99px; transition:width 0.4s ease; width:0%;">
                </div>
            </div>
            <span id="qb-coverage-pct" style="font-weight:600; color:#6366f1; min-width:44px; text-align:right;">0%</span>
        </div>
    </div>

    <!-- Unit Cards Container -->
    <div id="qb-units-container" style="display:none;">
        <div id="qb-units-loading" style="text-align:center; padding:40px; color:#64748b;">
            ⏳ <?php echo olama_exam_translate('Loading...'); ?>
        </div>
        <div id="qb-units-list"></div>
        <div id="qb-units-empty" style="display:none; text-align:center; padding:40px; color:#64748b;">
            📭 <?php echo olama_exam_translate('No curriculum units found for this selection.'); ?>
        </div>
    </div>

    <!-- Questions Panel (shown when a unit is expanded) -->
    <div id="qb-questions-panel" class="olama-exam-card" style="display:none; margin-top:16px;">
        <div class="olama-exam-card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3>
                📝 <span id="qb-active-unit-name"></span>
                (<span id="qb-question-count">0</span>)
            </h3>
            <div style="display:flex; gap:8px;">
                <button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" id="qb-import-gift-btn">
                    📥 <?php echo olama_exam_translate('Import GIFT'); ?>
                </button>
                <button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" id="qb-import-csv-btn">
                    📊 <?php echo olama_exam_translate('Import CSV'); ?>
                </button>
                <button class="olama-exam-btn olama-exam-btn-primary olama-exam-btn-sm" id="qb-add-question-btn">
                    + <?php echo olama_exam_translate('Add Question'); ?>
                </button>
                <button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" id="qb-close-panel-btn">
                    ✕
                </button>
            </div>
        </div>
        <!-- Filters -->
        <div style="padding:12px 20px; display:flex; gap:10px; flex-wrap:wrap; border-bottom:1px solid #e2e8f0;">
            <select id="filter-type" style="min-width:140px;">
                <option value=""><?php echo olama_exam_translate('All'); ?> —
                    <?php echo olama_exam_translate('Question Type'); ?>
                </option>
                <?php foreach ($type_labels as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-difficulty" style="min-width:120px;">
                <option value=""><?php echo olama_exam_translate('All'); ?> —
                    <?php echo olama_exam_translate('Difficulty'); ?>
                </option>
                <?php foreach ($diff_labels as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" id="filter-search" class="olama-exam-search"
                placeholder="<?php echo olama_exam_translate('Search'); ?>..." style="flex:1; min-width:160px;">
            <button class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm olama-exam-bulk-delete"
                style="display:none;">
                🗑 <?php echo olama_exam_translate('Delete Selected'); ?>
            </button>
        </div>
        <!-- Questions Table -->
        <div id="questions-loading" style="text-align:center; padding:40px; color:#64748b;">
            ⏳ <?php echo olama_exam_translate('Loading...'); ?>
        </div>
        <table class="olama-exam-table" id="questions-table" style="display:none;">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="olama-exam-select-all"></th>
                    <th>#</th>
                    <th><?php echo olama_exam_translate('Question Text'); ?></th>
                    <th><?php echo olama_exam_translate('Question Type'); ?></th>
                    <th><?php echo olama_exam_translate('Difficulty'); ?></th>
                    <th>v</th>
                    <th><?php echo olama_exam_translate('Actions'); ?></th>
                </tr>
            </thead>
            <tbody id="questions-tbody"></tbody>
        </table>
        <div id="questions-empty" style="display:none; text-align:center; padding:40px; color:#64748b;">
            📝 <?php echo olama_exam_translate('No questions found.'); ?>
        </div>
    </div>

    <!-- Question Modal -->
    <div id="question-modal" class="olama-exam-modal-overlay">
        <div class="olama-exam-modal" style="max-width:800px;">
            <div class="olama-exam-modal-header">
                <h3 id="question-modal-title"><?php echo olama_exam_translate('Add Question'); ?></h3>
                <button class="olama-exam-modal-close">&times;</button>
            </div>
            <form id="olama-exam-question-form">
                <div class="olama-exam-modal-body">
                    <input type="hidden" name="id" id="q-id" value="0">
                    <input type="hidden" name="image_filename" id="q-image-filename" value="">
                    <input type="hidden" name="unit_id" id="q-unit-id" value="0">

                    <!-- Row: Type + Difficulty -->
                    <div class="olama-exam-form-row">
                        <div class="olama-exam-form-group">
                            <label><?php echo olama_exam_translate('Question Type'); ?></label>
                            <select name="type" id="question-type-select">
                                <?php foreach ($type_labels as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="olama-exam-form-group">
                            <label><?php echo olama_exam_translate('Difficulty'); ?></label>
                            <select name="difficulty" id="q-difficulty">
                                <?php foreach ($diff_labels as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $key === 'medium' ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Row: Language + Image -->
                    <div class="olama-exam-form-row">
                        <div class="olama-exam-form-group">
                            <label><?php echo olama_exam_translate('Language'); ?></label>
                            <select name="language" id="q-language">
                                <option value="ar">العربية</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                        <div class="olama-exam-form-group">
                            <label><?php echo olama_exam_translate('Image'); ?></label>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="button"
                                    class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm olama-exam-upload-image">📷
                                    <?php echo olama_exam_translate('Upload Image'); ?></button>
                                <span id="q-image-preview" style="font-size:13px; color:#64748b;"></span>
                                <button type="button"
                                    class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm olama-exam-remove-image"
                                    style="display:none;">✕</button>
                            </div>
                        </div>
                    </div>

                    <!-- Question Text -->
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Question Text'); ?></label>
                        <textarea name="question_text" id="q-text" rows="3" required></textarea>
                    </div>

                    <!-- Type-specific answer fields -->

                    <!-- MCQ -->
                    <div class="question-type-fields" id="fields-mcq">
                        <label style="font-weight:600; margin-bottom:8px; display:block;">Choices</label>
                        <div id="mcq-choices-list"></div>
                        <button type="button"
                            class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm add-choice-btn"
                            style="margin-top:8px;">+ Add Choice</button>
                        <p style="font-size:12px; color:#64748b; margin-top:4px;">Select the radio button next to the
                            correct answer.</p>
                    </div>

                    <!-- True/False -->
                    <div class="question-type-fields" id="fields-tf" style="display:none;">
                        <label
                            style="font-weight:600; margin-bottom:8px; display:block;"><?php echo olama_exam_translate('Correct Answer'); ?></label>
                        <div style="display:flex; gap:16px;">
                            <label style="cursor:pointer;"><input type="radio" name="tf_correct" value="true" checked>
                                <?php echo olama_exam_translate('True'); ?></label>
                            <label style="cursor:pointer;"><input type="radio" name="tf_correct" value="false">
                                <?php echo olama_exam_translate('False'); ?></label>
                        </div>
                    </div>

                    <!-- Short Answer -->
                    <div class="question-type-fields" id="fields-short" style="display:none;">
                        <label style="font-weight:600; margin-bottom:8px; display:block;">Accepted Answers (one per
                            line, case-insensitive)</label>
                        <textarea id="short-answers" rows="3"
                            placeholder="Answer 1&#10;Answer 2&#10;إجابة 3"></textarea>
                    </div>

                    <!-- Matching -->
                    <div class="question-type-fields" id="fields-matching" style="display:none;">
                        <label style="font-weight:600; margin-bottom:8px; display:block;">Pairs (left → right)</label>
                        <div id="matching-pairs-list"></div>
                        <button type="button"
                            class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm add-pair-btn"
                            style="margin-top:8px;">+ Add Pair</button>
                    </div>

                    <!-- Ordering -->
                    <div class="question-type-fields" id="fields-ordering" style="display:none;">
                        <label style="font-weight:600; margin-bottom:8px; display:block;">Items (in correct
                            order)</label>
                        <div id="ordering-items-list"></div>
                        <button type="button"
                            class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm add-order-item-btn"
                            style="margin-top:8px;">+ Add Item</button>
                        <p style="font-size:12px; color:#64748b; margin-top:4px;">Enter items in the CORRECT order. They
                            will be shuffled for students.</p>
                    </div>

                    <!-- Fill in the Blank -->
                    <div class="question-type-fields" id="fields-fill_blank" style="display:none;">
                        <label style="font-weight:600; margin-bottom:8px; display:block;">Answers (one per blank, use
                            ____ in question text)</label>
                        <textarea id="fill-blank-answers" rows="3"
                            placeholder="Answer for blank 1&#10;Answer for blank 2"></textarea>
                    </div>

                    <!-- Essay -->
                    <div class="question-type-fields" id="fields-essay" style="display:none;">
                        <div class="olama-exam-form-row">
                            <div class="olama-exam-form-group">
                                <label>Word Limit (0 = no limit)</label>
                                <input type="number" id="essay-word-limit" value="300" min="0">
                            </div>
                            <div class="olama-exam-form-group">
                                <label>Guidelines (optional)</label>
                                <input type="text" id="essay-guidelines" placeholder="e.g. Support with evidence">
                            </div>
                        </div>
                    </div>

                    <!-- Explanation -->
                    <div class="olama-exam-form-group" style="margin-top:16px;">
                        <label><?php echo olama_exam_translate('Explanation'); ?>
                            (<?php echo olama_exam_translate('optional'); ?>)</label>
                        <textarea name="explanation" id="q-explanation" rows="2"></textarea>
                    </div>
                </div>
                <div class="olama-exam-modal-footer">
                    <button type="button"
                        class="olama-exam-btn olama-exam-btn-outline olama-exam-modal-close"><?php echo olama_exam_translate('Cancel'); ?></button>
                    <button type="submit"
                        class="olama-exam-btn olama-exam-btn-primary"><?php echo olama_exam_translate('Save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.qb-unit-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s ease;
    cursor: default;
}
.qb-unit-card:hover { border-color: #6366f1; box-shadow: 0 2px 8px rgba(99,102,241,0.08); }
.qb-unit-card.active { border-color: #6366f1; background: #f5f3ff; }
.qb-unit-info { display: flex; align-items: center; gap: 14px; flex: 1; }
.qb-unit-number {
    background: linear-gradient(135deg, #6366f1, #818cf8);
    color: #fff;
    font-weight: 700;
    min-width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}
.qb-unit-name { font-weight: 600; color: #1e293b; font-size: 15px; }
.qb-unit-qcount {
    font-size: 13px;
    color: #64748b;
    background: #f1f5f9;
    padding: 4px 10px;
    border-radius: 99px;
    min-width: 80px;
    text-align: center;
}
.qb-unit-qcount.has-questions { background: #dcfce7; color: #16a34a; }
</style>

<script>
    (function ($) {
        const typeLabels = <?php echo json_encode($type_labels); ?>;
        const diffLabels = <?php echo json_encode($diff_labels); ?>;
        let activeUnitId = 0;
        let unitsList = [];

        // ── Grade → Subject Cascade ────────────────────────────
        $('#qb-grade-select').on('change', function () {
            const gradeId = $(this).val();
            const subjectSel = $('#qb-subject-select');

            // Reset everything downstream
            subjectSel.html('<option value="0">— <?php echo olama_exam_translate("Select Grade First"); ?> —</option>').prop('disabled', true);
            $('#qb-units-container, #qb-completion-bar, #qb-questions-panel').hide();
            activeUnitId = 0;

            if (!gradeId || gradeId == '0') return;

            subjectSel.html('<option value="0">⏳ <?php echo olama_exam_translate("Loading..."); ?></option>').prop('disabled', true);

            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_get_subjects_by_grade',
                nonce: olamaExam.nonce,
                grade_id: gradeId,
            }, function (res) {
                if (!res.success) return;
                let html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                res.data.forEach(function (s) {
                    html += `<option value="${s.id}">${s.subject_name}</option>`;
                });
                subjectSel.html(html).prop('disabled', false);
            });
        });

        // ── Subject Selected → Load Units ──────────────────────
        $('#qb-subject-select').on('change', function () {
            const subjectId = $(this).val();
            const gradeId = $('#qb-grade-select').val();

            $('#qb-questions-panel').hide();
            activeUnitId = 0;

            if (!subjectId || subjectId == '0' || !gradeId || gradeId == '0') {
                $('#qb-units-container, #qb-completion-bar').hide();
                return;
            }

            loadUnits(gradeId, subjectId);
        });

        // ── Load Curriculum Units ──────────────────────────────
        function loadUnits(gradeId, subjectId) {
            $('#qb-units-container').show();
            $('#qb-units-loading').show();
            $('#qb-units-list, #qb-units-empty').hide();

            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_get_units_by_subject',
                nonce: olamaExam.nonce,
                grade_id: gradeId,
                subject_id: subjectId,
            }, function (res) {
                $('#qb-units-loading').hide();
                if (!res.success) return;

                unitsList = res.data;
                if (unitsList.length === 0) {
                    $('#qb-units-empty').show();
                    $('#qb-completion-bar').hide();
                    return;
                }

                // Completion ratio
                const withQuestions = unitsList.filter(u => parseInt(u.question_count) > 0).length;
                const total = unitsList.length;
                const pct = Math.round((withQuestions / total) * 100);
                $('#qb-coverage-text').text(`${withQuestions} / ${total} <?php echo olama_exam_translate('units covered'); ?>`);
                $('#qb-coverage-fill').css('width', pct + '%');
                $('#qb-coverage-pct').text(pct + '%');
                $('#qb-completion-bar').show();

                // Render unit cards
                let html = '';
                unitsList.forEach(function (u) {
                    const qc = parseInt(u.question_count) || 0;
                    const hasQ = qc > 0 ? 'has-questions' : '';
                    html += `<div class="qb-unit-card" data-unit-id="${u.id}">
                        <div class="qb-unit-info">
                            <div class="qb-unit-number">${escapeHtml(u.unit_number)}</div>
                            <div class="qb-unit-name">${escapeHtml(u.unit_name)}</div>
                        </div>
                        <div class="qb-unit-qcount ${hasQ}">${qc} <?php echo olama_exam_translate('questions'); ?></div>
                        <button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm qb-view-unit-btn"
                            data-unit-id="${u.id}" data-unit-name="${escapeHtml(u.unit_name)}" style="margin-inline-start:12px;">
                            📋 <?php echo olama_exam_translate('View Questions'); ?>
                        </button>
                    </div>`;
                });
                $('#qb-units-list').html(html).show();
            });
        }

        // ── View Questions for a Unit ──────────────────────────
        $(document).on('click', '.qb-view-unit-btn', function () {
            activeUnitId = parseInt($(this).data('unit-id'));
            const unitName = $(this).data('unit-name');
            $('#qb-active-unit-name').text(unitName);
            $('#q-unit-id').val(activeUnitId);

            // Highlight active card
            $('.qb-unit-card').removeClass('active');
            $(`.qb-unit-card[data-unit-id="${activeUnitId}"]`).addClass('active');

            $('#qb-questions-panel').show();
            loadQuestions();

            // Scroll to panel
            $('html, body').animate({ scrollTop: $('#qb-questions-panel').offset().top - 40 }, 300);
        });

        // ── Close Questions Panel ──────────────────────────────
        $('#qb-close-panel-btn').on('click', function () {
            $('#qb-questions-panel').hide();
            $('.qb-unit-card').removeClass('active');
            activeUnitId = 0;
        });

        // ── Load Questions ─────────────────────────────────────
        function loadQuestions() {
            const filters = {
                action: 'olama_exam_get_questions',
                nonce: olamaExam.nonce,
                unit_id: activeUnitId,
                type: $('#filter-type').val(),
                difficulty: $('#filter-difficulty').val(),
                search: $('#filter-search').val(),
            };

            $('#questions-loading').show();
            $('#questions-table, #questions-empty').hide();

            $.post(olamaExam.ajaxUrl, filters, function (res) {
                $('#questions-loading').hide();
                if (!res.success) return;

                const qs = res.data;
                $('#qb-question-count').text(qs.length);

                if (qs.length === 0) {
                    $('#questions-empty').show();
                    return;
                }

                let html = '';
                qs.forEach(function (q) {
                    const text = q.question_text.length > 80 ? q.question_text.substring(0, 80) + '...' : q.question_text;
                    const imgIcon = q.image_filename ? '📷 ' : '';
                    html += `<tr>
                    <td><input type="checkbox" class="olama-exam-row-check" value="${q.id}"></td>
                    <td>${q.id}</td>
                    <td>${imgIcon}${escapeHtml(text)}</td>
                    <td><span class="olama-exam-badge olama-exam-badge-${q.type}">${typeLabels[q.type] || q.type}</span></td>
                    <td><span class="olama-exam-badge olama-exam-badge-${q.difficulty}">${diffLabels[q.difficulty] || q.difficulty}</span></td>
                    <td style="color:#64748b;">${q.version}</td>
                    <td>
                        <button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm olama-exam-edit-question" data-id="${q.id}">✏️</button>
                        <button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm olama-exam-duplicate-question" data-id="${q.id}">📋</button>
                        <button class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm olama-exam-delete-question" data-id="${q.id}">🗑</button>
                    </td>
                </tr>`;
                });

                $('#questions-tbody').html(html);
                $('#questions-table').show();
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // ── Filter Events ──────────────────────────────────────
        $('#filter-type, #filter-difficulty').on('change', function () { if (activeUnitId) loadQuestions(); });
        let searchTimer;
        $('#filter-search').on('keyup', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { if (activeUnitId) loadQuestions(); }, 300);
        });

        // ── Bulk delete toggle ─────────────────────────────────
        $(document).on('change', '.olama-exam-row-check, #olama-exam-select-all', function () {
            const checked = $('.olama-exam-row-check:checked').length;
            $('.olama-exam-bulk-delete').toggle(checked > 0);
        });

        // ── Question Type Switch ───────────────────────────────
        $('#question-type-select').on('change', function () {
            $('.question-type-fields').hide();
            $('#fields-' + $(this).val()).show();
        });

        // ── MCQ Choices ────────────────────────────────────────
        function addMcqChoice(text, isCorrect) {
            const idx = $('#mcq-choices-list .mcq-choice-row').length;
            $('#mcq-choices-list').append(`
            <div class="mcq-choice-row" style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                <input type="radio" name="mcq_correct" value="${idx}" ${isCorrect ? 'checked' : ''}>
                <input type="text" class="mcq-choice-text" value="${escapeHtml(text || '')}" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;" placeholder="Choice ${idx + 1}">
                <button type="button" class="remove-choice-btn" style="border:none; background:none; cursor:pointer; color:#dc2626; font-size:18px;">&times;</button>
            </div>
        `);
        }

        $(document).on('click', '.add-choice-btn', function () { addMcqChoice('', false); });
        $(document).on('click', '.remove-choice-btn', function () {
            $(this).closest('.mcq-choice-row').remove();
            $('#mcq-choices-list .mcq-choice-row').each(function (i) {
                $(this).find('input[type=radio]').val(i);
            });
        });

        // ── Matching Pairs ─────────────────────────────────────
        function addMatchingPair(left, right) {
            $('#matching-pairs-list').append(`
            <div class="matching-pair-row" style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                <input type="text" class="match-left" value="${escapeHtml(left || '')}" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;" placeholder="Left">
                <span style="color:#64748b;">→</span>
                <input type="text" class="match-right" value="${escapeHtml(right || '')}" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;" placeholder="Right">
                <button type="button" class="remove-pair-btn" style="border:none; background:none; cursor:pointer; color:#dc2626; font-size:18px;">&times;</button>
            </div>
        `);
        }

        $(document).on('click', '.add-pair-btn', function () { addMatchingPair('', ''); });
        $(document).on('click', '.remove-pair-btn', function () { $(this).closest('.matching-pair-row').remove(); });

        // ── Ordering Items ─────────────────────────────────────
        function addOrderingItem(text) {
            const idx = $('#ordering-items-list .ordering-item-row').length + 1;
            $('#ordering-items-list').append(`
            <div class="ordering-item-row" style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                <span style="color:#64748b; width:24px; text-align:center;">${idx}.</span>
                <input type="text" class="order-item-text" value="${escapeHtml(text || '')}" style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px;" placeholder="Item ${idx}">
                <button type="button" class="remove-order-item-btn" style="border:none; background:none; cursor:pointer; color:#dc2626; font-size:18px;">&times;</button>
            </div>
        `);
        }

        $(document).on('click', '.add-order-item-btn', function () { addOrderingItem(''); });
        $(document).on('click', '.remove-order-item-btn', function () {
            $(this).closest('.ordering-item-row').remove();
            $('#ordering-items-list .ordering-item-row').each(function (i) {
                $(this).find('span:first').text((i + 1) + '.');
            });
        });

        // ── Build answers_json from form ───────────────────────
        function buildAnswersJson(type) {
            switch (type) {
                case 'mcq':
                    const choices = [];
                    let correct = 0;
                    $('#mcq-choices-list .mcq-choice-row').each(function (i) {
                        choices.push($(this).find('.mcq-choice-text').val().trim());
                        if ($(this).find('input[type=radio]').is(':checked')) correct = i;
                    });
                    return JSON.stringify({ choices, correct });

                case 'tf':
                    return JSON.stringify({ correct: $('input[name=tf_correct]:checked').val() === 'true' });

                case 'short':
                    const answers = $('#short-answers').val().split('\n').map(a => a.trim()).filter(a => a);
                    return JSON.stringify({ answers });

                case 'matching':
                    const pairs = [];
                    $('#matching-pairs-list .matching-pair-row').each(function () {
                        pairs.push({ left: $(this).find('.match-left').val().trim(), right: $(this).find('.match-right').val().trim() });
                    });
                    return JSON.stringify({ pairs });

                case 'ordering':
                    const items = [];
                    const correctOrder = [];
                    $('#ordering-items-list .ordering-item-row').each(function (i) {
                        items.push($(this).find('.order-item-text').val().trim());
                        correctOrder.push(i);
                    });
                    return JSON.stringify({ items, correct_order: correctOrder });

                case 'fill_blank':
                    const blanks = $('#fill-blank-answers').val().split('\n').map(a => a.trim()).filter(a => a);
                    return JSON.stringify({ answers: blanks });

                case 'essay':
                    return JSON.stringify({
                        word_limit: parseInt($('#essay-word-limit').val()) || 0,
                        guidelines: $('#essay-guidelines').val().trim()
                    });
            }
            return '{}';
        }

        // ── Populate form from question data ───────────────────
        function populateForm(q) {
            $('#q-id').val(q.id);
            $('#question-type-select').val(q.type).trigger('change');
            $('#q-difficulty').val(q.difficulty);
            $('#q-unit-id').val(q.unit_id || activeUnitId);
            $('#q-language').val(q.language);
            $('#q-text').val(q.question_text);
            $('#q-explanation').val(q.explanation || '');
            $('#q-image-filename').val(q.image_filename || '');

            if (q.image_filename) {
                $('#q-image-preview').text(q.image_filename);
                $('.olama-exam-remove-image').show();
            } else {
                $('#q-image-preview').text('');
                $('.olama-exam-remove-image').hide();
            }

            const data = typeof q.answers_decoded === 'object' ? q.answers_decoded : JSON.parse(q.answers_json || '{}');

            switch (q.type) {
                case 'mcq':
                    $('#mcq-choices-list').empty();
                    (data.choices || []).forEach((c, i) => addMcqChoice(c, i === (data.correct || 0)));
                    break;
                case 'tf':
                    $(`input[name=tf_correct][value=${data.correct ? 'true' : 'false'}]`).prop('checked', true);
                    break;
                case 'short':
                    $('#short-answers').val((data.answers || []).join('\n'));
                    break;
                case 'matching':
                    $('#matching-pairs-list').empty();
                    (data.pairs || []).forEach(p => addMatchingPair(p.left, p.right));
                    break;
                case 'ordering':
                    $('#ordering-items-list').empty();
                    (data.items || []).forEach(item => addOrderingItem(item));
                    break;
                case 'fill_blank':
                    $('#fill-blank-answers').val((data.answers || []).join('\n'));
                    break;
                case 'essay':
                    $('#essay-word-limit').val(data.word_limit || 300);
                    $('#essay-guidelines').val(data.guidelines || '');
                    break;
            }
        }

        // ── Reset form ─────────────────────────────────────────
        function resetForm() {
            $('#olama-exam-question-form')[0].reset();
            $('#q-id').val(0);
            $('#q-unit-id').val(activeUnitId);
            $('#q-image-filename').val('');
            $('#q-image-preview').text('');
            $('.olama-exam-remove-image').hide();
            $('#mcq-choices-list, #matching-pairs-list, #ordering-items-list').empty();
            addMcqChoice('', true);
            addMcqChoice('', false);
            $('#question-type-select').trigger('change');
            $('#question-modal-title').text('<?php echo olama_exam_translate("Add Question"); ?>');
        }

        // ── Open Add Question ──────────────────────────────────
        $('#qb-add-question-btn').on('click', function () {
            resetForm();
            $('#question-modal').addClass('active');
        });

        // ── Import GIFT/CSV ────────────────────────────────────
        $('#qb-import-gift-btn').on('click', function () {
            const gradeId = $('#qb-grade-select').val();
            const subjectId = $('#qb-subject-select').val();
            window.location.href = `?page=olama-exam-import-gift&unit_id=${activeUnitId}&grade_id=${gradeId}&subject_id=${subjectId}`;
        });

        $('#qb-import-csv-btn').on('click', function () {
            const gradeId = $('#qb-grade-select').val();
            const subjectId = $('#qb-subject-select').val();
            window.location.href = `?page=olama-exam-import-csv&unit_id=${activeUnitId}&grade_id=${gradeId}&subject_id=${subjectId}`;
        });

        // ── Open Edit Question ─────────────────────────────────
        $(document).on('click', '.olama-exam-edit-question', function () {
            const id = $(this).data('id');
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_get_questions',
                nonce: olamaExam.nonce,
                unit_id: activeUnitId,
            }, function (res) {
                if (!res.success) return;
                const q = res.data.find(q => q.id == id);
                if (!q) return;
                resetForm();
                populateForm(q);
                $('#question-modal-title').text('<?php echo olama_exam_translate("Edit Question"); ?>');
                $('#question-modal').addClass('active');
            });
        });

        // ── Save Question ──────────────────────────────────────
        $('#olama-exam-question-form').on('submit', function (e) {
            e.preventDefault();
            const type = $('#question-type-select').val();
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_save_question',
                nonce: olamaExam.nonce,
                id: $('#q-id').val(),
                unit_id: $('#q-unit-id').val(),
                type: type,
                question_text: $('#q-text').val(),
                answers_json: buildAnswersJson(type),
                difficulty: $('#q-difficulty').val(),
                language: $('#q-language').val(),
                explanation: $('#q-explanation').val(),
                image_filename: $('#q-image-filename').val(),
            }, function (res) {
                if (res.success) {
                    ExamAdmin.toast(res.data.message);
                    ExamAdmin.closeModal();
                    loadQuestions();
                    // Refresh unit counts
                    loadUnits($('#qb-grade-select').val(), $('#qb-subject-select').val());
                } else {
                    ExamAdmin.toast(res.data.message, 'error');
                }
            });
        });

        // ── Delete Question ────────────────────────────────────
        $(document).on('click', '.olama-exam-delete-question', function () {
            if (!confirm('Delete this question?')) return;
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_delete_question',
                nonce: olamaExam.nonce,
                id: $(this).data('id'),
            }, function (res) {
                ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
                if (res.success) {
                    loadQuestions();
                    loadUnits($('#qb-grade-select').val(), $('#qb-subject-select').val());
                }
            });
        });

        // ── Duplicate Question ─────────────────────────────────
        $(document).on('click', '.olama-exam-duplicate-question', function () {
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_duplicate_question',
                nonce: olamaExam.nonce,
                id: $(this).data('id'),
            }, function (res) {
                ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
                if (res.success) {
                    loadQuestions();
                    loadUnits($('#qb-grade-select').val(), $('#qb-subject-select').val());
                }
            });
        });

        // ── Bulk Delete ────────────────────────────────────────
        $(document).on('click', '.olama-exam-bulk-delete', function () {
            const ids = [];
            $('.olama-exam-row-check:checked').each(function () { ids.push($(this).val()); });
            if (ids.length === 0 || !confirm(`Delete ${ids.length} question(s)?`)) return;
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_bulk_delete_questions',
                nonce: olamaExam.nonce,
                ids: ids,
            }, function (res) {
                ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
                if (res.success) {
                    loadQuestions();
                    loadUnits($('#qb-grade-select').val(), $('#qb-subject-select').val());
                }
            });
        });

        // ── Image Upload ───────────────────────────────────────
        $(document).on('click', '.olama-exam-upload-image', function (e) {
            e.preventDefault();
            const input = $('<input type="file" accept="image/*" style="display:none;">');
            $('body').append(input);
            input.trigger('click');
            input.on('change', function () {
                const file = this.files[0];
                if (!file) return;
                const fd = new FormData();
                fd.append('action', 'olama_exam_upload_question_image');
                fd.append('nonce', olamaExam.nonce);
                fd.append('question_image', file);
                $.ajax({
                    url: olamaExam.ajaxUrl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function (res) {
                        if (res.success) {
                            $('#q-image-filename').val(res.data.filename);
                            $('#q-image-preview').text(res.data.filename);
                            $('.olama-exam-remove-image').show();
                            ExamAdmin.toast(res.data.message);
                        } else {
                            ExamAdmin.toast(res.data.message, 'error');
                        }
                    }
                });
                input.remove();
            });
        });

        $(document).on('click', '.olama-exam-remove-image', function () {
            $('#q-image-filename').val('');
            $('#q-image-preview').text('');
            $(this).hide();
        });

        // ── Deep Linking Support ───────────────────────────────
        function handleDeepLinking() {
            const urlParams = new URLSearchParams(window.location.search);
            const editQuestionId = urlParams.get('edit_question');
            const gradeId = urlParams.get('grade_id');
            const subjectId = urlParams.get('subject_id');
            const unitId = urlParams.get('unit_id');

            if (!gradeId) return;

            // Step 1: Set Grade
            $('#qb-grade-select').val(gradeId).trigger('change');

            // Step 2: Set Subject (wait for cascade)
            const checkSubject = setInterval(() => {
                const subjectSel = $('#qb-subject-select');
                if (subjectId && subjectSel.find(`option[value="${subjectId}"]`).length > 0) {
                    clearInterval(checkSubject);
                    subjectSel.val(subjectId).trigger('change');

                    // Step 3: Handle Unit / Question (wait for units list)
                    const checkUnits = setInterval(() => {
                        if (unitsList.length > 0) {
                            clearInterval(checkUnits);

                            if (editQuestionId) {
                                // Sub-scenario: Open Edit Modal
                                $.post(olamaExam.ajaxUrl, {
                                    action: 'olama_exam_get_questions',
                                    nonce: olamaExam.nonce,
                                    id: editQuestionId
                                }, function (res) {
                                    if (res.success && res.data.length > 0) {
                                        const q = res.data[0];
                                        activeUnitId = q.unit_id;
                                        $('#qb-units-list .qb-unit-card').removeClass('active');
                                        $(`#qb-units-list .qb-unit-card[data-id="${q.unit_id}"]`).addClass('active');
                                        loadQuestions();
                                        resetForm();
                                        populateForm(q);
                                        $('#question-modal-title').text('<?php echo olama_exam_translate("Edit Question"); ?>');
                                        $('#question-modal').addClass('active');
                                    }
                                });
                            } else if (unitId) {
                                // Sub-scenario: Just expand the unit
                                activeUnitId = unitId;
                                $('#qb-units-list .qb-unit-card').removeClass('active');
                                $(`#qb-units-list .qb-unit-card[data-id="${unitId}"]`).addClass('active');
                                loadQuestions();
                            }
                        }
                    }, 200);
                }
            }, 200);
        }

        handleDeepLinking();

    })(jQuery);
</script>