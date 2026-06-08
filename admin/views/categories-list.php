<?php
/**
 * Admin View: Categories List
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (check_admin_referer('oee_delete_category_' . intval($_GET['id']))) {
        $del_id = intval($_GET['id']);
        $del_res = OEE_Question_Categories::delete($del_id);
        if (is_wp_error($del_res)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($del_res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(olama_exam_translate('Category deleted.')) . '</p></div>';
        }
    }
}

$categories = OEE_Question_Categories::get_all();
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Categories'); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=olama-exam-categories&action=add'); ?>" class="olama-exam-btn olama-exam-btn-primary">+ <?php echo olama_exam_translate('Add New'); ?></a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/student-acceptance-tabs.php'; ?>

    <div class="olama-exam-card">
        <table class="olama-exam-table">
            <thead>
                <tr>
                    <th><?php echo olama_exam_translate('Category Name'); ?></th>
                    <th><?php echo olama_exam_translate('Subject'); ?></th>
                    <th><?php echo olama_exam_translate('Language'); ?></th>
                    <th><?php echo olama_exam_translate('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="4"><?php echo olama_exam_translate('No categories yet. Create one to start adding questions.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><strong><a href="<?php echo admin_url('admin.php?page=olama-exam-categories&action=edit&id=' . $cat->id); ?>"><?php echo esc_html($cat->name); ?></a></strong></td>
                            <td><?php echo esc_html($cat->subject_name ?: '—'); ?></td>
                            <td><?php echo $cat->language === 'ar' ? 'العربية' : 'English'; ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=olama-exam-categories&action=edit&id=' . $cat->id); ?>" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm">✏️ <?php echo olama_exam_translate('Edit'); ?></a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=olama-exam-categories&action=delete&id=' . $cat->id), 'oee_delete_category_' . $cat->id); ?>" class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm" onclick="return confirm('<?php echo esc_attr(olama_exam_translate('Are you sure you want to delete this category?')); ?>');">🗑 <?php echo olama_exam_translate('Delete'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
