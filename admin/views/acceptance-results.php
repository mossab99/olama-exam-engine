<?php
/**
 * Admin View: Acceptance Results
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Retrieve query filters
$prof_filter   = isset($_GET['profession_id']) ? intval($_GET['profession_id']) : 0;
$test_filter   = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;
$result_filter = isset($_GET['result_status']) ? sanitize_text_field($_GET['result_status']) : '';
$start_filter  = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_filter    = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

// Build Query
$query = "SELECT ap.name, ap.national_id, ap.phone, ap.email,
                 p.name_ar AS profession,
                 t.title AS test_title,
                 att.score, att.max_score, att.percentage, att.result, att.started_at
          FROM {$wpdb->prefix}oee_acceptance_applicants ap
          JOIN {$wpdb->prefix}olama_exam_attempts att ON att.id = ap.attempt_id
          JOIN {$wpdb->prefix}oee_acceptance_tests t ON t.id = ap.test_id
          JOIN {$wpdb->prefix}oee_professions p ON p.id = t.profession_id
          WHERE att.exam_type = 'acceptance'";

$params = array();

if ($prof_filter > 0) {
    $query .= " AND t.profession_id = %d";
    $params[] = $prof_filter;
}
if ($test_filter > 0) {
    $query .= " AND ap.test_id = %d";
    $params[] = $test_filter;
}
if ($result_filter === 'pass') {
    $query .= " AND att.result = 'pass'";
} elseif ($result_filter === 'fail') {
    $query .= " AND att.result = 'fail'";
}
if (!empty($start_filter)) {
    $query .= " AND att.started_at >= %s";
    $params[] = $start_filter . ' 00:00:00';
}
if (!empty($end_filter)) {
    $query .= " AND att.started_at <= %s";
    $params[] = $end_filter . ' 23:59:59';
}

$query .= " ORDER BY att.started_at DESC";

if (!empty($params)) {
    $results = $wpdb->get_results($wpdb->prepare($query, $params));
} else {
    $results = $wpdb->get_results($query);
}

// Load dropdown filters data
$professions = OEE_Professions::get_all();
$tests = OEE_Acceptance_Tests::get_all();
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('Acceptance Results'); ?></h1>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/job-apps-tabs.php'; ?>

    <!-- Filters Wrapper -->
    <div class="olama-exam-filters" style="align-items: flex-end; gap: 16px;">
        <form method="get" action="" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; flex:1;">
            <input type="hidden" name="page" value="oee-acceptance-results">

            <!-- Profession Filter -->
            <div class="olama-exam-form-group" style="margin-bottom: 0; min-width: 160px;">
                <label style="font-weight:600; font-size:12.5px; display:block; margin-bottom:4px; color:#475569;"><?php echo olama_exam_translate('Profession'); ?></label>
                <select name="profession_id" id="filter-profession" style="width: 100%;">
                    <option value="0"><?php echo olama_exam_translate('All'); ?></option>
                    <?php foreach ($professions as $p): ?>
                        <option value="<?php echo $p->id; ?>" <?php echo $prof_filter === intval($p->id) ? 'selected' : ''; ?>><?php echo esc_html($p->name_ar); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Test Filter -->
            <div class="olama-exam-form-group" style="margin-bottom: 0; min-width: 160px;">
                <label style="font-weight:600; font-size:12.5px; display:block; margin-bottom:4px; color:#475569;"><?php echo olama_exam_translate('Test'); ?></label>
                <select name="test_id" id="filter-test" style="width: 100%;">
                    <option value="0"><?php echo olama_exam_translate('All'); ?></option>
                    <?php foreach ($tests as $t): ?>
                        <option value="<?php echo $t->id; ?>" <?php echo $test_filter === intval($t->id) ? 'selected' : ''; ?>><?php echo esc_html($t->title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Pass/Fail Filter -->
            <div class="olama-exam-form-group" style="margin-bottom: 0; min-width: 160px;">
                <label style="font-weight:600; font-size:12.5px; display:block; margin-bottom:4px; color:#475569;"><?php echo olama_exam_translate('Result'); ?></label>
                <select name="result_status" id="filter-result" style="width: 100%;">
                    <option value=""><?php echo olama_exam_translate('All'); ?></option>
                    <option value="pass" <?php echo $result_filter === 'pass' ? 'selected' : ''; ?>><?php echo olama_exam_translate('Pass'); ?></option>
                    <option value="fail" <?php echo $result_filter === 'fail' ? 'selected' : ''; ?>><?php echo olama_exam_translate('Fail'); ?></option>
                </select>
            </div>

            <!-- Date Range Filters -->
            <div class="olama-exam-form-group" style="margin-bottom: 0;">
                <label style="font-weight:600; font-size:12.5px; display:block; margin-bottom:4px; color:#475569;"><?php echo olama_exam_translate('Start Date'); ?></label>
                <input type="date" name="start_date" value="<?php echo esc_attr($start_filter); ?>" style="padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; min-width: 160px;">
            </div>

            <div class="olama-exam-form-group" style="margin-bottom: 0;">
                <label style="font-weight:600; font-size:12.5px; display:block; margin-bottom:4px; color:#475569;"><?php echo olama_exam_translate('End Date'); ?></label>
                <input type="date" name="end_date" value="<?php echo esc_attr($end_filter); ?>" style="padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; min-width: 160px;">
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="olama-exam-btn olama-exam-btn-primary olama-exam-btn-sm"><?php echo olama_exam_translate('Filter'); ?></button>
                <a href="<?php echo admin_url('admin.php?page=oee-acceptance-results'); ?>" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm"><?php echo olama_exam_translate('Reset'); ?></a>
            </div>
        </form>

        <!-- CSV Export Button -->
        <div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="oee_export_acceptance_csv">
                <input type="hidden" name="profession_id" value="<?php echo $prof_filter; ?>">
                <input type="hidden" name="test_id" value="<?php echo $test_filter; ?>">
                <input type="hidden" name="result_status" value="<?php echo esc_attr($result_filter); ?>">
                <input type="hidden" name="start_date" value="<?php echo esc_attr($start_filter); ?>">
                <input type="hidden" name="end_date" value="<?php echo esc_attr($end_filter); ?>">
                <?php wp_nonce_field('oee_export_csv_nonce'); ?>
                <button type="submit" class="olama-exam-btn olama-exam-btn-success olama-exam-btn-sm">📊 <?php echo olama_exam_translate('Export to CSV'); ?></button>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="olama-exam-card">
        <table class="olama-exam-table">
            <thead>
                <tr>
                    <th><?php echo olama_exam_translate('Applicant Name'); ?></th>
                    <th><?php echo olama_exam_translate('National ID'); ?></th>
                    <th><?php echo olama_exam_translate('Phone'); ?></th>
                    <th><?php echo olama_exam_translate('Profession'); ?></th>
                    <th><?php echo olama_exam_translate('Test Title'); ?></th>
                    <th><?php echo olama_exam_translate('Score'); ?></th>
                    <th><?php echo olama_exam_translate('Result'); ?></th>
                    <th><?php echo olama_exam_translate('Date'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="8"><?php echo olama_exam_translate('No results found.'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $row): 
                        $passed = $row->result === 'pass';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($row->name); ?></strong></td>
                            <td><?php echo esc_html($row->national_id); ?></td>
                            <td><?php echo esc_html($row->phone); ?></td>
                            <td><?php echo esc_html($row->profession); ?></td>
                            <td><?php echo esc_html($row->test_title); ?></td>
                            <td><?php echo floatval($row->percentage); ?>% (<?php echo floatval($row->score); ?>/<?php echo floatval($row->max_score); ?>)</td>
                            <td>
                                <span class="olama-exam-badge <?php echo $passed ? 'olama-exam-badge-active' : 'olama-exam-badge-closed'; ?>">
                                    <?php echo $passed ? olama_exam_translate('Pass') : olama_exam_translate('Fail'); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($row->started_at))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
