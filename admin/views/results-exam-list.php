<?php
/** Exam picker presented as a chronological results table. */
if (!defined('ABSPATH')) { exit; }
$result_statuses = array('published'=>'منشور', 'active'=>'نشط', 'closed'=>'مغلق');
$format_exam_date = static function($value) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', (string) $value, $m) || $m[1] === '0000') return '—';
    return $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5];
};
?>
<section class="olama-exam-card oe-results-exams" aria-labelledby="results-exams-heading">
    <div class="olama-exam-card-header">
        <h3 id="results-exams-heading">اختبارات <?php echo esc_html($result_types[$selected_exam_type]); ?> <span class="oe-results-count"><?php echo count($exams); ?></span></h3>
        <span class="oe-results-order"><?php echo $selected_exam_type === 'direct' ? 'الأحدث أولاً حسب تاريخ الإنشاء' : 'الأحدث أولاً حسب تاريخ الامتحان'; ?></span>
    </div>
    <p class="oe-results-list-hint">الامتحانات المنشورة والنشطة والمغلقة فقط. افتح «عرض النتائج» للاطلاع على درجات الطلاب.</p>
    <?php if (!$results_scope_ready): ?>
        <div class="oe-results-empty" role="status"><strong>اختر الصف والشعبة لعرض نتائج الامتحانات.</strong><p>اختيار الصف والشعبة إلزامي لعرض الاختبارات وإحصاءاتها.</p></div>
    <?php elseif (!$exams): ?>
        <div class="oe-results-empty"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><strong>لا توجد امتحانات مطابقة</strong><p>غيّر نوع الاختبار أو الفلاتر لعرض امتحانات أخرى.</p></div>
    <?php else: ?>
            <table class="olama-exam-table oe-results-exam-table">
                <thead><tr><th>الامتحان</th><th>الصف والشعبة</th><th>تاريخ الامتحان</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>
                <?php foreach ($exams as $listed_exam):
                    $listed_type = isset($result_types[$listed_exam->exam_type]) ? $listed_exam->exam_type : 'exam';
                    $results_url = add_query_arg(array(
                        'page'=>'olama-exam-results', 'exam_id'=>$listed_exam->id, 'exam_type'=>$listed_type,
                        'academic_year_id'=>$selected_year_id, 'semester_id'=>$selected_semester_id,
                        'grade_id'=>$selected_grade_id, 'section_id'=>$selected_section_id,
                    ), admin_url('admin.php')) . '#exam-results-detail';
                    $is_selected = intval($listed_exam->id) === $selected_exam_id;
                ?>
                    <tr class="<?php echo $is_selected ? 'oe-results-selected' : ''; ?>">
                        <td><div class="oe-exam-title oe-results-exam-title" data-title="<?php echo esc_attr($listed_exam->title); ?>" data-subject="<?php echo esc_attr($listed_exam->subject_name ?? ''); ?>"><strong><?php echo esc_html($listed_exam->subject_name ?: $listed_exam->title); ?></strong><span class="oe-exam-title-detail"><?php echo esc_html($listed_exam->title); ?></span></div></td>
                        <td><div class="oe-results-class"><strong><?php echo esc_html($listed_exam->grade_name ?: '—'); ?></strong><span><?php echo esc_html($listed_exam->section_name ? 'الشعبة ' . $listed_exam->section_name : '—'); ?></span></div></td>
                        <td><?php if ($listed_type === 'direct'): ?><span class="oe-results-order">مراجعة مفتوحة · بلا موعد أو حد زمني</span><?php else: ?><div class="oe-exam-dates"><div><span>البداية</span><bdi dir="ltr"><?php echo esc_html($format_exam_date($listed_exam->start_time)); ?></bdi></div><div><span>النهاية</span><bdi dir="ltr"><?php echo esc_html($format_exam_date($listed_exam->end_time)); ?></bdi></div></div><?php endif; ?></td>
                        <td><span class="oe-results-status oe-results-status-<?php echo esc_attr($listed_exam->status); ?>"><?php echo esc_html($result_statuses[$listed_exam->status]); ?></span></td>
                        <td><a class="olama-exam-btn <?php echo $is_selected ? 'olama-exam-btn-primary' : 'olama-exam-btn-outline'; ?> olama-exam-btn-sm" href="<?php echo esc_url($results_url); ?>" <?php if ($is_selected): ?>aria-current="page"<?php endif; ?>>عرض النتائج</a><?php if ($is_selected): ?><small class="oe-results-current">الاختبار المحدد</small><?php endif; ?></td>
                    </tr>
                    <tr class="oe-exam-summary-row<?php echo $is_selected ? ' oe-results-selected' : ''; ?>">
                        <td colspan="5">
                            <?php $exam_summary = $exam_summaries[$listed_exam->id]; include OLAMA_EXAM_PATH . 'admin/views/exam-summary.php'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
    <?php endif; ?>
</section>
