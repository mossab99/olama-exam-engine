<?php
/**
 * Native Olama Exam question-bank JSON import/export.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Json_Parser
{
    const FORMAT = 'olama-exam-question-bank';
    const VERSION = 1;
    const TYPES = array('mcq', 'tf', 'short', 'matching', 'ordering', 'fill_blank', 'essay');

    public static function parse($content)
    {
        $document = json_decode((string) $content, true);
        if (!is_array($document)) {
            return array(
                'questions' => array(),
                'errors' => array(array('line' => 0, 'message' => 'Invalid JSON: ' . json_last_error_msg())),
                'metadata' => array(),
            );
        }

        $errors = array();
        if (($document['format'] ?? '') !== self::FORMAT) {
            $errors[] = array('line' => 0, 'message' => 'Unsupported JSON format identifier.');
        }
        if (intval($document['version'] ?? 0) !== self::VERSION) {
            $errors[] = array('line' => 0, 'message' => 'Unsupported OEE JSON version.');
        }
        if (!isset($document['questions']) || !is_array($document['questions'])) {
            $errors[] = array('line' => 0, 'message' => 'The JSON file must contain a questions array.');
        }

        $questions = array();
        foreach (($document['questions'] ?? array()) as $index => $question) {
            $validated = self::validate_question($question, $index + 1);
            if (is_wp_error($validated)) {
                $errors[] = array('line' => $index + 1, 'message' => $validated->get_error_message());
            } else {
                $questions[] = $validated;
            }
        }

        return array(
            'questions' => $questions,
            'errors' => $errors,
            'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(),
        );
    }

    private static function validate_question($question, $number)
    {
        if (!is_array($question)) {
            return new WP_Error('invalid_question', "Question {$number} must be an object.");
        }

        $type = sanitize_key($question['type'] ?? '');
        $text = (string) ($question['question'] ?? $question['question_text'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return new WP_Error('invalid_type', "Question {$number} has an unsupported type.");
        }
        if (trim($text) === '') {
            return new WP_Error('empty_question', "Question {$number} has no question text.");
        }

        $answers = self::build_answers($type, $question, $number);
        if (is_wp_error($answers)) {
            return $answers;
        }

        return array(
            'external_id' => sanitize_text_field((string) ($question['external_id'] ?? '')),
            'type' => $type,
            'question_text' => $text,
            'answers_json' => wp_json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'difficulty' => in_array($question['difficulty'] ?? '', array('easy', 'medium', 'hard'), true)
                ? $question['difficulty'] : 'medium',
            'language' => sanitize_text_field((string) ($question['language'] ?? 'ar')),
            'explanation' => (string) ($question['explanation'] ?? ''),
        );
    }

    private static function build_answers($type, $question, $number)
    {
        $legacy = is_array($question['answers'] ?? null) ? $question['answers'] : array();

        switch ($type) {
            case 'mcq':
                $input = $question['choices'] ?? $legacy['choices'] ?? array();
                if (!is_array($input) || count($input) < 2) {
                    return new WP_Error('few_choices', "Question {$number} needs at least two choices.");
                }
                $choices = array();
                $correct = null;
                foreach ($input as $index => $choice) {
                    if (is_array($choice)) {
                        $value = (string) ($choice['text'] ?? '');
                        if (!empty($choice['correct'])) {
                            if ($correct !== null) {
                                return new WP_Error('multiple_correct', "Question {$number} has more than one correct choice.");
                            }
                            $correct = $index;
                        }
                    } else {
                        $value = (string) $choice;
                    }
                    if (trim($value) === '') {
                        return new WP_Error('empty_choice', "Question {$number} contains an empty choice.");
                    }
                    $choices[] = $value;
                }
                if ($correct === null && isset($question['correct'])) {
                    $correct = intval($question['correct']);
                } elseif ($correct === null && isset($legacy['correct'])) {
                    $correct = intval($legacy['correct']);
                }
                if ($correct === null || !isset($choices[$correct])) {
                    return new WP_Error('no_correct', "Question {$number} needs exactly one correct choice.");
                }
                return array('choices' => $choices, 'correct' => $correct);

            case 'tf':
                $correct = $question['correct'] ?? $legacy['correct'] ?? null;
                if (!is_bool($correct)) {
                    return new WP_Error('invalid_tf', "Question {$number} requires a boolean correct value.");
                }
                return array('correct' => $correct);

            case 'short':
            case 'fill_blank':
                $accepted = $question['accepted_answers'] ?? $legacy['answers'] ?? array();
                if (!is_array($accepted) || count(array_filter($accepted, 'strlen')) === 0) {
                    return new WP_Error('no_answers', "Question {$number} requires accepted answers.");
                }
                return array('answers' => array_values(array_map('strval', $accepted)));

            case 'matching':
                $pairs = $question['pairs'] ?? $legacy['pairs'] ?? array();
                if (!is_array($pairs) || count($pairs) < 2) {
                    return new WP_Error('few_pairs', "Question {$number} requires at least two pairs.");
                }
                $clean = array();
                foreach ($pairs as $pair) {
                    if (!is_array($pair) || trim((string) ($pair['left'] ?? '')) === '' || trim((string) ($pair['right'] ?? '')) === '') {
                        return new WP_Error('invalid_pair', "Question {$number} contains an invalid matching pair.");
                    }
                    $clean[] = array('left' => (string) $pair['left'], 'right' => (string) $pair['right']);
                }
                return array('pairs' => $clean);

            case 'ordering':
                $items = $question['items'] ?? $legacy['items'] ?? array();
                if (!is_array($items) || count($items) < 2) {
                    return new WP_Error('few_items', "Question {$number} requires at least two ordered items.");
                }
                return array('items' => array_values(array_map('strval', $items)), 'correct_order' => range(0, count($items) - 1));

            case 'essay':
                return array(
                    'word_limit' => max(0, intval($question['word_limit'] ?? $legacy['word_limit'] ?? 0)),
                    'guidelines' => (string) ($question['guidelines'] ?? $legacy['guidelines'] ?? ''),
                );
        }

        return new WP_Error('invalid_type', "Question {$number} has an unsupported type.");
    }

    public static function import($parsed, $target)
    {
        if (!empty($parsed['errors'])) {
            return array('imported' => 0, 'skipped' => count($parsed['questions']), 'errors' => $parsed['errors']);
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $imported = 0;

        foreach ($parsed['questions'] as $question) {
            $data = array_merge($question, array(
                'category_id' => intval($target['category_id'] ?? 0),
                'unit_id' => intval($target['unit_id'] ?? 0),
                'lesson_id' => intval($target['lesson_id'] ?? 0),
                'profession_id' => intval($target['profession_id'] ?? 0) ?: null,
                'grade_level_id' => intval($target['grade_level_id'] ?? 0) ?: null,
            ));

            if (!empty($data['profession_id']) || !empty($data['grade_level_id'])) {
                $data['unit_id'] = 0;
                $data['lesson_id'] = 0;
            }

            $result = Olama_Exam_Questions::save_question($data);
            if (is_wp_error($result) || intval($result) <= 0) {
                $wpdb->query('ROLLBACK');
                $message = is_wp_error($result) ? $result->get_error_message() : 'A question could not be saved.';
                return array('imported' => 0, 'skipped' => count($parsed['questions']), 'errors' => array($message));
            }
            $imported++;
        }

        $wpdb->query('COMMIT');
        return array('imported' => $imported, 'skipped' => 0, 'errors' => array());
    }

    public static function export($questions, $metadata = array())
    {
        $output = array(
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'metadata' => array_merge(array('exported_at' => gmdate('c')), $metadata),
            'questions' => array(),
        );

        foreach ($questions as $question) {
            $answers = json_decode($question->answers_json, true) ?: array();
            $item = array(
                'external_id' => 'oee-' . intval($question->id),
                'type' => $question->type,
                'question' => $question->question_text,
                'difficulty' => $question->difficulty,
                'language' => $question->language,
                'explanation' => $question->explanation ?: '',
            );

            switch ($question->type) {
                case 'mcq':
                    $item['choices'] = array();
                    foreach (($answers['choices'] ?? array()) as $index => $choice) {
                        $item['choices'][] = array('id' => chr(97 + $index), 'text' => $choice, 'correct' => $index === intval($answers['correct'] ?? -1));
                    }
                    break;
                case 'tf':
                    $item['correct'] = (bool) ($answers['correct'] ?? false);
                    break;
                case 'short':
                case 'fill_blank':
                    $item['accepted_answers'] = $answers['answers'] ?? array();
                    break;
                case 'matching':
                    $item['pairs'] = $answers['pairs'] ?? array();
                    break;
                case 'ordering':
                    $item['items'] = $answers['items'] ?? array();
                    break;
                case 'essay':
                    $item['word_limit'] = intval($answers['word_limit'] ?? 0);
                    $item['guidelines'] = (string) ($answers['guidelines'] ?? '');
                    break;
            }
            $output['questions'][] = $item;
        }

        return wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
