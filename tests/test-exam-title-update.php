<?php
/** Run with: php tests/test-exam-title-update.php */

define('ABSPATH', __DIR__);

class WP_Error
{
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function olama_exam_translate($value) { return $value; }
function olama_exam_log($value) {}

class Fake_Exam_Title_Wpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $exam_exists = true;
    public $update_args = null;

    public function prepare($query, ...$args) { return $query; }
    public function get_row($query) { return $this->exam_exists ? (object) array('id' => 7) : null; }
    public function update($table, $data, $where, $formats, $where_formats)
    {
        $this->update_args = compact('table', 'data', 'where', 'formats', 'where_formats');
        return 1;
    }
}

$wpdb = new Fake_Exam_Title_Wpdb();
require_once dirname(__DIR__) . '/includes/class-exam-manager.php';

$result = Olama_Exam_Manager::update_title(7, '  New <b>Exam</b> Name  ');
if ($result !== 'New Exam Name') {
    fwrite(STDERR, "The renamed title was not sanitized.\n");
    exit(1);
}
if ($wpdb->update_args['data'] !== array('title' => 'New Exam Name') ||
    $wpdb->update_args['where'] !== array('id' => 7)) {
    fwrite(STDERR, "The rename changed fields other than the exam title.\n");
    exit(1);
}

$empty = Olama_Exam_Manager::update_title(7, '   ');
if (!($empty instanceof WP_Error) || $empty->get_error_message() !== 'Exam title is required.') {
    fwrite(STDERR, "An empty exam title was accepted.\n");
    exit(1);
}

$wpdb->exam_exists = false;
$missing = Olama_Exam_Manager::update_title(999, 'Missing exam');
if (!($missing instanceof WP_Error) || $missing->get_error_message() !== 'Exam not found.') {
    fwrite(STDERR, "A missing exam was not rejected.\n");
    exit(1);
}

echo "Exam title update checks passed.\n";
