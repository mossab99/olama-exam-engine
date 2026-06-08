<?php
/**
 * Admin View: Student Tests Form (Add/Edit)
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$test = null;
$error = '';

if ($id > 0) {
    $test = OEE_Student_Tests::get($id);
}

$grade_levels = OEE_Grade_Levels::get_all('active');
$categories   = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_exam_question_categories ORDER BY name ASC");

// Pre-load question counts by category for each grade level
$counts_map = array();
foreach ($grade_levels as $gl) {
    $counts_map[$gl->id] = OEE_Grade_Levels::get_question_count_by_category($gl->id);
}

// Handle Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oee_save_test'])) {
    if (check_admin_referer('oee_save_student_test_nonce')) {
        $data = array(
            'id'             => $id,
            'grade_level_id' => intval($_POST['grade_level_id'] ?? 0),
            'title'          => sanitize_text_field($_POST['title'] ?? ''),
            'duration_min'   => intval($_POST['duration_min'] ?? 60),
            'pass_score_pct' => intval($_POST['pass_score_pct'] ?? 60),
            'subject_config' => wp_unslash($_POST['subject_config'] ?? '[]'),
            'status'         => sanitize_text_field($_POST['status'] ?? 'active'),
            'expires_at'     => !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) . ' 00:00:00' : null,
        );

        $res = OEE_Student_Tests::save($data);
        if (is_wp_error($res)) {
            $error = $res->get_error_message();
        } else {
            echo '<script>window.location.href="' . admin_url('admin.php?page=oee-student-tests&message=saved') . '";</script>';
            exit;
        }
    }
}

$title = $id > 0 ? olama_exam_translate('Edit Student Test') : olama_exam_translate('Add Student Test');
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo esc_html($title); ?></h1>
        </div>
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=oee-student-tests'); ?>" class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Cancel'); ?>
            </a>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/student-acceptance-tabs.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <form method="post" action="" id="oee-student-test-form">
        <?php wp_nonce_field('oee_save_student_test_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="subject_config" id="subject_config_hidden" value="<?php echo esc_attr($test ? $test->subject_config : '[]'); ?>">

        <div class="olama-exam-card">
            <div class="olama-exam-card-header">
                <h3>📄 <?php echo olama_exam_translate('Test Setup'); ?></h3>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="grade_level_id"><?php echo olama_exam_translate('Grade Level'); ?> *</label>
                    <select name="grade_level_id" id="grade_level_id" required>
                        <option value="">— <?php echo olama_exam_translate('Select'); ?> —</option>
                        <?php foreach ($grade_levels as $gl): ?>
                            <option value="<?php echo $gl->id; ?>" <?php echo ($test && $test->grade_level_id == $gl->id) ? 'selected' : ''; ?>>
                                <?php echo esc_html($gl->name_ar); ?>
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
                    <label for="duration_min"><?php echo olama_exam_translate('Duration (minutes)'); ?></label>
                    <input name="duration_min" type="number" id="duration_min" value="<?php echo esc_attr($test ? $test->duration_min : 60); ?>" min="1">
                </div>
                <div class="olama-exam-form-group">
                    <label for="pass_score_pct"><?php echo olama_exam_translate('Pass Score Percentage'); ?></label>
                    <input name="pass_score_pct" type="number" id="pass_score_pct" value="<?php echo esc_attr($test ? $test->pass_score_pct : 60); ?>" min="1" max="100">
                </div>
                <div class="olama-exam-form-group">
                    <label for="expires_at"><?php echo olama_exam_translate('Expiry Date'); ?></label>
                    <input name="expires_at" type="date" id="expires_at" value="<?php echo esc_attr($test && $test->expires_at ? date('Y-m-d', strtotime($test->expires_at)) : ''); ?>">
                </div>
            </div>

            <div class="olama-exam-form-row" style="grid-template-columns: 1fr 1fr;">
                <div class="olama-exam-form-group">
                    <label for="status"><?php echo olama_exam_translate('Status'); ?></label>
                    <select name="status" id="status">
                        <option value="active" <?php echo ($test && $test->status === 'active') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Active'); ?></option>
                        <option value="inactive" <?php echo ($test && $test->status === 'inactive') ? 'selected' : ''; ?>><?php echo olama_exam_translate('Inactive'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Subject Configuration Builder -->
            <div style="margin-top: 32px; border-top: 1px solid #e2e8f0; padding-top: 24px;">
                <h3 style="margin-bottom: 8px;">📚 <?php echo olama_exam_translate('Subject Configuration'); ?></h3>
                <p style="color: #64748b; font-size: 14px; margin-bottom: 16px;"><?php echo olama_exam_translate('Configure the number of questions to pull from each subject category.'); ?></p>
                
                <table class="olama-exam-table" id="subject-config-table">
                    <thead>
                        <tr>
                            <th><?php echo olama_exam_translate('Subject Category'); ?></th>
                            <th><?php echo olama_exam_translate('Live Question Count'); ?></th>
                            <th><?php echo olama_exam_translate('Question Count'); ?></th>
                            <th><?php echo olama_exam_translate('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="config-rows-container">
                        <!-- Dynamic Rows Inserted Here -->
                    </tbody>
                </table>
                
                <div style="margin-top: 16px;">
                    <button type="button" class="olama-exam-btn olama-exam-btn-outline" id="add-config-row-btn">+ <?php echo olama_exam_translate('Add Category Config'); ?></button>
                </div>
            </div>

            <div style="margin-top: 32px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                <input type="submit" name="oee_save_test" id="submit" class="olama-exam-btn olama-exam-btn-primary" value="<?php echo esc_attr(olama_exam_translate('Save Changes')); ?>">
                <a href="<?php echo admin_url('admin.php?page=oee-student-tests'); ?>" class="olama-exam-btn olama-exam-btn-outline"><?php echo olama_exam_translate('Cancel'); ?></a>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    const qCounts = <?php echo json_encode($counts_map); ?>;
    const categories = <?php echo json_encode($categories); ?>;
    let initialConfig = [];
    
    try {
        initialConfig = JSON.parse($('#subject_config_hidden').val() || '[]');
    } catch(e) {
        initialConfig = [];
    }

    // Dynamic Title suggestion when Grade Level changes
    var previousSuggest = '';
    $('#grade_level_id').on('change', function() {
        var gradeName = $(this).find('option:selected').text().trim();
        var currentTitle = $('#title').val().trim();
        
        if (!gradeName || gradeName.indexOf('—') === 0) {
            updateAllRowCounts();
            return;
        }

        var today = new Date();
        var dd = String(today.getDate()).padStart(2, '0');
        var mm = String(today.getMonth() + 1).padStart(2, '0');
        var yyyy = today.getFullYear();
        var dateStr = yyyy + '-' + mm + '-' + dd;
        
        var autoTitle = gradeName + ' - اختبار القبول - ' + dateStr;
        
        if (currentTitle === '' || currentTitle === previousSuggest) {
            $('#title').val(autoTitle);
            previousSuggest = autoTitle;
        }
        
        updateAllRowCounts();
    });

    function getAvailableCount(catId) {
        const gradeId = $('#grade_level_id').val();
        if (!gradeId || gradeId === '0') return 0;
        if (qCounts[gradeId] && qCounts[gradeId][catId]) {
            return parseInt(qCounts[gradeId][catId]);
        }
        return 0;
    }

    function addConfigRow(catId = 0, numQuestions = 5) {
        const rowId = 'row-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
        let catOptions = `<option value="">— <?php echo esc_js(olama_exam_translate('Select')); ?> —</option>`;
        
        categories.forEach(function(cat) {
            const selected = (cat.id == catId) ? 'selected' : '';
            catOptions += `<option value="${cat.id}" ${selected}>${cat.name}</option>`;
        });

        const available = catId > 0 ? getAvailableCount(catId) : 0;
        const warningStyle = (numQuestions > available) ? 'display: block;' : 'display: none;';

        const rowHtml = `
            <tr class="config-row" id="${rowId}">
                <td>
                    <select class="config-category" required style="width: 100%;">
                        ${catOptions}
                    </select>
                </td>
                <td>
                    <span class="live-count-badge" style="font-weight: 600; padding: 4px 10px; border-radius: 99px; background: #f1f5f9; color: #64748b;">
                        ${available}
                    </span>
                </td>
                <td>
                    <input type="number" class="config-num-questions" value="${numQuestions}" min="1" required style="width: 100px;">
                    <div class="row-warning-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; ${warningStyle}">
                        ⚠️ <?php echo esc_js(olama_exam_translate('Not enough questions in this category! Available:')); ?> <span class="avail-num">${available}</span>
                    </div>
                </td>
                <td>
                    <button type="button" class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm remove-row-btn">🗑</button>
                </td>
            </tr>
        `;

        $('#config-rows-container').append(rowHtml);
    }

    function updateAllRowCounts() {
        $('.config-row').each(function() {
            const catId = $(this).find('.config-category').val();
            const avail = catId ? getAvailableCount(catId) : 0;
            $(this).find('.live-count-badge').text(avail);
            $(this).find('.avail-num').text(avail);
            
            const numInput = $(this).find('.config-num-questions');
            const num = parseInt(numInput.val()) || 0;
            if (num > avail && catId) {
                $(this).find('.row-warning-msg').show();
            } else {
                $(this).find('.row-warning-msg').hide();
            }
        });
    }

    // Add Row Click Event
    $('#add-config-row-btn').on('click', function() {
        addConfigRow();
    });

    // Remove Row Event
    $(document).on('click', '.remove-row-btn', function() {
        $(this).closest('.config-row').remove();
    });

    // Category Selector Change Event
    $(document).on('change', '.config-category', function() {
        const catId = $(this).val();
        const row = $(this).closest('.config-row');
        const avail = catId ? getAvailableCount(catId) : 0;
        
        row.find('.live-count-badge').text(avail);
        row.find('.avail-num').text(avail);
        
        const numInput = row.find('.config-num-questions');
        const num = parseInt(numInput.val()) || 0;
        if (num > avail && catId) {
            row.find('.row-warning-msg').show();
        } else {
            row.find('.row-warning-msg').hide();
        }
    });

    // Question Input Change Event
    $(document).on('input change', '.config-num-questions', function() {
        const row = $(this).closest('.config-row');
        const catId = row.find('.config-category').val();
        const num = parseInt($(this).val()) || 0;
        const avail = catId ? getAvailableCount(catId) : 0;
        
        if (num > avail && catId) {
            row.find('.row-warning-msg').show();
        } else {
            row.find('.row-warning-msg').hide();
        }
    });

    // Load initial configs
    if (initialConfig && initialConfig.length > 0) {
        initialConfig.forEach(function(item) {
            addConfigRow(item.category_id, item.num_questions);
        });
    } else {
        // Add one empty row by default
        addConfigRow();
    }

    // Serialize subject configuration JSON on form submit
    $('#oee-student-test-form').on('submit', function(e) {
        const config = [];
        let hasError = false;
        
        $('.config-row').each(function() {
            const catId = $(this).find('.config-category').val();
            const num = $(this).find('.config-num-questions').val();
            
            if (catId && num) {
                const avail = getAvailableCount(catId);
                if (parseInt(num) > avail) {
                    hasError = true;
                }
                config.push({
                    category_id: parseInt(catId),
                    num_questions: parseInt(num)
                });
            }
        });

        if (hasError) {
            if (!confirm('<?php echo esc_js(olama_exam_translate("Warning: Some categories do not have enough questions. Are you sure you want to save?")); ?>')) {
                e.preventDefault();
                return false;
            }
        }
        
        $('#subject_config_hidden').val(JSON.stringify(config));
    });
});
</script>
