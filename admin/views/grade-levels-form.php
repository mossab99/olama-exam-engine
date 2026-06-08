<?php
/**
 * Admin View: Grade Levels Form (Add/Edit)
 */

if (!defined('ABSPATH')) {
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$grade_level = null;
$error = '';

if ($id > 0) {
    $grade_level = OEE_Grade_Levels::get($id);
}

// Handle Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oee_save_grade_level'])) {
    if (check_admin_referer('oee_save_grade_level_nonce')) {
        $data = array(
            'id' => $id,
            'name_ar' => sanitize_text_field($_POST['name_ar'] ?? ''),
            'name_en' => sanitize_text_field($_POST['name_en'] ?? ''),
            'sort_order' => intval($_POST['sort_order'] ?? 0),
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
        );

        $res = OEE_Grade_Levels::save($data);
        if (is_wp_error($res)) {
            $error = $res->get_error_message();
        } else {
            echo '<script>window.location.href="' . admin_url('admin.php?page=oee-grade-levels&message=saved') . '";</script>';
            exit;
        }
    }
}

$title = $id > 0 ? olama_exam_translate('Edit Grade Level') : olama_exam_translate('Add Grade Level');
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo esc_html($title); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-grade-levels'); ?>" class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Cancel'); ?>
            </a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/student-acceptance-tabs.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('oee_save_grade_level_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="olama-exam-card">
            <div class="olama-exam-card-header">
                <h3>🏫 <?php echo olama_exam_translate('Grade Level Details'); ?></h3>
            </div>

            <div class="olama-exam-form-row">
                <div class="olama-exam-form-group">
                    <label for="name_ar"><?php echo olama_exam_translate('Arabic Name'); ?> *</label>
                    <input name="name_ar" type="text" id="name_ar" value="<?php echo esc_attr($grade_level ? $grade_level->name_ar : ''); ?>" required>
                </div>
                <div class="olama-exam-form-group">
                    <label for="name_en"><?php echo olama_exam_translate('English Name'); ?></label>
                    <input name="name_en" type="text" id="name_en" value="<?php echo esc_attr($grade_level ? $grade_level->name_en : ''); ?>">
                </div>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="sort_order"><?php echo olama_exam_translate('Sort Order'); ?></label>
                    <input name="sort_order" type="number" id="sort_order" value="<?php echo esc_attr($grade_level ? $grade_level->sort_order : 0); ?>">
                </div>
                <div class="olama-exam-form-group">
                    <label for="status"><?php echo olama_exam_translate('Status'); ?></label>
                    <select name="status" id="status">
                        <option value="active" <?php echo ($grade_level && $grade_level->status === 'active') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Active'); ?></option>
                        <option value="inactive" <?php echo ($grade_level && $grade_level->status === 'inactive') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Inactive'); ?></option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <input type="submit" name="oee_save_grade_level" id="submit" class="olama-exam-btn olama-exam-btn-primary" value="<?php echo esc_attr(olama_exam_translate('Save Changes')); ?>">
                <a href="<?php echo admin_url('admin.php?page=oee-grade-levels'); ?>" class="olama-exam-btn olama-exam-btn-outline"><?php echo olama_exam_translate('Cancel'); ?></a>
            </div>
        </div>
    </form>
</div>
