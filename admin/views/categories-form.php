<?php
/**
 * Admin View: Categories Form (Add/Edit)
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$category = null;
$error = '';

if ($id > 0) {
    $category = OEE_Question_Categories::get($id);
}

// Fetch active subjects from the SIS for optional relation mapping
$subjects = $wpdb->get_results(
    "SELECT s.*, g.grade_name 
     FROM {$wpdb->prefix}olama_subjects s
     LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
     WHERE s.is_active = 1
     ORDER BY g.grade_name ASC, s.subject_name ASC"
);

// Handle Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oee_save_category'])) {
    if (check_admin_referer('oee_save_category_nonce')) {
        $data = array(
            'id'         => $id,
            'name'       => sanitize_text_field($_POST['name'] ?? ''),
            'subject_id' => intval($_POST['subject_id'] ?? 0),
            'language'   => sanitize_text_field($_POST['language'] ?? 'ar'),
        );

        $res = OEE_Question_Categories::save($data);
        if (is_wp_error($res)) {
            $error = $res->get_error_message();
        } else {
            echo '<script>window.location.href="' . admin_url('admin.php?page=olama-exam-categories&message=saved') . '";</script>';
            exit;
        }
    }
}

$title = $id > 0 ? olama_exam_translate('Edit Category') : olama_exam_translate('Add Category');
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo esc_html($title); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=olama-exam-categories'); ?>" class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Cancel'); ?>
            </a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/student-acceptance-tabs.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('oee_save_category_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="olama-exam-card">
            <div class="olama-exam-card-header">
                <h3>🏷️ <?php echo olama_exam_translate('Category Name'); ?></h3>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="name"><?php echo olama_exam_translate('Category Name'); ?> *</label>
                    <input name="name" type="text" id="name" value="<?php echo esc_attr($category ? $category->name : ''); ?>" required>
                </div>
                <div class="olama-exam-form-group">
                    <label for="language"><?php echo olama_exam_translate('Language'); ?></label>
                    <select name="language" id="language">
                        <option value="ar" <?php echo ($category && $category->language === 'ar') ? 'selected' : ''; ?>>العربية</option>
                        <option value="en" <?php echo ($category && $category->language === 'en') ? 'selected' : ''; ?>>English</option>
                    </select>
                </div>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr;">
                <div class="olama-exam-form-group">
                    <label for="subject_id"><?php echo olama_exam_translate('Subject'); ?> (<?php echo olama_exam_translate('optional'); ?>)</label>
                    <select name="subject_id" id="subject_id">
                        <option value="0">— <?php echo olama_exam_translate('Select'); ?> —</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo $sub->id; ?>" <?php echo ($category && $category->subject_id == $sub->id) ? 'selected' : ''; ?>>
                                <?php echo esc_html($sub->grade_name . ' - ' . $sub->subject_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <input type="submit" name="oee_save_category" id="submit" class="olama-exam-btn olama-exam-btn-primary" value="<?php echo esc_attr(olama_exam_translate('Save Changes')); ?>">
                <a href="<?php echo admin_url('admin.php?page=olama-exam-categories'); ?>" class="olama-exam-btn olama-exam-btn-outline"><?php echo olama_exam_translate('Cancel'); ?></a>
            </div>
        </div>
    </form>
</div>
