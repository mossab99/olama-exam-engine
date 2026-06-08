<?php
/**
 * Admin View: School Exams Tabs
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
?>
<div class="olama-exam-tabs" style="margin-bottom: 24px; border-bottom: 1px solid #cbd5e1; display: flex; gap: 24px; padding-bottom: 0;">
    <a href="<?php echo admin_url('admin.php?page=olama-exam-create'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'olama-exam-create' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'olama-exam-create' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('Create Exam'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=olama-exam-create-quiz'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'olama-exam-create-quiz' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'olama-exam-create-quiz' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('Create Quiz'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=olama-exam-results'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'olama-exam-results' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'olama-exam-results' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('Results'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=olama-exam-grade-essays'); ?>" 
       class="olama-exam-tab" 
       style="padding: 0 4px 12px 4px; font-weight: 600; font-size: 15px; text-decoration: none; color: <?php echo $current_page === 'olama-exam-grade-essays' ? '#6366f1' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $current_page === 'olama-exam-grade-essays' ? '#6366f1' : 'transparent'; ?>; transition: all 0.2s ease;">
       <?php echo olama_exam_translate('Grade Essays'); ?>
    </a>
</div>
