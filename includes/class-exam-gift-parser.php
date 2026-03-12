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

        // Normalize line endings
        $content = str_replace(array("\r\n", "\r"), "\n", $content);

        // Remove comments (lines starting with //)
        $content = preg_replace('/^\/\/.*$/m', '', $content);

        // Split into blocks by blank lines
        $blocks = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

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
                $questions[] = $parsed;
            }
        }

        return array('questions' => $questions, 'errors' => $errors);
    }

    /**
     * Parse a single GIFT block
     *
     * @param string $block
     * @param int $block_num
     * @return array|WP_Error|null
     */
    private static function parse_block($block, $block_num)
    {
        // Extract optional title: ::Title::
        $title = '';
        if (preg_match('/^::(.+?)::\s*(.*)$/s', $block, $m)) {
            $title = trim($m[1]);
            $block = trim($m[2]);
        }

        // Find answer block inside {}
        if (!preg_match('/^(.*?)\{(.*)\}\s*$/s', $block, $m)) {
            return new WP_Error('no_answers', "Block #{$block_num}: No answer block {} found.");
        }

        $question_text = trim($m[1]);
        $answer_block = trim($m[2]);

        if (empty($question_text)) {
            return new WP_Error('empty_question', "Block #{$block_num}: Empty question text.");
        }

        // Detect question type
        $type = self::detect_type($answer_block);

        switch ($type) {
            case 'tf':
                return self::parse_tf($question_text, $answer_block, $title);
            case 'matching':
                return self::parse_matching($question_text, $answer_block, $title);
            case 'short':
                return self::parse_short($question_text, $answer_block, $title);
            case 'mcq':
                return self::parse_mcq($question_text, $answer_block, $title);
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

        // Split by = (correct) and ~ (wrong)
        // Format: =correct ~wrong1 ~wrong2
        preg_match_all('/([=~])([^=~]+)/', $answer_block, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return new WP_Error('no_choices', 'MCQ: No answer choices found.');
        }

        foreach ($matches as $i => $match) {
            $marker = $match[1];
            $text = trim($match[2]);

            // Remove percentage weights like %50%
            $text = preg_replace('/^%\d+%\s*/', '', $text);

            // Remove feedback (starting with #)
            $text = preg_replace('/#.*$/', '', $text);
            $text = trim($text);

            if (empty($text))
                continue;

            $choices[] = $text;
            if ($marker === '=') {
                $correct_index = count($choices) - 1;
            }
        }

        if (empty($choices) || $correct_index === null) {
            return new WP_Error('invalid_mcq', 'MCQ: Need at least one correct and one wrong answer.');
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

        // Extract all =answer entries
        preg_match_all('/=\s*([^=~#\n]+)/', $answer_block, $matches);

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
        preg_match_all('/=\s*(.+?)\s*->\s*(.+?)(?=\s*=|$)/s', $answer_block, $matches, PREG_SET_ORDER);

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
