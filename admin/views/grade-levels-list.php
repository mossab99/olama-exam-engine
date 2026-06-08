<?php
/**
 * Admin View: Grade Levels List
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (check_admin_referer('oee_delete_grade_level_' . intval($_GET['id']))) {
        $del_id = intval($_GET['id']);
        $del_res = OEE_Grade_Levels::delete($del_id);
        if (is_wp_error($del_res)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($del_res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(olama_exam_translate('Grade level deleted.')) . '</p></div>';
        }
    }
}

$grade_levels = OEE_Grade_Levels::get_all();
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('grade_levels_menu'); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-grade-levels&action=add'); ?>" class="olama-exam-btn olama-exam-btn-primary">+ <?php echo olama_exam_translate('Add New'); ?></a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/student-acceptance-tabs.php'; ?>

    <div class="olama-exam-card">
        <table class="olama-exam-table">
            <thead>
                <tr>
                    <th><?php echo olama_exam_translate('Arabic Name'); ?></th>
                    <th><?php echo olama_exam_translate('English Name'); ?></th>
                    <th><?php echo olama_exam_translate('Sort Order'); ?></th>
                    <th><?php echo olama_exam_translate('Status'); ?></th>
                    <th><?php echo olama_exam_translate('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grade_levels)): ?>
                    <tr>
                        <td colspan="5"><?php echo olama_exam_translate('No grade levels found.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($grade_levels as $gl): ?>
                        <tr>
                            <td><strong><a href="<?php echo admin_url('admin.php?page=oee-grade-levels&action=edit&id=' . $gl->id); ?>"><?php echo esc_html($gl->name_ar); ?></a></strong></td>
                            <td><?php echo esc_html($gl->name_en ?: '—'); ?></td>
                            <td><?php echo intval($gl->sort_order); ?></td>
                            <td>
                                <span class="olama-exam-badge <?php echo $gl->status === 'active' ? 'olama-exam-badge-active' : 'olama-exam-badge-closed'; ?>">
                                    <?php echo esc_html($gl->status); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=oee-grade-levels&action=edit&id=' . $gl->id); ?>" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm">✏️ <?php echo olama_exam_translate('Edit'); ?></a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=oee-grade-levels&action=delete&id=' . $gl->id), 'oee_delete_grade_level_' . $gl->id); ?>" class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm" onclick="return confirm('<?php echo esc_attr(olama_exam_translate('Are you sure you want to delete this grade level?')); ?>');">🗑 <?php echo olama_exam_translate('Delete'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
