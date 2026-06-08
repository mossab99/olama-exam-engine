<?php
/**
 * Admin View: Professions List
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (check_admin_referer('oee_delete_profession_' . intval($_GET['id']))) {
        $del_id = intval($_GET['id']);
        $del_res = OEE_Professions::delete($del_id);
        if (is_wp_error($del_res)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($del_res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(olama_exam_translate('Profession deleted.')) . '</p></div>';
        }
    }
}

$professions = OEE_Professions::get_all();
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Professions'); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-professions&action=add'); ?>" class="olama-exam-btn olama-exam-btn-primary">+ <?php echo olama_exam_translate('Add New'); ?></a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/job-apps-tabs.php'; ?>

    <div class="olama-exam-card">
        <table class="olama-exam-table">
            <thead>
                <tr>
                    <th><?php echo olama_exam_translate('Arabic Name'); ?></th>
                    <th><?php echo olama_exam_translate('English Name'); ?></th>
                    <th><?php echo olama_exam_translate('Question Count'); ?></th>
                    <th><?php echo olama_exam_translate('Status'); ?></th>
                    <th><?php echo olama_exam_translate('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($professions)): ?>
                    <tr>
                        <td colspan="5"><?php echo olama_exam_translate('No professions found.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($professions as $prof): 
                        $q_count = OEE_Professions::get_question_count($prof->id);
                        ?>
                        <tr>
                            <td><strong><a href="<?php echo admin_url('admin.php?page=oee-professions&action=edit&id=' . $prof->id); ?>"><?php echo esc_html($prof->name_ar); ?></a></strong></td>
                            <td><?php echo esc_html($prof->name_en ?: '—'); ?></td>
                            <td>
                                <span class="olama-exam-badge olama-exam-badge-published">
                                    <?php echo $q_count; ?>
                                </span>
                            </td>
                            <td>
                                <span class="olama-exam-badge <?php echo $prof->status === 'active' ? 'olama-exam-badge-active' : 'olama-exam-badge-closed'; ?>">
                                    <?php echo esc_html($prof->status); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=oee-professions&action=edit&id=' . $prof->id); ?>" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm">✏️ <?php echo olama_exam_translate('Edit'); ?></a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=oee-professions&action=delete&id=' . $prof->id), 'oee_delete_profession_' . $prof->id); ?>" class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm" onclick="return confirm('<?php echo esc_attr(olama_exam_translate('Are you sure you want to delete this profession?')); ?>');">🗑 <?php echo olama_exam_translate('Delete'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
