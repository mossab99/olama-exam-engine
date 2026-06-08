<?php
/**
 * Public Acceptance Test Controller & Templating
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEE_Acceptance_Public
{
    public function __construct()
    {
        $this->register_rewrite();
        add_action('template_redirect', array($this, 'handle_acceptance_request'));
    }

    /**
     * Register rewrite rules and query vars
     */
    public function register_rewrite()
    {
        add_rewrite_rule(
            '^acceptance/([a-zA-Z0-9]+)/?$',
            'index.php?oee_acceptance_token=$matches[1]',
            'top'
        );

        add_filter('query_vars', function ($vars) {
            $vars[] = 'oee_acceptance_token';
            return $vars;
        });

        // Flush rewrite rules once to apply the new route automatically
        if (!get_option('oee_acceptance_rewrite_flushed_v1')) {
            flush_rewrite_rules(false);
            update_option('oee_acceptance_rewrite_flushed_v1', true);
        }
    }

    /**
     * Handle public test requests
     */
    public function handle_acceptance_request()
    {
        // 1. Get Token (supporting pretty rewrite rule and query param fallback)
        $token = get_query_var('oee_acceptance_token');
        if (!$token && isset($_GET['oee-acceptance'])) {
            $token = sanitize_text_field($_GET['oee-acceptance']);
        }

        if (empty($token)) {
            return;
        }

        // 2. Fetch and validate acceptance test
        $test = OEE_Acceptance_Tests::get_by_token($token);
        if (!$test) {
            $this->render_error_page(olama_exam_translate('acceptance_inactive'));
            return;
        }

        // Ensure session is started for transient key
        if (!session_id() && !headers_sent()) {
            @session_start();
        }

        $session_id = session_id() ?: 'default';
        $transient_key = 'oee_acc_' . $token . '_' . $session_id;
        $attempt_id = get_transient($transient_key);

        global $wpdb;

        // 3. Process POST (applicant info submission)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'oee_start_acceptance') {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'oee_acceptance_start')) {
                $this->render_error_page(olama_exam_translate('Security check failed.'));
                return;
            }

            $name = sanitize_text_field($_POST['applicant_name'] ?? '');
            $national_id = sanitize_text_field($_POST['national_id'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');

            if (empty($name) || empty($national_id) || empty($phone)) {
                $error = olama_exam_translate('Please fill in all required fields.');
                $this->render_info_form($test, $error);
                return;
            }

            // Generate unique student UID for public applicant
            $student_uid = 'acceptance_' . substr(md5($name . time() . rand()), 0, 10);

            // Start the exam in the engine
            $result = Olama_Exam_Engine::start_exam($test->id, $student_uid, false, true);
            if (is_wp_error($result)) {
                $this->render_info_form($test, $result->get_error_message());
                return;
            }

            $attempt_id = $result['attempt_id'];

            // Insert metadata
            $wpdb->insert("{$wpdb->prefix}oee_acceptance_applicants", array(
                'attempt_id' => $attempt_id,
                'test_id' => $test->id,
                'name' => $name,
                'national_id' => $national_id,
                'phone' => $phone,
                'email' => $email,
                'created_at' => current_time('mysql'),
            ));

            // Store transient for 2 hours
            set_transient($transient_key, $attempt_id, HOUR_IN_SECONDS * 2);

            // Redirect (PRG Pattern)
            wp_redirect(add_query_arg('r', time(), home_url($_SERVER['REQUEST_URI'])));
            exit;
        }

        // 4. If session attempt exists, process rendering
        if ($attempt_id) {
            $attempt = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_exam_attempts WHERE id = %d",
                $attempt_id
            ));

            if ($attempt) {
                if ($attempt->submitted_at) {
                    // Render Results Screen
                    $this->render_results_page($attempt, $test);
                    return;
                } else {
                    // Render Exam Taking UI
                    $this->render_exam_taking_page($attempt, $test);
                    return;
                }
            }
        }

        // 5. Render default Applicant info form
        $this->render_info_form($test);
    }

    /**
     * Enqueue standard assets for frontend
     */
    private function enqueue_assets()
    {
        if (function_exists('olama_exam_enqueue_frontend_assets')) {
            olama_exam_enqueue_frontend_assets(true);
        }
    }

    /**
     * Render applicant info form page
     */
    private function render_info_form($test, $error = '')
    {
        $this->enqueue_assets();
        get_header();
        ?>
        <div class="os-exam-acceptance-wrap" dir="rtl" style="max-width: 600px; margin: 50px auto; padding: 20px;">
            <div class="oe-placement-card" style="background:#fff; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.08); padding:32px;">
                <div class="oe-placement-header" style="text-align:center; margin-bottom:24px; border-bottom:2px solid #f1f5f9; padding-bottom:16px;">
                    <h2 style="font-size:22px; color:#1e293b;"><?php echo olama_exam_translate('acceptance_form_title'); ?></h2>
                    <span class="oe-subject-tag" style="background:#6366f115; color:#6366f1; padding:4px 12px; border-radius:99px; font-weight:600; font-size:14px; margin-top:8px; display:inline-block;"><?php echo esc_html($test->profession_name_ar); ?></span>
                </div>

                <?php if (!empty($error)): ?>
                    <div style="background:#fee2e2; color:#ef4444; border:1px solid #fca5a5; border-radius:8px; padding:12px; margin-bottom:20px; font-weight:500;">
                        ⚠️ <?php echo esc_html($error); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="oe-form">
                    <input type="hidden" name="action" value="oee_start_acceptance">
                    <?php wp_nonce_field('oee_acceptance_start'); ?>

                    <div class="oe-form-group" style="margin-bottom:16px;">
                        <label style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:14px;"><?php echo olama_exam_translate('applicant_name'); ?> *</label>
                        <input type="text" name="applicant_name" required style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="مثال: محمد أحمد علي">
                    </div>

                    <div class="oe-form-group" style="margin-bottom:16px;">
                        <label style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:14px;"><?php echo olama_exam_translate('national_id'); ?> *</label>
                        <input type="text" name="national_id" required style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="رقم الهوية أو الرقم الوطني">
                    </div>

                    <div class="oe-form-group" style="margin-bottom:16px;">
                        <label style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:14px;"><?php echo olama_exam_translate('phone'); ?> *</label>
                        <input type="tel" name="phone" required style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="مثال: 079XXXXXXXX">
                    </div>

                    <div class="oe-form-group" style="margin-bottom:24px;">
                        <label style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:14px;"><?php echo olama_exam_translate('email'); ?></label>
                        <input type="email" name="email" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px;" placeholder="example@domain.com">
                    </div>

                    <div class="oe-form-actions">
                        <button type="submit" class="oe-btn oe-btn-primary oe-btn-lg" style="width:100%; padding:12px; background:#6366f1; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
                            🚀 <?php echo olama_exam_translate('start_acceptance_exam'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        get_footer();
        exit;
    }

    /**
     * Render active exam taking interface
     */
    private function render_exam_taking_page($attempt, $test)
    {
        $this->enqueue_assets();
        get_header();

        // Standard student engine dependencies
        $exam_id = intval($attempt->exam_id);
        $final_student_uid = sanitize_text_field($attempt->student_uid);
        ?>
        <div class="os-exam-acceptance-wrap" dir="rtl" style="padding: 20px 0;">
            <div class="oe-container" dir="rtl" id="oe-exam-container" data-exam-id="<?php echo $exam_id; ?>"
                data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>"
                data-nonce="<?php echo wp_create_nonce('olama_exam_nonce'); ?>"
                data-student-uid="<?php echo esc_attr($final_student_uid); ?>">

                <!-- Loading State -->
                <div id="oe-loading" class="oe-loading">
                    <div class="oe-spinner"></div>
                    <p><?php echo olama_exam_translate('Loading exam...'); ?></p>
                </div>

                <!-- Exam Header (sticky) -->
                <div id="oe-header" class="oe-header" style="display:none; margin-bottom: 20px;">
                    <div class="oe-header-top" style="display:flex; justify-content:space-between; align-items:center;">
                        <div class="oe-header-info">
                            <h2 id="oe-exam-title" style="margin:0; font-size:20px; color:#1e293b;"></h2>
                            <div id="oe-student-name" class="oe-student-name-display" style="font-size:14px; color:#64748b; margin-top:4px;"></div>
                        </div>
                        <div class="oe-timer" id="oe-timer" style="background:#fee2e2; color:#ef4444; padding:8px 16px; border-radius:8px; font-weight:600; display:flex; align-items:center; gap:8px;">
                            <span class="oe-timer-icon">⏱</span>
                            <span id="oe-timer-display">--:--</span>
                        </div>
                    </div>
                    <div class="oe-progress" style="background:#e2e8f0; border-radius:99px; height:8px; margin-top:12px; overflow:hidden;">
                        <div class="oe-progress-bar" id="oe-progress-bar" style="background:#6366f1; height:100%; width:0%; transition:width 0.3s;"></div>
                    </div>
                    <div class="oe-progress-text" style="font-size:13px; color:#64748b; margin-top:6px;">
                        <span id="oe-answered-count">0</span> / <span id="oe-total-count">0</span>
                        <?php echo olama_exam_translate('answered'); ?>
                    </div>
                </div>

                <!-- Main Layout with Sidebar -->
                <div class="oe-main-layout" style="display:flex; gap:24px; align-items: flex-start;">
                    <div class="oe-content-side" style="flex:1;">
                        <!-- Questions Container -->
                        <div id="oe-questions" class="oe-questions" style="display:none;"></div>

                        <!-- Submit Footer -->
                        <div id="oe-footer" class="oe-footer" style="display:none; margin-top:20px; text-align:left; background:#fff; padding:16px 24px; border-radius:12px; border:1px solid #e2e8f0; justify-content:space-between; align-items:center;">
                            <div class="oe-autosave-status" id="oe-autosave-status" style="font-size:13px; color:#64748b;"></div>
                            <button type="button" class="oe-btn oe-btn-primary oe-btn-lg" id="oe-submit-btn" style="background:#10b981; color:#fff; padding:10px 24px; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
                                ✅ <?php echo olama_exam_translate('Submit Exam'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Navigation Sidebar -->
                    <aside id="oe-navigation" class="oe-navigation" style="display:none; width:280px; position:sticky; top:20px;">
                        <div class="oe-nav-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                            <div class="oe-nav-header" style="border-bottom:1px solid #f1f5f9; padding-bottom:10px; margin-bottom:14px;">
                                <h3 style="margin:0; font-size:16px; color:#1e293b;"><?php echo olama_exam_translate('Quiz navigation'); ?></h3>
                            </div>
                            <div id="oe-nav-grid" class="oe-nav-grid" style="display:grid; grid-template-columns:repeat(5, 1fr); gap:8px;">
                                <!-- JS will populate this -->
                            </div>
                            <div class="oe-nav-footer" style="margin-top:16px; text-align:center; border-top:1px solid #f1f5f9; padding-top:12px;">
                                <a href="javascript:void(0)" id="oe-finish-scroll" class="oe-finish-link" style="color:#6366f1; text-decoration:none; font-weight:500; font-size:14px;">
                                    <?php echo olama_exam_translate('Finish attempt...'); ?>
                                </a>
                            </div>
                        </div>
                    </aside>
                </div>

                <!-- Results Container -->
                <div id="oe-results" class="oe-results" style="display:none;"></div>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <div id="oe-confirm-modal" class="oe-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div class="oe-modal" style="background:#fff; padding:24px; border-radius:12px; max-width:400px; width:100%; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <h3 style="margin:0 0 10px; font-size:18px; color:#1e293b;"><?php echo olama_exam_translate('Submit Exam?'); ?></h3>
                <p id="oe-confirm-text" style="color:#64748b; font-size:14px; margin-bottom:20px;"></p>
                <div class="oe-modal-actions" style="display:flex; justify-content:center; gap:12px;">
                    <button class="oe-btn oe-btn-outline" id="oe-confirm-cancel" style="padding:8px 16px; border:1px solid #cbd5e1; border-radius:6px; background:none; cursor:pointer; font-weight:500;"><?php echo olama_exam_translate('Cancel'); ?></button>
                    <button class="oe-btn oe-btn-primary" id="oe-confirm-ok" style="padding:8px 20px; background:#ef4444; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:500;"><?php echo olama_exam_translate('Submit'); ?></button>
                </div>
            </div>
        </div>
        <?php
        get_footer();
        exit;
    }

    /**
     * Render results screen (correct answers NOT displayed)
     */
    private function render_results_page($attempt, $test)
    {
        $this->enqueue_assets();
        get_header();

        $score_pct = floatval($attempt->percentage);
        $passed = (bool) $attempt->result === 'pass';
        ?>
        <div class="os-exam-acceptance-wrap" dir="rtl" style="max-width: 500px; margin: 80px auto; padding: 20px;">
            <div class="oe-placement-card" style="background:#fff; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.08); padding:40px; text-align:center;">
                <div class="os-exam-score-circle" style="width:120px; height:120px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; margin:0 auto 24px; font-size:32px; font-weight:700; color:#1e293b; border:4px solid <?php echo $passed ? '#10b981' : '#ef4444'; ?>;">
                    <?php echo $score_pct; ?>%
                </div>
                <h3 style="font-size:22px; margin:0 0 10px; color:#1e293b;"><?php echo esc_html($test->title); ?></h3>
                <div class="os-exam-verdict" style="font-size:18px; font-weight:600; color:<?php echo $passed ? '#10b981' : '#ef4444'; ?>; margin-bottom:20px;">
                    <?php echo $passed ? olama_exam_translate('acceptance_passed') : olama_exam_translate('acceptance_failed'); ?>
                </div>
                <p class="os-exam-thankyou" style="font-size:15px; color:#64748b; line-height:1.6; border-top:1px solid #f1f5f9; padding-top:20px; margin:0;">
                    <?php echo olama_exam_translate('acceptance_thankyou'); ?>
                </p>
            </div>
        </div>
        <?php
        get_footer();
        exit;
    }

    /**
     * Render simple error page
     */
    private function render_error_page($message)
    {
        $this->enqueue_assets();
        get_header();
        ?>
        <div class="os-exam-acceptance-wrap" dir="rtl" style="max-width: 500px; margin: 100px auto; padding: 20px;">
            <div class="oe-placement-card" style="background:#fff; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.08); padding:32px; text-align:center; border-top:4px solid #ef4444;">
                <div style="font-size:40px; margin-bottom:16px;">⚠️</div>
                <h3 style="font-size:18px; color:#1e293b; margin:0 0 12px;"><?php echo olama_exam_translate('Error'); ?></h3>
                <p style="color:#64748b; font-size:14px; line-height:1.5; margin:0;"><?php echo esc_html($message); ?></p>
            </div>
        </div>
        <?php
        get_footer();
        exit;
    }
}
new OEE_Acceptance_Public();
