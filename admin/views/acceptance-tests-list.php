<?php
/**
 * Admin View: Acceptance Tests List
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (check_admin_referer('oee_delete_test_' . intval($_GET['id']))) {
        $del_id = intval($_GET['id']);
        $del_res = OEE_Acceptance_Tests::delete($del_id);
        if (is_wp_error($del_res)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($del_res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(olama_exam_translate('Acceptance test deleted.')) . '</p></div>';
        }
    }
}

$tests = OEE_Acceptance_Tests::get_all();
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Acceptance Tests'); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-acceptance-tests&action=add'); ?>" class="olama-exam-btn olama-exam-btn-primary">+ <?php echo olama_exam_translate('Add New'); ?></a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/job-apps-tabs.php'; ?>

    <div class="olama-exam-card">
        <table class="olama-exam-table">
            <thead>
                <tr>
                    <th><?php echo olama_exam_translate('Test Title'); ?></th>
                    <th><?php echo olama_exam_translate('Profession'); ?></th>
                    <th><?php echo olama_exam_translate('Questions'); ?></th>
                    <th><?php echo olama_exam_translate('Duration'); ?></th>
                    <th><?php echo olama_exam_translate('Pass %'); ?></th>
                    <th><?php echo olama_exam_translate('Status'); ?></th>
                    <th><?php echo olama_exam_translate('Expiry'); ?></th>
                    <th><?php echo olama_exam_translate('Public URL'); ?></th>
                    <th><?php echo olama_exam_translate('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tests)): ?>
                    <tr>
                        <td colspan="9"><?php echo olama_exam_translate('No acceptance tests found.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tests as $test): 
                        $public_url = home_url('/acceptance/' . $test->public_token);
                        ?>
                        <tr>
                            <td><strong><a href="<?php echo admin_url('admin.php?page=oee-acceptance-tests&action=edit&id=' . $test->id); ?>"><?php echo esc_html($test->title); ?></a></strong></td>
                            <td><?php echo esc_html($test->profession_name_ar); ?></td>
                            <td><?php echo intval($test->num_questions); ?></td>
                            <td><?php echo intval($test->duration_min); ?> min</td>
                            <td><?php echo intval($test->pass_score_pct); ?>%</td>
                            <td>
                                <span class="olama-exam-badge <?php echo $test->status === 'active' ? 'olama-exam-badge-active' : 'olama-exam-badge-closed'; ?>">
                                    <?php echo esc_html($test->status); ?>
                                </span>
                            </td>
                            <td><?php echo $test->expires_at ? esc_html(date('Y-m-d', strtotime($test->expires_at))) : '—'; ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px; direction: ltr;">
                                    <input type="text" readonly value="<?php echo esc_url($public_url); ?>" id="tok-url-<?php echo $test->id; ?>" style="direction: ltr; text-align: left; font-size:12px; width:220px; padding:6px 10px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px;">
                                    <button type="button" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" style="padding: 6px 10px;" onclick="oeeCopyLink(<?php echo $test->id; ?>)">📋</button>
                                </div>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=oee-acceptance-tests&action=edit&id=' . $test->id); ?>" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm">✏️ <?php echo olama_exam_translate('Edit'); ?></a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=oee-acceptance-tests&action=delete&id=' . $test->id), 'oee_delete_test_' . $test->id); ?>" class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm" onclick="return confirm('<?php echo esc_attr(olama_exam_translate('Are you sure you want to delete this test?')); ?>');">🗑 <?php echo olama_exam_translate('Delete'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function oeeCopyLink(id) {
    var copyText = document.getElementById("tok-url-" + id);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand("copy");
    alert("<?php echo esc_js(olama_exam_translate('Link copied to clipboard!')); ?>");
}
</script>
