<?php
/** Run with: php tests/test-tex-import.php [path-to-tex] */
define('ABSPATH', __DIR__);

class WP_Error
{
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

require_once dirname(__DIR__) . '/includes/class-exam-tex-parser.php';

$path = $argv[1] ?? 'C:/Users/Mossab/Downloads/امتحان_المنحة.tex';
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Could not read fixture: {$path}\n");
    exit(1);
}

$parsed = Olama_Exam_Tex_Parser::parse($content);
if (count($parsed['questions']) !== 30 || count($parsed['errors']) !== 0) {
    fwrite(STDERR, 'Expected 30 questions and no parse errors: ' . json_encode($parsed['errors'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$diagram_numbers = array_values(array_map(function ($question) {
    return $question['source_number'];
}, array_filter($parsed['questions'], function ($question) {
    return !empty($question['needs_image']);
})));
if ($diagram_numbers !== array(8, 17)) {
    fwrite(STDERR, 'Expected TikZ flags for questions 8 and 17.\n');
    exit(1);
}

foreach ($parsed['questions'] as $question) {
    $answers = json_decode($question['answers_json'], true);
    if (count($answers['choices'] ?? array()) !== 4 || ($answers['correct'] ?? null) !== -1) {
        fwrite(STDERR, "Invalid choices or assumed answer in question {$question['source_number']}.\n");
        exit(1);
    }
}

$review_errors = Olama_Exam_Tex_Parser::validate_review($parsed);
if (count($review_errors) !== 32) {
    fwrite(STDERR, 'Expected review to require 30 correct answers and 2 diagram acknowledgements.' . "\n");
    exit(1);
}

$review = array();
for ($number = 1; $number <= 30; $number++) {
    $review[$number] = array(
        'correct' => 0,
        'ack_media' => in_array($number, array(8, 17), true),
    );
}
$reviewed = Olama_Exam_Tex_Parser::parse($content, $review);
if (Olama_Exam_Tex_Parser::validate_review($reviewed) !== array()) {
    fwrite(STDERR, 'Expected a complete manual review to unlock the TeX import.' . "\n");
    exit(1);
}

echo "TeX adapter checks passed: 30 questions, 2 TikZ flags, no assumed answers.\n";
