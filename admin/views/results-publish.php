<?php
/** Publish active academic results; rendered only on the Results tab. */
if (!defined('ABSPATH')) { exit; }
?>
    <?php if (Olama_Exam_Ajax::can_supervise_exams()): ?>
    <div class="olama-exam-card oe-publish-results">
        <div class="olama-exam-card-header"><h3>نشر نتائج الاختبارات</h3></div>
        <p>نشر نتائج اختبارات التقويم والاختبارات القصيرة المنشورة والنشطة والمغلقة للعام والفصل الدراسي النشطين.</p>
        <div class="oe-publish-controls"><div class="olama-exam-form-group">
        <label for="publish-results-grade">الصف</label>
        <select id="publish-results-grade">
            <option value="0">جميع الصفوف</option>
            <?php foreach ($grades as $g): ?>
                <option value="<?php echo absint($g->id); ?>"><?php echo esc_html($g->grade_name); ?></option>
            <?php endforeach; ?>
        </select></div>
        <button type="button" id="publish-exam-results" class="olama-exam-btn olama-exam-btn-primary">إظهار كل نتائج الاختبارات</button>
        </div>
        <p id="publish-results-feedback" role="status" aria-live="polite"></p>
    </div>
    <?php endif; ?>
<script>
jQuery(function($) {
    $('#publish-exam-results').on('click', function() {
        var button = $(this);
        var grade = $('#publish-results-grade');
        var feedback = $('#publish-results-feedback');
        button.prop('disabled', true);
        grade.prop('disabled', true);
        feedback.text('جارٍ نشر النتائج...');
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_publish_results', nonce: olamaExam.nonce, grade_id: grade.val()
        }).done(function(res) {
            feedback.text(res.success ? 'تم تفعيل إظهار النتائج لـ ' + res.data.updated + ' اختبار.' : (res.data.message || 'تعذر نشر النتائج.'));

        }).fail(function(xhr) {
            feedback.text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'تعذر نشر النتائج. حاول مرة أخرى.');
        }).always(function() {
            button.prop('disabled', false);
            grade.prop('disabled', false);
        });
    });

});
</script>
