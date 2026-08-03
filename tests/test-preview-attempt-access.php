<?php
/** Run with: php tests/test-preview-attempt-access.php */

define('ABSPATH', __DIR__);

$test_can_manage = true;

function current_user_can($capability)
{
    global $test_can_manage;
    return $capability === 'manage_options' && $test_can_manage;
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function olama_exam_translate($value)
{
    return $value;
}

function wp_send_json_error($data, $status = 200)
{
    throw new RuntimeException(($data['message'] ?? 'Error') . '|' . $status);
}

require_once dirname(__DIR__) . '/includes/class-exam-ajax.php';

$guard = new ReflectionMethod(Olama_Exam_Ajax::class, 'abort_if_no_attempt_access');

$preview_attempt = (object) array(
    'id' => 17,
    'exam_id' => 409,
    'exam_type' => 'school',
    'student_uid' => '',
    'is_preview' => 1,
);

try {
    $guard->invoke(null, $preview_attempt, '', false, true);
} catch (RuntimeException $error) {
    fwrite(STDERR, 'Authorized preview attempt was rejected: ' . $error->getMessage() . "\n");
    exit(1);
}

try {
    $guard->invoke(null, $preview_attempt, '', false, false);
    fwrite(STDERR, "Non-preview access unexpectedly accepted an empty student UID.\n");
    exit(1);
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Attempt not found.|404') {
        fwrite(STDERR, 'Unexpected guard response: ' . $error->getMessage() . "\n");
        exit(1);
    }
}

echo "Preview attempt access checks passed.\n";

