<?php
/** Shared result-summary presentation for a single exam. */
if (!defined('ABSPATH')) { exit; }
$summary_total = intval($exam_summary['attempts']);
$summary_pending = intval($exam_summary['pending']);
$summary_percent = static function($value) {
    return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') . '%';
};
?>
<div class="oe-exam-summary oe-results-summary<?php echo !$summary_total ? ' oe-summary-empty' : ''; ?>">
    <?php if (!$summary_total): ?>
        <span>لا توجد محاولات مسلّمة لهذا الاختبار بعد.</span>
    <?php else:
        $summary_metrics = array(
            array(($selected_exam_type ?? '') === 'direct' ? 'آخر محاولة لكل طالب' : 'محاولات مسلّمة', $summary_total, 'oe-summary-blue'),
            array('المتوسط', $summary_percent($exam_summary['average']), ''),
            array('نسبة النجاح', $summary_percent($exam_summary['pass_rate']), 'oe-summary-green'),
            array('أعلى درجة', $summary_percent($exam_summary['highest']), ''),
            array('أدنى درجة', $summary_percent($exam_summary['lowest']), ''),
        );
    ?>
        <div class="oe-summary-body">
            <div class="oe-summary-metrics">
                <?php foreach ($summary_metrics as $metric): ?>
                    <div class="oe-summary-metric <?php echo esc_attr($metric[2]); ?>"><strong><bdi dir="ltr"><?php echo esc_html($metric[1]); ?></bdi></strong><span><?php echo esc_html($metric[0]); ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="oe-summary-outcomes">
                <span class="oe-summary-passed">ناجح: <?php echo intval($exam_summary['passed']); ?></span>
                <span class="oe-summary-failed">راسب: <?php echo intval($exam_summary['failed']); ?></span>
                <?php if ($summary_pending): ?><span class="oe-summary-pending">بانتظار التصحيح: <?php echo $summary_pending; ?></span><?php endif; ?>
                <span class="oe-summary-track" aria-hidden="true"><i style="width:<?php echo max(0, min(100, (float) $exam_summary['pass_rate'])); ?>%"></i></span>
                <?php if ($summary_pending): ?><small>المؤشرات للمحاولات المصححة فقط</small><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
