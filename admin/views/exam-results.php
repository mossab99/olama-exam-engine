<?php
/**
 * Admin View: Exam Results Dashboard
 * Shows exam results with filters, statistics summary, and student scores table
 */
if (!defined('ABSPATH'))
    exit;

global $wpdb;

// Hierarchical Filters
$selected_year_id = intval($_GET['academic_year_id'] ?? 0);
$selected_semester_id = intval($_GET['semester_id'] ?? 0);
$selected_grade_id = intval($_GET['grade_id'] ?? 0);
$selected_section_id = intval($_GET['section_id'] ?? 0);

// Default to active year/semester if not set
if (!$selected_year_id && class_exists('Olama_School_Academic')) {
    $active_year = Olama_School_Academic::get_active_year();
    $selected_year_id = $active_year ? $active_year->id : 0;
}
if (!$selected_semester_id && $selected_year_id && class_exists('Olama_School_Academic')) {
    $active_semester = Olama_School_Academic::get_active_semester($selected_year_id);
    $selected_semester_id = $active_semester ? $active_semester->id : 0;
}

// Fetch lists for dropdowns
$years = class_exists('Olama_School_Academic') ? Olama_School_Academic::get_years() : array();
$semesters = ($selected_year_id && class_exists('Olama_School_Academic')) ? Olama_School_Academic::get_semesters($selected_year_id) : array();
$grades = class_exists('Olama_School_Grade') ? Olama_School_Grade::get_grades() : array();
$sections = ($selected_grade_id && $selected_year_id && class_exists('Olama_School_Section')) ? Olama_School_Section::get_by_grade($selected_grade_id, $selected_year_id) : array();

// Build Exam Query
$exam_query = "SELECT e.id, e.title, sub.subject_name, s.section_name
               FROM {$wpdb->prefix}olama_exam_exams e
               LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
               LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
               WHERE 1=1";

if ($selected_year_id) $exam_query .= $wpdb->prepare(" AND e.academic_year_id = %d", $selected_year_id);
if ($selected_semester_id) $exam_query .= $wpdb->prepare(" AND e.semester_id = %d", $selected_semester_id);
if ($selected_section_id) $exam_query .= $wpdb->prepare(" AND e.section_id = %d", $selected_section_id);

$exam_query .= " ORDER BY e.created_at DESC";
$exams = $wpdb->get_results($exam_query);

$selected_exam_id = intval($_GET['exam_id'] ?? 0);
$review_attempt_id = intval($_GET['review_attempt'] ?? 0);
$selected_exam = null;
$attempts = array();
$stats = null;
$review_data = null;

// ── Handle Attempt Review ─────────────────────────────
if ($review_attempt_id) {
    $review_attempt = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, e.title as exam_title, e.passing_grade, e.show_results,
                st.student_name, sub.subject_name, s.section_name
         FROM {$wpdb->prefix}olama_exam_attempts a
         JOIN {$wpdb->prefix}olama_exam_exams e ON a.exam_id = e.id
         LEFT JOIN {$wpdb->prefix}olama_students st ON a.student_uid = st.student_uid
         LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
         LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
         WHERE a.id = %d",
        $review_attempt_id
    ));

    if ($review_attempt) {
        $snapshot = json_decode($review_attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($review_attempt->answers_json, true) ?: array();
        $selected_exam_id = intval($review_attempt->exam_id);

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

// ── Normal Results Dashboard ──────────────────────────
if ($selected_exam_id && !$review_attempt_id) {
    $selected_exam = $wpdb->get_row($wpdb->prepare(
        "SELECT e.*, sub.subject_name, s.section_name, g.grade_name
         FROM {$wpdb->prefix}olama_exam_exams e
         LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
         LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
         LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
         WHERE e.id = %d",
        $selected_exam_id
    ));

    if ($selected_exam) {
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                s.student_name, 
                s.student_uid,
                sec.section_name,
                g.grade_name,
                a.id as attempt_id, a.score, a.max_score, a.percentage, a.result, a.submitted_at
             FROM {$wpdb->prefix}olama_student_enrollment e_sis
             JOIN {$wpdb->prefix}olama_students s ON e_sis.student_id = s.id
             JOIN {$wpdb->prefix}olama_sections sec ON e_sis.section_id = sec.id
             JOIN {$wpdb->prefix}olama_grades g ON sec.grade_id = g.id
             LEFT JOIN {$wpdb->prefix}olama_exam_attempts a ON (a.student_uid = s.student_uid AND a.exam_id = %d)
             WHERE e_sis.section_id = %d
             ORDER BY s.student_name ASC",
            $selected_exam_id,
            $selected_exam->section_id
        ));

        // Calculate statistics
        if (!empty($attempts)) {
            $recorded_attempts = array_filter($attempts, function ($a) {
                return !empty($a->attempt_id);
            });

            $scores = array_map(function ($a) {
                return floatval($a->percentage);
            }, $recorded_attempts);

            $pass_count = count(array_filter($recorded_attempts, function ($a) {
                return $a->result === 'pass';
            }));

            $stats = array(
                'total' => count($recorded_attempts),
                'average' => empty($scores) ? 0 : round(array_sum($scores) / count($scores), 1),
                'highest' => empty($scores) ? 0 : round(max($scores), 1),
                'lowest' => empty($scores) ? 0 : round(min($scores), 1),
                'pass_count' => $pass_count,
                'fail_count' => count($recorded_attempts) - $pass_count,
                'pass_rate' => empty($recorded_attempts) ? 0 : round(($pass_count / count($recorded_attempts)) * 100, 1),
            );
        }
    }
}
?>
<div class="olama-exam-wrap">
    <div class="olama-exam-header">
        <h1><?php echo olama_exam_translate('Results'); ?></h1>
    </div>

    <?php if ($review_data): ?>
        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- ATTEMPT REVIEW VIEW -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <?php $ra = $review_data['attempt'];
        $rqs = $review_data['questions']; ?>

        <div style="margin-bottom: 16px;">
            <a href="<?php echo admin_url('admin.php?page=olama-exam-results&exam_id=' . $selected_exam_id); ?>"
                class="button">
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
            <div style="padding: 12px 20px; display: flex; gap: 30px; flex-wrap: wrap; font-size: 14px; color: #475569;">
                <span><strong><?php echo olama_exam_translate('Exam'); ?>:</strong>
                    <?php echo esc_html($ra->exam_title); ?></span>
                <span><strong><?php echo olama_exam_translate('Student'); ?>:</strong>
                    <?php echo esc_html($ra->student_name ?? 'ID: ' . $ra->student_uid); ?></span>
                <span><strong><?php echo olama_exam_translate('Score'); ?>:</strong>
                    <?php echo ($ra->score ?? 0) . ' / ' . ($ra->max_score ?? 0); ?>
                    (<?php echo $ra->percentage ?? 0; ?>%)</span>
                <span><strong><?php echo olama_exam_translate('Submitted'); ?>:</strong>
                    <?php echo date('d/m/Y H:i', strtotime($ra->submitted_at)); ?></span>
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
                    <div
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
                            <div style="font-weight: 600; font-size: 13px; color: #64748b; margin-bottom: 6px;">📄
                                <?php echo olama_exam_translate('Student Answer'); ?>:</div>
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
                            <div style="font-weight: 600; font-size: 13px; color: #64748b; margin-bottom: 6px;">✅
                                <?php echo olama_exam_translate('Correct Answer'); ?>:</div>
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
                        <div
                            style="margin-top: 10px; padding: 8px 14px; background: #eff6ff; border-radius: 8px; font-size: 13px; color: #1e40af;">
                            📖 <?php echo esc_html($rq['explanation']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Back to results button -->
        <div style="text-align: center; margin: 24px 0;">
            <a href="<?php echo admin_url('admin.php?page=olama-exam-results&exam_id=' . $selected_exam_id); ?>"
                class="button button-primary" style="padding: 8px 28px; font-size: 14px;">
                ← <?php echo olama_exam_translate('Back to Results'); ?>
            </a>
        </div>

    <?php else: ?>
        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- NORMAL RESULTS DASHBOARD -->
        <!-- ═══════════════════════════════════════════════════════ -->

        <!-- Exam Selector -->
        <div class="olama-exam-card" style="margin-bottom: 20px;">
            <div class="olama-exam-card-header">
                <h3>📊 <?php echo olama_exam_translate('Select Exam'); ?></h3>
            </div>
            <div style="padding: 16px 20px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <!-- Academic Year -->
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px;">
                            📅 <?php echo olama_exam_translate('Academic Year'); ?>
                        </label>
                        <select id="year-filter" class="olama-exam-select" style="width: 100%;">
                            <option value=""><?php echo olama_exam_translate('Select Year'); ?></option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo $y->id; ?>" <?php selected($selected_year_id, $y->id); ?>>
                                    <?php echo esc_html($y->year_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Semester -->
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px;">
                            🌗 <?php echo olama_exam_translate('Semester'); ?>
                        </label>
                        <select id="semester-filter" class="olama-exam-select" style="width: 100%;" <?php echo empty($semesters) ? 'disabled' : ''; ?>>
                            <option value=""><?php echo olama_exam_translate('Select Semester'); ?></option>
                            <?php foreach ($semesters as $s): ?>
                                <option value="<?php echo $s->id; ?>" <?php selected($selected_semester_id, $s->id); ?>>
                                    <?php echo esc_html($s->semester_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Grade -->
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px;">
                            🎓 <?php echo olama_exam_translate('Grade'); ?>
                        </label>
                        <select id="grade-filter" class="olama-exam-select" style="width: 100%;">
                            <option value=""><?php echo olama_exam_translate('Select Grade'); ?></option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?php echo $g->id; ?>" <?php selected($selected_grade_id, $g->id); ?>>
                                    <?php echo esc_html($g->grade_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Section -->
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px;">
                            🏫 <?php echo olama_exam_translate('Section'); ?>
                        </label>
                        <select id="section-filter" class="olama-exam-select" style="width: 100%;" <?php echo empty($sections) ? 'disabled' : ''; ?>>
                            <option value=""><?php echo olama_exam_translate('Select Section'); ?></option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo $sec->id; ?>" <?php selected($selected_section_id, $sec->id); ?>>
                                    <?php echo esc_html($sec->section_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Exam -->
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px;">
                            📝 <?php echo olama_exam_translate('Select Exam'); ?>
                        </label>
                        <select id="exam-select" class="olama-exam-select" style="width: 100%;" <?php echo empty($exams) ? 'disabled' : ''; ?>>
                            <option value=""><?php echo olama_exam_translate('Select Exam'); ?></option>
                            <?php foreach ($exams as $e): ?>
                                <option value="<?php echo $e->id; ?>" <?php selected($selected_exam_id, $e->id); ?>>
                                    <?php
                                    echo esc_html($e->title);
                                    if ($e->subject_name) echo ' — ' . esc_html($e->subject_name);
                                    if ($e->section_name) echo ' (' . esc_html($e->section_name) . ')';
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($selected_exam && $stats): ?>
            <!-- Statistics Cards -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px;">
                <div class="olama-exam-card" style="text-align: center; padding: 20px;">
                    <div style="font-size: 28px; font-weight: 800; color: #2563eb;"><?php echo $stats['total']; ?></div>
                    <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                        <?php echo olama_exam_translate('Attempts'); ?>
                    </div>
                </div>
                <div class="olama-exam-card" style="text-align: center; padding: 20px;">
                    <div style="font-size: 28px; font-weight: 800; color: #0f172a;"><?php echo $stats['average']; ?>%</div>
                    <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                        <?php echo olama_exam_translate('Average'); ?>
                    </div>
                </div>
                <div class="olama-exam-card" style="text-align: center; padding: 20px;">
                    <div style="font-size: 28px; font-weight: 800; color: #059669;"><?php echo $stats['highest']; ?>%</div>
                    <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                        <?php echo olama_exam_translate('Highest'); ?>
                    </div>
                </div>
                <div class="olama-exam-card" style="text-align: center; padding: 20px;">
                    <div style="font-size: 28px; font-weight: 800; color: #dc2626;"><?php echo $stats['lowest']; ?>%</div>
                    <div style="font-size: 13px; color: #64748b; margin-top: 4px;"><?php echo olama_exam_translate('Lowest'); ?>
                    </div>
                </div>
                <div class="olama-exam-card" style="text-align: center; padding: 20px;">
                    <div style="font-size: 28px; font-weight: 800; color: #059669;"><?php echo $stats['pass_rate']; ?>%</div>
                    <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                        <?php echo olama_exam_translate('Pass Rate'); ?>
                    </div>
                </div>
            </div>

            <!-- Pass/Fail Summary Bar -->
            <div class="olama-exam-card" style="margin-bottom: 20px;">
                <div style="padding: 16px 20px;">
                    <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <div style="height: 24px; background: #fee2e2; border-radius: 12px; overflow: hidden;">
                                <div
                                    style="height: 100%; width: <?php echo $stats['pass_rate']; ?>%; background: linear-gradient(90deg, #059669, #34d399); border-radius: 12px; transition: width 0.8s ease;">
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 16px; font-size: 14px; font-weight: 600;">
                            <span style="color: #059669;">✅ <?php echo $stats['pass_count']; ?>
                                <?php echo olama_exam_translate('Pass'); ?></span>
                            <span style="color: #dc2626;">❌ <?php echo $stats['fail_count']; ?>
                                <?php echo olama_exam_translate('Fail'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Table + Export -->
            <div class="olama-exam-card">
                <div class="olama-exam-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>📋 <?php echo esc_html($selected_exam->title); ?></h3>
                    <button type="button" class="button" id="export-csv-btn" data-exam-id="<?php echo $selected_exam_id; ?>"
                        style="display: flex; align-items: center; gap: 6px;">
                        📥 <?php echo olama_exam_translate('Export CSV'); ?>
                    </button>
                </div>

                <table class="olama-exam-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th
                                style="text-align: start; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                #</th>
                            <th
                                style="text-align: start; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Student'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Grade'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Section'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Exam Taken'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Score'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Result'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Manual'); ?>
                            </th>
                            <th
                                style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                <?php echo olama_exam_translate('Actions'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $idx => $a): ?>
                            <tr>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #64748b;">
                                    <?php echo $idx + 1; ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 600;">
                                    <?php echo esc_html($a->student_name ?? 'ID: ' . $a->student_uid); ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php echo esc_html($a->grade_name); ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php echo esc_html($a->section_name); ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php if ($a->attempt_id): ?>
                                        <span style="color: #059669; font-size: 18px;">✅</span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 18px;">➖</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php if ($a->attempt_id): ?>
                                        <div style="font-weight: 600;"><?php echo ($a->score ?? 0) . ' / ' . ($a->max_score ?? 0); ?></div>
                                        <div style="font-size: 11px; color: #64748b;"><?php echo $a->percentage; ?>%</div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php if ($a->attempt_id): ?>
                                        <?php if ($a->result === 'pass'): ?>
                                            <span
                                                style="background: #d1fae5; color: #059669; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">✅
                                                <?php echo olama_exam_translate('Pass'); ?></span>
                                        <?php elseif ($a->result === 'fail'): ?>
                                            <span
                                                style="background: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">❌
                                                <?php echo olama_exam_translate('Fail'); ?></span>
                                        <?php else: ?>
                                            <span
                                                style="background: #fef3c7; color: #d97706; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">⏳
                                                <?php echo olama_exam_translate('Pending'); ?></span>
                                        <?php endif; ?>
                                        <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">
                                            <?php echo date('d/m/Y H:i', strtotime($a->submitted_at)); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                        <?php if (!$a->attempt_id): ?>
                                            <a href="<?php echo home_url('/exams/?exam_view=take&exam_id=' . $selected_exam_id . '&manual_student_uid=' . $a->student_uid); ?>" 
                                               target="_blank"
                                               class="button button-small" 
                                               style="background: #2563eb; color: #fff; border: none; padding: 4px 8px; font-size: 11px; text-decoration: none; border-radius: 4px;">
                                                🚀 <?php echo olama_exam_translate('Manual'); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                        <?php if ($a->attempt_id): ?>
                                        <div style="display: flex; gap: 4px; justify-content: center;">
                                            <a href="<?php echo admin_url('admin.php?page=olama-exam-results&exam_id=' . $selected_exam_id . '&review_attempt=' . $a->attempt_id); ?>"
                                                class="button" style="padding: 2px 8px; font-size: 12px;" title="<?php echo olama_exam_translate('Preview Attempt'); ?>">
                                                🔍
                                            </a>
                                            <button type="button" class="button delete-attempt-btn" data-id="<?php echo $a->attempt_id; ?>"
                                                style="padding: 2px 8px; font-size: 12px; color: #dc2626;" title="<?php echo olama_exam_translate('Delete Attempt'); ?>">
                                                🗑️
                                            </button>
                                            <button type="button" class="button retake-attempt-btn" data-id="<?php echo $a->attempt_id; ?>"
                                                style="padding: 2px 8px; font-size: 12px; color: #2563eb;" title="<?php echo olama_exam_translate('Retake Attempt'); ?>">
                                                🔄
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-style: italic; font-size: 12px;"><?php echo olama_exam_translate('No Attempt'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Question Statistics -->
            <?php
            // Compute per-question stats
            $question_stats = array();
            foreach ($attempts as $a) {
                if (empty($a->attempt_id)) {
                    continue;
                }
                $snapshot = json_decode($a->questions_snapshot_json, true) ?: array();
                $answers = json_decode($a->answers_json, true) ?: array();
                foreach ($snapshot as $q) {
                    $qid = $q['question_id'];
                    if (!isset($question_stats[$qid])) {
                        $question_stats[$qid] = array(
                            'text' => mb_substr(strip_tags($q['question_text']), 0, 80),
                            'type' => $q['type'],
                            'total' => 0,
                            'correct' => 0,
                            'partial' => 0,
                            'incorrect' => 0,
                            'unanswered' => 0,
                        );
                    }
                    $question_stats[$qid]['total']++;
                    $student_answer = $answers[$qid] ?? null;
                    if ($student_answer === null || $student_answer === '' || (is_array($student_answer) && empty(array_filter($student_answer)))) {
                        $question_stats[$qid]['unanswered']++;
                    } elseif ($q['type'] === 'essay') {
                        // Can't determine for essay
                    } else {
                        $correct_data = $q['correct'] ?? array();
                        $points = $q['points'] ?? 1;
                        $earned = Olama_Exam_Grader::grade_question($q['type'], $student_answer, $correct_data, $points);
                        if ($earned >= $points) {
                            $question_stats[$qid]['correct']++;
                        } elseif ($earned > 0) {
                            $question_stats[$qid]['partial']++;
                        } else {
                            $question_stats[$qid]['incorrect']++;
                        }
                    }
                }
            }
            ?>
            <?php if (!empty($question_stats)): ?>
                <div class="olama-exam-card" style="margin-top: 20px;">
                    <div class="olama-exam-card-header">
                        <h3>📈 <?php echo olama_exam_translate('Question Statistics'); ?></h3>
                    </div>
                    <table class="olama-exam-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th
                                    style="text-align: start; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                    #</th>
                                <th
                                    style="text-align: start; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                    <?php echo olama_exam_translate('Question'); ?>
                                </th>
                                <th
                                    style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                    <?php echo olama_exam_translate('Type'); ?>
                                </th>
                                <th
                                    style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                    <?php echo olama_exam_translate('Correct Rate'); ?>
                                </th>
                                <th
                                    style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                    <?php echo olama_exam_translate('Correct'); ?>
                                </th>
                                <th
                                    style="text-align: center; padding: 12px 16px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b;">
                                    <?php echo olama_exam_translate('Incorrect'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $qi = 0;
                            foreach ($question_stats as $qid => $qs):
                                $qi++;
                                $rate = $qs['total'] > 0 ? round(($qs['correct'] / $qs['total']) * 100, 1) : 0;
                                $bar_color = $rate >= 70 ? '#059669' : ($rate >= 40 ? '#d97706' : '#dc2626');
                                ?>
                                <tr>
                                    <td style="padding: 10px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #64748b;">
                                        <?php echo $qi; ?>
                                    </td>
                                    <td
                                        style="padding: 10px 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo esc_html($qs['text']); ?>
                                    </td>
                                    <td
                                        style="padding: 10px 16px; border-bottom: 1px solid #f1f5f9; text-align: center; font-size: 12px; color: #64748b; text-transform: uppercase;">
                                        <?php echo $qs['type']; ?>
                                    </td>
                                    <td style="padding: 10px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                        <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                            <div
                                                style="width: 60px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                <div
                                                    style="height: 100%; width: <?php echo $rate; ?>%; background: <?php echo $bar_color; ?>; border-radius: 4px;">
                                                </div>
                                            </div>
                                            <span
                                                style="font-size: 13px; font-weight: 600; color: <?php echo $bar_color; ?>;"><?php echo $rate; ?>%</span>
                                        </div>
                                    </td>
                                    <td
                                        style="padding: 10px 16px; border-bottom: 1px solid #f1f5f9; text-align: center; color: #059669; font-weight: 600;">
                                        <?php echo $qs['correct']; ?>
                                    </td>
                                    <td
                                        style="padding: 10px 16px; border-bottom: 1px solid #f1f5f9; text-align: center; color: #dc2626; font-weight: 600;">
                                        <?php echo $qs['incorrect']; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($selected_exam_id && $selected_exam && empty($attempts)): ?>
            <div class="olama-exam-card">
                <p style="color: #64748b; text-align: center; padding: 40px;">
                    <?php echo olama_exam_translate('No results yet for this exam.'); ?>
                </p>
            </div>
        <?php elseif (!$selected_exam_id): ?>
            <div class="olama-exam-card">
                <p style="color: #64748b; text-align: center; padding: 40px;">
                    <?php echo olama_exam_translate('Select an exam to view results.'); ?>
                </p>
            </div>
        <?php endif; ?>
    <?php endif; /* end review_data else */ ?>
</div>

<script>
    jQuery(function ($) {
        // Hierarchical Filters Logic
        function updateFilters(params) {
            var url = new URL(window.location.href);
            Object.keys(params).forEach(key => {
                if (params[key]) {
                    url.searchParams.set(key, params[key]);
                } else {
                    url.searchParams.delete(key);
                }
            });
            window.location.href = url.toString();
        }

        $('#year-filter').on('change', function () {
            updateFilters({
                academic_year_id: $(this).val(),
                semester_id: '',
                section_id: '',
                exam_id: ''
            });
        });

        $('#semester-filter').on('change', function () {
            updateFilters({
                semester_id: $(this).val(),
                exam_id: ''
            });
        });

        $('#grade-filter').on('change', function () {
            updateFilters({
                grade_id: $(this).val(),
                section_id: '',
                exam_id: ''
            });
        });

        $('#section-filter').on('change', function () {
            updateFilters({
                section_id: $(this).val(),
                exam_id: ''
            });
        });

        $('#exam-select').on('change', function () {
            updateFilters({
                exam_id: $(this).val()
            });
        });

        // CSV Export
        $('#export-csv-btn').on('click', function () {
            var examId = $(this).data('exam-id');
            var btn = $(this);
            btn.prop('disabled', true).text('⏳ ...');

            $.post(ajaxurl, {
                action: 'olama_exam_export_csv',
                nonce: '<?php echo wp_create_nonce('olama_exam_nonce'); ?>',
                exam_id: examId
            }, function (response) {
                btn.prop('disabled', false).html('📥 <?php echo olama_exam_translate('Export CSV'); ?>');
                if (response.success && response.data.csv) {
                    // Trigger CSV download
                    var BOM = '\uFEFF';
                    var blob = new Blob([BOM + response.data.csv], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = response.data.filename || 'results.csv';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    alert(response.data ? response.data.message : 'Export failed');
                }
            }).fail(function () {
                btn.prop('disabled', false).html('📥 <?php echo olama_exam_translate('Export CSV'); ?>');
                alert('Network error');
            });
        });

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

        // Retake Attempt
        $('.retake-attempt-btn').on('click', function () {
            var id = $(this).data('id');
            if (!confirm('<?php echo esc_js(olama_exam_translate('Allow student to retake? This will delete the current attempt.')); ?>')) {
                return;
            }

            $.post(ajaxurl, {
                action: 'olama_exam_retake_attempt',
                nonce: '<?php echo wp_create_nonce('olama_exam_nonce'); ?>',
                attempt_id: id
            }, function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error resetting attempt');
                }
            });
        });
    });
</script>