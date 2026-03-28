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
        $view = sanitize_text_field($_GET['exam_view'] ?? 'dashboard');
        $exam_id = intval($_GET['exam_id'] ?? 0);
        $attempt_id = intval($_GET['attempt_id'] ?? 0);

        // Check if this is a placement test
        $is_placement = false;
        if ($exam_id > 0) {
            global $wpdb;
            $is_placement = $wpdb->get_var($wpdb->prepare("SELECT is_placement FROM {$wpdb->prefix}olama_exam_exams WHERE id = %d", $exam_id));
        }

        if (!is_user_logged_in() && !$is_placement) {
            return '<div class="olama-exam-login-required">' .
                olama_exam_translate('Please log in to access exams.') .
                '</div>';
        }

        // If not logged in and it's a placement test, we must show form unless taking
        if (!is_user_logged_in() && $is_placement && $view === 'dashboard') {
            $view = 'placement';
        }

        switch ($view) {
            case 'take':
                return $this->render_exam_taking($exam_id);
            case 'results':
                return $this->render_results($attempt_id);
            case 'placement':
                return $this->render_placement_form($exam_id);
            default:
                return $this->render_dashboard();
        }
    }
    private function render_dashboard()
    {
        // Force enqueue assets early to ensure they load even if global detection fails
        if (function_exists('olama_exam_enqueue_frontend_assets')) {
            olama_exam_enqueue_frontend_assets(true);
        }

        global $wpdb;
        $family_id = wp_get_current_user()->user_login;
        $student_filter = sanitize_text_field($_GET['student_uid'] ?? '');

        // Get ALL assigned or attempted exams for all students in this family
        $sql = $wpdb->prepare(
            "SELECT e.*, st.student_name, st.student_uid, g.grade_name, s.section_name, sub.subject_name, sub.color_code
             FROM {$wpdb->prefix}olama_exam_exams e
             JOIN (
                SELECT st_inner.student_uid, st_inner.student_name, st_inner.family_id, en_inner.section_id, 0 as exam_id
                FROM {$wpdb->prefix}olama_students st_inner
                JOIN {$wpdb->prefix}olama_student_enrollment en_inner ON st_inner.student_uid = en_inner.student_uid
                UNION
                SELECT st_inner2.student_uid, st_inner2.student_name, st_inner2.family_id, 0 as section_id, a_inner.exam_id
                FROM {$wpdb->prefix}olama_students st_inner2
                JOIN {$wpdb->prefix}olama_exam_attempts a_inner ON st_inner2.student_uid = a_inner.student_uid
             ) st ON (st.family_id = %s AND (st.section_id = e.section_id OR st.exam_id = e.id))
             LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
             LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
             WHERE 1=1",
            $family_id
        );

        if ($student_filter) {
            $sql .= $wpdb->prepare(" AND st.student_uid = %s", $student_filter);
        }

        $sql .= " GROUP BY e.id, st.student_uid ORDER BY e.start_time ASC";
        $all_exams = $wpdb->get_results($sql);

        // Get completed attempts
        $completed_attempts_query = $wpdb->prepare(
            "SELECT a.*, e.title as exam_title, st.student_name, e.show_results
              FROM {$wpdb->prefix}olama_exam_attempts a
              JOIN {$wpdb->prefix}olama_exam_exams e ON a.exam_id = e.id
              JOIN {$wpdb->prefix}olama_students st ON a.student_uid = st.student_uid
              WHERE st.family_id = %s AND a.submitted_at IS NOT NULL",
            $family_id
        );

        if ($student_filter) {
            $completed_attempts_query .= $wpdb->prepare(" AND a.student_uid = %s", $student_filter);
        }

        $completed_attempts_query .= " ORDER BY a.submitted_at DESC LIMIT 15";
        $completed_attempts = $wpdb->get_results($completed_attempts_query);

        ob_start();
        ?>
        <div class="oe-container oe-dashboard-wrap" dir="auto">
            <header class="oe-dashboard-header">
                <h2 class="oe-title"><?php echo olama_exam_translate('Student Exams'); ?></h2>
                <p class="oe-subtitle">
                    <?php 
                    if ($student_filter && !empty($all_exams)) {
                        echo sprintf(olama_exam_translate('Viewing exams for: %s'), esc_html($all_exams[0]->student_name));
                    } else {
                        echo sprintf(olama_exam_translate('Total assignments for your children: %d'), count($all_exams));
                    }
                    ?>
                </p>
            </header>

            <div class="oe-dashboard-content">
                <section class="oe-section">
                    <div class="oe-section-header">
                        <h3 class="oe-section-title">
                            <span class="oe-icon">📝</span>
                            <?php echo olama_exam_translate('Assigned Exams'); ?>
                        </h3>
                    </div>

                    <?php if (!empty($all_exams)): ?>
                        <div class="oe-exam-grid">
                            <?php foreach ($all_exams as $exam):
                                // Check attempts for THIS student
                                $attempt_count = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_id = %d AND student_uid = %s",
                                    $exam->id, $exam->student_uid
                                ));
                                $has_unsubmitted = $wpdb->get_var($wpdb->prepare(
                                    "SELECT id FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_id = %d AND student_uid = %s AND submitted_at IS NULL",
                                    $exam->id, $exam->student_uid
                                ));

                                $remaining_attempts = intval($exam->max_attempts) - intval($attempt_count);
                                $is_active_status = in_array($exam->status, array('active', 'published'));
                                
                                $now = current_time('mysql');
                                $in_window = ($now >= $exam->start_time && $now <= $exam->end_time);
                                if (!$in_window) {
                                    $now_ts = current_time('timestamp');
                                    $in_window = ($now_ts >= strtotime($exam->start_time) && $now_ts <= strtotime($exam->end_time));
                                }

                                // Logical status class
                                if (!$is_active_status) {
                                    $status_class = 'oe-status-inactive';
                                } elseif ($has_unsubmitted) {
                                    $status_class = 'oe-status-progress';
                                } elseif ($in_window && ($remaining_attempts > 0)) {
                                    $status_class = 'oe-status-active';
                                } else {
                                    $status_class = 'oe-status-locked';
                                }
                                
                                // Subject Color fallback
                                $subject_color = $exam->color_code ?: '#2563eb';
                                ?>
                                <div class="oe-exam-card <?php echo $status_class; ?>" style="--oe-subject: <?php echo $subject_color; ?>;">
                                    <div class="oe-card-content">
                                        <div class="oe-card-top">
                                            <div class="oe-subject-tag" style="background: <?php echo $subject_color; ?>20; color: <?php echo $subject_color; ?>;"><?php echo esc_html($exam->subject_name ?: olama_exam_translate('General')); ?></div>
                                            <?php if (!$student_filter): ?>
                                                <div class="oe-student-tag">👤 <?php echo esc_html($exam->student_name); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <h4 class="oe-card-title"><?php echo esc_html($exam->title); ?></h4>
                                        <div class="oe-card-meta">
                                            <div class="oe-meta-row">⏱ <strong><?php echo olama_exam_translate('Duration:'); ?></strong> <?php echo $exam->duration_minutes; ?> min</div>
                                            <div class="oe-meta-row">🔢 <strong><?php echo olama_exam_translate('Attempts:'); ?></strong> <?php echo $attempt_count; ?>/<?php echo $exam->max_attempts; ?></div>
                                            <?php if ($is_active_status): ?>
                                                <div class="oe-meta-row">📅 <strong><?php echo olama_exam_translate('Ends:'); ?></strong> <?php echo date('d M, Y H:i', strtotime($exam->end_time)); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="oe-card-details">
                                            <?php if (!$is_active_status): ?>
                                                <span class="oe-status-badge">⚠️ <?php echo ucfirst($exam->status); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="oe-card-footer">
                                        <?php if (!$is_active_status): ?>
                                            <div class="oe-card-notice oe-notice-info"><?php echo olama_exam_translate('Not open yet'); ?></div>
                                        <?php elseif ($has_unsubmitted): ?>
                                            <a href="?exam_view=take&exam_id=<?php echo $exam->id; ?>&student_uid=<?php echo esc_attr($exam->student_uid); ?>" class="oe-btn oe-btn-warning oe-full-width">
                                                🔄 <?php echo olama_exam_translate('Resume Exam'); ?>
                                            </a>
                                        <?php elseif ($in_window && $remaining_attempts > 0): ?>
                                            <a href="?exam_view=take&exam_id=<?php echo $exam->id; ?>&student_uid=<?php echo esc_attr($exam->student_uid); ?>" class="oe-btn oe-btn-primary oe-full-width">
                                                🚀 <?php echo olama_exam_translate('Start Exam'); ?>
                                            </a>
                                        <?php elseif (!$in_window): ?>
                                            <div class="oe-card-notice oe-notice-info">⌛ <?php echo olama_exam_translate('Outside time window'); ?></div>
                                        <?php else: ?>
                                            <div class="oe-card-notice oe-notice-danger">🚫 <?php echo olama_exam_translate('No attempts left'); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="oe-empty-state">
                            <div class="oe-empty-icon">📂</div>
                            <p><?php echo olama_exam_translate('No exams are currently available for your children.'); ?></p>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if (!empty($completed_attempts)): ?>
                <section class="oe-section oe-completed-section">
                    <div class="oe-section-header">
                        <h3 class="oe-section-title">
                            <span class="oe-icon">📊</span>
                            <?php echo olama_exam_translate('Recent Results'); ?>
                        </h3>
                    </div>
                    <div class="oe-results-grid">
                        <?php foreach ($completed_attempts as $a): ?>
                            <div class="oe-result-card">
                                <div class="oe-result-header">
                                    <strong><?php echo esc_html($a->exam_title); ?></strong>
                                    <span class="oe-result-student"><?php echo esc_html($a->student_name); ?></span>
                                </div>
                                <div class="oe-result-body">
                                    <div class="oe-result-score oe-score-<?php echo $a->result; ?>">
                                        <?php echo $a->percentage; ?>%
                                        <span class="oe-score-label"><?php echo strtoupper($a->result); ?></span>
                                    </div>
                                    <div class="oe-result-meta">
                                        <span>📅 <?php echo date('d/m/y', strtotime($a->submitted_at)); ?></span>
                                        <?php if ($a->show_results): ?>
                                            <a href="?exam_view=results&attempt_id=<?php echo $a->id; ?>&student_uid=<?php echo esc_attr($a->student_uid); ?>" class="oe-link-action">
                                                <?php echo olama_exam_translate('View Details'); ?> ➔
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
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
        // Force enqueue assets here to ensure they load even if global detection fails
        if (function_exists('olama_exam_enqueue_frontend_assets')) {
            olama_exam_enqueue_frontend_assets(true);
        }

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
                        <div id="oe-student-name" class="oe-student-name-display"></div>
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

    /**
     * Render the prospective student info form for placement tests
     */
    private function render_placement_form($exam_id)
    {
        if (!$exam_id) {
            return '<div class="oe-error">' . olama_exam_translate('Invalid placement test.') . '</div>';
        }

        global $wpdb;
        $exam = $wpdb->get_row($wpdb->prepare("SELECT e.*, g.grade_name FROM {$wpdb->prefix}olama_exam_exams e LEFT JOIN {$wpdb->prefix}olama_grades g ON e.section_id = g.id WHERE e.id = %d", $exam_id));

        if (!$exam || !$exam->is_placement) {
            return '<div class="oe-error">' . olama_exam_translate('This is not a placement test.') . '</div>';
        }

        ob_start();
        ?>
        <div class="oe-container" dir="auto">
            <div class="oe-placement-card">
                <div class="oe-placement-header">
                    <h2><?php echo olama_exam_translate('Grade Placement Test'); ?></h2>
                    <p><?php echo esc_html($exam->title); ?></p>
                </div>
                <form id="oe-placement-form" class="oe-form">
                    <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                    
                    <div class="oe-form-group">
                        <label><?php echo olama_exam_translate('Student Name'); ?> *</label>
                        <input type="text" name="student_name" required placeholder="<?php echo olama_exam_translate('Full Name'); ?>">
                    </div>

                    <div class="oe-form-row">
                        <div class="oe-form-group">
                            <label><?php echo olama_exam_translate('Guardian Name'); ?></label>
                            <input type="text" name="guardian_name">
                        </div>
                        <div class="oe-form-group">
                            <label><?php echo olama_exam_translate('Mobile Number'); ?> *</label>
                            <input type="tel" name="mobile" required>
                        </div>
                    </div>

                    <div class="oe-form-row">
                        <div class="oe-form-group">
                            <label><?php echo olama_exam_translate('Old School Name'); ?></label>
                            <input type="text" name="old_school">
                        </div>
                        <div class="oe-form-group">
                            <label><?php echo olama_exam_translate('Last Finished Grade'); ?></label>
                            <input type="text" name="last_finished_grade">
                        </div>
                    </div>

                    <div class="oe-form-group">
                        <label><?php echo olama_exam_translate('Address'); ?></label>
                        <textarea name="address" rows="2"></textarea>
                    </div>

                    <div class="oe-form-actions">
                        <button type="submit" class="oe-btn oe-btn-primary oe-btn-lg" id="oe-start-placement-btn">
                            🚀 <?php echo olama_exam_translate('Start Test'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#oe-placement-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#oe-start-placement-btn');
                $btn.prop('disabled', true).text('⏳...');

                var formData = {
                    action: 'olama_exam_start_placement',
                    nonce: olamaExam.nonce,
                    exam_id: $('input[name="exam_id"]').val(),
                    student_name: $('input[name="student_name"]').val(),
                    guardian_name: $('input[name="guardian_name"]').val(),
                    mobile: $('input[name="mobile"]').val(),
                    old_school: $('input[name="old_school"]').val(),
                    last_finished_grade: $('input[name="last_finished_grade"]').val(),
                    address: $('textarea[name="address"]').val()
                };

                $.post(olamaExam.ajaxUrl, formData, function(res) {
                    if (res.success) {
                        window.location.href = window.location.pathname + '?exam_view=take&exam_id=' + res.data.exam_id + '&student_uid=' + res.data.student_uid;
                    } else {
                        alert(res.data.message || 'Error');
                        $btn.prop('disabled', false).text('🚀 ' + '<?php echo olama_exam_translate('Start Test'); ?>');
                    }
                });
            });
        });
        </script>
        
        <style>
        .oe-placement-card { background: white; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); padding: 32px; max-width: 600px; margin: 40px auto; }
        .oe-placement-header { text-align: center; margin-bottom: 32px; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; }
        .oe-placement-header h2 { color: #1e293b; margin: 0; font-size: 24px; }
        .oe-placement-header p { color: #64748b; margin: 8px 0 0; }
        .oe-form-group { margin-bottom: 20px; }
        .oe-form-group label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 14px; }
        .oe-form-group input, .oe-form-group textarea { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 15px; transition: border-color 0.2s; }
        .oe-form-group input:focus { border-color: #6366f1; outline: none; }
        .oe-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .oe-form-actions { margin-top: 32px; }
        @media (max-width: 600px) { .oe-form-row { grid-template-columns: 1fr; } .oe-placement-card { padding: 20px; margin: 20px; } }
        </style>
        <?php
        return ob_get_clean();
    }
}
