<?php
/** Run with: php tests/test-json-import.php */
define('ABSPATH', __DIR__);

class WP_Error
{
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

require_once dirname(__DIR__) . '/includes/class-exam-json-parser.php';

$template = file_get_contents(dirname(__DIR__) . '/templates/questions-oee-template.json');
$parsed = Olama_Exam_Json_Parser::parse($template);
if (count($parsed['errors']) !== 0 || count($parsed['questions']) !== 1) {
    fwrite(STDERR, 'The OEE JSON template did not validate.\n');
    exit(1);
}

$answers = json_decode($parsed['questions'][0]['answers_json'], true);
if (($answers['choices'][0] ?? '') !== '\\(\\dfrac{1}{2}\\)') {
    fwrite(STDERR, "TeX backslashes were not preserved by JSON parsing.\n");
    exit(1);
}

$row = (object) array(
    'id' => 10,
    'type' => 'mcq',
    'question_text' => $parsed['questions'][0]['question_text'],
    'answers_json' => $parsed['questions'][0]['answers_json'],
    'difficulty' => 'easy',
    'language' => 'ar',
    'explanation' => '',
);
$exported = Olama_Exam_Json_Parser::export(array($row));
$round_trip = Olama_Exam_Json_Parser::parse($exported);
if (count($round_trip['errors']) !== 0 || $round_trip['questions'][0]['question_text'] !== $row->question_text) {
    fwrite(STDERR, "OEE JSON export/import round trip failed.\n");
    exit(1);
}

echo "OEE JSON parser round-trip checks passed.\n";
