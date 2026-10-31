<?php
/**
 * Admin View: Create / List Exams
 * Shows exam list with status badges + create/edit form with question selection.
 * Integrated with SIS Exam Schedule for auto-title, dates, and unit-filtered questions.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Active academic context
list($active_year, $active_semester) = olama_exam_get_active_academic_context();
$grades = Olama_Exam_Manager::get_ordered_grades();

// Check if showing form (edit param present = show form, even for new exams with edit=0)
$show_form = isset($_GET['edit']);
$edit_id   = intval($_GET['edit'] ?? 0);
$exam      = $edit_id > 0 ? Olama_Exam_Manager::get_exam($edit_id) : null;
$is_direct_mode = ($_GET['page'] ?? '') === 'olama-exam-direct' || ($exam && $exam->exam_type === 'direct');
$direct_lesson = $is_direct_mode ? Olama_Exam_Manager::get_direct_lesson($exam->direct_lesson_id ?? intval($_GET['lesson_id'] ?? 0)) : null;
if ($is_direct_mode && (!$direct_lesson || ($edit_id > 0 && (!$exam || $exam->exam_type !== 'direct')))) {
    wp_die('الدرس أو الامتحان المباشر غير موجود.');
}
$return_url = admin_url('admin.php?page=' . ($is_direct_mode ? 'olama-exam-direct' : (($_GET['page'] ?? '') === 'olama-exam-create-quiz' ? 'olama-exam-create-quiz' : 'olama-exam-create')));
if ($direct_lesson) {
    $return_url = add_query_arg(array('filter_grade' => $direct_lesson->grade_id, 'filter_subject' => $direct_lesson->subject_id), $return_url);
}
$exam_questions = ($exam && $exam->question_mode === 'manual' && $exam->manual_question_ids) 
    ? json_decode($exam->manual_question_ids, true) : array();

// ── Teacher Access Guard ────────────────────────────────────────────────────
// When a teacher opens the edit form for an existing exam, verify they are
// assigned to that exam's subject+section. Supervisors/Admins always pass.
if ($show_form && $edit_id > 0 && $exam) {
    if (!Olama_Exam_Ajax::can_teacher_access_exam($exam)) {
        $list_page = $is_direct_mode ? 'olama-exam-direct' : (($exam->exam_type === 'quiz') ? 'olama-exam-create-quiz' : 'olama-exam-create');
        echo '<script>window.location.href="' . admin_url('admin.php?page=' . $list_page . '&access_denied=1') . '";</script>';
        exit;
    }
}
// ── End Access Guard ─────────────────────────────────────────────────────────

// Detect Quiz Mode
$is_quiz_mode = (isset($_GET['page']) && $_GET['page'] === 'olama-exam-create-quiz');
$page_title = $show_form 
    ? ($exam ? olama_exam_translate($is_quiz_mode ? 'Edit Quiz' : 'Edit Exam') : ($is_quiz_mode ? olama_exam_translate('Create Quiz') : 'إنشاء اختبار التقويم'))
    : ($is_quiz_mode ? 'اختبارات قصيرة' : 'اختبارات التقويم');
if ($is_direct_mode) { $page_title = $exam ? 'تعديل امتحان مباشر' : 'إنشاء امتحان مباشر'; }

$exam_label = $is_quiz_mode ? olama_exam_translate('Quiz') : olama_exam_translate('Exam');

$status_labels = array(
    'draft'     => array('label' => olama_exam_translate('Draft'),     'class' => 'olama-exam-badge-draft'),
    'published' => array('label' => olama_exam_translate('Published'), 'class' => 'olama-exam-badge-published'),
    'active'    => array('label' => olama_exam_translate('Active'),    'class' => 'olama-exam-badge-active'),
    'closed'    => array('label' => olama_exam_translate('Closed'),    'class' => 'olama-exam-badge-closed'),
);

// Pre-filter list by URL params
$list_grade_id   = intval($_GET['filter_grade'] ?? 0);
$list_section_id = intval($_GET['filter_section'] ?? 0);
$list_subject_id = intval($_GET['filter_subject'] ?? 0);
$list_semesters = ($active_year && class_exists('Olama_School_Academic')) ? Olama_School_Academic::get_semesters($active_year->id) : array();
$list_semester_id = intval($_GET['filter_semester'] ?? ($active_semester->id ?? 0));
if (!in_array($list_semester_id, array_map('intval', array_column($list_semesters, 'id')), true)) {
    $list_semester_id = 0;
}
?>

<div class="olama-exam-wrap" dir="rtl">
    <!-- Header -->
    <div class="olama-exam-header">
        <div>
            <h1><?php echo $page_title; ?></h1>
        </div>
        <div class="actions">
            <?php if ($show_form): ?>
                <a href="<?php echo esc_url($return_url); ?>" class="olama-exam-btn olama-exam-btn-outline">
                    ← <?php echo olama_exam_translate('Back to List'); ?>
                </a>
            <?php else: ?>
                <button class="olama-exam-btn olama-exam-btn-primary" id="btn-new-exam" data-mode="<?php echo $is_quiz_mode ? 'quiz' : 'exam'; ?>">
                    + <?php echo $is_quiz_mode ? olama_exam_translate('Create Quiz') : 'إنشاء اختبار التقويم'; ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/school-exams-tabs.php'; ?>

    <?php if (!$show_form): ?>
    <!-- ═══════════════════ EXAM LIST VIEW ═══════════════════ -->
    <p class="oe-exam-list-message" style="padding:0 4px 16px; margin:0; text-align:right;">نشر الامتحان يجهّزه فقط. يظهر للطلاب بعد التفعيل، ويُغلق تلقائيًا عند انتهاء موعده.</p>

    <?php
    // Show access-denied notice if redirected from a denied exam
    if (!empty($_GET['access_denied'])): ?>
    <div class="notice notice-error" style="margin:10px 0;">
        <p><strong>⛔ <?php echo olama_exam_translate('Access Denied'); ?>:</strong>
        <?php echo olama_exam_translate('You are not assigned to that exam\'s subject or section.'); ?></p>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="olama-exam-filters oe-school-list-filters">
        <div class="olama-exam-form-group">
            <label for="filter-exam-year">العام الأكاديمي</label>
            <input type="text" id="filter-exam-year" value="<?php echo esc_attr($active_year->year_name ?? '—'); ?>" readonly data-id="<?php echo absint($active_year->id ?? 0); ?>">
        </div>
        <div class="olama-exam-form-group">
            <label for="filter-exam-semester">الفصل الدراسي</label>
            <select id="filter-exam-semester" required>
                <option value="">— اختر الفصل الدراسي —</option>
                <?php foreach ($list_semesters as $semester): ?>
                    <option value="<?php echo absint($semester->id); ?>" <?php echo $list_semester_id === intval($semester->id) ? 'selected' : ''; ?>><?php echo esc_html($semester->semester_name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="olama-exam-form-group">
        <label for="filter-exam-grade">الصف <span class="oe-filter-required">*</span></label>
        <select id="filter-exam-grade" required>
            <option value="">— اختر الصف —</option>
            <?php foreach ($grades as $g): ?>
                <option value="<?php echo $g->id; ?>" <?php echo ($list_grade_id == $g->id) ? 'selected' : ''; ?>><?php echo esc_html($g->grade_name); ?></option>
            <?php endforeach; ?>
        </select>
        </div>
        <div class="olama-exam-form-group">
            <label for="filter-exam-section">الشعبة <span class="oe-filter-required">*</span></label>
            <select id="filter-exam-section" required disabled><option value="">— اختر الصف أولاً —</option></select>
        </div>
        <div class="olama-exam-form-group">
            <label for="filter-exam-subject">المادة</label>
            <select id="filter-exam-subject" disabled><option value="">— اختر الصف أولاً —</option></select>
        </div>
        <div class="olama-exam-form-group">
        <label for="filter-exam-status">الحالة</label>
        <select id="filter-exam-status">
            <option value=""><?php echo olama_exam_translate('All'); ?> — <?php echo olama_exam_translate('Status'); ?></option>
            <?php foreach ($status_labels as $key => $sl): ?>
                <option value="<?php echo $key; ?>"><?php echo $sl['label']; ?></option>
            <?php endforeach; ?>
        </select>
        </div>
        <div class="olama-exam-form-group oe-filter-search">
        <label for="filter-exam-search">البحث</label>
        <input type="search" id="filter-exam-search" class="olama-exam-search"
            placeholder="<?php echo olama_exam_translate('Search'); ?>...">
        </div>
    </div>



    <div class="olama-exam-card">
        <div class="olama-exam-card-header">
            <h3><?php echo $is_quiz_mode ? 'اختبارات قصيرة' : 'اختبارات التقويم'; ?> (<span id="exam-count">0</span>)</h3>
        </div>
        <div id="exams-filter-prompt" class="oe-exam-list-message" role="status">اختر الفصل الدراسي والصف والشعبة لعرض الاختبارات.</div>
        <div id="exams-list-error" class="oe-exam-list-message" role="alert" style="display:none;">تعذر تحميل الاختبارات. حاول مجدداً بتعديل الفلتر.</div>
        <div id="exams-loading" style="display:none; text-align:center; padding:40px; color:#64748b;">
            ⏳ <?php echo olama_exam_translate('Loading...'); ?>
        </div>
        <table class="olama-exam-table" id="exams-table" style="display:none;">
            <thead>
                <tr>
                    <th><?php echo olama_exam_translate('Title'); ?></th>
                    <th><?php echo olama_exam_translate('Section'); ?></th>
                    <th><?php echo olama_exam_translate('Subject'); ?></th>
                    <th><?php echo olama_exam_translate('Questions'); ?></th>
                    <th><?php echo olama_exam_translate('Duration'); ?></th>
                    <th>تاريخ الامتحان</th>
                    <th><?php echo olama_exam_translate('Status'); ?></th>
                    <th><?php echo olama_exam_translate('Actions'); ?></th>
                </tr>
            </thead>
            <tbody id="exams-tbody"></tbody>
        </table>
        <div id="exams-empty" style="display:none; text-align:center; padding:40px; color:#64748b;">
            📝 <?php echo sprintf(olama_exam_translate('No %s found. Create your first %s!'), strtolower($is_quiz_mode ? olama_exam_translate('Quizzes') : olama_exam_translate('Exams')), strtolower($exam_label)); ?>
        </div>
    </div>

    <?php else: ?>
    <!-- ═══════════════════ EXAM FORM VIEW ═══════════════════ -->

    <form id="exam-form">
        <?php if ($is_direct_mode): ?>
        <input type="hidden" name="direct_lesson_id" value="<?php echo absint($direct_lesson->id); ?>">
        <div class="olama-exam-card" style="padding:20px;" dir="rtl"><strong><?php echo esc_html($direct_lesson->unit_name . ' — ' . $direct_lesson->lesson_title); ?></strong><p>أسئلة هذا الاختبار محصورة ببنك هذا الدرس فقط.</p></div>
        <?php endif; ?>
        <input type="hidden" name="id" value="<?php echo $exam ? $exam->id : 0; ?>">
        <input type="hidden" name="exam_type" value="<?php echo $is_direct_mode ? 'direct' : ($exam ? $exam->exam_type : ($is_quiz_mode ? 'quiz' : 'exam')); ?>">

        <!-- Info Card -->
        <div class="olama-exam-card">
            <div class="olama-exam-card-header" style="justify-content: space-between; display: flex;">
                <h3>📋 <?php echo sprintf(olama_exam_translate('%s Details'), $exam_label); ?></h3>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <?php if (!$is_quiz_mode && !$is_direct_mode): ?>
                    <label class="oe-toggle-option" style="margin: 0;">
                        <span style="font-size: 13px; font-weight: 600; color: #1e293b; margin: 0 10px;"><?php echo olama_exam_translate('Grade Placement Test'); ?></span>
                        <div class="oe-toggle-switch">
                            <input type="checkbox" name="is_placement" id="is-placement-toggle" <?php echo ($exam && $exam->is_placement) ? 'checked' : ''; ?>>
                            <label class="oe-toggle-slider" for="is-placement-toggle"></label>
                        </div>
                    </label>
                    <?php endif; ?>
                    <?php if ($exam): ?>
                        <span class="olama-exam-badge <?php echo $status_labels[$exam->status]['class'] ?? ''; ?>">
                            <?php echo $status_labels[$exam->status]['label'] ?? $exam->status; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!$is_quiz_mode && !$is_direct_mode): ?>
            <!-- SIS Exam Info Banner -->
            <div id="sis-exam-info" style="display:none; background:#f0fdf4; border-bottom:1px solid #dcfce7; padding:12px 20px;">
                <div style="display:flex; align-items:center; gap:12px; color:#166534; font-size:13px; font-weight:600;">
                    <span style="font-size:18px;">📅</span>
                    <div>
                        <div id="sis-exam-name">...</div>
                        <div id="sis-exam-dates" style="font-weight:400; font-size:12px; color:#15803d;">...</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div style="padding:20px;">
                <!-- Title (auto-generated) -->
                <div class="olama-exam-form-group">
                    <label><?php echo sprintf(olama_exam_translate('%s Title'), $exam_label); ?></label>
                    <input type="text" name="title" id="exam-title-input" value="<?php echo esc_attr($exam->title ?? ($direct_lesson ? 'اختبار — ' . $direct_lesson->lesson_title : '')); ?>" required
                        placeholder="<?php echo olama_exam_translate('Auto-generated after selecting grade, section & subject'); ?>">
                    <small id="exam-title-hint" style="color:#64748b; font-size:12px; display:none;">
                        💡 <?php echo olama_exam_translate('Title auto-generated. You can edit it.'); ?>
                    </small>
                </div>

                <!-- Placement Toggle Script -->
                <script>
                jQuery(document).ready(function($) {
                    $('#is-placement-toggle').on('change', function() {
                        if ($(this).is(':checked')) {
                            $('#section-select-group').fadeOut();
                            $('#exam-section-select').val(0);
                        } else {
                            $('#section-select-group').fadeIn();
                        }
                        // Re-trigger schedule if possible to update title
                        if (typeof fetchScheduleInfo === 'function') fetchScheduleInfo();
                    });
                });
                </script>

                <!-- Academic Context (read-only) -->
                <div class="olama-exam-form-row">
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Academic Year'); ?></label>
                        <input type="text" value="<?php echo esc_attr($active_year->year_name ?? '—'); ?>" readonly
                            style="background:#f1f5f9; cursor:not-allowed;">
                        <input type="hidden" name="academic_year_id" value="<?php echo $active_year->id ?? 0; ?>">
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Semester'); ?></label>
                        <input type="text" value="<?php echo esc_attr($active_semester->semester_name ?? '—'); ?>" readonly
                            style="background:#f1f5f9; cursor:not-allowed;">
                        <input type="hidden" name="semester_id" value="<?php echo $active_semester->id ?? 0; ?>">
                    </div>
                </div>

                <!-- Grade → Section, Subject -->
                <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr;">
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Grade'); ?></label>
                        <select id="exam-grade-select">
                            <option value="0">— <?php echo olama_exam_translate('Select'); ?> —</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?php echo $g->id; ?>" <?php echo ($exam && isset($exam->grade_id) && $exam->grade_id == $g->id) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($g->grade_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="olama-exam-form-group" id="section-select-group">
                        <label><?php echo olama_exam_translate('Section'); ?></label>
                        <select name="section_id" id="exam-section-select" disabled>
                            <option value="0">— <?php echo olama_exam_translate('Select Grade First'); ?> —</option>
                        </select>
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Subject'); ?></label>
                        <select name="subject_id" id="exam-subject-select" disabled>
                            <option value="0">— <?php echo olama_exam_translate('Select Grade First'); ?> —</option>
                        </select>
                    </div>
                </div>

                <!-- SIS Exam Schedule Info Banner -->
                <div id="sis-exam-info" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:18px;">📅</span>
                        <div>
                            <strong id="sis-exam-name" style="color:#16a34a;"></strong>
                            <span id="sis-exam-dates" style="color:#64748b; font-size:12px; display:block;"></span>
                        </div>
                    </div>
                </div>

                <!-- Timing -->
                <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr;">
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Start Time'); ?></label>
                        <input type="datetime-local" name="start_time" id="exam-start-time"
                            value="<?php echo $exam ? date('Y-m-d\TH:i', strtotime($exam->start_time)) : ''; ?>" required>
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('End Time'); ?></label>
                        <input type="datetime-local" name="end_time" id="exam-end-time"
                            value="<?php echo $exam ? date('Y-m-d\TH:i', strtotime($exam->end_time)) : ''; ?>" required>
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Duration'); ?> (<?php echo olama_exam_translate('minutes'); ?>)</label>
                        <input type="number" name="duration_minutes" value="<?php echo esc_attr($exam->duration_minutes ?? 60); ?>" 
                            min="5" max="300" required>
                    </div>
                </div>

                <!-- Settings -->
                <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr;">
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Passing Grade'); ?> (%)</label>
                        <input type="number" name="passing_grade" value="<?php echo esc_attr($exam->passing_grade ?? 50); ?>" 
                            min="0" max="100">
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Max Attempts'); ?></label>
                        <input type="number" name="max_attempts" value="<?php echo esc_attr($exam->max_attempts ?? 1); ?>" min="1" max="10">
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Show Results'); ?></label>
                        <select name="show_results">
                            <option value="0" <?php echo ($exam && $exam->show_results == 0) ? 'selected' : ''; ?>>
                                <?php echo olama_exam_translate('No'); ?></option>
                            <option value="1" <?php echo ($exam && $exam->show_results == 1) ? 'selected' : ''; ?>>
                                <?php echo olama_exam_translate('Yes'); ?></option>
                        </select>
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Show Correct Answers'); ?></label>
                        <select name="show_correct_answers">
                            <option value="0" <?php echo ($exam && $exam->show_correct_answers == 0) ? 'selected' : ''; ?>>
                                <?php echo olama_exam_translate('No'); ?></option>
                            <option value="1" <?php echo ($exam && $exam->show_correct_answers == 1) ? 'selected' : ''; ?>>
                                <?php echo olama_exam_translate('Yes'); ?></option>
                        </select>
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Password'); ?> (<?php echo olama_exam_translate('Optional'); ?>)</label>
                        <input type="password" name="password" value="<?php echo esc_attr($exam->password ?? ''); ?>" 
                            placeholder="<?php echo olama_exam_translate('Leave blank for no password'); ?>" autocomplete="new-password">
                    </div>
                    <div class="olama-exam-form-group">
                        <label><?php echo olama_exam_translate('Question Limit'); ?> (<?php echo olama_exam_translate('Optional'); ?>)</label>
                        <input type="number" name="question_limit" id="exam-question-limit" value="<?php echo esc_attr($exam->question_limit ?? ''); ?>" 
                            min="1" placeholder="<?php echo olama_exam_translate('No limit'); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Question Selection Card -->
        <div class="olama-exam-card" style="margin-top:20px;">
            <div class="olama-exam-card-header">
                <h3>❓ <?php echo olama_exam_translate('Questions'); ?></h3>
            </div>
            <div style="padding:20px;">
                <!-- Mode Selector Cards -->
                <div class="oe-mode-selector">
                    <label class="oe-mode-card <?php echo (!$exam || $exam->question_mode === 'manual') ? 'active' : ''; ?>">
                        <input type="radio" name="question_mode" value="manual" 
                            <?php echo (!$exam || $exam->question_mode === 'manual') ? 'checked' : ''; ?>>
                        <div class="oe-mode-icon">📝</div>
                        <div class="oe-mode-info">
                            <h4><?php echo olama_exam_translate('Manual Selection'); ?></h4>
                            <p><?php echo olama_exam_translate('Hand-pick questions from the question bank'); ?></p>
                        </div>
                        <div class="oe-mode-check">✓</div>
                    </label>
                    <label class="oe-mode-card <?php echo ($exam && $exam->question_mode === 'random') ? 'active' : ''; ?>">
                        <input type="radio" name="question_mode" value="random"
                            <?php echo ($exam && $exam->question_mode === 'random') ? 'checked' : ''; ?>>
                        <div class="oe-mode-icon">🎲</div>
                        <div class="oe-mode-info">
                            <h4><?php echo olama_exam_translate('Random from Unit'); ?></h4>
                            <p><?php echo olama_exam_translate('Auto-select random questions per student'); ?></p>
                        </div>
                        <div class="oe-mode-check">✓</div>
                    </label>
                </div>

                <!-- Toggle: Show all subject units -->
                <div class="oe-toggle-option">
                    <div class="oe-toggle-switch">
                        <input type="checkbox" id="q-show-all-units">
                        <label class="oe-toggle-slider" for="q-show-all-units"></label>
                    </div>
                    <label class="oe-toggle-label" for="q-show-all-units">
                        <?php echo olama_exam_translate('Show all subject units'); ?>
                        <span><?php echo olama_exam_translate('Select from the entire subject curriculum instead of just assigned material.'); ?></span>
                    </label>
                </div>

                <!-- Random Mode Settings -->
                <div id="random-mode-settings" style="display:<?php echo ($exam && $exam->question_mode === 'random') ? 'block' : 'none'; ?>;">
                    <div class="oe-random-panel">
                        <div class="olama-exam-form-row" style="grid-template-columns:1fr 1fr 1fr;">
                            <div class="olama-exam-form-group">
                                <label><?php echo olama_exam_translate('Unit'); ?></label>
                                <select name="random_unit_id" id="random-unit-select">
                                    <option value="0">— <?php echo olama_exam_translate('All Units'); ?> —</option>
                                </select>
                            </div>
                            <div class="olama-exam-form-group">
                                <label><?php echo olama_exam_translate('Number of Questions'); ?></label>
                                <input type="number" name="random_count" value="<?php echo esc_attr($exam->random_count ?? 10); ?>" 
                                    min="1" max="200">
                            </div>
                            <div class="olama-exam-form-group">
                                <label><?php echo olama_exam_translate('Difficulty Filter'); ?></label>
                                <select name="random_difficulty">
                                    <option value=""><?php echo olama_exam_translate('All'); ?></option>
                                    <option value="easy" <?php echo ($exam && $exam->random_difficulty === 'easy') ? 'selected' : ''; ?>>
                                        <?php echo olama_exam_translate('Easy'); ?></option>
                                    <option value="medium" <?php echo ($exam && $exam->random_difficulty === 'medium') ? 'selected' : ''; ?>>
                                        <?php echo olama_exam_translate('Medium'); ?></option>
                                    <option value="hard" <?php echo ($exam && $exam->random_difficulty === 'hard') ? 'selected' : ''; ?>>
                                        <?php echo olama_exam_translate('Hard'); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="oe-random-hint">
                            <span class="oe-hint-icon">💡</span>
                            <?php echo olama_exam_translate('Questions will be randomly selected each time a student starts the exam.'); ?>
                        </div>
                    </div>
                </div>

                <!-- Manual Mode: Question List -->
                <div id="manual-mode-settings" style="display:<?php echo (!$exam || $exam->question_mode === 'manual') ? 'block' : 'none'; ?>;">
                    <!-- Search Toolbar -->
                    <div class="oe-question-toolbar">
                        <select id="q-filter-unit">
                            <option value=""><?php echo olama_exam_translate('All Units (select subject first)'); ?></option>
                        </select>
                        <select id="q-filter-type">
                            <option value=""><?php echo olama_exam_translate('All Types'); ?></option>
                            <option value="mcq"><?php echo olama_exam_translate('MCQ'); ?></option>
                            <option value="tf"><?php echo olama_exam_translate('True / False'); ?></option>
                            <option value="short"><?php echo olama_exam_translate('Short Answer'); ?></option>
                            <option value="matching"><?php echo olama_exam_translate('Matching'); ?></option>
                            <option value="ordering"><?php echo olama_exam_translate('Ordering'); ?></option>
                            <option value="fill_blank"><?php echo olama_exam_translate('Fill in the Blank'); ?></option>
                            <option value="essay"><?php echo olama_exam_translate('Essay'); ?></option>
                        </select>
                        <div class="oe-search-input-wrap">
                            <input type="search" id="q-filter-search" placeholder="<?php echo olama_exam_translate('Search questions...'); ?>">
                        </div>
                        <button type="button" class="oe-search-btn" id="btn-search-questions">
                            <?php echo olama_exam_translate('Search'); ?>
                        </button>
                    </div>

                    <!-- Questions Panels -->
                    <div class="oe-questions-grid">
                        <!-- Left: Available -->
                        <div class="oe-panel">
                            <div class="oe-panel-header available">
                                <h4>📚 <?php echo olama_exam_translate('Available Questions'); ?></h4>
                                <span class="oe-panel-count" id="available-count">0</span>
                            </div>
                            <div id="available-questions" class="oe-panel-body available">
                                <div class="oe-empty-state">
                                    <div class="oe-empty-icon">🔎</div>
                                    <div class="oe-empty-text"><?php echo olama_exam_translate('Use search filters to find questions.'); ?></div>
                                </div>
                            </div>
                        </div>
                        <!-- Right: Selected -->
                        <div class="oe-panel">
                            <div class="oe-panel-header selected">
                                <h4>✅ <?php echo olama_exam_translate('Selected Questions'); ?></h4>
                                <span class="oe-panel-count" id="selected-count">0</span>
                            </div>
                            <div id="selected-questions" class="oe-panel-body selected"
                                data-initial='<?php echo json_encode($exam_questions); ?>'>
                                <div class="oe-empty-state empty-msg">
                                    <div class="oe-empty-icon">📋</div>
                                    <div class="oe-empty-text"><?php echo olama_exam_translate('Click + to add questions here.'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="manual_question_ids" id="manual-question-ids" 
                        value="<?php echo esc_attr(json_encode($exam_questions)); ?>">
                </div>
            </div>
        </div>

        <!-- Save Bar -->
        <div style="padding:20px 0; display:flex; gap:12px; justify-content:flex-end;">
            <a href="<?php echo esc_url($return_url); ?>"
                class="olama-exam-btn olama-exam-btn-outline"><?php echo olama_exam_translate('Cancel'); ?></a>
            <button type="submit" class="olama-exam-btn olama-exam-btn-primary" style="min-width:160px;">
                💾 <?php echo olama_exam_translate('Save Exam'); ?>
            </button>
        </div>
    </form>

    <?php endif; ?>
</div>

<!-- ═══════════════════ ADDITIONAL CSS ═══════════════════ -->
<?php if (!$show_form): ?>
<div id="edit-exam-name-modal" class="olama-exam-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="edit-exam-name-heading">
    <div class="olama-exam-modal" style="max-width:520px;">
        <form id="edit-exam-name-form">
            <div class="olama-exam-modal-header">
                <h3 id="edit-exam-name-heading">✏️ <?php echo esc_html(olama_exam_translate('Edit Exam Name')); ?></h3>
                <button type="button" class="olama-exam-modal-close" aria-label="<?php echo esc_attr(olama_exam_translate('Cancel')); ?>">&times;</button>
            </div>
            <div class="olama-exam-modal-body">
                <input type="hidden" id="edit-exam-name-id" value="">
                <div class="olama-exam-form-group" style="margin-bottom:0;">
                    <label for="edit-exam-name-input"><?php echo esc_html(olama_exam_translate('Exam Name')); ?></label>
                    <input type="text" id="edit-exam-name-input" maxlength="255" required autocomplete="off">
                </div>
            </div>
            <div class="olama-exam-modal-footer">
                <button type="button" class="olama-exam-btn olama-exam-btn-outline olama-exam-modal-close"><?php echo esc_html(olama_exam_translate('Cancel')); ?></button>
                <button type="submit" class="olama-exam-btn olama-exam-btn-primary" id="save-exam-name-btn">
                    <?php echo esc_html(olama_exam_translate('Save Name')); ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.olama-exam-badge-draft     { background: #f3f4f6; color: #4b5563; }
.olama-exam-badge-published { background: #dbeafe; color: #1d4ed8; }
.olama-exam-badge-active    { background: #dcfce7; color: #16a34a; }
.olama-exam-badge-closed    { background: #fee2e2; color: #dc2626; }

.oe-action-buttons {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: center;
}
.oe-action-group {
    display: flex;
    gap: 4px;
}
.olama-exam-btn-student {
    background: #6366f1;
    color: white;
    border-color: #6366f1;
}
.olama-exam-btn-student:hover {
    background: #4f46e5;
}
</style>

<!-- ═══════════════════ JAVASCRIPT ═══════════════════ -->
<script>
(function($) {
    var statusLabels = <?php echo json_encode(array_map(function($s){ return $s['label']; }, $status_labels)); ?>;
    var statusClasses = <?php echo json_encode(array_map(function($s){ return $s['class']; }, $status_labels)); ?>;
    var selectedIds = <?php echo json_encode($exam_questions); ?>;
    var isQuizMode = <?php echo $is_quiz_mode ? 'true' : 'false'; ?>;
    var directLesson = <?php echo wp_json_encode($direct_lesson); ?>;

    // Cached schedule data
    var scheduleInfo = null;
    var materialUnits = [];
    var allSubjectUnits = [];

    // ── Exam List ──────────────────────────────────────────
    var examListGeneration = 0;
    function loadExams() {
        if ($('#exams-table').length === 0) return; // not on list view
        var generation = ++examListGeneration;

        var filters = {
            action: 'olama_exam_get_exams',
            nonce: olamaExam.nonce,
            status: $('#filter-exam-status').val(),
            search: ($('#filter-exam-search').val() || '').trim(),
            grade_id: $('#filter-exam-grade').val(),
            section_id: $('#filter-exam-section').val(),
            subject_id: $('#filter-exam-subject').val(),
            academic_year_id: $('#filter-exam-year').attr('data-id'),
            semester_id: $('#filter-exam-semester').val(),
            list_scope: 'section',
            exam_type: isQuizMode ? 'quiz' : 'exam',
        };

        $('#exams-table, #exams-empty, #exams-list-error, #exams-filter-prompt, #exams-loading').hide();
        $('#exams-tbody').empty();
        $('#exam-count').text('0');
        if (!OlamaExamList.hasFilters(filters)) {
            $('#exams-filter-prompt').show();
            return;
        }
        $('#exams-loading').show();

        $.post(olamaExam.ajaxUrl, filters, function(res) {
            if (generation !== examListGeneration) return;
            $('#exams-loading').hide();
            if (!res.success) { $('#exams-list-error').show(); return; }

            var exams = res.data;
            $('#exam-count').text(exams.length);

            if (exams.length === 0) {
                $('#exams-empty').show();
                return;
            }

            var html = '';
            for (var i = 0; i < exams.length; i++) {
                var e = exams[i];
                var title = OlamaExamList.titleParts(e);
                var titleHtml = '<div class="oe-exam-title"><strong>' + escHtml(title.subject) + '</strong>' +
                    (title.detail ? '<span class="oe-exam-title-detail">' + escHtml(title.detail) + '</span>' : '') +
                    (title.academic ? '<small class="oe-exam-title-academic"><bdi>(' + escHtml(title.academic) + ')</bdi></small>' : '') + '</div>';
                var datesHtml = '<div class="oe-exam-dates"><div><span>البداية</span><bdi dir="ltr">' + escHtml(OlamaExamList.dateTime(e.start_time)) + '</bdi></div>' +
                    '<div><span>النهاية</span><bdi dir="ltr">' + escHtml(OlamaExamList.dateTime(e.end_time)) + '</bdi></div></div>';

                var placementBadge = e.is_placement == 1 ? '<span class="olama-exam-badge" style="background:#fef3c7; color:#92400e;">Placement</span> ' : '';
                var resultsLink = '<a class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm oe-summary-results-link" title="عرض النتائج" href="?page=olama-exam-results&exam_id=' + Number(e.id) +
                    '&academic_year_id=' + Number(e.academic_year_id) + '&semester_id=' + Number(e.semester_id) +
                    '&grade_id=' + Number($('#filter-exam-grade').val()) + '&section_id=' + Number(e.section_id) + '">📊 عرض النتائج</a>';
                html += '<tr>' +
                    '<td>' + titleHtml + '</td>' +
                    '<td>' + escHtml((e.grade_name || '') + ' ' + (e.section_name || '')) + '</td>' +
                    '<td>' + escHtml(e.subject_name || '—') + '</td>' +
                    '<td style="text-align:center;">' + e.question_count + '</td>' +
                    '<td>' + e.duration_minutes + ' min</td>' +
                    '<td>' + datesHtml + '</td>' +
                    '<td>' + placementBadge + '<span class="olama-exam-badge ' + (statusClasses[e.status] || '') + '">' + (statusLabels[e.status] || e.status) + '</span></td>' +
                    '<td>' +
                        '<div class="oe-action-buttons">' +
                            '<div class="oe-action-group main">' +
                                '<a href="?page=' + (isQuizMode ? 'olama-exam-create-quiz' : 'olama-exam-create') + '&edit=' + e.id + '" ' +
                                    'class="olama-exam-btn olama-exam-btn-primary olama-exam-btn-sm" title="<?php echo olama_exam_translate("Edit"); ?>">✏️</a>' +
                                '<a href="?page=olama-exam-student-preview&id=' + e.id + '" ' +
                                    'class="olama-exam-btn olama-exam-btn-student olama-exam-btn-sm" title="<?php echo olama_exam_translate("Student Preview"); ?>">👨‍🎓</a>' +
                                '<button type="button" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-copy-link" data-id="' + e.id + '" title="<?php echo olama_exam_translate("Copy Public Link"); ?>">🔗</button>' +
                                '<button type="button" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-replicate" data-id="' + e.id + '" title="<?php echo olama_exam_translate("Replicate"); ?>">👯</button>' +
                            '</div>' +
                            '<div class="oe-action-group secondary">' +
                                '<a href="?page=olama-exam-preview&id=' + e.id + '" ' +
                                    'class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" title="<?php echo olama_exam_translate("Teacher Preview"); ?>">👁️</a>' +
                                '<button type="button" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-edit-exam-name" data-id="' + e.id + '" data-title="' + escAttr(e.title) + '" title="<?php echo esc_attr(olama_exam_translate("Edit Exam Name")); ?>">🏷️</button>' +
                                buildStatusBtn(e) +
                                '<button class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm btn-delete-exam" ' +
                                    'data-id="' + e.id + '" title="<?php echo olama_exam_translate("Delete"); ?>">🗑</button>' +
                            '</div>' +
                            (isQuizMode ? resultsLink : '') +
                        '</div>' +
                    '</td>' +
                '</tr>';
                if (!isQuizMode) {
                    html += '<tr class="oe-exam-summary-row"><td colspan="8">' + renderExamSummary(e.summary, resultsLink) + '</td></tr>';
                }
            }

            $('#exams-tbody').html(html);
            $('#exams-table').show();
        }).fail(function() {
            if (generation !== examListGeneration) return;
            $('#exams-loading').hide();
            $('#exams-list-error').show();
        });
    }

    function renderExamSummary(summary, resultsLink) {
        var s = summary || {};
        var total = Number(s.attempts) || 0, pending = Number(s.pending) || 0;
        if (!total) {
            return '<div class="oe-exam-summary oe-summary-empty">' + resultsLink + '<span>لا توجد محاولات مسلّمة لهذا الاختبار بعد.</span></div>';
        }
        function percent(value) {
            return value == null ? '—' : '<bdi dir="ltr">' + escHtml(String(Number(value))) + '%</bdi>';
        }
        function metric(label, value, tone) {
            return '<div class="oe-summary-metric ' + (tone || '') + '"><strong>' + value + '</strong><span>' + label + '</span></div>';
        }
        var rate = Math.max(0, Math.min(100, Number(s.pass_rate) || 0));
        return '<div class="oe-exam-summary"><div class="oe-summary-action">' + resultsLink + '<small>ملخص النتائج</small></div><div class="oe-summary-body"><div class="oe-summary-metrics">' +
            metric('محاولات مسلّمة', total, 'oe-summary-blue') + metric('المتوسط', percent(s.average)) +
            metric('نسبة النجاح', percent(s.pass_rate), 'oe-summary-green') + metric('أعلى درجة', percent(s.highest)) + metric('أدنى درجة', percent(s.lowest)) +
            '</div><div class="oe-summary-outcomes"><span class="oe-summary-passed">ناجح: ' + (Number(s.passed) || 0) + '</span><span class="oe-summary-failed">راسب: ' + (Number(s.failed) || 0) + '</span>' +
            (pending ? '<span class="oe-summary-pending">بانتظار التصحيح: ' + pending + '</span>' : '') +
            '<span class="oe-summary-track" aria-hidden="true"><i style="width:' + rate + '%"></i></span>' +
            (pending ? '<small>المؤشرات للمحاولات المصححة فقط</small>' : '') + '</div></div></div>';
    }

    function buildStatusBtn(e) {
        switch (e.status) {
            case 'draft':
                return '<button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-status" ' +
                    'data-id="' + e.id + '" data-status="published" title="نشر وتجهيز الامتحان">📢</button>';
            case 'published':
                return '<button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-status" ' +
                    'data-id="' + e.id + '" data-status="active" title="تفعيل وإتاحة الامتحان للطلاب">▶️</button>';
            case 'active':
                return '<button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-status" ' +
                    'data-id="' + e.id + '" data-status="closed" title="Close">⏹️</button>';
            case 'closed':
                return '<button class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm btn-status" ' +
                    'data-id="' + e.id + '" data-status="draft" title="Re-edit">🔄</button>';
        }
        return '';
    }

    function escHtml(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Rename an exam without opening or submitting the full edit form.
    $(document).on('click', '.btn-edit-exam-name', function() {
        $('#edit-exam-name-id').val($(this).data('id'));
        $('#edit-exam-name-input').val($(this).attr('data-title'));
        ExamAdmin.openModal('edit-exam-name-modal');
        window.setTimeout(function() {
            $('#edit-exam-name-input').trigger('focus').select();
        }, 0);
    });

    $('#edit-exam-name-form').on('submit', function(e) {
        e.preventDefault();

        var $button = $('#save-exam-name-btn');
        var title = $('#edit-exam-name-input').val().trim();
        if (!title) {
            $('#edit-exam-name-input').trigger('focus');
            return;
        }

        $button.prop('disabled', true);
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_update_exam_title',
            nonce: olamaExam.nonce,
            id: $('#edit-exam-name-id').val(),
            title: title,
        }, function(res) {
            ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
            if (res.success) {
                ExamAdmin.closeModal();
                loadExams();
            }
        }).fail(function() {
            ExamAdmin.toast(<?php echo wp_json_encode(olama_exam_translate('Failed to update exam name.')); ?>, 'error');
        }).always(function() {
            $button.prop('disabled', false);
        });
    });

    // Copy Public Link
    $(document).on('click', '.btn-copy-link', function() {
        var id = $(this).data('id');
        var base = olamaExam.examsPageUrl;
        var sep = base.indexOf('?') !== -1 ? '&' : '?';
        var link = base + sep + 'exam_id=' + id;
        
        var tempInput = document.createElement("input");
        tempInput.style.position = "absolute";
        tempInput.style.left = "-1000px";
        tempInput.style.top = "-1000px";
        tempInput.value = link;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand("copy");
        document.body.removeChild(tempInput);
        
        if (typeof ExamAdmin !== 'undefined' && ExamAdmin.toast) {
            ExamAdmin.toast('<?php echo olama_exam_translate("Link copied to clipboard!"); ?>');
        } else {
            alert('<?php echo olama_exam_translate("Link copied to clipboard!"); ?>');
        }
        
        $(this).text('📋').css('color', '#16a34a');
        var btn = this;
        setTimeout(function() { 
            $(btn).text('🔗').css('color', '');
        }, 2000);
    });

    // List view events
    $('#filter-exam-status').on('change', loadExams);
    var searchTimer;
    $('#filter-exam-search').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadExams, 300);
    });

    // A grade owns both the required section and the optional subject filter.
    var listFilterGeneration = 0;
    function loadGradeFilters(gradeId, restoreSection, restoreSubject) {
        var token = ++listFilterGeneration;
        var sectionsReady = false, subjectsReady = false;
        var section = $('#filter-exam-section'), subject = $('#filter-exam-subject');
        section.empty().append($('<option>').val('').text('— اختر الشعبة —')).prop('disabled', true);
        subject.empty().append($('<option>').val('').text('كل المواد')).prop('disabled', true);
        loadExams();
        if (!Number(gradeId)) return;
        function current() { return token === listFilterGeneration; }
        function refresh() { if (sectionsReady && subjectsReady) loadExams(); }
        function failed() { if (current()) ExamAdmin.toast('تعذر تحميل قوائم الصف. أعد اختيار الصف للمحاولة مجدداً.', 'error'); }
        $.post(olamaExam.ajaxUrl, {action:'olama_exam_get_sections_by_grade', nonce:olamaExam.nonce, grade_id:gradeId}).done(function(res) {
            if (!current()) return;
            if (!res.success) { failed(); return; }
            (res.data || []).forEach(function(item) { section.append($('<option>').val(item.id).text(item.section_name)); });
            section.prop('disabled', false).val(String(restoreSection || ''));
            if (!section.val()) section.val('');
            sectionsReady = true;
            refresh();
        }).fail(failed);
        $.post(olamaExam.ajaxUrl, {action:'olama_exam_get_subjects_by_grade', nonce:olamaExam.nonce, grade_id:gradeId}).done(function(res) {
            if (!current()) return;
            if (!res.success) { failed(); return; }
            (res.data || []).forEach(function(item) { subject.append($('<option>').val(item.id).text(item.subject_name)); });
            subject.prop('disabled', false).val(String(restoreSubject || ''));
            if (!subject.val()) subject.val('');
            subjectsReady = true;
            refresh();
        }).fail(failed);
    }
    $('#filter-exam-grade').on('change', function() { loadGradeFilters($(this).val(), 0, 0); });
    $('#filter-exam-section, #filter-exam-semester, #filter-exam-subject').on('change', loadExams);

    // New Exam button → redirect to form
    $('#btn-new-exam').on('click', function() {
        var mode = $(this).data('mode') || 'exam';
        var page = mode === 'quiz' ? 'olama-exam-create-quiz' : 'olama-exam-create';
        window.location = '?page=' + page + '&edit=0';
    });

    // Replicate exam
    $(document).on('click', '.btn-replicate', function() {
        if (!confirm('<?php echo olama_exam_translate("Replicate this exam?"); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('⏳');
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_replicate_exam',
            nonce: olamaExam.nonce,
            id: $btn.data('id'),
        }, function(res) {
            ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadExams();
            else $btn.prop('disabled', false).text('👯');
        });
    });

    // Status change
    $(document).on('click', '.btn-status', function() {
        var $btn = $(this);
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_update_status',
            nonce: olamaExam.nonce,
            id: $btn.data('id'),
            status: $btn.data('status'),
        }, function(res) {
            ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadExams();
        });
    });

    // Delete exam
    $(document).on('click', '.btn-delete-exam', function() {
        if (!confirm('<?php echo olama_exam_translate("Delete this exam?"); ?>')) return;
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_delete_exam',
            nonce: olamaExam.nonce,
            id: $(this).data('id'),
        }, function(res) {
            ExamAdmin.toast(res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadExams();
        });
    });

    // ── Grade → Section + Subject Cascade ──────────────────
    $('#exam-grade-select').on('change', function() {
        var gradeId = $(this).val();
        var sectionSel = $('#exam-section-select');
        var subjectSel = $('#exam-subject-select');

        if (!gradeId || gradeId == '0') {
            sectionSel.html('<option value="0">— <?php echo olama_exam_translate("Select Grade First"); ?> —</option>').prop('disabled', true);
            subjectSel.html('<option value="0">— <?php echo olama_exam_translate("Select Grade First"); ?> —</option>').prop('disabled', true);
            return;
        }

        var isPlacement = $('#is-placement-toggle').is(':checked');
        if (isPlacement) {
            $('#section-select-group').hide();
        } else {
            $('#section-select-group').show();
        }

        var sectionsLoaded = false;
        var subjectsLoaded = false;

        function checkReady() {
            if (sectionsLoaded && subjectsLoaded) {
                fetchScheduleInfo();
            }
        }

        // Load sections
        sectionSel.html('<option value="0">⏳</option>').prop('disabled', true);
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_get_sections_by_grade',
            nonce: olamaExam.nonce,
            grade_id: gradeId,
        }, function(res) {
            sectionsLoaded = true;
            if (res.success) {
                var html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                for (var i = 0; i < res.data.length; i++) {
                    var s = res.data[i];
                    var sel = (<?php echo intval($exam->section_id ?? 0); ?> == s.id) ? 'selected' : '';
                    html += '<option value="' + s.id + '" ' + sel + '>' + s.section_name + '</option>';
                }
                sectionSel.html(html).prop('disabled', false);
            }
            checkReady();
        });

        // Load subjects
        subjectSel.html('<option value="0">⏳</option>').prop('disabled', true);
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_get_subjects_by_grade',
            nonce: olamaExam.nonce,
            grade_id: gradeId,
        }, function(res) {
            subjectsLoaded = true;
            if (res.success) {
                var html = '<option value="0">— <?php echo olama_exam_translate("Select"); ?> —</option>';
                for (var j = 0; j < res.data.length; j++) {
                    var s2 = res.data[j];
                    var sel2 = (<?php echo intval($exam->subject_id ?? 0); ?> == s2.id) ? 'selected' : '';
                    html += '<option value="' + s2.id + '" ' + sel2 + '>' + s2.subject_name + '</option>';
                }
                subjectSel.html(html).prop('disabled', false);
                if (directLesson) {
                    subjectSel.val(directLesson.subject_id);
                    // Keep the fixed subject in the submitted form.
                    subjectSel.find('option').filter(function() { return this.value != directLesson.subject_id; }).remove();
                }
            }
            checkReady();
        });
    });

    // ── Fetch Exam Schedule Info when all 3 are selected ──
    function fetchScheduleInfo() {
        if (directLesson) {
            populateUnitDropdowns();
            searchQuestions();
            updateSelectedUI();
            return;
        }
        var gradeId   = $('#exam-grade-select').val();
        var sectionId = $('#exam-section-select').val();
        var subjectId = $('#exam-subject-select').val();
        var isPlacement = $('#is-placement-toggle').is(':checked');

        if (!gradeId || gradeId == '0' || !subjectId || subjectId == '0') return;

        // For placement tests, section is optional/ignored for schedule info
        if (isPlacement) sectionId = 0;

        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_get_exam_schedule_info',
            nonce: olamaExam.nonce,
            grade_id: gradeId,
            section_id: sectionId || 0,
            subject_id: subjectId,
            is_placement: isPlacement ? 1 : 0,
            exam_type: isQuizMode ? 'quiz' : 'exam',
        }, function(res) {
            if (!res.success) {
                scheduleInfo = null;
                materialUnits = [];
                $('#sis-exam-info').hide();
                populateUnitDropdowns();
                return;
            }

            scheduleInfo = res.data;
            materialUnits = res.data.material_units || [];

            // If no material units found, default to showing all subject units
            if (materialUnits.length === 0) {
                $('#q-show-all-units').prop('checked', true).trigger('change');
            }

            // Feature #1: Auto-generate title (only for new exams/quizzes)
            var isNew = $('input[name="id"]').val() == '0';
            if (isNew && res.data.auto_title) {
                $('#exam-title-input').val(res.data.auto_title);
                $('#exam-title-hint').show();
            }

            // Feature #2: Default dates from exam schedule
            if (isNew && !isQuizMode && res.data.exam_date) {
                var d = res.data.exam_date; // format: YYYY-MM-DD
                $('#exam-start-time').val(d + 'T00:00');
                $('#exam-end-time').val(d + 'T23:59');
            }

            // Show SIS info banner
            if (res.data.semester_exam) {
                $('#sis-exam-name').text(res.data.semester_exam.exam_name);
                $('#sis-exam-dates').text(res.data.semester_exam.start_date + ' → ' + res.data.semester_exam.end_date);
                $('#sis-exam-info').show();
            }

            // Feature #3: Populate unit dropdowns with exam material units
            populateUnitDropdowns();
        });

        // Also load ALL subject units for the override checkbox
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_get_units_by_subject',
            nonce: olamaExam.nonce,
            grade_id: gradeId,
            subject_id: subjectId,
        }, function(res) {
            allSubjectUnits = res.data || [];
            populateUnitDropdowns();
        });
    }

    function populateUnitDropdowns() {
        if (directLesson) {
            var option = $('<option>').val('l_' + directLesson.id).text(directLesson.lesson_title);
            $('#q-filter-unit, #random-unit-select').empty().append(option).prop('disabled', true);
            $('#q-show-all-units').closest('.oe-toggle-option').hide();
            $('#q-show-all-units').closest('.oe-toggle-row').hide();
            return;
        }
        var showAll = $('#q-show-all-units').is(':checked');
        var units = showAll ? allSubjectUnits : materialUnits;
        var currentRandomUnit = <?php echo intval($exam->random_unit_id ?? 0); ?>;
        var currentRandomLesson = <?php echo intval($exam->random_lesson_id ?? 0); ?>;

        // Manual mode unit filter
        var html = '<option value=""><?php echo olama_exam_translate("All Exam Material Units"); ?></option>';
        for (var i = 0; i < units.length; i++) {
            var u = units[i];
            html += '<option value="u_' + u.id + '">' + u.unit_number + ' - ' + u.unit_name + ' (' + u.question_count + ')</option>';
            if (u.lessons && u.lessons.length > 0) {
                for (var k = 0; k < u.lessons.length; k++) {
                    var l = u.lessons[k];
                    html += '<option value="l_' + l.id + '">&nbsp;&nbsp;&nbsp;↳ ' + l.lesson_number + ' - ' + l.lesson_title + ' (' + l.question_count + ')</option>';
                }
            }
        }
        $('#q-filter-unit').html(html);

        // Random mode unit selector
        var rHtml = '<option value="0">— <?php echo olama_exam_translate("All Units"); ?> —</option>';
        for (var j = 0; j < units.length; j++) {
            var u2 = units[j];
            var selU = (currentRandomUnit == u2.id && currentRandomLesson == 0) ? 'selected' : '';
            rHtml += '<option value="u_' + u2.id + '" ' + selU + '>' + u2.unit_number + ' - ' + u2.unit_name + ' (' + u2.question_count + ')</option>';
            if (u2.lessons && u2.lessons.length > 0) {
                for (var m = 0; m < u2.lessons.length; m++) {
                    var l2 = u2.lessons[m];
                    var selL = (currentRandomLesson == l2.id) ? 'selected' : '';
                    rHtml += '<option value="l_' + l2.id + '" ' + selL + '>&nbsp;&nbsp;&nbsp;↳ ' + l2.lesson_number + ' - ' + l2.lesson_title + ' (' + l2.question_count + ')</option>';
                }
            }
        }
        $('#random-unit-select').html(rHtml);
    }

    // Feature #4: Override checkbox
    $('#q-show-all-units').on('change', populateUnitDropdowns);

    // Trigger schedule fetch when section or subject changes
    $('#exam-section-select').on('change', fetchScheduleInfo);
    $('#exam-subject-select').on('change', fetchScheduleInfo);

    // Auto-trigger grade cascade on edit
    <?php if ($direct_lesson || ($exam && isset($exam->grade_id) && $exam->grade_id > 0)): ?>
    $(document).ready(function() {
        var initialGrade = <?php echo intval($direct_lesson->grade_id ?? $exam->grade_id); ?>;
        if (directLesson) {
            $('#exam-grade-select option').filter(function() { return this.value != initialGrade; }).remove();
        }
        $('#exam-grade-select').val(initialGrade).trigger('change');
    });
    <?php endif; ?>

    <?php if (!$show_form && $list_grade_id > 0): ?>
    $(document).ready(function() {
        loadGradeFilters(<?php echo $list_grade_id; ?>, <?php echo $list_section_id; ?>, <?php echo $list_subject_id; ?>);
    });
    <?php endif; ?>

    // ── Question Mode Toggle ───────────────────────────────
    $('input[name="question_mode"]').on('change', function() {
        var mode = $(this).val();
        // Toggle active class on mode cards
        $('.oe-mode-card').removeClass('active');
        $(this).closest('.oe-mode-card').addClass('active');
        $('#random-mode-settings').toggle(mode === 'random');
        $('#manual-mode-settings').toggle(mode === 'manual');
    });

    // Type badge CSS classes map
    var typeBadgeClasses = {
        'mcq': 'olama-exam-badge-mcq',
        'tf': 'olama-exam-badge-tf',
        'short': 'olama-exam-badge-short',
        'matching': 'olama-exam-badge-matching',
        'ordering': 'olama-exam-badge-ordering',
        'fill_blank': 'olama-exam-badge-fill_blank',
        'essay': 'olama-exam-badge-essay'
    };

    function getTypeBadge(type) {
        var cls = typeBadgeClasses[type] || 'olama-exam-badge-essay';
        var label = type ? type.toUpperCase().replace('_', ' ') : '';
        return '<span class="q-type-badge ' + cls + '">' + label + '</span>';
    }

    // ── Manual Question Selection ──────────────────────────
    function searchQuestions() {
        var filterVal = $('#q-filter-unit').val();
        var filterUnit = '';
        var filterLesson = '';
        if (filterVal) {
            if (filterVal.startsWith('u_')) {
                filterUnit = filterVal.substring(2);
            } else if (filterVal.startsWith('l_')) {
                filterLesson = filterVal.substring(2);
            }
        }

        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_get_questions',
            nonce: olamaExam.nonce,
            grade_id: $('#exam-grade-select').val(),
            subject_id: $('#exam-subject-select').val(),
            unit_id: directLesson ? directLesson.unit_id : filterUnit,
            lesson_id: directLesson ? directLesson.id : filterLesson,
            type: $('#q-filter-type').val(),
            search: $('#q-filter-search').val(),
        }, function(res) {
            if (!res.success) return;
            var qs = res.data;
            $('#available-count').text(qs.length);

            if (qs.length === 0) {
                $('#available-questions').html('<div class="oe-empty-state"><div class="oe-empty-icon">📭</div><div class="oe-empty-text"><?php echo olama_exam_translate("No questions found."); ?></div></div>');
                return;
            }

            var html = '';
            for (var i = 0; i < qs.length; i++) {
                var q = qs[i];
                var isSelected = false;
                for (var k = 0; k < selectedIds.length; k++) {
                    if (selectedIds[k] == q.id) {
                        isSelected = true;
                        break;
                    }
                }
                var text = q.question_text.length > 60 ? q.question_text.substring(0, 60) + '...' : q.question_text;
                html += '<div class="q-item" data-id="' + q.id + '">' +
                    getTypeBadge(q.type) +
                    '<span class="q-text">' + escHtml(text) + '</span>' +
                    '<span class="q-unit-name">' + escHtml(q.unit_name || '') + '</span>' +
                    '<button type="button" class="q-action add ' + (isSelected ? 'disabled' : '') + '" ' +
                        (isSelected ? 'disabled style="opacity:0.3;"' : '') + ' data-id="' + q.id + '">＋</button>' +
                '</div>';
            }
            $('#available-questions').html(html);
        });
    }

    $('#btn-search-questions').on('click', searchQuestions);
    $('#q-filter-search').on('keyup', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); searchQuestions(); }
    });

    // Add question
    $(document).on('click', '.q-action.add:not(.disabled)', function() {
        var id = parseInt($(this).data('id'));
        var alreadySelected = false;
        for (var i = 0; i < selectedIds.length; i++) {
            if (selectedIds[i] == id) {
                alreadySelected = true;
                break;
            }
        }
        if (alreadySelected) return;

        var limit = parseInt($('#exam-question-limit').val());
        if (limit > 0 && selectedIds.length >= limit) {
            ExamAdmin.toast('<?php echo olama_exam_translate("Question limit reached."); ?>', 'error');
            return;
        }

        selectedIds.push(id);
        updateSelectedUI();
        // Disable in available list
        $('.q-action.add[data-id="' + id + '"]').addClass('disabled').prop('disabled', true).css('opacity', '0.3');
    });

    // Remove question
    $(document).on('click', '.q-action.remove', function() {
        var id = parseInt($(this).data('id'));
        var newIds = [];
        for (var i = 0; i < selectedIds.length; i++) {
            if (selectedIds[i] != id) {
                newIds.push(selectedIds[i]);
            }
        }
        selectedIds = newIds;
        updateSelectedUI();
        // Re-enable in available list  
        $('.q-action.add[data-id="' + id + '"]').removeClass('disabled').prop('disabled', false).css('opacity', '1');
    });

    function updateSelectedUI() {
        $('#selected-count').text(selectedIds.length);
        $('#manual-question-ids').val(JSON.stringify(selectedIds));

        if (selectedIds.length === 0) {
            $('#selected-questions').html('<div class="oe-empty-state empty-msg"><div class="oe-empty-icon">📋</div><div class="oe-empty-text"><?php echo olama_exam_translate("Click + to add questions here."); ?></div></div>');
            return;
        }

        // Get question details for selected IDs
        $.post(olamaExam.ajaxUrl, {
            action: 'olama_exam_get_questions',
            nonce: olamaExam.nonce,
            grade_id: $('#exam-grade-select').val(),
            subject_id: $('#exam-subject-select').val(),
            lesson_id: directLesson ? directLesson.id : '',
        }, function(res) {
            if (!res.success) return;
            var all = res.data;
            var html = '';
            for (var i = 0; i < selectedIds.length; i++) {
                var id = selectedIds[i];
                var q = null;
                for (var j = 0; j < all.length; j++) {
                    if (all[j].id == id) {
                        q = all[j];
                        break;
                    }
                }
                if (!q) continue;
                var text = q.question_text.length > 50 ? q.question_text.substring(0, 50) + '...' : q.question_text;
                html += '<div class="q-item" data-id="' + q.id + '">' +
                    '<span class="q-number">' + (i + 1) + '</span>' +
                    '<span class="q-text">' + escHtml(text) + '</span>' +
                    getTypeBadge(q.type) +
                    '<button type="button" class="q-action remove" data-id="' + q.id + '">✕</button>' +
                '</div>';
            }
            $('#selected-questions').html(html);
        });
    }

    // Load initial selected questions
    if (selectedIds.length > 0) {
        updateSelectedUI();
    }

    // ── Save Exam ──────────────────────────────────────────
    $('#exam-form').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serializeArray();
        var data = {};
        for (var i = 0; i < formData.length; i++) {
            if (formData[i].name === 'random_unit_id') {
                var randomVal = formData[i].value;
                if (randomVal && randomVal.startsWith('u_')) {
                    data['random_unit_id'] = randomVal.substring(2);
                    data['random_lesson_id'] = '0';
                } else if (randomVal && randomVal.startsWith('l_')) {
                    data['random_unit_id'] = '0';
                    data['random_lesson_id'] = randomVal.substring(2);
                } else {
                    data['random_unit_id'] = '0';
                    data['random_lesson_id'] = '0';
                }
            } else {
                data[formData[i].name] = formData[i].value;
            }
        }
        data.action = 'olama_exam_save_exam';
        if (directLesson) {
            data.direct_lesson_id = directLesson.id;
            data.exam_type = 'direct';
            data.random_lesson_id = directLesson.id;
            data.random_unit_id = directLesson.unit_id;
        }
        data.nonce = olamaExam.nonce;

        $.post(olamaExam.ajaxUrl, data, function(res) {
            if (res.success) {
                ExamAdmin.toast(res.data.message);
                var gradeId = $('#exam-grade-select').val();
                var sectionId = $('#exam-section-select').val();
                var url = '?page=' + (directLesson ? 'olama-exam-direct' : (isQuizMode ? 'olama-exam-create-quiz' : 'olama-exam-create'));
                if (directLesson) url += '&filter_subject=' + directLesson.subject_id;
                else url += '&filter_subject=' + $('#exam-subject-select').val() + '&filter_semester=' + data.semester_id;
                if (gradeId && gradeId != '0') url += '&filter_grade=' + gradeId;
                if (sectionId && sectionId != '0') url += '&filter_section=' + sectionId;
                window.location = url;
            } else {
                ExamAdmin.toast(res.data.message, 'error');
            }
        });
    });

    // ── Initial Load ───────────────────────────────────────
    loadExams();

})(jQuery);
</script>
