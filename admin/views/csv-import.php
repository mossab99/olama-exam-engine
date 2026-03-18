<?php
/**
 * Admin View: CSV Import
 * Upload a CSV file, preview parsed questions, then import into a unit.
 */
if (!defined('ABSPATH'))
    exit;

global $wpdb;

// Get active year and semester from the SIS
$active_year = Olama_School_Academic::get_active_year();
$active_semester = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}olama_semesters WHERE is_active = 1 LIMIT 1");
$grades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_grades WHERE is_active = 1 ORDER BY grade_name ASC");
?>
<div class="olama-exam-wrap">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Import CSV'); ?></h1>
        </div>
        <div class="actions" style="display:flex; gap:10px;">
            <a href="?page=olama-exam&grade_id=<?php echo intval($_GET['grade_id'] ?? 0); ?>&subject_id=<?php echo intval($_GET['subject_id'] ?? 0); ?>&unit_id=<?php echo intval($_GET['unit_id'] ?? 0); ?>" 
                class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Back to Question Bank'); ?>
            </a>
            <a href="<?php echo admin_url('admin-ajax.php?action=olama_exam_download_csv_template&nonce=' . wp_create_nonce('olama_exam_nonce')); ?>"
                class="olama-exam-btn olama-exam-btn-outline" id="download-template-btn">
                📥 <?php echo olama_exam_translate('Download Template'); ?>
            </a>
        </div>
    </div>

    <!-- Settings Card -->
    <div class="olama-exam-card">
        <div class="olama-exam-card-header">
            <h3>📊 CSV / Excel Import</h3>
        </div>

        <!-- Academic Context (read-only) + Grade/Subject/Unit selectors -->
        <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr 1fr 1fr;">
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Academic Year'); ?></label>
                <input type="text" value="<?php echo esc_attr($active_year->year_name ?? '—'); ?>" readonly
                    style="background:#f1f5f9; cursor:not-allowed;">
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Semester'); ?></label>
                <input type="text" value="<?php echo esc_attr($active_semester->semester_name ?? '—'); ?>"
                    readonly style="background:#f1f5f9; cursor:not-allowed;">
                <input type="hidden" id="csv-semester-id" value="<?php echo esc_attr($active_semester->id ?? 0); ?>">
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Grade'); ?></label>
                <select id="csv-grade">
                    <option value="0">— <?php echo olama_exam_translate('Select'); ?> —</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?php echo $g->id; ?>"><?php echo esc_html($g->grade_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Subject'); ?></label>
                <select id="csv-subject" disabled>
                    <option value="0">— <?php echo olama_exam_translate('Select Grade First'); ?> —</option>
                </select>
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Unit'); ?></label>
                <select id="csv-unit" disabled>
                    <option value="0">— <?php echo olama_exam_translate('Select Subject First'); ?> —</option>
                </select>
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Lesson'); ?></label>
                <select id="csv-lesson" disabled>
                    <option value="0">— <?php echo olama_exam_translate('General Unit Questions'); ?> —</option>
                </select>
            </div>
        </div>

        <div class="olama-exam-form-row">
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('CSV File'); ?></label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="file" id="csv-file-input" accept=".csv">
                </div>
            </div>
        </div>

        <!-- CSV Format Guide -->
        <div
            style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:14px; margin-bottom:16px; font-size:13px;">
            <strong>📋 CSV Column Format:</strong><br>
            <code
                style="background:#e0f2fe; padding:2px 6px; border-radius:4px;">type, question, answer_data, correct, difficulty, category, language</code>
            <ul style="margin:8px 0 0 16px; line-height:1.8;">
                <li><strong>mcq:</strong> answer_data = <code>choice1|choice2|choice3</code>, correct =
                    <code>choice2</code></li>
                <li><strong>tf:</strong> correct = <code>true</code> or <code>false</code></li>
                <li><strong>short:</strong> correct = <code>answer1|answer2</code> (multiple accepted answers)</li>
                <li><strong>matching:</strong> answer_data = <code>Left1:Right1|Left2:Right2</code></li>
                <li><strong>ordering:</strong> answer_data = <code>Item1|Item2</code>, correct = <code>2|1|3</code>
                    (correct order)</li>
                <li><strong>fill_blank:</strong> use <code>____</code> in question, correct =
                    <code>answer1|answer2</code></li>
                <li><strong>essay:</strong> answer_data = <code>word_limit:300</code> (optional)</li>
            </ul>
        </div>

        <div style="display:flex; gap:10px;">
            <button id="csv-preview-btn" class="olama-exam-btn olama-exam-btn-outline">
                👁 <?php echo olama_exam_translate('Preview'); ?>
            </button>
            <button id="csv-import-btn" class="olama-exam-btn olama-exam-btn-primary" disabled>
                📥 <?php echo olama_exam_translate('Import'); ?>
            </button>
        </div>
    </div>

    <!-- Preview Card -->
    <div class="olama-exam-card" id="csv-preview-card" style="display:none;">
        <div class="olama-exam-card-header">
            <h3>📋 Preview (<span id="csv-preview-count">0</span> questions)</h3>
        </div>
        <div id="csv-errors" style="display:none; margin-bottom:16px;"></div>
        <table class="olama-exam-table" id="csv-preview-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo olama_exam_translate('Question Type'); ?></th>
                    <th><?php echo olama_exam_translate('Difficulty'); ?></th>
                    <th><?php echo olama_exam_translate('Question Text'); ?></th>
                </tr>
            </thead>
            <tbody id="csv-preview-tbody"></tbody>
        </table>
    </div>

    <!-- Result Card -->
    <div class="olama-exam-card" id="csv-result-card" style="display:none;">
        <div class="olama-exam-card-header">
            <h3>✅ Import Complete</h3>
        </div>
        <div id="csv-result-body"></div>
    </div>
</div>

<script>
    (function ($) {
        let parsedData = null;

        // ── Grade → Subject → Unit Cascade ─────────────────────
        $('#csv-grade').on('change', function () {
            const gradeId = $(this).val();
            $('#csv-subject').html('<option value="0">⏳</option>').prop('disabled', true);
            $('#csv-unit').html('<option value="0">— <?php echo olama_exam_translate("Select Subject First"); ?> —</option>').prop('disabled', true);
            if (!gradeId || gradeId == '0') return;
            $.post(olamaExam.ajaxUrl, { action: 'olama_exam_get_subjects_by_grade', nonce: olamaExam.nonce, grade_id: gradeId }, function (res) {
                let html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                (res.data || []).forEach(s => html += `<option value="${s.id}">${s.subject_name}</option>`);
                $('#csv-subject').html(html).prop('disabled', false);
            });
        });

        $('#csv-subject').on('change', function () {
            const subjectId = $(this).val();
            const gradeId = $('#csv-grade').val();
            const semesterId = $('#csv-semester-id').val();
            $('#csv-unit').html('<option value="0">⏳</option>').prop('disabled', true);
            if (!subjectId || subjectId == '0') return;
            $.post(olamaExam.ajaxUrl, { 
                action: 'olama_exam_get_units_by_subject', 
                nonce: olamaExam.nonce, 
                grade_id: gradeId, 
                subject_id: subjectId,
                semester_id: semesterId
            }, function (res) {
                let html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                (res.data || []).forEach(u => html += `<option value="${u.id}">${u.unit_number} - ${u.unit_name}</option>`);
                $('#csv-unit').html(html).prop('disabled', false);

                // Auto-select unit from URL
                const unitId = new URLSearchParams(window.location.search).get('unit_id');
                if (unitId && $('#csv-unit option[value="' + unitId + '"]').length > 0) {
                    $('#csv-unit').val(unitId);
                    $('#csv-unit').trigger('change');
                }
            });
        });

        $('#csv-unit').on('change', function () {
            const unitId = $(this).val();
            $('#csv-lesson').html('<option value="0">— <?php echo olama_exam_translate("General Unit Questions"); ?> —</option>').prop('disabled', true);
            if (!unitId || unitId == '0') return;
            $.post(olamaExam.ajaxUrl, { 
                action: 'olama_exam_get_lessons_by_unit', 
                nonce: olamaExam.nonce, 
                unit_id: unitId
            }, function (res) {
                let html = '<option value="0">— <?php echo olama_exam_translate("General Unit Questions"); ?> —</option>';
                if (res.success && res.data) {
                    res.data.forEach(l => html += `<option value="${l.id}">${l.lesson_number} - ${l.lesson_title}</option>`);
                }
                $('#csv-lesson').html(html).prop('disabled', false);

                const paramLessonId = new URLSearchParams(window.location.search).get('lesson_id');
                if (paramLessonId && $('#csv-lesson option[value="' + paramLessonId + '"]').length > 0) {
                    $('#csv-lesson').val(paramLessonId);
                }
            });
        });

        // ── Auto Pre-selection ────────────────────────────────
        const urlParams = new URLSearchParams(window.location.search);
        const gradeId = urlParams.get('grade_id');
        const subjectId = urlParams.get('subject_id');

        if (gradeId) {
            $('#csv-grade').val(gradeId).trigger('change');
            if (subjectId) {
                const checkSubject = setInterval(() => {
                    const subjectSel = $('#csv-subject');
                    if (subjectSel.find(`option[value="${subjectId}"]`).length > 0) {
                        clearInterval(checkSubject);
                        subjectSel.val(subjectId).trigger('change');
                    }
                }, 200);
            }
        }

        // Preview CSV
        $('#csv-preview-btn').on('click', function () {
            const fileInput = document.getElementById('csv-file-input');
            if (!fileInput.files.length) { ExamAdmin.toast('Please select a CSV file.', 'error'); return; }

            const fd = new FormData();
            fd.append('action', 'olama_exam_import_csv');
            fd.append('nonce', olamaExam.nonce);
            fd.append('csv_file', fileInput.files[0]);
            fd.append('mode', 'preview');

            $(this).prop('disabled', true).text('⏳ Parsing...');
            $.ajax({
                url: olamaExam.ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (res) {
                    $('#csv-preview-btn').prop('disabled', false).text('👁 Preview');
                    if (!res.success) { ExamAdmin.toast(res.data.message || 'Parse error', 'error'); return; }

                    parsedData = res.data;
                    $('#csv-preview-count').text(parsedData.count);

                    if (parsedData.errors && parsedData.errors.length > 0) {
                        let errHtml = '<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px; color:#dc2626; font-size:13px;">';
                        errHtml += '<strong>⚠️ Errors:</strong><ul style="margin:4px 0 0 16px;">';
                        parsedData.errors.forEach(e => errHtml += `<li>${e.message || e}</li>`);
                        errHtml += '</ul></div>';
                        $('#csv-errors').html(errHtml).show();
                    } else {
                        $('#csv-errors').hide();
                    }

                    let html = '';
                    parsedData.questions.forEach((q, i) => {
                        const text = q.question_text.length > 60 ? q.question_text.substring(0, 60) + '...' : q.question_text;
                        html += `<tr>
                        <td>${i + 1}</td>
                        <td><span class="olama-exam-badge olama-exam-badge-${q.type}">${q.type.toUpperCase()}</span></td>
                        <td><span class="olama-exam-badge olama-exam-badge-${q.difficulty}">${q.difficulty}</span></td>
                        <td>${$('<span>').text(text).html()}</td>
                    </tr>`;
                    });
                    $('#csv-preview-tbody').html(html);
                    $('#csv-preview-card').show();
                    $('#csv-import-btn').prop('disabled', parsedData.count === 0);
                }
            });
        });

        // Import CSV
        $('#csv-import-btn').on('click', function () {
            const unitId = $('#csv-unit').val();
            if (!unitId || unitId == '0') {
                ExamAdmin.toast('<?php echo olama_exam_translate("Please select a unit for import."); ?>', 'error');
                return;
            }

            const fileInput = document.getElementById('csv-file-input');
            if (!fileInput.files.length) { ExamAdmin.toast('Please select a CSV file.', 'error'); return; }

            const fd = new FormData();
            fd.append('action', 'olama_exam_import_csv');
            fd.append('nonce', olamaExam.nonce);
            fd.append('csv_file', fileInput.files[0]);
            fd.append('unit_id', unitId);
            fd.append('lesson_id', $('#csv-lesson').val() || 0);
            fd.append('mode', 'import');

            $(this).prop('disabled', true).text('⏳ Importing...');
            $.ajax({
                url: olamaExam.ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (res) {
                    $('#csv-import-btn').prop('disabled', false).text('📥 Import');
                    if (!res.success) { ExamAdmin.toast(res.data.message || 'Import error', 'error'); return; }

                    const d = res.data;
                    let html = `<div style="display:flex; gap:24px; justify-content:center; padding:20px;">
                    <div style="text-align:center;"><div style="font-size:28px; font-weight:700; color:#059669;">${d.imported}</div><div style="color:#64748b;">Imported</div></div>
                    <div style="text-align:center;"><div style="font-size:28px; font-weight:700; color:#d97706;">${d.skipped}</div><div style="color:#64748b;">Skipped</div></div>
                    <div style="text-align:center;"><div style="font-size:28px; font-weight:700; color:#dc2626;">${(d.errors || []).length}</div><div style="color:#64748b;">Errors</div></div>
                </div>`;

                    if (d.errors && d.errors.length > 0) {
                        html += '<div style="margin-top:12px; font-size:13px; color:#dc2626;">';
                        d.errors.forEach(e => html += `<div>• ${typeof e === 'string' ? e : e.message}</div>`);
                        html += '</div>';
                    }

                    $('#csv-result-body').html(html);
                    $('#csv-result-card').show();
                    ExamAdmin.toast(`${d.imported} question(s) imported!`);
                }
            });
        });
    })(jQuery);
</script>