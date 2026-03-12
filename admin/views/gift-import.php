<?php
/**
 * Admin View: GIFT Import
 * Upload or paste GIFT content, preview parsed questions, then import into a unit.
 */
if (!defined('ABSPATH'))
    exit;

global $wpdb;
$grades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_grades WHERE is_active = 1 ORDER BY grade_name ASC");
?>
<div class="olama-exam-wrap">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Import GIFT'); ?></h1>
        </div>
        <div class="actions">
            <a href="?page=olama-exam&grade_id=<?php echo intval($_GET['grade_id'] ?? 0); ?>&subject_id=<?php echo intval($_GET['subject_id'] ?? 0); ?>&unit_id=<?php echo intval($_GET['unit_id'] ?? 0); ?>" 
                class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Back to Question Bank'); ?>
            </a>
        </div>
    </div>

    <!-- Settings Card -->
    <div class="olama-exam-card">
        <div class="olama-exam-card-header">
            <h3>📄 Moodle GIFT Format</h3>
        </div>

        <!-- Grade → Subject → Unit selectors -->
        <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr;">
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Grade'); ?></label>
                <select id="gift-grade">
                    <option value="0">— <?php echo olama_exam_translate('Select'); ?> —</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?php echo $g->id; ?>"><?php echo esc_html($g->grade_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Subject'); ?></label>
                <select id="gift-subject" disabled>
                    <option value="0">— <?php echo olama_exam_translate('Select Grade First'); ?> —</option>
                </select>
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Unit'); ?></label>
                <select id="gift-unit" disabled>
                    <option value="0">— <?php echo olama_exam_translate('Select Subject First'); ?> —</option>
                </select>
            </div>
        </div>

        <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr;">
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Language'); ?></label>
                <select id="gift-language">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div class="olama-exam-form-group">
                <label><?php echo olama_exam_translate('Difficulty'); ?></label>
                <select id="gift-difficulty">
                    <option value="easy"><?php echo olama_exam_translate('Easy'); ?></option>
                    <option value="medium" selected><?php echo olama_exam_translate('Medium'); ?></option>
                    <option value="hard"><?php echo olama_exam_translate('Hard'); ?></option>
                </select>
            </div>
        </div>

        <!-- GIFT Content Input -->
        <div class="olama-exam-form-group">
            <label>GIFT Content (paste or upload a .txt file)</label>
            <textarea id="gift-content" rows="12" style="font-family:monospace; font-size:13px;" placeholder="// Example MCQ
::Capital Question::What is the capital of France?{=Paris ~London ~Berlin ~Madrid}

// Example True/False
The Earth is round.{TRUE}

// Example Short Answer
What color is the sky?{=Blue =blue}

// Example Matching
Match countries to capitals.{
=France -> Paris
=Germany -> Berlin
=Japan -> Tokyo
}"></textarea>
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
            <label class="olama-exam-btn olama-exam-btn-outline" style="cursor:pointer;">
                📁 <?php echo olama_exam_translate('Upload .txt File'); ?>
                <input type="file" id="gift-file-input" accept=".txt,.gift" style="display:none;">
            </label>
            <button id="gift-preview-btn" class="olama-exam-btn olama-exam-btn-outline">
                👁 <?php echo olama_exam_translate('Preview'); ?>
            </button>
            <button id="gift-import-btn" class="olama-exam-btn olama-exam-btn-primary" disabled>
                📥 <?php echo olama_exam_translate('Import'); ?>
            </button>
            <span id="gift-status" style="font-size:14px; color:#64748b;"></span>
        </div>
    </div>

    <!-- Preview Card -->
    <div class="olama-exam-card" id="gift-preview-card" style="display:none;">
        <div class="olama-exam-card-header">
            <h3>📋 Preview (<span id="gift-preview-count">0</span> questions)</h3>
        </div>
        <div id="gift-errors" style="display:none; margin-bottom:16px;"></div>
        <table class="olama-exam-table" id="gift-preview-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo olama_exam_translate('Question Type'); ?></th>
                    <th><?php echo olama_exam_translate('Question Text'); ?></th>
                    <th>Answer</th>
                </tr>
            </thead>
            <tbody id="gift-preview-tbody"></tbody>
        </table>
    </div>

    <!-- Result Card -->
    <div class="olama-exam-card" id="gift-result-card" style="display:none;">
        <div class="olama-exam-card-header">
            <h3>✅ Import Complete</h3>
        </div>
        <div id="gift-result-body"></div>
    </div>
</div>

<script>
    (function ($) {
        // ── Grade → Subject → Unit Cascade ─────────────────────
        $('#gift-grade').on('change', function () {
            const gradeId = $(this).val();
            $('#gift-subject').html('<option value="0">⏳</option>').prop('disabled', true);
            $('#gift-unit').html('<option value="0">— <?php echo olama_exam_translate("Select Subject First"); ?> —</option>').prop('disabled', true);
            if (!gradeId || gradeId == '0') return;
            $.post(olamaExam.ajaxUrl, { action: 'olama_exam_get_subjects_by_grade', nonce: olamaExam.nonce, grade_id: gradeId }, function (res) {
                let html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                (res.data || []).forEach(s => html += `<option value="${s.id}">${s.subject_name}</option>`);
                $('#gift-subject').html(html).prop('disabled', false);
            });
        });

        $('#gift-subject').on('change', function () {
            const subjectId = $(this).val();
            const gradeId = $('#gift-grade').val();
            $('#gift-unit').html('<option value="0">⏳</option>').prop('disabled', true);
            if (!subjectId || subjectId == '0') return;
            $.post(olamaExam.ajaxUrl, { action: 'olama_exam_get_units_by_subject', nonce: olamaExam.nonce, grade_id: gradeId, subject_id: subjectId }, function (res) {
                let html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                (res.data || []).forEach(u => html += `<option value="${u.id}">${u.unit_number} - ${u.unit_name}</option>`);
                $('#gift-unit').html(html).prop('disabled', false);

                // Auto-select unit from URL
                const unitId = new URLSearchParams(window.location.search).get('unit_id');
                if (unitId && $('#gift-unit option[value="' + unitId + '"]').length > 0) {
                    $('#gift-unit').val(unitId);
                }
            });
        });

        // ── Auto Pre-selection ────────────────────────────────
        const urlParams = new URLSearchParams(window.location.search);
        const gradeId = urlParams.get('grade_id');
        const subjectId = urlParams.get('subject_id');

        if (gradeId) {
            $('#gift-grade').val(gradeId).trigger('change');
            if (subjectId) {
                const checkSubject = setInterval(() => {
                    const subjectSel = $('#gift-subject');
                    if (subjectSel.find(`option[value="${subjectId}"]`).length > 0) {
                        clearInterval(checkSubject);
                        subjectSel.val(subjectId).trigger('change');
                    }
                }, 200);
            }
        }

        // Load file content into textarea
        $('#gift-file-input').on('change', function () {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                $('#gift-content').val(e.target.result);
                $('#gift-status').text('File loaded: ' + file.name);
            };
            reader.readAsText(file);
        });

        // Preview
        $('#gift-preview-btn').on('click', function () {
            const content = $('#gift-content').val().trim();
            if (!content) { ExamAdmin.toast('Please paste or upload GIFT content.', 'error'); return; }

            $(this).prop('disabled', true).text('⏳ Parsing...');
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_import_gift',
                nonce: olamaExam.nonce,
                gift_content: content,
                mode: 'preview',
            }, function (res) {
                $('#gift-preview-btn').prop('disabled', false).text('👁 Preview');
                if (!res.success) { ExamAdmin.toast(res.data.message || 'Parse error', 'error'); return; }

                const data = res.data;
                $('#gift-preview-count').text(data.count);

                if (data.errors && data.errors.length > 0) {
                    let errHtml = '<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px; color:#dc2626; font-size:13px;">';
                    errHtml += '<strong>⚠️ Errors:</strong><ul style="margin:4px 0 0 16px;">';
                    data.errors.forEach(e => errHtml += `<li>${e.message || e}</li>`);
                    errHtml += '</ul></div>';
                    $('#gift-errors').html(errHtml).show();
                } else {
                    $('#gift-errors').hide();
                }

                let html = '';
                data.questions.forEach((q, i) => {
                    const text = q.question_text.length > 60 ? q.question_text.substring(0, 60) + '...' : q.question_text;
                    html += `<tr>
                    <td>${i + 1}</td>
                    <td><span class="olama-exam-badge olama-exam-badge-${q.type}">${q.type.toUpperCase()}</span></td>
                    <td>${$('<span>').text(text).html()}</td>
                    <td style="font-size:12px; color:#64748b; max-width:200px; overflow:hidden;">${q.answers_json.substring(0, 60)}...</td>
                </tr>`;
                });
                $('#gift-preview-tbody').html(html);
                $('#gift-preview-card').show();
                $('#gift-import-btn').prop('disabled', data.count === 0);
            });
        });

        // Import
        $('#gift-import-btn').on('click', function () {
            const unitId = $('#gift-unit').val();
            if (!unitId || unitId == '0') {
                ExamAdmin.toast('<?php echo olama_exam_translate("Please select a unit for import."); ?>', 'error');
                return;
            }

            $(this).prop('disabled', true).text('⏳ Importing...');
            $.post(olamaExam.ajaxUrl, {
                action: 'olama_exam_import_gift',
                nonce: olamaExam.nonce,
                gift_content: $('#gift-content').val(),
                unit_id: unitId,
                language: $('#gift-language').val(),
                difficulty: $('#gift-difficulty').val(),
                mode: 'import',
            }, function (res) {
                $('#gift-import-btn').prop('disabled', false).text('📥 Import');
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

                $('#gift-result-body').html(html);
                $('#gift-result-card').show();
                ExamAdmin.toast(`${d.imported} question(s) imported!`);
            });
        });
    })(jQuery);
</script>