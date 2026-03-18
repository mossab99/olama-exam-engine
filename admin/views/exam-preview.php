<?php
/**
 * Admin View: Exam Preview
 * Allows teachers/admins to see the exam in student format with answer keys and edit links.
 */

if (!defined('ABSPATH')) {
    exit;
}

$exam_id = intval($_GET['id'] ?? 0);
$exam = Olama_Exam_Manager::get_exam($exam_id);

if (!$exam) {
    wp_die(olama_exam_translate('Exam not found.'));
}

// Fetch questions (resolving random if needed)
$questions = Olama_Exam_Manager::get_exam_questions($exam_id);

// Enqueue student styles for the preview
wp_enqueue_style('olama-exam-student', OLAMA_EXAM_URL . 'assets/css/exam-student.css', array(), OLAMA_EXAM_VERSION);
wp_enqueue_style('olama-exam-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap', array(), null);

?>

<style>
    .olama-exam-preview-header {
        position: fixed;
        top: 32px; /* WP admin bar height */
        left: 160px; /* Default sidebar width */
        right: 0;
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
        z-index: 1000;
        padding: 12px 20px;
        transition: left 0.2s ease;
    }
    #adminmenuwrap { z-index: 1001; }
    .preview-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        max-width: 1200px;
        margin: 0 auto;
        gap: 20px;
    }
    .preview-info {
        flex: 1;
        min-width: 0;
    }
    .preview-info h2 {
        margin: 4px 0 0;
        font-size: 18px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: #1e293b;
    }
    .preview-badge {
        background: #fef3c7;
        color: #92400e;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        display: inline-block;
    }
    .preview-actions {
        display: flex;
        gap: 10px;
        flex-shrink: 0;
    }
    @media (max-width: 960px) {
        .olama-exam-preview-header { left: 36px; }
    }
    @media (max-width: 782px) {
        .olama-exam-preview-header { top: 46px; left: 0; }
        .preview-container { flex-direction: column; align-items: flex-start; gap: 10px; }
        .preview-actions { width: 100%; justify-content: flex-end; }
    }
    /* Teacher preview specifics */
    .preview-correct {
        border-color: #16a34a !important;
        background: #f0fdf4 !important;
    }
    .preview-correct span { color: #16a34a !important; font-weight: 600; }
    .oe-tf-btn.preview-correct { color: #16a34a !important; font-weight: 600; }
</style>

<div class="olama-exam-preview-header">
    <div class="preview-container">
        <div class="preview-info">
            <span class="preview-badge">👁️ <?php echo olama_exam_translate('Teacher Preview'); ?></span>
            <h2><?php echo esc_html($exam->title); ?></h2>
        </div>
        <div class="preview-actions">
            <a href="?page=olama-exam-create" class="olama-exam-btn olama-exam-btn-outline">
                ← <?php echo olama_exam_translate('Back to List'); ?>
            </a>
            <a href="?page=olama-exam-create&edit=<?php echo $exam->id; ?>" class="olama-exam-btn olama-exam-btn-primary">
                ✏️ <?php echo olama_exam_translate('Edit Exam'); ?>
            </a>
        </div>
    </div>
</div>

<div class="oe-container" dir="auto" style="margin-top: 100px;">
    <div class="oe-header">
        <div class="oe-header-top">
            <div class="oe-header-info">
                <h2><?php echo esc_html($exam->title); ?></h2>
            </div>
            <div class="oe-timer">
                <span class="oe-timer-icon">⏱</span>
                <span><?php echo $exam->duration_minutes; ?>:00</span>
            </div>
        </div>
        <div class="oe-progress">
             <div class="oe-progress-bar" style="width: 0%;"></div>
        </div>
        <div class="oe-progress-text">
            <span>0</span> / <span><?php echo count($questions); ?></span>
            <?php echo olama_exam_translate('answered'); ?>
        </div>
    </div>

    <div class="oe-questions">
        <?php foreach ($questions as $idx => $q): 
            $answers = json_decode($q->answers_json, true) ?: array();
            ?>
            <div class="oe-question-card oe-visible" id="q-<?php echo $q->id; ?>">
                <div class="oe-q-header">
                    <span class="oe-q-number"><?php echo $idx + 1; ?></span>
                    <a href="?page=olama-exam&edit_question=<?php echo $q->id; ?>&grade_id=<?php echo $exam->grade_id; ?>&subject_id=<?php echo $exam->subject_id; ?>" 
                       class="olama-exam-btn olama-exam-btn-outline olama-exam-btn-sm" 
                       style="font-size: 11px; padding: 4px 8px;">
                       ✏️ <?php echo olama_exam_translate('Edit Question'); ?>
                    </a>
                </div>

                <?php if ($q->image_filename): ?>
                    <img class="oe-q-image" src="<?php echo admin_url('admin-ajax.php'); ?>?action=olama_exam_stream_image&file=<?php echo urlencode($q->image_filename); ?>&nonce=<?php echo wp_create_nonce('olama_exam_nonce'); ?>" alt="">
                <?php endif; ?>

                <div class="oe-q-text"><?php echo wp_kses_post($q->question_text); ?></div>

                <div class="preview-answer-area">
                    <?php render_preview_answer_area($q, $answers); ?>
                </div>

                <?php if (!empty($q->explanation)): ?>
                    <div class="oe-review-explanation" style="display: block; margin-top: 20px;">
                        📖 <strong><?php echo olama_exam_translate('Explanation'); ?>:</strong><br>
                        <?php echo wp_kses_post($q->explanation); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
function render_preview_answer_area($q, $answers) {
    switch ($q->type) {
        case 'mcq':
            echo '<div class="oe-choices">';
            $choices = $answers['choices'] ?? array();
            $correct = $answers['correct'] ?? 0;
            foreach ($choices as $i => $choice) {
                $cls = ($i == $correct) ? 'oe-selected preview-correct' : '';
                echo '<div class="oe-choice ' . $cls . '">';
                echo '  <div class="oe-choice-radio"></div>';
                echo '  <span>' . esc_html($choice) . ($i == $correct ? ' ✅' : '') . '</span>';
                echo '</div>';
            }
            echo '</div>';
            break;

        case 'tf':
            $correct = filter_var($answers['correct'] ?? true, FILTER_VALIDATE_BOOLEAN);
            echo '<div class="oe-tf-container">';
            echo '<button type="button" class="oe-tf-btn ' . ($correct ? 'oe-selected-true preview-correct' : '') . '">' . ($correct ? '✅' : '') . ' صح</button>';
            echo '<button type="button" class="oe-tf-btn ' . (!$correct ? 'oe-selected-false preview-correct' : '') . '">' . (!$correct ? '✅' : '') . ' خطأ</button>';
            echo '</div>';
            break;

        case 'short':
            $accepted = (array)($answers['answers'] ?? array());
            echo '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">';
            echo '<strong>' . olama_exam_translate('Accepted Answers') . ':</strong><br>';
            echo '<code style="color:#16a34a;">' . esc_html(implode(', ', $accepted)) . '</code>';
            echo '</div>';
            break;

        case 'matching':
            $pairs = $answers['pairs'] ?? array();
            echo '<div class="oe-matching-wrap">';
            echo '<div class="oe-matching-left" style="width:100%;">';
            foreach ($pairs as $pair) {
                echo '<div class="oe-matching-item" style="justify-content:space-between;">';
                echo '  <span>' . esc_html($pair['left']) . '</span>';
                echo '  <span style="color:#16a34a; font-weight:600;">↔ ' . esc_html($pair['right']) . '</span>';
                echo '</div>';
            }
            echo '</div></div>';
            break;

        case 'ordering':
            $items = $answers['items'] ?? array();
            echo '<div class="oe-ordering-list">';
            foreach ($items as $i => $item) {
                echo '<div class="oe-ordering-item" style="border-color:#bbf7d0; background:#f0fdf4;">';
                echo '  <span class="oe-ordering-num">' . ($i + 1) . '</span>';
                echo '  <span>' . esc_html($item) . '</span>';
                echo '  <span style="margin-inline-start:auto;">✅</span>';
                echo '</div>';
            }
            echo '</div>';
            break;

        case 'fill_blank':
            $correct_answers = (array)($answers['answers'] ?? array());
            $text = $q->question_text;
            $idx = 0;
            $text = preg_replace_callback('/_{3,}/', function($m) use (&$correct_answers, &$idx) {
                $val = $correct_answers[$idx] ?? '???';
                $idx++;
                return '<span style="color:#16a34a; font-weight:600; border-bottom:2px solid #16a34a; padding:0 4px;">' . esc_html($val) . '</span>';
            }, $text);
            echo '<div class="oe-fill-text">' . $text . '</div>';
            break;

        case 'essay':
            echo '<p style="color:#64748b; font-style:italic;">' . olama_exam_translate('Subjective grading by teacher.') . '</p>';
            break;
    }
}
?>
