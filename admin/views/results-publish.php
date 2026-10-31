<?php
/** Publish active academic results; rendered only on the Results tab. */
if (!defined('ABSPATH')) { exit; }
?>
    <?php if (Olama_Exam_Ajax::can_supervise_exams()): ?>
    <div class="olama-exam-card oe-publish-results">
        <div class="olama-exam-card-header"><h3>إظهار وإخفاء نتائج الاختبارات</h3></div>
        <p>التحكم بإظهار نتائج اختبارات التقويم والاختبارات القصيرة المنشورة والنشطة والمغلقة للعام والفصل الدراسي النشطين، حسب الصف المحدد أو جميع الصفوف.</p>
        <div class="oe-publish-controls"><div class="olama-exam-form-group">
        <label for="publish-results-grade">الصف</label>
        <select id="publish-results-grade">
            <option value="0">جميع الصفوف</option>
            <?php foreach ($grades as $g): ?>
                <option value="<?php echo absint($g->id); ?>"><?php echo esc_html($g->grade_name); ?></option>
            <?php endforeach; ?>
        </select></div>
        <button type="button" id="publish-exam-results" class="olama-exam-btn olama-exam-btn-primary">إظهار كل نتائج الاختبارات</button>
        <button type="button" id="hide-exam-results" class="olama-exam-btn olama-exam-btn-outline">إخفاء جميع نتائج الاختبارات</button>
        </div>
        <p id="publish-results-feedback" role="status" aria-live="polite"></p>
    </div>
    <?php endif; ?>
<script>
jQuery(function($) {
    $('#publish-exam-results, #hide-exam-results').on('click', function() {
        var hiding = this.id === 'hide-exam-results';
        var buttons = $('#publish-exam-results, #hide-exam-results');
        var grade = $('#publish-results-grade');
        var feedback = $('#publish-results-feedback');
        buttons.prop('disabled', true);
        grade.prop('disabled', true);
        feedback.text(hiding ? 'جارٍ إخفاء النتائج...' : 'جارٍ نشر النتائج...');
        $.post(olamaExam.ajaxUrl, {
            action: hiding ? 'olama_exam_hide_results' : 'olama_exam_publish_results', nonce: olamaExam.nonce, grade_id: grade.val()
        }).done(function(res) {
            feedback.text(res.success ? (hiding ? 'تم إخفاء النتائج لـ ' : 'تم تفعيل إظهار النتائج لـ ') + res.data.updated + ' اختبار.' : (res.data.message || 'تعذر تحديث إظهار النتائج.'));

        }).fail(function(xhr) {
            feedback.text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'تعذر تحديث إظهار النتائج. حاول مرة أخرى.');
        }).always(function() {
            buttons.prop('disabled', false);
            grade.prop('disabled', false);
        });
    });

});
</script>
