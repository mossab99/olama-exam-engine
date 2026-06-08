<?php
/**
 * Admin View: Acceptance Tests Form (Add/Edit)
 */

if (!defined('ABSPATH')) {
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$test = null;
$error = '';

if ($id > 0) {
    $test = OEE_Acceptance_Tests::get($id);
}

$professions = OEE_Professions::get_all('active');

// Handle Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oee_save_test'])) {
    if (check_admin_referer('oee_save_test_nonce')) {
        $data = array(
            'id' => $id,
            'profession_id' => intval($_POST['profession_id'] ?? 0),
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'duration_min' => intval($_POST['duration_min'] ?? 45),
            'num_questions' => intval($_POST['num_questions'] ?? 40),
            'pass_score_pct' => intval($_POST['pass_score_pct'] ?? 60),
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
            'expires_at' => !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) . ' 00:00:00' : null,
        );

        $res = OEE_Acceptance_Tests::save($data);
        if (is_wp_error($res)) {
            $error = $res->get_error_message();
        } else {
            wp_redirect(admin_url('admin.php?page=oee-acceptance-tests&message=saved'));
            exit;
        }
    }
}

$title = $id > 0 ? olama_exam_translate('Edit Acceptance Test') : olama_exam_translate('Add Acceptance Test');
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo esc_html($title); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-acceptance-tests'); ?>" class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Cancel'); ?>
            </a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/job-apps-tabs.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('oee_save_test_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="olama-exam-card">
            <div class="olama-exam-card-header">
                <h3>📄 <?php echo olama_exam_translate('Test Setup'); ?></h3>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="profession_id"><?php echo olama_exam_translate('Profession'); ?> *</label>
                    <select name="profession_id" id="profession_id" required>
                        <option value="">— <?php echo olama_exam_translate('Select'); ?> —</option>
                        <?php foreach ($professions as $prof): ?>
                            <option value="<?php echo $prof->id; ?>" <?php echo ($test && $test->profession_id == $prof->id) ? 'selected' : ''; ?>>
                                <?php echo esc_html($prof->name_ar); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-exam-form-group">
                    <label for="title"><?php echo olama_exam_translate('Test Title'); ?> *</label>
                    <input name="title" type="text" id="title" value="<?php echo esc_attr($test ? $test->title : ''); ?>" required>
                </div>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="num_questions"><?php echo olama_exam_translate('Number of Questions'); ?></label>
                    <input name="num_questions" type="number" id="num_questions" value="<?php echo esc_attr($test ? $test->num_questions : 40); ?>" min="1" max="100">
                </div>
                <div class="olama-exam-form-group">
                    <label for="duration_min"><?php echo olama_exam_translate('Duration (Minutes)'); ?></label>
                    <input name="duration_min" type="number" id="duration_min" value="<?php echo esc_attr($test ? $test->duration_min : 45); ?>" min="1">
                </div>
                <div class="olama-exam-form-group">
                    <label for="pass_score_pct"><?php echo olama_exam_translate('Pass Score Percentage'); ?></label>
                    <input name="pass_score_pct" type="number" id="pass_score_pct" value="<?php echo esc_attr($test ? $test->pass_score_pct : 60); ?>" min="1" max="100">
                </div>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="expires_at"><?php echo olama_exam_translate('Expiry Date'); ?></label>
                    <input name="expires_at" type="date" id="expires_at" value="<?php echo esc_attr($test && $test->expires_at ? date('Y-m-d', strtotime($test->expires_at)) : ''); ?>">
                </div>
                <div class="olama-exam-form-group">
                    <label for="status"><?php echo olama_exam_translate('Status'); ?></label>
                    <select name="status" id="status">
                        <option value="active" <?php echo ($test && $test->status === 'active') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Active'); ?></option>
                        <option value="inactive" <?php echo ($test && $test->status === 'inactive') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Inactive'); ?></option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <input type="submit" name="oee_save_test" id="submit" class="olama-exam-btn olama-exam-btn-primary" value="<?php echo esc_attr(olama_exam_translate('Save Changes')); ?>">
                <a href="<?php echo admin_url('admin.php?page=oee-acceptance-tests'); ?>" class="olama-exam-btn olama-exam-btn-outline"><?php echo olama_exam_translate('Cancel'); ?></a>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    var previousSuggest = '';
    
    $('#profession_id').on('change', function() {
        var profName = $(this).find('option:selected').text().trim();
        var currentTitle = $('#title').val().trim();
        
        if (!profName || profName.indexOf('—') === 0) {
            return;
        }

        var today = new Date();
        var dd = String(today.getDate()).padStart(2, '0');
        var mm = String(today.getMonth() + 1).padStart(2, '0');
        var yyyy = today.getFullYear();
        var dateStr = yyyy + '-' + mm + '-' + dd;
        
        var autoTitle = profName + ' - اختبار القبول - ' + dateStr;
        
        if (currentTitle === '' || currentTitle === previousSuggest) {
            $('#title').val(autoTitle);
            previousSuggest = autoTitle;
        }
    });
});
</script>
