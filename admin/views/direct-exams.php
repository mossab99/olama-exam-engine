<?php
/** Lesson-based direct exams, using the same curriculum hierarchy as the question bank. */
if (!defined('ABSPATH')) { exit; }
global $wpdb;
list($active_year, $active_semester) = olama_exam_get_active_academic_context();
$grades = Olama_Exam_Manager::get_ordered_grades();
?>
<div class="olama-exam-wrap oe-direct-page" dir="rtl">
    <div class="olama-exam-header oe-direct-page-header">
        <div class="oe-direct-heading">
            <span class="oe-direct-page-icon"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span></span>
            <div><h1>الامتحانات المباشرة</h1><p>من الدرس إلى الاختبار، في مكان واحد.</p></div>
        </div>
        <span class="oe-direct-header-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span> بنك أسئلة مستقل لكل درس</span>
    </div>
    <?php include OLAMA_EXAM_PATH . 'admin/views/school-exams-tabs.php'; ?>
    <?php if (!empty($_GET['access_denied'])): ?><div class="notice notice-error"><p>ليس لديك صلاحية الوصول إلى هذا الاختبار.</p></div><?php endif; ?>
    <div class="olama-exam-card oe-direct-context">
        <div class="olama-exam-card-header"><h3><span class="dashicons dashicons-book-alt" aria-hidden="true"></span> اختر الصف والمادة</h3><span class="oe-direct-caption">المنهج الدراسي للفصل النشط</span></div>
        <div class="olama-exam-form-row oe-direct-context-grid">
            <div class="olama-exam-form-group"><label for="direct-year">العام الدراسي</label><input id="direct-year" type="text" readonly value="<?php echo esc_attr($active_year->year_name ?? '—'); ?>"></div>
            <div class="olama-exam-form-group"><label for="direct-semester">الفصل الدراسي</label><input id="direct-semester" type="text" readonly value="<?php echo esc_attr($active_semester->semester_name ?? '—'); ?>"></div>
            <div class="olama-exam-form-group"><label for="direct-grade">الصف</label><select id="direct-grade"><option value="0">— اختر —</option>
                <?php foreach ($grades as $grade): ?><option value="<?php echo absint($grade->id); ?>"><?php echo esc_html($grade->grade_name); ?></option><?php endforeach; ?>
            </select></div>
            <div class="olama-exam-form-group"><label for="direct-subject">المادة</label><select id="direct-subject" disabled><option value="0">— اختر الصف أولاً —</option></select></div>
        </div>
    </div>
    <div id="direct-overview" class="oe-direct-overview" hidden>
        <div class="oe-direct-stat"><span class="oe-direct-stat-icon"><span class="dashicons dashicons-category" aria-hidden="true"></span></span><div><strong id="direct-unit-count">0</strong><span>وحدات دراسية</span></div></div>
        <div class="oe-direct-stat"><span class="oe-direct-stat-icon oe-direct-green"><span class="dashicons dashicons-book" aria-hidden="true"></span></span><div><strong id="direct-ready-count">0 / 0</strong><span>دروس تتوفر لها أسئلة</span></div></div>
        <div class="oe-direct-stat"><span class="oe-direct-stat-icon oe-direct-blue"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span></span><div><strong id="direct-exam-count">0</strong><span>امتحانات مباشرة</span></div></div>
    </div>
    <div id="direct-feedback" class="oe-direct-feedback" role="status" aria-live="polite" data-state="empty"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span id="direct-feedback-text">اختر الصف والمادة لعرض الوحدات والدروس.</span></div>
    <div id="direct-units"></div>
</div>
<style>
.oe-direct-page .dashicons {flex-shrink:0; font-size:18px; width:18px; height:18px;}
.oe-direct-page-header {gap:20px; border:1px solid #e2e8f0;}
.oe-direct-heading {display:flex; align-items:center; gap:14px;}
.oe-direct-page-icon {display:grid; place-items:center; width:48px; height:48px; border-radius:13px; background:#eef2ff; color:#6366f1; flex-shrink:0;}
.oe-direct-page-icon .dashicons {font-size:27px; width:27px; height:27px;}
.oe-direct-heading p {margin:6px 0 0; color:#64748b; font-size:13px;}
.oe-direct-header-note {display:inline-flex; align-items:center; gap:7px; color:#64748b; font-size:12px;}
.oe-direct-page .olama-exam-tabs {overflow-x:auto; white-space:nowrap;}
.oe-direct-context .olama-exam-card-header {margin-bottom:22px;}
.oe-direct-context h3 {display:flex; align-items:center; gap:8px;}
.oe-direct-context h3 .dashicons {color:#6366f1;}
.oe-direct-caption {font-size:12px; color:#64748b;}
.oe-direct-page .oe-direct-context-grid {display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin:0;}
.oe-direct-context-grid .olama-exam-form-group {margin:0; min-width:0;}
.oe-direct-context-grid label {display:block; margin-bottom:8px; font-size:13px; font-weight:600;}
.oe-direct-page .oe-direct-context-grid input,
.oe-direct-page .oe-direct-context-grid select {width:100%; max-width:none; min-width:0; height:44px; min-height:44px; margin:0; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; box-sizing:border-box;}
.oe-direct-page .oe-direct-context-grid select {padding:0 12px 0 30px; background-position:left 10px center;}
.oe-direct-page .oe-direct-context-grid input[readonly] {background:#f1f5f9; color:#475569; padding:0 12px; box-shadow:none;}
.oe-direct-overview {display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-bottom:18px;}
.oe-direct-overview[hidden] {display:none;}
.oe-direct-stat {display:flex; align-items:center; gap:12px; padding:17px 20px; background:#fff; border:1px solid #e2e8f0; border-radius:10px;}
.oe-direct-stat-icon {display:grid; place-items:center; width:40px; height:40px; border-radius:10px; background:#eef2ff; color:#6366f1;}
.oe-direct-stat-icon.oe-direct-green {background:#ecfdf5; color:#059669;}
.oe-direct-stat-icon.oe-direct-blue {background:#eff6ff; color:#2563eb;}
.oe-direct-stat strong {display:block; font-size:21px; line-height:1.3; color:#0f172a; font-variant-numeric:tabular-nums;}
#direct-ready-count {direction:ltr; text-align:right;}
.oe-direct-stat div>span {display:block; margin-top:4px; color:#64748b; font-size:12px;}
.oe-direct-feedback {display:flex; align-items:center; gap:9px; padding:14px 16px; margin-bottom:18px; border:1px solid #e0e7ff; border-radius:9px; background:#f5f7ff; color:#475569; font-size:13px;}
.oe-direct-feedback>.dashicons {color:#6366f1;}
.oe-direct-feedback[data-state="error"] {background:#fef2f2; border-color:#fecaca; color:#b91c1c;}
.oe-direct-feedback[data-state="empty"] {padding:28px 20px; justify-content:center; background:#fff; border-style:dashed;}
.oe-direct-page .oe-direct-unit {padding:0; margin-bottom:12px; border:1px solid #dfe5f1; border-radius:11px; box-shadow:0 1px 2px #0f172a04; overflow:hidden; transition:border-color .2s,box-shadow .2s;}
.oe-direct-unit:hover {border-color:#a5b4fc; box-shadow:0 3px 12px #6366f10a;}
.oe-direct-unit summary {display:flex; align-items:center; gap:16px; cursor:pointer; padding:18px 20px; list-style:none;}
.oe-direct-unit summary::-webkit-details-marker {display:none;}
.oe-direct-unit summary:focus-visible {outline:2px solid #6366f1; outline-offset:-3px; border-radius:10px;}
.oe-direct-unit-number {display:grid; place-items:center; min-width:38px; height:38px; padding:0 7px; border-radius:9px; background:linear-gradient(135deg,#6366f1,#818cf8); color:white; font-size:15px; font-weight:700;}
.oe-direct-unit-title {flex:1; min-width:0;}
.oe-direct-unit-title strong {display:block; font-size:15px; line-height:1.6; color:#1e293b;}
.oe-direct-unit-title small {display:block; margin-top:3px; font-size:12px; color:#64748b;}
.oe-direct-pills {display:flex; align-items:center; gap:8px; flex-wrap:wrap;}
.oe-direct-pill {display:inline-flex; align-items:center; gap:5px; white-space:nowrap; padding:4px 10px; border-radius:99px; background:#f1f5f9; color:#64748b; font-size:12px; line-height:1.6; font-weight:500;}
.oe-direct-pill.has-questions {background:#dcfce7; color:#15803d;}
.oe-direct-pill.has-exams {background:#eef2ff; color:#4f46e5;}
.oe-direct-coverage {display:flex; flex-direction:column; gap:6px; min-width:105px; font-size:11px; color:#64748b;}
.oe-direct-track {display:block; height:6px; background:#e2e8f0; border-radius:10px; overflow:hidden;}
.oe-direct-track i {display:block; height:100%; background:#34c38f; border-radius:10px;}
.oe-direct-disclosure {display:flex; align-items:center; gap:6px; color:#4f46e5; font-size:12px; white-space:nowrap;}
.oe-direct-disclosure .dashicons {transition:transform .2s;}
.oe-direct-unit[open] .oe-direct-disclosure .dashicons {transform:rotate(180deg);}
.oe-direct-disclosure .when-open,.oe-direct-unit[open] .oe-direct-disclosure .when-closed {display:none;}
.oe-direct-unit[open] .oe-direct-disclosure .when-open {display:inline;}
.oe-direct-lessons {margin:0 20px; padding:14px 0 18px; border-top:1px solid #e8edf5;}
.oe-direct-lesson {border:1px solid #e2e8f0; background:#f8fafc; border-radius:9px; padding:13px 14px; margin-top:9px;}
.oe-direct-lesson:first-child {margin-top:0;}
.oe-direct-lesson-header {display:flex; align-items:center; gap:12px; flex-wrap:wrap;}
.oe-direct-lesson-name {display:flex; flex:1; align-items:center; gap:10px; min-width:200px;}
.oe-direct-lesson-name strong {font-size:13px; line-height:1.7;}
.oe-direct-lesson-number {display:grid; place-items:center; min-width:26px; height:26px; padding:0 5px; border:1px solid #dfe5f1; border-radius:7px; background:#fff; color:#64748b; font-size:12px;}
.oe-direct-page .oe-direct-create {min-width:133px; justify-content:center; font-size:12px; padding:8px 13px; min-height:36px; white-space:nowrap; background:#365cf5; color:white;}
.oe-direct-page .oe-direct-create:disabled {background:#edf0f5; color:#64748b; opacity:1; cursor:not-allowed; box-shadow:none; transform:none;}
.oe-direct-exams {display:flex; flex-direction:column; gap:8px; margin-top:12px; padding-top:12px; border-top:1px solid #e2e8f0;}
.oe-direct-exams:empty {display:none;}
.oe-direct-exam {display:flex; gap:12px; align-items:center; flex-wrap:wrap; background:white; padding:12px; border:1px solid #e5eaf3; border-radius:8px;}
.oe-direct-exam .oe-direct-title {flex:1; min-width:170px; font-size:13px; font-weight:600;}
.oe-direct-title small {display:block; font-weight:400; color:#64748b; margin-top:4px; font-size:12px;}
.oe-direct-exam-actions {display:flex; align-items:center; gap:6px; flex-wrap:wrap;}
.oe-direct-page .oe-direct-exam-actions .olama-exam-btn {padding:5px 9px; font-size:12px; border-width:1px;}
.oe-direct-page .oe-direct-delete {color:#b91c1c; border-color:#fecaca;}
.oe-direct-page .oe-direct-delete:hover {background:#fef2f2;}
.oe-direct-status-draft {background:#f1f5f9; color:#475569;}
.oe-direct-status-published {background:#dbeafe; color:#1d4ed8;}
.oe-direct-status-active {background:#dcfce7; color:#15803d;}
.oe-direct-status-closed {background:#fef2f2; color:#b91c1c;}
@media(max-width:1100px) {
    .oe-direct-unit summary {flex-wrap:wrap; gap:12px;}
    .oe-direct-unit-title {flex-basis:calc(100% - 60px);}
    .oe-direct-disclosure {margin-inline-start:auto;}
}
@media(max-width:782px) {
    .oe-direct-page .oe-direct-context-grid {grid-template-columns:repeat(2,minmax(0,1fr));}
    .oe-direct-header-note,.oe-direct-caption {display:none;}
    .oe-direct-stat {padding:14px 12px; gap:8px;}
    .oe-direct-stat-icon {display:none;}
    .oe-direct-stat strong {font-size:19px;}
    .oe-direct-page .olama-exam-header {padding:18px;}
    .oe-direct-page .olama-exam-header h1 {font-size:20px;}
    .oe-direct-lesson-name {flex-basis:100%;}
    .oe-direct-lesson-header .oe-direct-create {margin-inline-start:auto;}
}
@media(max-width:480px) {
    .oe-direct-page .oe-direct-context-grid {grid-template-columns:1fr;}
    .oe-direct-overview {gap:6px;}
    .oe-direct-stat {padding:12px 8px;}
    .oe-direct-stat div>span {font-size:11px;}
    .oe-direct-unit summary {padding:15px;}
    .oe-direct-lessons {margin:0 12px;}
    .oe-direct-coverage {display:none;}
    .oe-direct-exam-actions {flex-basis:100%;}
}
@media(prefers-reduced-motion:reduce) {
    .oe-direct-page * {transition:none!important;}
}
</style>
<script>
(function($) {
    var yearId = <?php echo absint($active_year->id ?? 0); ?>;
    var semesterId = <?php echo absint($active_semester->id ?? 0); ?>;
    var generation = 0;
    var statuses = {draft:'مسودة', published:'منشور', active:'نشط', closed:'مغلق'};
    function esc(value) { return $('<div>').text(value == null ? '' : value).html(); }
    function icon(name) { return '<span class="dashicons dashicons-' + name + '" aria-hidden="true"></span>'; }
    function feedback(message, state) {
        $('#direct-feedback-text').text(message);
        $('#direct-feedback').attr('data-state', state || 'info');
    }
    function request(action, data) { return $.post(olamaExam.ajaxUrl, $.extend({action:action, nonce:olamaExam.nonce}, data)); }
    function fail() { feedback('تعذر تحميل البيانات. حاول مجدداً باختيار المادة.', 'error'); }
    function load() {
        var openUnits = $('#direct-units details[open]').map(function() { return Number($(this).data('unit')); }).get();
        var token = ++generation;
        var grade = $('#direct-grade').val(), subject = $('#direct-subject').val();
        $('#direct-units').empty().attr('aria-busy', 'true');
        $('#direct-overview').prop('hidden', true);
        if (!Number(subject)) { $('#direct-units').attr('aria-busy', 'false'); feedback('اختر الصف والمادة لعرض الوحدات والدروس.', 'empty'); return; }
        if (!yearId || !semesterId) { $('#direct-units').attr('aria-busy', 'false'); feedback('يجب تحديد العام والفصل الدراسي النشطين أولاً.', 'error'); return; }
        feedback('جارٍ تحميل الوحدات والدروس...', 'loading');
        var scope = {grade_id:grade, subject_id:subject, academic_year_id:yearId, semester_id:semesterId};
        $.when(request('olama_exam_get_units_by_subject', scope), request('olama_exam_get_exams', $.extend({}, scope, {exam_type:'direct'})))
        .done(function(unitsResponse, examsResponse) {
            if (token !== generation) return;
            var unitsResult = unitsResponse[0], examsResult = examsResponse[0];
            if (!unitsResult.success || !examsResult.success) { fail(); return; }
            var units = unitsResult.data || [], exams = examsResult.data || [], html = '';
            var totalLessons = 0, readyLessons = 0, totalExams = 0;
            units.forEach(function(unit, index) {
                var lessons = unit.lessons || [];
                var ready = lessons.filter(function(lesson) { return Number(lesson.question_count) > 0; }).length;
                var unitExams = exams.filter(function(exam) { return lessons.some(function(lesson) { return Number(exam.direct_lesson_id) === Number(lesson.id); }); }).length;
                var questions = lessons.reduce(function(sum, lesson) { return sum + Number(lesson.question_count || 0); }, 0);
                var coverage = lessons.length ? Math.round(ready / lessons.length * 100) : 0;
                var expanded = openUnits.length ? openUnits.indexOf(Number(unit.id)) !== -1 : index === 0;
                totalLessons += lessons.length;
                readyLessons += ready;
                totalExams += unitExams;
                html += '<details class="olama-exam-card oe-direct-unit" data-unit="' + Number(unit.id) + '"' + (expanded ? ' open' : '') + '><summary>' +
                    '<span class="oe-direct-unit-number">' + esc(unit.unit_number) + '</span><span class="oe-direct-unit-title"><strong>' + esc(unit.unit_name) + '</strong><small>عدد الدروس: ' + lessons.length + '</small></span>' +
                    '<span class="oe-direct-pills"><span class="oe-direct-pill ' + (questions ? 'has-questions' : '') + '">' + questions + ' سؤال</span><span class="oe-direct-pill ' + (unitExams ? 'has-exams' : '') + '">' + unitExams + ' اختبار</span></span>' +
                    '<span class="oe-direct-coverage"><span class="oe-direct-track"><i style="width:' + coverage + '%"></i></span><span><bdi dir="ltr">' + ready + ' / ' + lessons.length + '</bdi> دروس بأسئلة</span></span>' +
                    '<span class="oe-direct-disclosure"><span class="when-closed">عرض الدروس</span><span class="when-open">إخفاء الدروس</span>' + icon('arrow-down-alt2') + '</span></summary><div class="oe-direct-lessons">';
                lessons.forEach(function(lesson) {
                    var lessonExams = exams.filter(function(exam) { return Number(exam.direct_lesson_id) === Number(lesson.id); });
                    var url = '?page=olama-exam-direct&edit=0&lesson_id=' + Number(lesson.id);
                    html += '<div class="oe-direct-lesson"><div class="oe-direct-lesson-header"><span class="oe-direct-lesson-name"><span class="oe-direct-lesson-number">' + esc(lesson.lesson_number) + '</span><strong>' + esc(lesson.lesson_title) + '</strong></span>' +
                        '<span class="oe-direct-pills"><span class="oe-direct-pill ' + (Number(lesson.question_count) ? 'has-questions' : '') + '">' + Number(lesson.question_count) + ' سؤال</span><span class="oe-direct-pill ' + (lessonExams.length ? 'has-exams' : '') + '">' + lessonExams.length + ' اختبار</span></span>';
                    html += Number(lesson.question_count) ? '<a class="olama-exam-btn olama-exam-btn-primary oe-direct-create" href="' + url + '" aria-label="' + esc('إنشاء اختبار — ' + lesson.lesson_title) + '">' + icon('plus-alt2') + ' إنشاء اختبار</a>' : '<button type="button" class="olama-exam-btn oe-direct-create" disabled title="أضف أسئلة لبنك هذا الدرس أولاً">' + icon('lock') + ' لا توجد أسئلة</button>';
                    html += '</div><div class="oe-direct-exams">';
                    lessonExams.forEach(function(exam) {
                        var status = Object.prototype.hasOwnProperty.call(statuses, exam.status) ? exam.status : 'draft';
                        html += '<div class="oe-direct-exam">' + icon('clipboard') + '<span class="oe-direct-title">' + esc(exam.title) + '<small>' + esc(exam.section_name || 'جميع الشعب') + ' · ' + Number(exam.question_count || 0) + ' سؤال · ' + Number(exam.duration_minutes || 0) + ' دقيقة</small></span><span class="oe-direct-pill oe-direct-status-' + status + '">' + esc(statuses[status]) + '</span><span class="oe-direct-exam-actions"><a class="olama-exam-btn olama-exam-btn-outline" href="?page=olama-exam-direct&edit=' + Number(exam.id) + '">تعديل</a>';
                        html += '<a class="olama-exam-btn olama-exam-btn-outline" href="?page=olama-exam-preview&id=' + Number(exam.id) + '">معاينة</a>';
                        var next = {draft:'published', published:'active', active:'closed', closed:'draft'}[exam.status];
                        var label = {draft:'نشر', published:'تفعيل', active:'إغلاق', closed:'إعادة إلى مسودة'}[exam.status];
                        if (next) html += '<button class="olama-exam-btn olama-exam-btn-outline oe-direct-status" data-id="' + Number(exam.id) + '" data-status="' + next + '">' + label + '</button>';
                        if (!Number(exam.attempt_count)) html += '<button class="olama-exam-btn olama-exam-btn-outline oe-direct-delete" data-id="' + Number(exam.id) + '">حذف</button>';
                        html += '</span></div>';
                    });
                    html += '</div></div>';
                });
                if (!(unit.lessons || []).length) html += '<p>لا توجد دروس في هذه الوحدة.</p>';
                html += '</div></details>';
            });
            $('#direct-units').html(html);
            $('#direct-unit-count').text(units.length);
            $('#direct-ready-count').text(readyLessons + ' / ' + totalLessons);
            $('#direct-exam-count').text(totalExams);
            $('#direct-overview').prop('hidden', !units.length);
            feedback(units.length ? 'يمكنك إنشاء أكثر من اختبار لكل درس. يظهر الامتحان للطلاب بعد تفعيله فقط، ويُغلق تلقائيًا عند انتهاء موعده.' : 'لا توجد وحدات لهذه المادة في الفصل الدراسي النشط.', units.length ? 'info' : 'empty');
        }).fail(function() { if (token === generation) fail(); }).always(function() { if (token === generation) $('#direct-units').attr('aria-busy', 'false'); });
    }
    $('#direct-grade').on('change', function() {
        var grade = $(this).val(), token = ++generation;
        $('#direct-units').empty();
        $('#direct-overview').prop('hidden', true);
        $('#direct-subject').html('<option value="0">— اختر —</option>').prop('disabled', true);
        feedback('اختر المادة لعرض الوحدات والدروس.', 'empty');
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
