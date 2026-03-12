<?php
/**
 * Admin View: Student Preview
 * Mirrors the student exam interface for admins to test the exam flow.
 */

if (!defined('ABSPATH')) {
    exit;
}

$exam_id = intval($_GET['id'] ?? 0);
$exam = Olama_Exam_Manager::get_exam($exam_id);

if (!$exam) {
    wp_die(olama_exam_translate('Exam not found.'));
}

// Enqueue styles
wp_enqueue_style('olama-exam-student', OLAMA_EXAM_URL . 'assets/css/exam-student.css', array(), OLAMA_EXAM_VERSION);
wp_enqueue_style('olama-exam-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap', array(), null);

// Enqueue scripts
wp_enqueue_script('olama-exam-engine', OLAMA_EXAM_URL . 'assets/js/exam-engine.js', array('jquery'), OLAMA_EXAM_VERSION, true);

?>

<style>
    .preview-mode-bar {
        background: #6366f1;
        color: white;
        padding: 8px 16px;
        text-align: center;
        font-weight: 600;
        font-size: 14px;
        position: sticky;
        top: 32px;
        z-index: 1001;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
    }
</style>

<div class="preview-mode-bar">
    <span>🛡️ <?php echo olama_exam_translate('Student Preview Mode (Simulation)'); ?></span>
    <a href="?page=olama-exam-create" class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" style="color:white; border-color:white;">
        <?php echo olama_exam_translate('Exit Preview'); ?>
    </a>
</div>

<div class="oe-container" dir="auto" id="oe-exam-container" 
    data-exam-id="<?php echo $exam_id; ?>"
    data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>"
    data-nonce="<?php echo wp_create_nonce('olama_exam_nonce'); ?>"
    data-is-preview="1">

    <!-- Loading State -->
    <div id="oe-loading" class="oe-loading">
        <div class="oe-spinner"></div>
        <p><?php echo olama_exam_translate('Initializing simulation...'); ?></p>
    </div>

    <!-- Exam Header -->
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
            ✅ <?php echo olama_exam_translate('Submit Preview'); ?>
        </button>
    </div>

    <!-- Results Container -->
    <div id="oe-results" class="oe-results" style="display:none;"></div>
</div>

<!-- Confirmation Modal -->
<div id="oe-confirm-modal" class="oe-modal-overlay" style="display:none;">
    <div class="oe-modal">
        <h3><?php echo olama_exam_translate('Submit Preview?'); ?></h3>
        <p id="oe-confirm-text"></p>
        <div class="oe-modal-actions">
            <button class="oe-btn oe-btn-outline" id="oe-confirm-cancel"><?php echo olama_exam_translate('Cancel'); ?></button>
            <button class="oe-btn oe-btn-primary" id="oe-confirm-ok"><?php echo olama_exam_translate('Submit'); ?></button>
        </div>
    </div>
</div>

