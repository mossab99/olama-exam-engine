<?php
/**
 * CSV Format Parser
 * Parses CSV files into structured question arrays.
 * Supports all 7 question types with UTF-8 BOM handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Csv_Parser
{
    /** Expected CSV columns */
    const COLUMNS = array('type', 'question', 'answer_data', 'correct', 'difficulty', 'category', 'language');

    /**
     * Parse a CSV file into questions array
     *
     * @param string $file_path
     * @return array ['questions' => [...], 'errors' => [...]]
     */
    public static function parse($file_path)
    {
        $questions = array();
        $errors = array();

        if (!file_exists($file_path)) {
            return array(
                'questions' => array(),
                'errors' => array(
                    array(
                        'line' => 0,
                        'message' => 'File not found.'
                    )
                )
            );
        }

        // Read file content with UTF-8 BOM handling
        $content = file_get_contents($file_path);
        $content = self::remove_bom($content);

        // Write cleaned content to temp file for fgetcsv
        $temp = tempnam(sys_get_temp_dir(), 'exam_csv_');
        file_put_contents($temp, $content);

        $handle = fopen($temp, 'r');
        if (!$handle) {
            @unlink($temp);
            return array(
                'questions' => array(),
                'errors' => array(
                    array(
                        'line' => 0,
                        'message' => 'Could not open CSV file.'
                    )
                )
            );
        }

        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            @unlink($temp);
            return array(
                'questions' => array(),
                'errors' => array(
                    array(
                        'line' => 1,
                        'message' => 'Empty CSV file or invalid format.'
                    )
                )
            );
        }

        // Normalize header
        $header = array_map(function ($h) {
            return strtolower(trim($h));
        }, $header);

        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count($row) < 2 || (count($row) === 1 && empty($row[0]))) {
                continue; // Skip empty rows
            }

            // Map row to header columns
            $data = array();
            foreach ($header as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
            }

            $validated = self::validate_row($data, $line);
            if (is_wp_error($validated)) {
                $errors[] = array(
                    'line' => $line,
                    'message' => $validated->get_error_message(),
                );
                continue;
            }

            $questions[] = $validated;
        }

        fclose($handle);
        @unlink($temp);

        return array('questions' => $questions, 'errors' => $errors);
    }

    /**
     * Remove UTF-8 BOM from string
     */
    private static function remove_bom($content)
    {
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            return substr($content, 3);
        }
        return $content;
    }

    /**
     * Validate and convert a single CSV row into a question structure
     *
     * @param array $row Associative array
     * @param int $line Line number
     * @return array|WP_Error
     */
    public static function validate_row($row, $line)
    {
        $type = strtolower($row['type'] ?? '');
        $question = $row['question'] ?? '';
        $answer_data = $row['answer_data'] ?? '';
        $correct = $row['correct'] ?? '';
        $difficulty = strtolower($row['difficulty'] ?? 'medium');
        $language = $row['language'] ?? 'ar';

        if (empty($type)) {
            return new WP_Error('no_type', "Line {$line}: Missing question type.");
        }

        if (empty($question)) {
            return new WP_Error('no_question', "Line {$line}: Missing question text.");
        }

        $valid_types = array('mcq', 'tf', 'short', 'matching', 'ordering', 'fill_blank', 'essay');
        if (!in_array($type, $valid_types)) {
            return new WP_Error('invalid_type', "Line {$line}: Invalid type '{$type}'. Valid: " . implode(', ', $valid_types));
        }

        if (!in_array($difficulty, array('easy', 'medium', 'hard'))) {
            $difficulty = 'medium';
        }

        // Build answers_json based on type
        $answers_json = self::build_answers_json($type, $answer_data, $correct, $line);
        if (is_wp_error($answers_json)) {
            return $answers_json;
        }

        return array(
            'type' => $type,
            'question_text' => $question,
            'answers_json' => $answers_json,
            'difficulty' => $difficulty,
            'language' => $language,
        );
    }

    /**
     * Build the answers_json string from CSV data
     */
    private static function build_answers_json($type, $answer_data, $correct, $line)
    {
        switch ($type) {
            case 'mcq':
                // answer_data = "choice1|choice2|choice3", correct = "choice2"
                $choices = array_filter(array_map('trim', explode('|', $answer_data)));
                if (count($choices) < 2) {
                    return new WP_Error('few_choices', "Line {$line}: MCQ needs at least 2 choices separated by |");
                }
                $correct_index = array_search(trim($correct), $choices);
                if ($correct_index === false) {
                    return new WP_Error('no_correct', "Line {$line}: Correct answer '{$correct}' not found in choices.");
                }
                return json_encode(array('choices' => array_values($choices), 'correct' => $correct_index), JSON_UNESCAPED_UNICODE);

            case 'tf':
                $val = strtolower(trim($correct));
                if (!in_array($val, array('true', 'false'))) {
                    return new WP_Error('invalid_tf', "Line {$line}: Correct must be 'true' or 'false'.");
                }
                return json_encode(array('correct' => ($val === 'true')), JSON_UNESCAPED_UNICODE);

            case 'short':
                // correct = "answer1|answer2" (multiple accepted answers)
                $answers = array_filter(array_map('trim', explode('|', $correct)));
                if (empty($answers)) {
                    return new WP_Error('no_answers', "Line {$line}: Short answer needs at least one correct answer.");
                }
                return json_encode(array('answers' => $answers), JSON_UNESCAPED_UNICODE);

            case 'matching':
                // answer_data = "Left1:Right1|Left2:Right2"
                $pairs = array();
                $entries = array_filter(array_map('trim', explode('|', $answer_data)));
                foreach ($entries as $entry) {
                    $parts = explode(':', $entry, 2);
                    if (count($parts) === 2) {
                        $pairs[] = array('left' => trim($parts[0]), 'right' => trim($parts[1]));
                    }
                }
                if (count($pairs) < 2) {
                    return new WP_Error('few_pairs', "Line {$line}: Matching needs at least 2 pairs (Left:Right|Left:Right).");
                }
                return json_encode(array('pairs' => $pairs), JSON_UNESCAPED_UNICODE);

            case 'ordering':
                // answer_data = "Item1|Item2|Item3", correct = "2|1|3" (correct order indices)
                $items = array_filter(array_map('trim', explode('|', $answer_data)));
                $order = array_filter(array_map('trim', explode('|', $correct)));
                if (count($items) < 2) {
                    return new WP_Error('few_items', "Line {$line}: Ordering needs at least 2 items.");
                }
                $order = array_map('intval', $order);
                return json_encode(array('items' => $items, 'correct_order' => $order), JSON_UNESCAPED_UNICODE);

            case 'fill_blank':
                // question has ____ placeholders, correct = "answer1|answer2" (one per blank)
                $answers = array_filter(array_map('trim', explode('|', $correct)));
                if (empty($answers)) {
                    return new WP_Error('no_blanks', "Line {$line}: Fill-in-the-blank needs at least one answer.");
                }
                return json_encode(array('answers' => $answers), JSON_UNESCAPED_UNICODE);

            case 'essay':
                // answer_data = "word_limit:300" (optional)
                $word_limit = 0;
                if (preg_match('/word_limit:(\d+)/', $answer_data, $m)) {
                    $word_limit = intval($m[1]);
                }
                return json_encode(array('word_limit' => $word_limit, 'guidelines' => ''), JSON_UNESCAPED_UNICODE);

            default:
                return new WP_Error('unknown_type', "Line {$line}: Unknown type '{$type}'.");
        }
    }

    /**
     * Import parsed questions into the database
     *
     * @param array $parsed Output from parse()
     * @param int   $category_id
     * @return array ['imported' => int, 'skipped' => int, 'errors' => [...]]
     */
    public static function import($parsed, $category_id, $unit_id = 0)
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
                'difficulty' => $q['difficulty'],
                'language' => $q['language'],
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
            'errors' => array_merge(
                array_map(function ($e) {
                    return $e['message']; }, $parsed['errors']),
                $errors
            ),
        );
    }

    /**
     * Get path to the CSV template
     */
    public static function get_template_path()
    {
        return OLAMA_EXAM_PATH . 'templates/questions-csv-template.csv';
    }
}
