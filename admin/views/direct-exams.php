<?php
/** Lesson-based direct exams, using the same curriculum hierarchy as the question bank. */
if (!defined('ABSPATH')) { exit; }
global $wpdb;
list($active_year, $active_semester) = olama_exam_get_active_academic_context();
$grades = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_grades WHERE is_active = 1 ORDER BY grade_name ASC");
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header"><h1>الامتحانات المباشرة</h1></div>
    <?php include OLAMA_EXAM_PATH . 'admin/views/school-exams-tabs.php'; ?>
    <?php if (!empty($_GET['access_denied'])): ?><div class="notice notice-error"><p>ليس لديك صلاحية الوصول إلى هذا الاختبار.</p></div><?php endif; ?>
    <div class="olama-exam-card">
        <div class="olama-exam-card-header"><h3>اختر الصف والمادة</h3></div>
        <div class="olama-exam-form-row" style="padding:20px;">
            <div class="olama-exam-form-group"><label>العام الدراسي</label><input readonly value="<?php echo esc_attr($active_year->year_name ?? '—'); ?>"></div>
            <div class="olama-exam-form-group"><label>الفصل الدراسي</label><input readonly value="<?php echo esc_attr($active_semester->semester_name ?? '—'); ?>"></div>
            <div class="olama-exam-form-group"><label for="direct-grade">الصف</label><select id="direct-grade"><option value="0">— اختر —</option>
                <?php foreach ($grades as $grade): ?><option value="<?php echo absint($grade->id); ?>"><?php echo esc_html($grade->grade_name); ?></option><?php endforeach; ?>
            </select></div>
            <div class="olama-exam-form-group"><label for="direct-subject">المادة</label><select id="direct-subject" disabled><option value="0">— اختر الصف أولاً —</option></select></div>
        </div>
    </div>
    <p id="direct-feedback" role="status" aria-live="polite">اختر الصف والمادة لعرض الوحدات والدروس.</p>
    <div id="direct-units"></div>
</div>
<style>
.oe-direct-unit summary {cursor:pointer; padding:20px; font-size:16px; font-weight:600;}
.oe-direct-lessons {padding:0 20px 20px;}
.oe-direct-lesson {border:1px solid #dbe4f0; background:#f8fafc; border-radius:8px; padding:14px; margin-top:10px;}
.oe-direct-lesson-header {display:flex; align-items:center; gap:12px; flex-wrap:wrap;}
.oe-direct-lesson-header strong {flex:1; min-width:180px;}
.oe-direct-exams {display:flex; flex-direction:column; gap:8px; margin-top:12px;}
.oe-direct-exam {display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:white; padding:12px; border-radius:6px;}
.oe-direct-exam .oe-direct-title {flex:1; min-width:180px;}
</style>
<script>
(function($) {
    var yearId = <?php echo absint($active_year->id ?? 0); ?>;
    var semesterId = <?php echo absint($active_semester->id ?? 0); ?>;
    var generation = 0;
    var statuses = {draft:'مسودة', published:'منشور', active:'نشط', closed:'مغلق'};
    function esc(value) { return $('<div>').text(value == null ? '' : value).html(); }
    function request(action, data) { return $.post(olamaExam.ajaxUrl, $.extend({action:action, nonce:olamaExam.nonce}, data)); }
    function fail() { $('#direct-feedback').text('تعذر تحميل البيانات. حاول مجدداً باختيار المادة.'); }
    function load() {
        var token = ++generation;
        var grade = $('#direct-grade').val(), subject = $('#direct-subject').val();
        $('#direct-units').empty();
        if (!Number(subject)) { $('#direct-feedback').text('اختر الصف والمادة لعرض الوحدات والدروس.'); return; }
        if (!yearId || !semesterId) { $('#direct-feedback').text('يجب تحديد العام والفصل الدراسي النشطين أولاً.'); return; }
        $('#direct-feedback').text('جارٍ تحميل الوحدات والدروس...');
        var scope = {grade_id:grade, subject_id:subject, academic_year_id:yearId, semester_id:semesterId};
        $.when(request('olama_exam_get_units_by_subject', scope), request('olama_exam_get_exams', $.extend({}, scope, {exam_type:'direct'})))
        .done(function(unitsResponse, examsResponse) {
            if (token !== generation) return;
            var unitsResult = unitsResponse[0], examsResult = examsResponse[0];
            if (!unitsResult.success || !examsResult.success) { fail(); return; }
            var units = unitsResult.data || [], exams = examsResult.data || [], html = '';
            units.forEach(function(unit) {
                html += '<details class="olama-exam-card oe-direct-unit" open><summary>' + esc(unit.unit_number) + ' — ' + esc(unit.unit_name) + '</summary><div class="oe-direct-lessons">';
                (unit.lessons || []).forEach(function(lesson) {
                    var lessonExams = exams.filter(function(exam) { return Number(exam.direct_lesson_id) === Number(lesson.id); });
                    var url = '?page=olama-exam-direct&edit=0&lesson_id=' + Number(lesson.id);
                    html += '<div class="oe-direct-lesson"><div class="oe-direct-lesson-header"><strong>' + esc(lesson.lesson_number) + ': ' + esc(lesson.lesson_title) + '</strong><span>' + Number(lesson.question_count) + ' سؤال · ' + lessonExams.length + ' اختبار</span>';
                    html += Number(lesson.question_count) ? '<a class="olama-exam-btn olama-exam-btn-primary" href="' + url + '">+ إنشاء اختبار</a>' : '<span>أضف أسئلة للدرس أولاً</span>';
                    html += '</div><div class="oe-direct-exams">';
                    lessonExams.forEach(function(exam) {
                        html += '<div class="oe-direct-exam"><span class="oe-direct-title">' + esc(exam.title) + ' — ' + esc(exam.section_name || '') + '</span><span>' + esc(statuses[exam.status] || exam.status) + '</span><a class="olama-exam-btn olama-exam-btn-outline" href="?page=olama-exam-direct&edit=' + Number(exam.id) + '">تعديل</a>';
                        html += '<a class="olama-exam-btn olama-exam-btn-outline" href="?page=olama-exam-preview&id=' + Number(exam.id) + '">معاينة</a>';
                        var next = {draft:'published', published:'active', active:'closed', closed:'draft'}[exam.status];
                        var label = {draft:'نشر', published:'تفعيل', active:'إغلاق', closed:'إعادة إلى مسودة'}[exam.status];
                        if (next) html += '<button class="olama-exam-btn olama-exam-btn-outline oe-direct-status" data-id="' + Number(exam.id) + '" data-status="' + next + '">' + label + '</button>';
                        if (!Number(exam.attempt_count)) html += '<button class="olama-exam-btn olama-exam-btn-outline oe-direct-delete" data-id="' + Number(exam.id) + '">حذف</button>';
                        html += '</div>';
                    });
                    html += '</div></div>';
                });
                if (!(unit.lessons || []).length) html += '<p>لا توجد دروس في هذه الوحدة.</p>';
                html += '</div></details>';
            });
            $('#direct-units').html(html);
            $('#direct-feedback').text(units.length ? 'يمكن إنشاء أكثر من اختبار لكل درس، والأسئلة محصورة ببنك الدرس.' : 'لا توجد وحدات لهذه المادة في الفصل الدراسي النشط.');
        }).fail(function() { if (token === generation) fail(); });
    }
    $('#direct-grade').on('change', function() {
        var grade = $(this).val(), token = ++generation;
        $('#direct-units').empty();
        $('#direct-subject').html('<option value="0">— اختر —</option>').prop('disabled', true);
        $('#direct-feedback').text('اختر المادة لعرض الوحدات والدروس.');
        if (!Number(grade)) return;
        request('olama_exam_get_subjects_by_grade', {grade_id:grade}).done(function(res) {
            if (token !== generation) return;
            if (!res.success) { fail(); return; }
            (res.data || []).forEach(function(subject) { $('#direct-subject').append($('<option>').val(subject.id).text(subject.subject_name)); });
            $('#direct-subject').prop('disabled', false).val(<?php echo absint($_GET['filter_subject'] ?? 0); ?>).trigger('change');
        }).fail(fail);
    });
    $('#direct-subject').on('change', load);
    $('#direct-units').on('click', '.oe-direct-status, .oe-direct-delete', function() {
        var button = $(this), deleting = button.hasClass('oe-direct-delete');
        if (deleting && !confirm('حذف هذا الاختبار؟')) return;
        button.prop('disabled', true);
        request(deleting ? 'olama_exam_delete_exam' : 'olama_exam_update_status', {id:button.data('id'), status:button.data('status')}).done(function(res) {
            if (res.success) load(); else ExamAdmin.toast(res.data.message, 'error');
        }).fail(function() { ExamAdmin.toast('تعذر تنفيذ العملية. حاول مجدداً.', 'error'); }).always(function() { button.prop('disabled', false); });
    });
    $('#direct-grade').val(<?php echo absint($_GET['filter_grade'] ?? 0); ?>).trigger('change');
})(jQuery);
</script>
