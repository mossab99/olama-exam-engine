<?php
/**
 * GIFT Format Parser
 * Parses Moodle GIFT formatted text into structured question arrays.
 * Supports: MCQ, True/False, Short Answer, Matching
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Gift_Parser
{
    /**
     * Parse GIFT content into an array of questions
     *
     * @param string $content Raw GIFT text
     * @return array ['questions' => [...], 'errors' => [...]]
     */
    public static function parse($content)
    {
        $questions = array();
        $errors = array();

        // 1. Preprocessing: Strip markdown code blocks if they wrap the content
        if (preg_match('/```(?:gift)?\s*(.*?)\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Normalize line endings
        $content = str_replace(array("\r\n", "\r"), "\n", $content);

        // Remove comments (lines starting with //)
        $content = preg_replace('/^\/\/.*$/m', '', $content);

        // 2. Split into blocks by blank lines
        // Use a more robust split that handles spaces on blank lines
        $blocks = preg_split('/\n[ \t]*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($blocks as $index => $block) {
            $block = trim($block);
            if (empty($block))
                continue;

            $parsed = self::parse_block($block, $index + 1);
            if (is_wp_error($parsed)) {
                $errors[] = array(
                    'line' => $index + 1,
                    'block' => mb_substr($block, 0, 80) . '...',
                    'message' => $parsed->get_error_message(),
                );
            } elseif ($parsed) {
                if (is_array($parsed) && isset($parsed[0])) {
                    // Handle multiple questions derived from one block (though rare in GIFT)
                    $questions = array_merge($questions, $parsed);
                } else {
                    $questions[] = $parsed;
                }
            }
        }

        return array('questions' => $questions, 'errors' => $errors);
    }

    /**
     * Parse a single GIFT block
     */
    private static function parse_block($block, $block_num)
    {
        // 1. Extract optional title: ::Title::
        $title = '';
        if (preg_match('/^::(.+?)::\s*(.*)$/su', $block, $m)) {
            $title = trim($m[1]);
            $block = trim($m[2]);
        }

        // 2. Find answer block inside {}
        // Use a more flexible regex to find the first { and last }
        $first_brace = mb_strpos($block, '{');
        $last_brace = mb_strrpos($block, '}');

        if ($first_brace === false || $last_brace === false || $last_brace < $first_brace) {
            // Check if it might be a description (GIFT allows blocks without {})
            // But for our engine, we expect questions. 
            // If No {} found, we could treat it as a comment or error.
            return new WP_Error('no_answers', "Block #{$block_num}: No answer block {} found.");
        }

        $before_text = mb_substr($block, 0, $first_brace);
        $answer_content = mb_substr($block, $first_brace + 1, $last_brace - $first_brace - 1);
        $after_text = mb_substr($block, $last_brace + 1);

        // Normalize question text: if there's text after braces, it's a fill_blank (cloze)
        $is_cloze = (trim($before_text) !== '' && trim($after_text) !== '');
        
        if ($is_cloze) {
            $question_text = trim($before_text) . ' ____ ' . trim($after_text);
        } else {
            $question_text = trim($before_text . ' ' . $after_text);
        }

        if (empty($question_text)) {
            $question_text = "[$block_num]"; 
        }

        $answer_content = trim($answer_content);

        // 3. Detect question type
        $type = self::detect_type($answer_content);

        // Special case: Essay
        if (empty($answer_content)) {
            $type = 'essay';
        }

        switch ($type) {
            case 'tf':
                return self::parse_tf($question_text, $answer_content, $title);
            case 'matching':
                return self::parse_matching($question_text, $answer_content, $title);
            case 'short':
                $res = self::parse_short($question_text, $answer_content, $title);
                if ($is_cloze && !is_wp_error($res)) {
                    $res['type'] = 'fill_blank';
                }
                return $res;
            case 'mcq':
                $res = self::parse_mcq($question_text, $answer_content, $title);
                if ($is_cloze && !is_wp_error($res)) {
                    // Convert MCQ in the middle of text to fill_blank but with choices
                    $res['type'] = 'fill_blank';
                }
                return $res;
            case 'essay':
                return array(
                    'type' => 'essay',
                    'question_text' => $question_text,
                    'title' => $title,
                    'answers_json' => json_encode(array('word_limit' => 300), JSON_UNESCAPED_UNICODE),
                );
            default:
                return new WP_Error('unknown_type', "Block #{$block_num}: Could not determine question type.");
        }
    }

    /**
     * Detect question type from answer block
     */
    private static function detect_type($answer_block)
    {
        $trimmed = strtoupper(trim($answer_block));

        // True/False: {TRUE}, {FALSE}, {T}, {F}
        if (in_array($trimmed, array('TRUE', 'FALSE', 'T', 'F'))) {
            return 'tf';
        }

        // Matching: contains -> arrows
        if (preg_match('/=.*->/', $answer_block)) {
            return 'matching';
        }

        // MCQ: contains ~ (wrong answers)
        if (strpos($answer_block, '~') !== false) {
            return 'mcq';
        }

        // Short answer: only = signs (correct answers)
        if (preg_match('/^=/', $answer_block)) {
            return 'short';
        }

        return 'unknown';
    }

    /**
     * Parse True/False question
     */
    private static function parse_tf($question_text, $answer_block, $title)
    {
        $correct = in_array(strtoupper(trim($answer_block)), array('TRUE', 'T'));

        return array(
            'type' => 'tf',
            'question_text' => $question_text,
            'title' => $title,
            'answers_json' => json_encode(array('correct' => $correct), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Parse MCQ question
     */
    private static function parse_mcq($question_text, $answer_block, $title)
    {
        $choices = array();
        $correct_index = null;

        // Extract all entries. We use a regex that looks for strings delimited by markers or start/end.
        // First, normalize the block to handle cases where markers are at the end (RTL export issues)
        // If we find markers at the end of lines, we logically move them to the start.
        $lines = explode("\n", $answer_block);
        $normalized_lines = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            // If line ends with = or ~, move it to the front
            if (preg_match('/^(.*?)([=~])$/u', $line, $m)) {
                $line = $m[2] . $m[1];
            }
            $normalized_lines[] = $line;
        }
        $answer_block = implode("\n", $normalized_lines);

        // Now parse standard way
        preg_match_all('/([=~])\s*([^=~]+)/u', $answer_block, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return new WP_Error('no_choices', 'MCQ: No answer choices found after normalization.');
        }

        foreach ($matches as $match) {
            $marker = $match[1];
            $text = trim($match[2]);

            // Remove percentage weights like %100%
            $text = preg_replace('/^%\d+%\s*/u', '', $text);

            // Remove feedback (starting with #)
            $text = preg_replace('/#.*$/u', '', $text);
            $text = trim($text);

            if (empty($text))
                continue;

            $choices[] = $text;
            if ($marker === '=') {
                $correct_index = count($choices) - 1;
            }
        }

        if (empty($choices)) {
            return new WP_Error('invalid_mcq', 'MCQ: No choices extracted.');
        }

        if ($correct_index === null) {
             return new WP_Error('no_correct', 'MCQ: No correct answer marked with = found.');
        }

        return array(
            'type' => 'mcq',
            'question_text' => $question_text,
            'title' => $title,
            'answers_json' => json_encode(array(
                'choices' => $choices,
                'correct' => $correct_index,
            ), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Parse Short Answer question
     */
    private static function parse_short($question_text, $answer_block, $title)
    {
        $answers = array();

        // Extract all entries. Support normalization like MCQ for RTL confusion
        $lines = explode("\n", $answer_block);
        $normalized_lines = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (preg_match('/^(.*?)([=])$/u', $line, $m)) {
                $line = $m[2] . $m[1];
            }
            $normalized_lines[] = $line;
        }
        $answer_block = implode("\n", $normalized_lines);

        preg_match_all('/=\s*([^=~#\n]+)/u', $answer_block, $matches);

        foreach ($matches[1] as $ans) {
            $ans = trim($ans);
            if (!empty($ans)) {
                $answers[] = $ans;
            }
        }

        if (empty($answers)) {
            return new WP_Error('no_answers', 'Short Answer: No correct answers found.');
        }

        return array(
            'type' => 'short',
            'question_text' => $question_text,
            'title' => $title,
            'answers_json' => json_encode(array(
                'answers' => $answers,
            ), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Parse Matching question
     */
    private static function parse_matching($question_text, $answer_block, $title)
    {
        $pairs = array();

        // Extract =left -> right pairs
        preg_match_all('/=\s*(.+?)\s*->\s*(.+?)(?=\s*=|$)/su', $answer_block, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $left = trim($match[1]);
            $right = trim($match[2]);
            if (!empty($left) && !empty($right)) {
                $pairs[] = array('left' => $left, 'right' => $right);
            }
        }

        if (count($pairs) < 2) {
            return new WP_Error('few_pairs', 'Matching: Need at least 2 pairs.');
        }

        return array(
            'type' => 'matching',
            'question_text' => $question_text,
            'title' => $title,
            'answers_json' => json_encode(array(
                'pairs' => $pairs,
            ), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Import parsed questions into the database
     *
     * @param array  $parsed     Output from parse()
     * @param int    $category_id
     * @param string $language
     * @param string $difficulty
     * @return array ['imported' => int, 'skipped' => int, 'errors' => [...]]
     */
    public static function import($parsed, $category_id, $language = 'ar', $difficulty = 'medium', $unit_id = 0)
    {
        $imported = 0;
        $skipped = 0;
        $errors = array();

        foreach ($parsed['questions'] as $q) {
            $result = Olama_Exam_Questions::save_question(array(
                'category_id' => $category_id,
                'unit_id' => $unit_id,
                'type' => $q['type'],
                'question_text' => $q['question_text'],
                'answers_json' => $q['answers_json'],
                'difficulty' => $difficulty,
                'language' => $language,
            ));

            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
                $skipped++;
            } else {
                $imported++;
            }
        }

        return array(
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_merge($parsed['errors'], $errors),
        );
    }
}
