<?php
/**
 * Admin View: Student Acceptance Results
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Retrieve query filters
$grade_filter  = isset($_GET['grade_level_id']) ? intval($_GET['grade_level_id']) : 0;
$test_filter   = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;
$result_filter = isset($_GET['result_status']) ? sanitize_text_field($_GET['result_status']) : '';
$start_filter  = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_filter    = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

// ── Handle Attempt Review ─────────────────────────────
$review_attempt_id = isset($_GET['review_attempt']) ? intval($_GET['review_attempt']) : 0;
$review_data = null;
if ($review_attempt_id > 0) {
    $review_attempt = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, t.title as exam_title, t.pass_score_pct as passing_grade,
                ap.student_name, gl.name_ar AS grade,
                ap.guardian_name, ap.phone as mobile, ap.email, ap.national_id, ap.date_of_birth
         FROM {$wpdb->prefix}olama_exam_attempts a
         JOIN {$wpdb->prefix}oee_student_applicants ap ON a.id = ap.attempt_id
         JOIN {$wpdb->prefix}oee_student_tests t ON ap.test_id = t.id
         JOIN {$wpdb->prefix}oee_grade_levels gl ON t.grade_level_id = gl.id
         WHERE a.id = %d",
        $review_attempt_id
    ));

    if ($review_attempt) {
        $snapshot = json_decode($review_attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($review_attempt->answers_json, true) ?: array();

        $review_questions = array();
        foreach ($snapshot as $idx => $q) {
            $qid = $q['question_id'];
            $student_answer = $answers[$qid] ?? null;
            $correct_data = $q['correct'] ?? array();
            $points = $q['points'] ?? 1;
            $type = $q['type'];

            // Determine earned score
            $earned = 0;
            $status = 'unanswered';
            if ($type === 'essay') {
                $essay_grade = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}olama_exam_essay_grades WHERE attempt_id = %d AND question_id = %d",
                    $review_attempt_id,
                    $qid
                ));
                $earned = $essay_grade ? floatval($essay_grade->score) : 0;
                $status = $essay_grade ? 'graded' : 'pending';
            } elseif ($student_answer !== null && $student_answer !== '' && !($type !== 'essay' && is_array($student_answer) && empty(array_filter($student_answer)))) {
                $earned = Olama_Exam_Grader::grade_question($type, $student_answer, $correct_data, $points);
                $status = ($earned >= $points) ? 'correct' : (($earned > 0) ? 'partial' : 'incorrect');
            }

            $review_questions[] = array(
                'index' => $idx + 1,
                'question_id' => $qid,
                'type' => $type,
                'text' => $q['question_text'],
                'image' => $q['image_filename'] ?? null,
                'student_answer' => $student_answer,
                'correct_data' => $correct_data,
                'choices' => $q['answers']['choices'] ?? array(),
                'lefts' => $q['answers']['lefts'] ?? array(),
                'rights' => $q['answers']['rights'] ?? array(),
                'items' => $q['answers']['items'] ?? array(),
                'points' => $points,
                'earned' => $earned,
                'status' => $status,
                'essay_grade' => $essay_grade ?? null,
                'explanation' => $q['explanation'] ?? null,
            );
        }

        $review_data = array(
            'attempt' => $review_attempt,
            'questions' => $review_questions,
        );
    }
}

// Build Query
$query = "SELECT ap.student_name, ap.guardian_name, ap.date_of_birth, ap.phone, ap.email, ap.national_id,
                 gl.name_ar AS grade,
                 t.title AS test_title,
                 att.id AS attempt_id, att.score, att.max_score, att.percentage, att.result, att.started_at
          FROM {$wpdb->prefix}oee_student_applicants ap
          JOIN {$wpdb->prefix}olama_exam_attempts att ON att.id = ap.attempt_id
          JOIN {$wpdb->prefix}oee_student_tests t ON t.id = ap.test_id
          JOIN {$wpdb->prefix}oee_grade_levels gl ON gl.id = t.grade_level_id
          WHERE att.exam_type = 'student_acceptance'";

$params = array();

if ($grade_filter > 0) {
    $query .= " AND t.grade_level_id = %d";
    $params[] = $grade_filter;
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
$grade_levels = OEE_Grade_Levels::get_all();
$tests = OEE_Student_Tests::get_all();
?>
<div class="olama-exam-wrap" dir="rtl">
    <div class="olama-exam-header">
        <div>
            <h1><?php echo olama_exam_translate('student_results_menu'); ?></h1>
        </div>
    </div>

    <?php include OLAMA_EXAM_PATH . 'admin/views/student-acceptance-tabs.php'; ?>

    <?php if ($review_data): ?>
        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- ATTEMPT REVIEW VIEW -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <?php $ra = $review_data['attempt'];
        $rqs = $review_data['questions']; ?>

        <div style="margin-bottom: 16px;">
            <a href="<?php echo admin_url('admin.php?page=oee-student-results'); ?>"
                class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Back'); ?>
            </a>
        </div>

        <!-- Student Info Card -->
        <div class="olama-exam-card" style="margin-bottom: 16px;">
            <div class="olama-exam-card-header"
                style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <h3>🔍 <?php echo olama_exam_translate('Review Attempt'); ?></h3>
                <span style="font-size: 14px; padding: 4px 14px; border-radius: 20px; font-weight: 600;
                <?php if ($ra->result === 'pass')
                    echo 'background:#d1fae5;color:#059669;';
                elseif ($ra->result === 'fail')
                    echo 'background:#fee2e2;color:#dc2626;';
                else
                    echo 'background:#fef3c7;color:#d97706;'; ?>">
                    <?php echo $ra->result === 'pass' ? '✅ ' . olama_exam_translate('Pass') : ($ra->result === 'fail' ? '❌ ' . olama_exam_translate('Fail') : '⏳ ' . olama_exam_translate('Pending')); ?>
                </span>
            </div>
            <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; font-size: 14px; color: #475569; background: #f8fafc; border-top: 1px solid #cbd5e1;">
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('Test Title'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->exam_title); ?></span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('student_name'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->student_name); ?></span>
                </div>
                <?php if (!empty($ra->guardian_name)): ?>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('guardian_name'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->guardian_name); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($ra->date_of_birth)): ?>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('student_dob'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->date_of_birth); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($ra->national_id)): ?>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('national_id'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->national_id); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($ra->mobile)): ?>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('phone'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->mobile); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($ra->email)): ?>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('email'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->email); ?></span>
                </div>
                <?php endif; ?>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('Grade Level'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo esc_html($ra->grade); ?></span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('Score'); ?></span>
                    <span style="font-size: 14px; font-weight: 600; color: #1e293b;">
                        <?php echo ($ra->score ?? 0) . ' / ' . ($ra->max_score ?? 0); ?>
                        (<?php echo $ra->percentage ?? 0; ?>%)
                    </span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 600; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo olama_exam_translate('Submitted'); ?></span>
                    <span style="font-size: 14px; font-weight: 500; color: #1e293b;"><?php echo date('Y-m-d H:i', strtotime($ra->submitted_at)); ?></span>
                </div>
            </div>
        </div>

        <!-- Questions Review -->
        <?php foreach ($rqs as $rq): ?>
            <?php
            $border_color = '#e2e8f0';
            $bg_color = '#fff';
            $status_icon = '⬜';
            if ($rq['status'] === 'correct') {
                $border_color = '#059669';
                $bg_color = '#f0fdf4';
                $status_icon = '✅';
            } elseif ($rq['status'] === 'partial') {
                $border_color = '#d97706';
                $bg_color = '#fffbeb';
                $status_icon = '🟡';
            } elseif ($rq['status'] === 'incorrect') {
                $border_color = '#dc2626';
                $bg_color = '#fef2f2';
                $status_icon = '❌';
            } elseif ($rq['status'] === 'pending') {
                $border_color = '#d97706';
                $bg_color = '#fffbeb';
                $status_icon = '⏳';
            } elseif ($rq['status'] === 'graded') {
                $border_color = '#2563eb';
                $bg_color = '#eff6ff';
                $status_icon = '📝';
            }
            ?>
            <div class="olama-exam-card"
                style="margin-bottom: 12px; border-left: 4px solid <?php echo $border_color; ?>; background: <?php echo $bg_color; ?>;">
                <div style="padding: 16px 20px;">
                    <!-- Question Header -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-weight: 800; font-size: 16px; color: #1e293b;">Q<?php echo $rq['index']; ?></span>
                            <span
                                style="font-size: 12px; color: #64748b; text-transform: uppercase; background: #f1f5f9; padding: 3px 10px; border-radius: 6px;"><?php echo $rq['type']; ?></span>
                            <span style="font-size: 14px;"><?php echo $status_icon; ?></span>
                        </div>
                        <span
                            style="font-weight: 700; font-size: 14px; color: <?php echo ($rq['earned'] >= $rq['points']) ? '#059669' : (($rq['earned'] > 0) ? '#d97706' : '#dc2626'); ?>;">
                            <?php echo $rq['earned']; ?> / <?php echo $rq['points']; ?>
                        </span>
                    </div>

                    <!-- Question Text -->
                    <div class="oee-math"
                        style="font-size: 15px; line-height: 1.7; color: #1e293b; margin-bottom: 14px; padding: 10px 14px; background: rgba(255,255,255,0.7); border-radius: 8px;">
                        <?php echo wp_kses_post($rq['text']); ?>
                    </div>

                    <?php if ($rq['image']): ?>
                        <img src="<?php echo admin_url('admin-ajax.php?action=olama_exam_stream_image&file=' . urlencode($rq['image'])); ?>"
                            style="max-width: 300px; border-radius: 8px; margin-bottom: 12px;">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <!-- Student Answer -->
                        <div>
                            <div style="display: flex; align-items: center; gap: 6px; font-weight: 600; font-size: 13px; color: #64748b; margin-bottom: 6px;">
                                <span>📄</span>
                                <span><?php echo olama_exam_translate('Student Answer'); ?></span>
                            </div>
                            <div
                                style="padding: 10px 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; min-height: 40px;">
                                <?php
                                $sa = $rq['student_answer'];
                                if ($sa === null || $sa === '' || (is_array($sa) && empty(array_filter($sa)))) {
                                    echo '<em style="color:#94a3b8;">' . olama_exam_translate('No answer provided') . '</em>';
                                } elseif ($rq['type'] === 'mcq') {
                                    $idx_val = intval($sa);
                                    echo esc_html(isset($rq['choices'][$idx_val]) ? $rq['choices'][$idx_val] : "Choice #$idx_val");
                                } elseif ($rq['type'] === 'tf') {
                                    echo ($sa === 'true' || $sa === true) ? '✅ True (صح)' : '❌ False (خطأ)';
                                } elseif ($rq['type'] === 'matching' && is_array($sa)) {
                                    foreach ($rq['lefts'] as $i => $left) {
                                        echo esc_html($left) . ' → <strong>' . esc_html($sa[$i] ?? '—') . '</strong><br>';
                                    }
                                } elseif ($rq['type'] === 'ordering' && is_array($sa)) {
                                    echo '<ol style="margin:0;padding-left:20px;">';
                                    foreach ($sa as $item)
                                        echo '<li>' . esc_html($item) . '</li>';
                                    echo '</ol>';
                                } elseif ($rq['type'] === 'fill_blank' && is_array($sa)) {
                                    echo implode(' , ', array_map('esc_html', $sa));
                                } elseif ($rq['type'] === 'essay') {
                                    echo nl2br(esc_html($sa));
                                } else {
                                    echo esc_html($sa);
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Correct Answer -->
                        <div>
                            <div style="display: flex; align-items: center; gap: 6px; font-weight: 600; font-size: 13px; color: #64748b; margin-bottom: 6px;">
                                <span>✅</span>
                                <span><?php echo olama_exam_translate('Correct Answer'); ?></span>
                            </div>
                            <div
                                style="padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 14px; min-height: 40px;">
                                <?php
                                $cd = $rq['correct_data'];
                                if ($rq['type'] === 'mcq') {
                                    $ci = is_array($cd) ? ($cd['correct_index'] ?? 0) : intval($cd);
                                    echo esc_html(isset($rq['choices'][$ci]) ? $rq['choices'][$ci] : "Choice #$ci");
                                } elseif ($rq['type'] === 'tf') {
                                    $cv = is_array($cd) ? ($cd['correct_value'] ?? $cd) : $cd;
                                    echo ($cv === true || $cv === 'true') ? '✅ True (صح)' : '❌ False (خطأ)';
                                } elseif ($rq['type'] === 'matching' && is_array($cd)) {
                                    $correct_pairs = $cd['pairs'] ?? $cd;
                                    if (is_array($correct_pairs)) {
                                        foreach ($rq['lefts'] as $i => $left) {
                                            echo esc_html($left) . ' → <strong>' . esc_html($correct_pairs[$i] ?? '—') . '</strong><br>';
                                        }
                                    }
                                } elseif ($rq['type'] === 'ordering' && is_array($cd)) {
                                    $correct_order = $cd['correct_order'] ?? $cd;
                                    echo '<ol style="margin:0;padding-left:20px;">';
                                    foreach ($correct_order as $item)
                                        echo '<li>' . esc_html($item) . '</li>';
                                    echo '</ol>';
                                } elseif ($rq['type'] === 'fill_blank' && is_array($cd)) {
                                    $blanks = $cd['blanks'] ?? $cd;
                                    echo implode(' , ', array_map('esc_html', (array) $blanks));
                                } elseif ($rq['type'] === 'short') {
                                    $accepts = $cd['accepted'] ?? (is_array($cd) ? $cd : array($cd));
                                    echo implode(' / ', array_map('esc_html', (array) $accepts));
                                } elseif ($rq['type'] === 'essay') {
                                    echo '<em style="color:#94a3b8;">' . olama_exam_translate('Manual grading') . '</em>';
                                    if ($rq['essay_grade']) {
                                        echo '<br><strong>' . olama_exam_translate('Score') . ':</strong> ' . $rq['essay_grade']->score . ' / ' . $rq['points'];
                                        if ($rq['essay_grade']->teacher_comment) {
                                            echo '<br><strong>' . olama_exam_translate('Comment') . ':</strong> ' . esc_html($rq['essay_grade']->teacher_comment);
                                        }
                                    }
                                } else {
                                    echo esc_html(is_array($cd) ? json_encode($cd, JSON_UNESCAPED_UNICODE) : $cd);
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($rq['explanation']): ?>
                        <div class="oee-math"
                            style="margin-top: 10px; padding: 8px 14px; background: #eff6ff; border-radius: 8px; font-size: 13px; color: #1e40af;">
                            📖 <?php echo esc_html($rq['explanation']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Back to results button -->
        <div style="text-align: center; margin: 24px 0;">
            <a href="<?php echo admin_url('admin.php?page=oee-student-results'); ?>"
                class="olama-exam-btn olama-exam-btn-primary" style="padding: 8px 28px; font-size: 14px;">
                ← <?php echo olama_exam_translate('Back to Results'); ?>
            </a>
        </div>
    <?php else: ?>
        <!-- Filters Wrapper -->
        <div class="olama-exam-filters" style="align-items: flex-end; gap: 16px;">
            <form method="get" action="" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; flex:1;">
                <input type="hidden" name="page" value="oee-student-results">

                <!-- Grade Level Filter -->
                <div class="olama-exam-form-group" style="margin-bottom: 0; min-width: 160px;">
                    <label style="font-weight:600; font-size:12.5px; display:block; margin-bottom:4px; color:#475569;"><?php echo olama_exam_translate('Grade Level'); ?></label>
                    <select name="grade_level_id" id="filter-grade-level" style="width: 100%;">
                        <option value="0"><?php echo olama_exam_translate('All'); ?></option>
                        <?php foreach ($grade_levels as $gl): ?>
                            <option value="<?php echo $gl->id; ?>" <?php echo $grade_filter === intval($gl->id) ? 'selected' : ''; ?>><?php echo esc_html($gl->name_ar); ?></option>
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
                    <a href="<?php echo admin_url('admin.php?page=oee-student-results'); ?>" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm"><?php echo olama_exam_translate('Reset'); ?></a>
                </div>
            </form>

            <!-- CSV Export Button -->
            <div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="oee_export_student_acceptance_csv">
                    <input type="hidden" name="grade_level_id" value="<?php echo $grade_filter; ?>">
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
                        <th><?php echo olama_exam_translate('student_name'); ?></th>
                        <th><?php echo olama_exam_translate('guardian_name'); ?></th>
                        <th><?php echo olama_exam_translate('student_dob'); ?></th>
                        <th><?php echo olama_exam_translate('national_id'); ?></th>
                        <th><?php echo olama_exam_translate('phone'); ?></th>
                        <th><?php echo olama_exam_translate('Grade Level'); ?></th>
                        <th><?php echo olama_exam_translate('Test Title'); ?></th>
                        <th><?php echo olama_exam_translate('Score'); ?></th>
                        <th><?php echo olama_exam_translate('Result'); ?></th>
                        <th><?php echo olama_exam_translate('Date'); ?></th>
                        <th style="text-align: center; width: 230px; min-width: 230px; white-space: nowrap;"><?php echo olama_exam_translate('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="11"><?php echo olama_exam_translate('No results found.'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($results as $row): 
                            $passed = $row->result === 'pass';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($row->student_name); ?></strong></td>
                                <td><?php echo esc_html($row->guardian_name); ?></td>
                                <td><?php echo esc_html($row->date_of_birth); ?></td>
                                <td><?php echo esc_html($row->national_id); ?></td>
                                <td><?php echo esc_html($row->phone); ?></td>
                                <td><?php echo esc_html($row->grade); ?></td>
                                <td><?php echo esc_html($row->test_title); ?></td>
                                <td><?php echo floatval($row->percentage); ?>% (<?php echo floatval($row->score); ?>/<?php echo floatval($row->max_score); ?>)</td>
                                <td>
                                    <span class="olama-exam-badge <?php echo $passed ? 'olama-exam-badge-active' : 'olama-exam-badge-closed'; ?>">
                                        <?php echo $passed ? olama_exam_translate('Pass') : olama_exam_translate('Fail'); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($row->started_at))); ?></td>
                                <td style="width: 230px; min-width: 230px; white-space: nowrap;">
                                    <div style="display: flex; gap: 6px; justify-content: center; align-items: center; white-space: nowrap;">
                                        <a href="<?php echo admin_url('admin.php?page=oee-student-results&review_attempt=' . $row->attempt_id); ?>"
                                           class="olama-exam-btn olama-exam-btn-primary olama-exam-btn-sm" 
                                           style="padding: 6px 10px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 4px; height: 28px; line-height: 1; box-shadow: none; transform: none; white-space: nowrap;" 
                                           title="<?php echo esc_attr(olama_exam_translate('View Results')); ?>">
                                            🔍 <?php echo olama_exam_translate('View Results'); ?>
                                        </a>
                                        <button type="button" 
                                                class="olama-exam-btn olama-exam-btn-danger olama-exam-btn-sm delete-attempt-btn" 
                                                data-id="<?php echo $row->attempt_id; ?>"
                                                style="padding: 6px 10px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 4px; height: 28px; line-height: 1; box-shadow: none; transform: none; white-space: nowrap;" 
                                                title="<?php echo esc_attr(olama_exam_translate('Delete Attempt')); ?>">
                                            🗑️ <?php echo olama_exam_translate('Delete Attempt'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Delete Attempt
    $('.delete-attempt-btn').on('click', function () {
        var id = $(this).data('id');
        if (!confirm('<?php echo esc_js(olama_exam_translate('Are you sure you want to delete this attempt? This action cannot be undone.')); ?>')) {
            return;
        }

        $.post(ajaxurl, {
            action: 'olama_exam_delete_attempt',
            nonce: '<?php echo wp_create_nonce('olama_exam_nonce'); ?>',
            attempt_id: id
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || 'Error deleting attempt');
            }
        });
    });
});
</script>
