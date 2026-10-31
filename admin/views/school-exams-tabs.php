<?php
/** Shared school exam navigation, ordered from right to left. */
if (!defined('ABSPATH')) { exit; }
$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
$school_tabs = array(
    'olama-exam-direct' => 'الامتحانات المباشرة',
    'olama-exam-create' => 'اختبارات التقويم',
    'olama-exam-create-quiz' => 'اختبارات قصيرة',
    'olama-exam-grade-essays' => 'تصحيح المقالات',
    'olama-exam-results' => 'النتائج',
);
?>
<nav class="olama-exam-tabs oe-school-tabs" dir="rtl" aria-label="اختبارات المدرسة">
    <?php foreach ($school_tabs as $tab_page => $tab_label): ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $tab_page)); ?>"
           class="olama-exam-tab<?php echo $current_page === $tab_page ? ' is-active' : ''; ?>"
           <?php if ($current_page === $tab_page): ?>aria-current="page"<?php endif; ?>><?php echo esc_html($tab_label); ?></a>
    <?php endforeach; ?>
</nav>