<?php
/**
 * Constrained LaTeX exam adapter.
 *
 * This intentionally supports the Olama scholarship-exam pattern rather than
 * attempting to execute or fully interpret arbitrary TeX documents.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Tex_Parser
{
    public static function parse($content, $review = array())
    {
        $content = self::remove_comments(str_replace(array("\r\n", "\r"), "\n", (string) $content));
        if (strlen($content) > 2 * 1024 * 1024) {
            return array('questions' => array(), 'errors' => array(array('line' => 0, 'message' => 'The TeX file exceeds 2 MB.')));
        }

        if (preg_match('/\\\\begin\{document\}(.*?)\\\\end\{document\}/su', $content, $document_match)) {
            $content = $document_match[1];
        }

        preg_match_all('/\\\\noindent\s*\\\\textbf\{\((\d+)\}/u', $content, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) {
            return array('questions' => array(), 'errors' => array(array('line' => 0, 'message' => 'No supported numbered questions were found.')));
        }

        $questions = array();
        $errors = array();
        $count = count($matches[0]);

        for ($index = 0; $index < $count; $index++) {
            $number = intval($matches[1][$index][0]);
            $start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($content);
            $block = trim(substr($content, $start, $end - $start));

            $parsed = self::parse_question($block, $number, $review[$number] ?? $review[(string) $number] ?? array());
            if (is_wp_error($parsed)) {
                $errors[] = array('line' => $number, 'message' => $parsed->get_error_message());
            } else {
                $questions[] = $parsed;
            }
        }

        return array('questions' => $questions, 'errors' => $errors, 'metadata' => array('source' => 'tex'));
    }

    private static function parse_question($block, $number, $review)
    {
        $choice_position = strpos($block, '\\choices');
        $choices = array();

        if ($choice_position !== false) {
            $choices = self::parse_choice_macro(substr($block, $choice_position));
        } else {
            $tabular_position = strpos($block, '\\begin{tabularx}');
            if ($tabular_position !== false) {
                $choice_position = $tabular_position;
                $choices = self::parse_tabular_choices(substr($block, $tabular_position));
            }
        }

        if (count($choices) !== 4) {
            return new WP_Error('unsupported_choices', "Question {$number}: expected exactly four supported choices.");
        }

        $stem = substr($block, 0, $choice_position);
        $needs_image = strpos($stem, '\\begin{tikzpicture}') !== false;
        $stem = preg_replace('/\\\\begin\{center\}.*?\\\\begin\{tikzpicture\}.*?\\\\end\{tikzpicture\}.*?\\\\end\{center\}/su', '', $stem);
        $stem = preg_replace('/\\\\begin\{center\}|\\\\end\{center\}/u', '', $stem);
        $stem = preg_replace('/\\\\vspace\*?\{[^}]*\}|\\\\newpage/u', '', $stem);
        $stem = preg_replace('/\\\\\\\\(?:\[[^\]]*\])?/u', ' ', $stem);
        $stem = trim(preg_replace('/\s+/u', ' ', $stem));
        $stem = self::normalize_inline_math($stem);

        $normalized_choices = array();
        foreach ($choices as $choice) {
            $choice = trim($choice);
            if (preg_match('/^\$(.*)\$$/su', $choice, $math)) {
                $choice = '\\(' . trim($math[1]) . '\\)';
            } elseif (strpos($choice, '\\(') !== 0) {
                $choice = '\\(' . $choice . '\\)';
            }
            $normalized_choices[] = $choice;
        }

        $correct = isset($review['correct']) ? intval($review['correct']) : -1;
        if ($correct < 0 || $correct > 3) {
            $correct = -1;
        }

        return array(
            'source_number' => $number,
            'type' => 'mcq',
            'question_text' => $stem,
            'answers_json' => wp_json_encode(array('choices' => $normalized_choices, 'correct' => $correct), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'difficulty' => 'medium',
            'language' => 'ar',
            'explanation' => '',
            'needs_correct' => $correct < 0,
            'needs_image' => $needs_image,
            'media_acknowledged' => !empty($review['ack_media']),
        );
    }

    private static function parse_choice_macro($text)
    {
        $position = strpos($text, '\\choices');
        if ($position === false) {
            return array();
        }
        $position += strlen('\\choices');
        $choices = array();

        for ($i = 0; $i < 4; $i++) {
            while (isset($text[$position]) && preg_match('/\s/u', $text[$position])) {
                $position++;
            }
            $argument = self::read_braced_argument($text, $position);
            if ($argument === null) {
                return array();
            }
            $choices[] = $argument['content'];
            $position = $argument['end'] + 1;
        }

        return $choices;
    }

    private static function parse_tabular_choices($text)
    {
        if (!preg_match('/\\\\begin\{tabularx\}\{\\\\textwidth\}\{[^}]+\}(.*?)\\\\end\{tabularx\}/su', $text, $match)) {
            return array();
        }

        $body = $match[1];
        preg_match_all('/\\\\textbf\{[a-d]\)\}\s*(.*?)(?=\s*&|\s*\\\\\\\\(?:\[[^\]]*\])?|$)/su', $body, $choice_matches);
        return array_map('trim', $choice_matches[1] ?? array());
    }

    private static function read_braced_argument($text, $position)
    {
        if (!isset($text[$position]) || $text[$position] !== '{') {
            return null;
        }

        $depth = 1;
        $length = strlen($text);
        for ($i = $position + 1; $i < $length; $i++) {
            if (self::is_escaped($text, $i)) {
                continue;
            }
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return array('content' => substr($text, $position + 1, $i - $position - 1), 'end' => $i);
                }
            }
        }
        return null;
    }

    private static function normalize_inline_math($text)
    {
        return preg_replace_callback('/(?<!\\\\)\$(?!\$)(.*?)(?<!\\\\)\$/su', function ($match) {
            return '\\(' . trim($match[1]) . '\\)';
        }, $text);
    }

    private static function remove_comments($content)
    {
        return preg_replace('/(?<!\\\\)%.*$/mu', '', $content);
    }

    private static function is_escaped($text, $position)
    {
        $slashes = 0;
        for ($i = $position - 1; $i >= 0 && $text[$i] === '\\'; $i--) {
            $slashes++;
        }
        return $slashes % 2 === 1;
    }

    public static function validate_review($parsed)
    {
        $errors = $parsed['errors'];
        foreach ($parsed['questions'] as $question) {
            if (!empty($question['needs_correct'])) {
                $errors[] = array('line' => $question['source_number'], 'message' => "Question {$question['source_number']} needs a correct answer.");
            }
            if (!empty($question['needs_image']) && empty($question['media_acknowledged'])) {
                $errors[] = array('line' => $question['source_number'], 'message' => "Question {$question['source_number']} contains TikZ; acknowledge that its image must be attached after import.");
            }
        }
        return $errors;
    }

    public static function import($parsed, $target)
    {
        $errors = self::validate_review($parsed);
        if (!empty($errors)) {
            return array('imported' => 0, 'skipped' => count($parsed['questions']), 'errors' => $errors);
        }

        foreach ($parsed['questions'] as &$question) {
            unset($question['source_number'], $question['needs_correct'], $question['needs_image'], $question['media_acknowledged']);
        }
        unset($question);
        $parsed['errors'] = array();

        return Olama_Exam_Json_Parser::import($parsed, $target);
    }
}
