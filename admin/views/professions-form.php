<?php
/**
 * Admin View: Professions Form (Add/Edit)
 */

if (!defined('ABSPATH')) {
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$profession = null;
$error = '';

if ($id > 0) {
    $profession = OEE_Professions::get($id);
}

// Handle Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oee_save_profession'])) {
    if (check_admin_referer('oee_save_profession_nonce')) {
        $data = array(
            'id' => $id,
            'name_ar' => sanitize_text_field($_POST['name_ar'] ?? ''),
            'name_en' => sanitize_text_field($_POST['name_en'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
        );

        $res = OEE_Professions::save($data);
        if (is_wp_error($res)) {
            $error = $res->get_error_message();
        } else {
            // Redirect
            echo '<script>window.location.href="' . admin_url('admin.php?page=oee-professions&message=saved') . '";</script>';
            exit;
        }
    }
}

$title = $id > 0 ? olama_exam_translate('Edit Profession') : olama_exam_translate('Add Profession');
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo esc_html($title); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-professions'); ?>" class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Cancel'); ?>
            </a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/job-apps-tabs.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('oee_save_profession_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="olama-exam-card">
            <div class="olama-exam-card-header">
                <h3>📄 <?php echo olama_exam_translate('Profession Details'); ?></h3>
            </div>

            <div class="olama-exam-form-row">
                <div class="olama-exam-form-group">
                    <label for="name_ar"><?php echo olama_exam_translate('Arabic Name'); ?> *</label>
                    <input name="name_ar" type="text" id="name_ar" value="<?php echo esc_attr($profession ? $profession->name_ar : ''); ?>" required>
                </div>
                <div class="olama-exam-form-group">
                    <label for="name_en"><?php echo olama_exam_translate('English Name'); ?></label>
                    <input name="name_en" type="text" id="name_en" value="<?php echo esc_attr($profession ? $profession->name_en : ''); ?>">
                </div>
            </div>

            <div class="olama-exam-form-group">
                <label for="description"><?php echo olama_exam_translate('Description'); ?></label>
                <textarea name="description" id="description" rows="5"><?php echo esc_textarea($profession ? $profession->description : ''); ?></textarea>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="status"><?php echo olama_exam_translate('Status'); ?></label>
                    <select name="status" id="status">
                        <option value="active" <?php echo ($profession && $profession->status === 'active') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Active'); ?></option>
                        <option value="inactive" <?php echo ($profession && $profession->status === 'inactive') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Inactive'); ?></option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <input type="submit" name="oee_save_profession" id="submit" class="olama-exam-btn olama-exam-btn-primary" value="<?php echo esc_attr(olama_exam_translate('Save Changes')); ?>">
                <a href="<?php echo admin_url('admin.php?page=oee-professions'); ?>" class="olama-exam-btn olama-exam-btn-outline"><?php echo olama_exam_translate('Cancel'); ?></a>
            </div>
        </div>
    </form>
</div>
