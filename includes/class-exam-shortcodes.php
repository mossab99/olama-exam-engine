<?php
/**
 * Exam Shortcodes — Student-facing [olama_exam]
 * Three views: dashboard (exam list), exam-taking, results
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Shortcodes
{
    public function __construct()
    {
        add_shortcode('olama_exam', array($this, 'render_exam_shortcode'));
    }

    /**
     * Main shortcode router
     */
    public function render_exam_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return '<div class="olama-exam-login-required">' .
                olama_exam_translate('Please log in to access exams.') .
                '</div>';
        }

        $view = sanitize_text_field($_GET['exam_view'] ?? 'dashboard');
        $exam_id = intval($_GET['exam_id'] ?? 0);
        $attempt_id = intval($_GET['attempt_id'] ?? 0);

        switch ($view) {
            case 'take':
                return $this->render_exam_taking($exam_id);
            case 'results':
                return $this->render_results($attempt_id);
            default:
                return $this->render_dashboard();
        }
    }

    /**
     * Dashboard — list of available and completed exams
     */
    private function render_dashboard()
    {
        global $wpdb;
        $wp_user = wp_get_current_user();

        // In Olama SIS, the user's login name IS the family_id
        // Look up all students belonging to this family, then their enrolled sections
        $family_id = $wp_user->user_login;

        // Get all student IDs for this family
        $student_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_students WHERE family_id = %s",
            $family_id
        ));

        // Get active year for enrollment filter
        $active_year = Olama_School_Academic::get_active_year();
        $year_id = $active_year ? $active_year->id : 0;

        // Get enrolled section IDs for these students in the current academic year
        $student_sections = array();
        if (!empty($student_ids)) {
            $s_placeholders = implode(',', array_fill(0, count($student_ids), '%d'));
            $student_sections = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT section_id FROM {$wpdb->prefix}olama_student_enrollment 
                 WHERE student_id IN ($s_placeholders) AND academic_year_id = %d AND status = 'active'",
                ...array_merge($student_ids, array($year_id))
            ));
        }

        $active_exams = array();
        $completed_attempts = array();

        if (!empty($student_sections)) {
            $placeholders = implode(',', array_fill(0, count($student_sections), '%d'));
            $active_exams = $wpdb->get_results($wpdb->prepare(
                "SELECT e.*, sub.subject_name, s.section_name, g.grade_name, st.student_uid, st.student_name
                 FROM {$wpdb->prefix}olama_exam_exams e
                 JOIN {$wpdb->prefix}olama_student_enrollment se ON e.section_id = se.section_id
                 JOIN {$wpdb->prefix}olama_students st ON se.student_id = st.id
                 LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
                 LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
                 LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
                 WHERE st.family_id = %s AND se.academic_year_id = %d AND se.status = 'active' AND e.status = 'active' AND e.section_id IN ($placeholders)
                 ORDER BY e.start_time ASC",
            ...array_merge(array($family_id, $year_id), $student_sections)
            ));
        }

        // Get completed attempts for all students in this family
        $completed_attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, e.title as exam_title, sub.subject_name, e.show_results, st.student_name
             FROM {$wpdb->prefix}olama_exam_attempts a
             JOIN {$wpdb->prefix}olama_exam_exams e ON a.exam_id = e.id
             JOIN {$wpdb->prefix}olama_students st ON a.student_uid = st.student_uid
             LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
             WHERE st.family_id = %s AND a.submitted_at IS NOT NULL
             ORDER BY a.submitted_at DESC LIMIT 20",
            $family_id
        ));

        ob_start();
        ?>
        <div class="oe-container" dir="auto">
            <div class="oe-dashboard">
                <h2 class="oe-title"><?php echo olama_exam_translate('My Exams'); ?></h2>

                <?php if (!empty($active_exams)): ?>
                    <div class="oe-section">
                        <h3 class="oe-section-title">📋 <?php echo olama_exam_translate('Available Exams'); ?></h3>
                        <div class="oe-exam-grid">
                            <?php foreach ($active_exams as $exam):
                                $attempt_count = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts 
                                 WHERE exam_id = %d AND student_id = %d",
                                    $exam->id,
                                    $student_id
                                ));
                                $has_unsubmitted = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts 
                                 WHERE exam_id = %d AND student_id = %d AND submitted_at IS NULL",
                                    $exam->id,
                                    $student_id
                                ));
                                $remaining_attempts = $exam->max_attempts - $attempt_count;
                                $can_take = $remaining_attempts > 0 || $has_unsubmitted > 0;
                                $now = current_time('timestamp');
                                $in_window = ($now >= strtotime($exam->start_time) && $now <= strtotime($exam->end_time));
                                ?>
                                <div class="oe-exam-card <?php echo !$can_take ? 'oe-disabled' : ''; ?>">
                                    <div class="oe-exam-card-header">
                                        <span class="oe-subject-badge"><?php echo esc_html($exam->subject_name ?? ''); ?></span>
                                        <span class="oe-duration">⏱ <?php echo $exam->duration_minutes; ?>
                                            <?php echo olama_exam_translate('minutes'); ?></span>
                                    </div>
                                    <h4 class="oe-exam-card-title"><?php echo esc_html($exam->title); ?></h4>
                                    <div class="oe-exam-card-info" style="font-weight: 600; color: #475569; margin-bottom: 6px;">
                                        👤 <?php echo esc_html($exam->student_name); ?>
                                    </div>
                                    <div class="oe-exam-card-info">
                                        <span><?php echo esc_html($exam->grade_name ?? ''); ?> —
                                            <?php echo esc_html($exam->section_name ?? ''); ?></span>
                                        <span><?php echo olama_exam_translate('Attempts'); ?>:
                                            <?php echo $attempt_count; ?>/<?php echo $exam->max_attempts; ?></span>
                                    </div>
                                    <?php if ($can_take && $in_window): ?>
                                        <a href="?exam_view=take&exam_id=<?php echo $exam->id; ?>&student_uid=<?php echo esc_attr($exam->student_uid); ?>" class="oe-btn oe-btn-primary">
                                            <?php echo $has_unsubmitted ? olama_exam_translate('Resume Exam') : olama_exam_translate('Start Exam'); ?>
                                        </a>
                                    <?php elseif (!$in_window): ?>
                                        <div class="oe-exam-card-status"><?php echo olama_exam_translate('Outside time window'); ?></div>
                                    <?php else: ?>
                                        <div class="oe-exam-card-status"><?php echo olama_exam_translate('No attempts remaining'); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="oe-empty"><?php echo olama_exam_translate('No active exams available.'); ?></div>
                <?php endif; ?>

                <?php if (!empty($completed_attempts)): ?>
                    <div class="oe-section" style="margin-top:32px;">
                        <h3 class="oe-section-title">📊 <?php echo olama_exam_translate('Completed Exams'); ?></h3>
                        <div class="oe-results-list">
                            <?php foreach ($completed_attempts as $a): ?>
                                <div class="oe-result-row">
                                    <div class="oe-result-info">
                                        <strong><?php echo esc_html($a->exam_title); ?></strong>
                                        <div style="font-size: 13px; color: #64748b; margin-top: 2px;">👤 <?php echo esc_html($a->student_name); ?></div>
                                        <span
                                            class="oe-result-date"><?php echo date('d/m/Y H:i', strtotime($a->submitted_at)); ?></span>
                                    </div>
                                    <div class="oe-result-score">
                                        <span class="oe-score-badge oe-score-<?php echo $a->result; ?>">
                                            <?php echo $a->percentage; ?>%
                                            <?php if ($a->result === 'pass'): ?>✅<?php elseif ($a->result === 'fail'): ?>❌<?php else: ?>⏳<?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if ($a->show_results): ?>
                                        <a href="?exam_view=results&attempt_id=<?php echo $a->id; ?>&student_uid=<?php echo esc_attr($a->student_uid); ?>"
                                            class="oe-btn oe-btn-outline oe-btn-sm">
                                            <?php echo olama_exam_translate('View Details'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Exam Taking — the active exam interface
     */
    private function render_exam_taking($exam_id)
    {
        if (!$exam_id) {
            return '<div class="oe-error">' . olama_exam_translate('Invalid exam.') . '</div>';
        }

        $student_uid = sanitize_text_field($_GET['student_uid'] ?? '');
        $manual_student_uid = (Olama_Exam_Ajax::can_manage_exams()) ? sanitize_text_field($_GET['manual_student_uid'] ?? '') : '';
        $final_student_uid = $manual_student_uid ?: $student_uid;

        ?>
        <div class="oe-container" dir="auto" id="oe-exam-container" data-exam-id="<?php echo $exam_id; ?>"
            data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>"
            data-nonce="<?php echo wp_create_nonce('olama_exam_nonce'); ?>"
            data-student-uid="<?php echo esc_attr($final_student_uid); ?>">

            <!-- Loading State -->
            <div id="oe-loading" class="oe-loading">
                <div class="oe-spinner"></div>
                <p><?php echo olama_exam_translate('Loading exam...'); ?></p>
            </div>

            <!-- Exam Header (sticky) -->
            <div id="oe-header" class="oe-header" style="display:none;">
                <div class="oe-header-top">
                    <div class="oe-header-info">
                        <h2 id="oe-exam-title"></h2>
                    </div>
                    <div class="oe-timer" id="oe-timer">
                        <span class="oe-timer-icon">⏱</span>
                        <span id="oe-timer-display">--:--</span>
                    </div>
                </div>
                <div class="oe-progress">
                    <div class="oe-progress-bar" id="oe-progress-bar"></div>
                </div>
                <div class="oe-progress-text">
                    <span id="oe-answered-count">0</span> / <span id="oe-total-count">0</span>
                    <?php echo olama_exam_translate('answered'); ?>
                </div>
            </div>

            <!-- Questions Container -->
            <div id="oe-questions" class="oe-questions" style="display:none;"></div>

            <!-- Submit Footer -->
            <div id="oe-footer" class="oe-footer" style="display:none;">
                <div class="oe-autosave-status" id="oe-autosave-status"></div>
                <button type="button" class="oe-btn oe-btn-primary oe-btn-lg" id="oe-submit-btn">
                    ✅ <?php echo olama_exam_translate('Submit Exam'); ?>
                </button>
            </div>

            <!-- Results Container (shown after submit) -->
            <div id="oe-results" class="oe-results" style="display:none;"></div>
        </div>

        <!-- Confirmation Modal -->
        <div id="oe-confirm-modal" class="oe-modal-overlay" style="display:none;">
            <div class="oe-modal">
                <h3><?php echo olama_exam_translate('Submit Exam?'); ?></h3>
                <p id="oe-confirm-text"></p>
                <div class="oe-modal-actions">
                    <button class="oe-btn oe-btn-outline"
                        id="oe-confirm-cancel"><?php echo olama_exam_translate('Cancel'); ?></button>
                    <button class="oe-btn oe-btn-primary"
                        id="oe-confirm-ok"><?php echo olama_exam_translate('Submit'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Results View — score + answer review
     */
    private function render_results($attempt_id)
    {
        global $wpdb;
        $student_uid = sanitize_text_field($_GET['student_uid'] ?? '');

        // Security check is done securely in the query using family_id unless admin
        $family_where = "";
        $family_id = "";
        if (!Olama_Exam_Ajax::can_manage_exams()) {
            $family_id = wp_get_current_user()->user_login;
            $family_where = $wpdb->prepare(" AND a.student_uid IN (SELECT student_uid FROM {$wpdb->prefix}olama_students WHERE family_id = %s) ", $family_id);
        }

        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, e.title as exam_title, e.show_results, e.passing_grade, sub.subject_name
             FROM {$wpdb->prefix}olama_exam_attempts a
             JOIN {$wpdb->prefix}olama_exam_exams e ON a.exam_id = e.id
             LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
             WHERE a.id = %d $family_where",
            $attempt_id
        ));

        if (!$attempt) {
            return '<div class="oe-error">' . olama_exam_translate('Results not found or permission denied.') . '</div>';
        }

        if (!$attempt->show_results) {
            return '<div class="oe-container"><div class="oe-message">' .
                olama_exam_translate('Results are not available for this exam.') . '</div></div>';
        }

        $snapshot = json_decode($attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($attempt->answers_json, true) ?: array();

        ob_start();
        ?>
        <div class="oe-container" dir="auto">
            <div class="oe-results-page">
                <!-- Score Summary -->
                <div class="oe-score-summary">
                    <a href="?exam_view=dashboard" class="oe-back-link">← <?php echo olama_exam_translate('Back'); ?></a>
                    <h2><?php echo esc_html($attempt->exam_title); ?></h2>
                    <div class="oe-score-circle oe-score-<?php echo $attempt->result; ?>">
                        <span class="oe-score-number"><?php echo $attempt->percentage; ?>%</span>
                        <span class="oe-score-text"><?php echo $attempt->score; ?> / <?php echo $attempt->max_score; ?></span>
                    </div>
                    <div class="oe-result-label oe-result-<?php echo $attempt->result; ?>">
                        <?php
                        switch ($attempt->result) {
                            case 'pass':
                                echo '✅ ' . olama_exam_translate('Pass');
                                break;
                            case 'fail':
                                echo '❌ ' . olama_exam_translate('Fail');
                                break;
                            default:
                                echo '⏳ ' . olama_exam_translate('Pending');
                                break;
                        }
                        ?>
                    </div>
                </div>

                <!-- Answer Review -->
                <div class="oe-answer-review">
                    <?php foreach ($snapshot as $idx => $q):
                        $q_id = $q['question_id'];
                        $student_answer = $answers[$q_id] ?? null;
                        $correct = $q['correct'] ?? array();
                        $type = $q['type'];

                        // Determine if correct
                        $is_correct = false;
                        if ($type !== 'essay' && $student_answer !== null) {
                            $earned = Olama_Exam_Grader::grade($attempt_id); // Reuse grading logic indirectly
                            // Simple check for display
                            $is_correct = self::check_answer($type, $student_answer, $correct);
                        }
                        ?>
                        <div
                            class="oe-review-card <?php echo $type === 'essay' ? 'oe-pending' : ($is_correct ? 'oe-correct' : 'oe-incorrect'); ?>">
                            <div class="oe-review-header">
                                <span class="oe-review-num">Q<?php echo $idx + 1; ?></span>
                                <span class="oe-review-status">
                                    <?php if ($type === 'essay'): ?>⏳
                                    <?php elseif ($is_correct): ?>✅
                                    <?php else: ?>❌
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="oe-review-question"><?php echo wp_kses_post($q['question_text']); ?></div>
                            <?php if ($student_answer !== null && $student_answer !== ''): ?>
                                <div class="oe-review-answer">
                                    <strong><?php echo olama_exam_translate('Your answer'); ?>:</strong>
                                    <?php echo esc_html(is_array($student_answer) ? implode(', ', $student_answer) : $student_answer); ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($q['explanation'])): ?>
                                <div class="oe-review-explanation">📖 <?php echo wp_kses_post($q['explanation']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Simple answer check for result display
     */
    private static function check_answer($type, $answer, $correct)
    {
        switch ($type) {
            case 'mcq':
                return intval($answer) === intval($correct['correct'] ?? -1);
            case 'tf':
                return filter_var($answer, FILTER_VALIDATE_BOOLEAN) === filter_var($correct['correct'] ?? true, FILTER_VALIDATE_BOOLEAN);
            case 'short':
                $accepted = $correct['answers'] ?? array();
                return in_array(mb_strtolower(trim($answer)), array_map(function ($a) {
                    return mb_strtolower(trim($a));
                }, (array) $accepted));
            default:
                return false;
        }
    }
}
