<?php
/**
 * Admin View: Grade Essays
 * Shows attempts with pending essay questions and allows inline grading
 */
if (!defined('ABSPATH'))
    exit;

global $wpdb;

// Get ALL submitted attempts, then filter in PHP for essay questions
$all_attempts = $wpdb->get_results(
    "SELECT a.id as attempt_id, a.exam_id, a.student_id, a.score, a.max_score, 
            a.percentage, a.result, a.submitted_at,
            a.questions_snapshot_json,
            e.title as exam_title,
            u.display_name as student_name
     FROM {$wpdb->prefix}olama_exam_attempts a
     JOIN {$wpdb->prefix}olama_exam_exams e ON a.exam_id = e.id
     LEFT JOIN {$wpdb->users} u ON a.student_id = u.ID
     WHERE a.submitted_at IS NOT NULL
     ORDER BY a.submitted_at DESC"
);

// Filter to attempts that have essay questions
$pending_attempts = array();
foreach ($all_attempts as $pa) {
    $snapshot = json_decode($pa->questions_snapshot_json, true) ?: array();
    $has_essay = false;
    $has_ungraded = false;

    foreach ($snapshot as $q) {
        if (isset($q['type']) && $q['type'] === 'essay') {
            $has_essay = true;
            $graded = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_exam_essay_grades WHERE attempt_id = %d AND question_id = %d",
                $pa->attempt_id,
                $q['question_id']
            ));
            if (!$graded) {
                $has_ungraded = true;
            }
        }
    }

    if ($has_essay) {
        $pa->has_ungraded_essays = $has_ungraded;
        $pending_attempts[] = $pa;
    }
}

// Check if grading a specific attempt
$grading_attempt_id = intval($_GET['grade_attempt'] ?? 0);
$grading_attempt = null;
$essay_questions = array();

if ($grading_attempt_id) {
    $grading_attempt = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, e.title as exam_title, u.display_name as student_name
         FROM {$wpdb->prefix}olama_exam_attempts a
         JOIN {$wpdb->prefix}olama_exam_exams e ON a.exam_id = e.id
         LEFT JOIN {$wpdb->users} u ON a.student_id = u.ID
         WHERE a.id = %d",
        $grading_attempt_id
    ));

    if ($grading_attempt) {
        $snapshot = json_decode($grading_attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($grading_attempt->answers_json, true) ?: array();

        foreach ($snapshot as $idx => $q) {
            if ($q['type'] === 'essay') {
                $q_id = $q['question_id'];
                $existing_grade = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}olama_exam_essay_grades 
                     WHERE attempt_id = %d AND question_id = %d",
                    $grading_attempt_id,
                    $q_id
                ));

                $essay_questions[] = array(
                    'index' => $idx + 1,
                    'question_id' => $q_id,
                    'question_text' => $q['question_text'],
                    'student_answer' => $answers[$q_id] ?? '',
                    'existing_grade' => $existing_grade,
                    'max_points' => $q['points'] ?? 1,
                );
            }
        }
    }
}
?>
<div class="olama-exam-wrap">
    <div class="olama-exam-header">
        <h1><?php echo olama_exam_translate('Grade Essays'); ?></h1>
    </div>

    <?php if ($grading_attempt && !empty($essay_questions)): ?>
        <!-- Grading Individual Attempt -->
        <div style="margin-bottom: 16px;">
            <a href="<?php echo admin_url('admin.php?page=olama-exam-grade-essays'); ?>" class="button">
                ← <?php echo olama_exam_translate('Back'); ?>
            </a>
        </div>

        <div class="olama-exam-card" style="margin-bottom: 20px;">
            <div class="olama-exam-card-header">
                <h3>📝 <?php echo esc_html($grading_attempt->exam_title); ?></h3>
            </div>
            <div style="padding: 16px 20px; display: flex; gap: 30px; flex-wrap: wrap; font-size: 14px; color: #64748b;">
                <span><strong><?php echo olama_exam_translate('Student'); ?>:</strong>
                    <?php echo esc_html($grading_attempt->student_name ?? 'ID: ' . $grading_attempt->student_id); ?></span>
                <span><strong><?php echo olama_exam_translate('Submitted'); ?>:</strong>
                    <?php echo date('d/m/Y H:i', strtotime($grading_attempt->submitted_at)); ?></span>
                <span><strong><?php echo olama_exam_translate('Current Score'); ?>:</strong>
                    <?php echo $grading_attempt->score ?? 0; ?> / <?php echo $grading_attempt->max_score ?? 0; ?></span>
            </div>
        </div>

        <?php foreach ($essay_questions as $eq): ?>
            <div class="olama-exam-card essay-grade-card" id="essay-card-<?php echo $eq['question_id']; ?>"
                style="margin-bottom: 16px;">
                <div class="olama-exam-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>Q<?php echo $eq['index']; ?> — <?php echo olama_exam_translate('Essay'); ?></h3>
                    <span id="grade-status-<?php echo $eq['question_id']; ?>" style="font-size: 14px; font-weight: 600;">
                        <?php if ($eq['existing_grade']): ?>
                            <span style="color: #059669;">✅ <?php echo olama_exam_translate('Graded'); ?></span>
                        <?php else: ?>
                            <span style="color: #d97706;">⏳ <?php echo olama_exam_translate('Pending'); ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div style="padding: 0 20px 10px;">
                    <div
                        style="font-size: 15px; line-height: 1.7; color: #1e293b; margin-bottom: 16px; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <?php echo wp_kses_post($eq['question_text']); ?>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; font-size: 14px; color: #1e293b; display: block; margin-bottom: 6px;">
                            📄 <?php echo olama_exam_translate('Student Answer'); ?>:
                        </label>
                        <div
                            style="padding: 14px 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 15px; line-height: 1.7; white-space: pre-wrap; min-height: 60px;">
                            <?php echo nl2br(esc_html($eq['student_answer'] ?: '— ' . olama_exam_translate('No answer provided') . ' —')); ?>
                        </div>
                    </div>

                    <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start;">
                        <div style="flex: 0 0 160px;">
                            <label style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                🏆 <?php echo olama_exam_translate('Score'); ?>:
                            </label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="number" class="essay-score-input" id="score-<?php echo $eq['question_id']; ?>"
                                    min="0" max="<?php echo $eq['max_points']; ?>" step="0.25"
                                    value="<?php echo $eq['existing_grade'] ? $eq['existing_grade']->score : ''; ?>"
                                    style="width: 80px; padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 16px; text-align: center;">
                                <span style="font-size: 14px; color: #64748b;">/ <?php echo $eq['max_points']; ?></span>
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                💬 <?php echo olama_exam_translate('Comment'); ?>:
                            </label>
                            <textarea class="essay-comment-input" id="comment-<?php echo $eq['question_id']; ?>" rows="3"
                                style="width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical; font-family: inherit;"
                                placeholder="<?php echo olama_exam_translate('Optional feedback for student...'); ?>"><?php echo esc_textarea($eq['existing_grade'] ? $eq['existing_grade']->teacher_comment : ''); ?></textarea>
                        </div>
                    </div>

                    <div style="margin-top: 14px; text-align: right;">
                        <button type="button" class="button button-primary essay-save-btn"
                            data-attempt="<?php echo $grading_attempt_id; ?>" data-question="<?php echo $eq['question_id']; ?>"
                            style="padding: 6px 24px;">
                            💾 <?php echo olama_exam_translate('Save Grade'); ?>
                        </button>
                        <span class="essay-save-feedback" id="feedback-<?php echo $eq['question_id']; ?>"
                            style="margin-right: 10px; font-size: 13px;"></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    <?php else: ?>
        <!-- Pending Essays List -->
        <div class="olama-exam-card">
            <div class="olama-exam-card-header">
                <h3>✍️ <?php echo olama_exam_translate('Pending Essay Grading'); ?></h3>
            </div>

            <?php if (empty($pending_attempts)): ?>
                <p style="color: #64748b; text-align: center; padding: 40px;">
                    <?php echo olama_exam_translate('No essays pending grading.'); ?>
                </p>
            <?php else: ?>
                <table class="olama-exam-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th
                                style="text-align: start; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Exam'); ?>
                            </th>
                            <th
                                style="text-align: start; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Student'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Score'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Submitted'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Actions'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_attempts as $pa): ?>
                            <tr>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 600;">
                                    <?php echo esc_html($pa->exam_title); ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9;">
                                    <?php echo esc_html($pa->student_name ?? 'ID: ' . $pa->student_id); ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php echo ($pa->score ?? 0) . ' / ' . ($pa->max_score ?? 0); ?>
                                </td>
                                <td
                                    style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center; font-size: 13px; color: #64748b;">
                                    <?php echo date('d/m/Y H:i', strtotime($pa->submitted_at)); ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <a href="<?php echo admin_url('admin.php?page=olama-exam-grade-essays&grade_attempt=' . $pa->attempt_id); ?>"
                                       class="button button-primary" style="padding: 4px 16px; font-size: 13px;">
                                        ✏️ <?php echo olama_exam_translate('Evaluate Answer'); ?>
                                    </a>
                                <?php if (!$pa->has_ungraded_essays): ?>
                                                        <span style="display:inline-block; margin-top:4px; color:#059669; font-size:12px;">✅ <?php echo olama_exam_translate('Graded'); ?></span>
                                                <?php else: ?>
                                                        <span style="display:inline-block; margin-top:4px; color:#d97706; font-size:12px;">⏳ <?php echo olama_exam_translate('Pending'); ?></span>
                                                <?php endif; ?>
                                            </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    jQuery(function ($) {
        $('.essay-save-btn').on('click', function () {
            var btn = $(this);
            var attemptId = btn.data('attempt');
            var questionId = btn.data('question');
            var score = parseFloat($('#score-' + questionId).val());
            var comment = $('#comment-' + questionId).val();
            var feedback = $('#feedback-' + questionId);

            if (isNaN(score) || score < 0) {
                feedback.css('color', '#dc2626').text('⚠️ <?php echo olama_exam_translate('Please enter a valid score'); ?>');
                return;
            }

            btn.prop('disabled', true).text('⏳ ...');
            feedback.css('color', '#d97706').text('<?php echo olama_exam_translate('Saving...'); ?>');

            $.post(ajaxurl, {
                action: 'olama_exam_grade_essay',
                nonce: '<?php echo wp_create_nonce('olama_exam_nonce'); ?>',
                attempt_id: attemptId,
                question_id: questionId,
                score: score,
                comment: comment
            }, function (response) {
                btn.prop('disabled', false).html('💾 <?php echo olama_exam_translate('Save Grade'); ?>');
                if (response.success) {
                    feedback.css('color', '#059669').text('✅ <?php echo olama_exam_translate('Saved!'); ?>');
                    $('#grade-status-' + questionId).html('<span style="color:#059669;">✅ <?php echo olama_exam_translate('Graded'); ?></span>');
                    setTimeout(function () { feedback.text(''); }, 3000);
                } else {
                    feedback.css('color', '#dc2626').text('❌ ' + (response.data ? response.data.message : 'Error'));
                }
            }).fail(function () {
                btn.prop('disabled', false).html('💾 <?php echo olama_exam_translate('Save Grade'); ?>');
                feedback.css('color', '#dc2626').text('❌ Network error');
            });
        });
    });
</script>