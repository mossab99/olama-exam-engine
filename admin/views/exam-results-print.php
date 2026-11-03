<?php
/** Standalone HTML report; data and access checks come from exam-results.php. */
if (!defined('ABSPATH')) { exit; }
$print_year = '';
$print_semester = '';
foreach ($years as $year) {
    if ((int) $year->id === (int) $selected_exam->academic_year_id) $print_year = $year->year_name;
}
foreach ($semesters as $semester) {
    if ((int) $semester->id === (int) $selected_exam->semester_id) $print_semester = $semester->semester_name;
}
$exam_summary = $exam_summaries[$selected_exam_id];
$print_number = static function($value) {
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
};
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html('نتائج الاختبار - ' . $selected_exam->title); ?></title>
    <style>
        * {box-sizing:border-box;}
        body {margin:0; background:#edf0f4; color:#111; font:14px/1.6 Tahoma,Arial,sans-serif;}
        main {max-width:1050px; margin:24px auto; padding:32px; background:#fff;}
        .toolbar {display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:24px;}
        button {padding:10px 24px; background:#2448a6; color:#fff; border:0; border-radius:5px; cursor:pointer; font:inherit;}
        h1 {font-size:23px; margin:0 0 8px;}
        h2 {font-size:18px; margin:0 0 16px;}
        header {border-bottom:2px solid #222; margin-bottom:20px; padding-bottom:16px;}
        .metadata {display:flex; flex-wrap:wrap; gap:6px 24px;}
        .oe-summary-metrics {display:flex; flex-wrap:wrap; gap:12px 28px; margin-bottom:10px;}
        .oe-summary-metric strong,.oe-summary-metric span {display:block;}
        .oe-summary-metric strong {font-size:18px;}
        .oe-summary-outcomes {display:flex; flex-wrap:wrap; gap:8px 24px;}
        .oe-summary-track {display:none;}
        .oe-exam-summary {border:1px solid #bbb; padding:14px; margin-bottom:20px; break-inside:avoid;}
        table {width:100%; border-collapse:collapse; table-layout:fixed; font-size:13px;}
        th,td {border:1px solid #aaa; padding:8px 6px; text-align:right; overflow-wrap:anywhere; vertical-align:top;}
        th {background:#eee; font-weight:bold;}
        th:first-child {width:7%;}
        th:nth-child(2) {width:30%;}
        thead {display:table-header-group;}
        tr {break-inside:avoid; page-break-inside:avoid;}
        .empty {text-align:center; padding:24px;}
        footer {margin-top:18px; font-size:11px; color:#444;}
        @page {size:A4 portrait; margin:12mm;}
        @media print {
            body {background:#fff; font-size:11pt;}
            main {max-width:none; margin:0; padding:0;}
            .toolbar {display:none !important;}
            table {font-size:10pt;}
            th,td {padding:6px 4px;}
            h1,h2 {break-after:avoid;}
        }
    </style>
</head>
<body>
<main>
    <div class="toolbar"><button type="button" onclick="window.print()">طباعة النتائج</button><span>يمكنك الطباعة أو الحفظ بصيغة PDF من نافذة الطباعة.</span></div>
    <header>
        <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
        <h2><?php echo esc_html('نتائج الاختبار: ' . $selected_exam->title); ?></h2>
        <div class="metadata">
            <span>المادة: <?php echo esc_html($selected_exam->subject_name ?: '—'); ?></span>
            <span>الصف: <?php echo esc_html($selected_exam->grade_name ?: '—'); ?></span>
            <span>الشعبة: <?php echo esc_html($selected_exam->section_name ?: '—'); ?></span>
            <span>العام الدراسي: <bdi><?php echo esc_html($print_year ?: '—'); ?></bdi></span>
            <span>الفصل الدراسي: <bdi><?php echo esc_html($print_semester ?: '—'); ?></bdi></span>
            <span>نوع الاختبار: <?php echo esc_html($result_types[$selected_exam_type]); ?></span>
        </div>
    </header>
    <?php include OLAMA_EXAM_PATH . 'admin/views/exam-summary.php'; ?>
    <table>
        <caption style="text-align:right; font-weight:bold; margin-bottom:8px;">درجات الطلاب<?php if ($selected_exam_type === 'direct'): ?> — آخر محاولة لكل طالب<?php endif; ?></caption>
        <thead><tr><th scope="col">#</th><th scope="col">اسم الطالب</th><th scope="col">الدرجة</th><th scope="col">النسبة</th><th scope="col">النتيجة</th><th scope="col">تاريخ التسليم</th></tr></thead>
        <tbody>
        <?php foreach ($attempts as $index => $attempt): $has_attempt = !empty($attempt->attempt_id); ?>
            <tr>
                <td><?php echo (int) $index + 1; ?></td>
                <td><?php echo esc_html($attempt->student_name ?: $attempt->student_uid); ?></td>
                <td><bdi dir="ltr"><?php echo $has_attempt ? esc_html($print_number($attempt->score) . ' / ' . $print_number($attempt->max_score)) : '—'; ?></bdi></td>
                <td><bdi dir="ltr"><?php echo $has_attempt ? esc_html($print_number($attempt->percentage) . '%') : '—'; ?></bdi></td>
                <td><?php echo !$has_attempt ? 'لم يتقدم' : ($attempt->result === 'pass' ? 'ناجح' : ($attempt->result === 'fail' ? 'راسب' : 'بانتظار التصحيح')); ?></td>
                <td><bdi dir="ltr"><?php echo $has_attempt && $attempt->submitted_at ? esc_html(date('d/m/Y H:i', strtotime($attempt->submitted_at))) : '—'; ?></bdi></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$attempts): ?><tr><td colspan="6" class="empty">لا توجد نتائج لهذا الاختبار بعد.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <footer>تاريخ الطباعة: <bdi dir="ltr"><?php echo esc_html(current_time('d/m/Y H:i')); ?></bdi></footer>
</main>
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
