<?php
/**
 * Standalone regression checks for math-aware GIFT parsing.
 * Run with: php tests/test-gift-math.php
 */

define('ABSPATH', __DIR__);

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code, $message)
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

require_once dirname(__DIR__) . '/includes/class-exam-gift-parser.php';

function assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$gift = <<<'GIFT'
::fraction:: ما قيمة $\dfrac{1}{2}+\dfrac{1}{2}$؟ {
~ $\dfrac{1}{2}$
= $1$
~ $x=2$
}

::inline:: Choose one {~$x=1$~$x=2$=$x=3$}

::short:: إذا كان \(x=2\)، فما قيمة \(x\)؟ {=\(2\)}
GIFT;

$parsed = Olama_Exam_Gift_Parser::parse($gift);
assert_same(0, count($parsed['errors']), 'Expected the math GIFT fixture to parse without errors.');
assert_same(3, count($parsed['questions']), 'Expected three parsed questions.');

$first = $parsed['questions'][0];
$first_answers = json_decode($first['answers_json'], true);
assert_same('mcq', $first['type'], 'Fraction question should be MCQ.');
assert_same(3, count($first_answers['choices']), 'Fraction question should preserve three choices.');
assert_same(1, $first_answers['correct'], 'Fraction question should preserve the correct marker.');
assert_same('$x=2$', $first_answers['choices'][2], 'An equals sign inside math must not split an answer.');
assert_same(true, strpos($first['question_text'], '\\dfrac{1}{2}') !== false, 'Nested TeX braces must remain in the stem.');

$inline_answers = json_decode($parsed['questions'][1]['answers_json'], true);
assert_same(3, count($inline_answers['choices']), 'Inline GIFT markers should remain supported.');
assert_same('$x=3$', $inline_answers['choices'][2], 'Inline math choices should remain intact.');

$short_answers = json_decode($parsed['questions'][2]['answers_json'], true);
assert_same('short', $parsed['questions'][2]['type'], 'Short answer type should be detected.');
assert_same(array('\\(2\\)'), $short_answers['answers'], 'Short-answer TeX should be preserved.');

echo "GIFT math parser checks passed.\n";
