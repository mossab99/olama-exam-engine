<?php
/** Run with: php tests/test-question-math-roundtrip.php */
define('ABSPATH', __DIR__);

class WP_Error
{
    public function __construct($code, $message) {}
}
class Fake_WPDB
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_fields = array();
    public function insert($table, $fields) {
        $this->last_fields = $fields;
        $this->insert_id = 42;
        return true;
    }
}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_kses_post($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function sanitize_file_name($value) { return (string) $value; }
function current_time($type) { return '2026-08-03 00:00:00'; }
function olama_exam_translate($value) { return $value; }

$wpdb = new Fake_WPDB();
require_once dirname(__DIR__) . '/includes/class-exam-questions.php';

$question = 'احسب \\(\\dfrac{1}{2}+\\dfrac{1}{2}\\)';
$answers = json_encode(array(
    'choices' => array('\\(\\dfrac{1}{2}\\)', '\\(1\\)'),
    'correct' => 1,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$result = Olama_Exam_Questions::save_question(array(
    'question_text' => $question,
    'type' => 'mcq',
    'answers_json' => $answers,
));
$stored_answers = json_decode($wpdb->last_fields['answers_json'], true);

if ($result !== 42 || $wpdb->last_fields['question_text'] !== $question || $stored_answers['choices'][0] !== '\\(\\dfrac{1}{2}\\)') {
    fwrite(STDERR, "Question math source did not survive the storage boundary.\n");
    exit(1);
}

echo "Question math storage round-trip checks passed.\n";
