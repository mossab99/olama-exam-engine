<?php
/**
 * Admin View: Student Acceptance Tabs
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
?>
<div class="olama-exam-tabs" style="margin-bottom: 24px; border-bottom: 1px solid #cbd5e1; display: flex; gap: 24px; padding-bottom: 0;">
    <a href="<?php echo admin_url('admin.php?page=oee-grade-levels'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'oee-grade-levels' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'oee-grade-levels' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('grade_levels_menu'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=oee-student-tests'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'oee-student-tests' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'oee-student-tests' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('student_tests_menu'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=oee-student-results'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'oee-student-results' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'oee-student-results' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('student_results_menu'); ?>
    </a>
</div>
