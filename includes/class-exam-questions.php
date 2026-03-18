<?php
/**
 * Exam Questions CRUD
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Questions
{
    /**
     * Get questions with optional filters
     */
    public static function get_questions($filters = array())
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_questions";
        $query = "SELECT q.*, cu.unit_name, cu.unit_number 
                  FROM $table q 
                  LEFT JOIN {$wpdb->prefix}olama_curriculum_units cu ON q.unit_id = cu.id 
                  WHERE 1=1";
        $params = array();

        if (!empty($filters['id'])) {
            $query .= " AND q.id = %d";
            $params[] = intval($filters['id']);
        }
        if (!empty($filters['unit_id'])) {
            $query .= " AND q.unit_id = %d";
            $params[] = intval($filters['unit_id']);
        }
        if (isset($filters['lesson_id']) && $filters['lesson_id'] !== '') {
            $query .= " AND q.lesson_id = %d";
            $params[] = intval($filters['lesson_id']);
        }
        if (!empty($filters['grade_id'])) {
            $query .= " AND cu.grade_id = %d";
            $params[] = intval($filters['grade_id']);
        }
        if (!empty($filters['subject_id'])) {
            $query .= " AND cu.subject_id = %d";
            $params[] = intval($filters['subject_id']);
        }
        // Legacy: still support category_id filter for backward compatibility
        if (!empty($filters['category_id'])) {
            $query .= " AND q.category_id = %d";
            $params[] = intval($filters['category_id']);
        }
        if (!empty($filters['type'])) {
            $query .= " AND q.type = %s";
            $params[] = sanitize_text_field($filters['type']);
        }
        if (!empty($filters['difficulty'])) {
            $query .= " AND q.difficulty = %s";
            $params[] = sanitize_text_field($filters['difficulty']);
        }
        if (!empty($filters['language'])) {
            $query .= " AND q.language = %s";
            $params[] = sanitize_text_field($filters['language']);
        }
        if (!empty($filters['search'])) {
            $query .= " AND q.question_text LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $query .= " ORDER BY q.id DESC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        return $wpdb->get_results($query);
    }

    /**
     * Get a single question by ID
     */
    public static function get_question($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_questions WHERE id = %d",
            intval($id)
        ));
    }

    /**
     * Save (create or update) a question — auto-increments version on update
     */
    public static function save_question($data)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_questions";
        $id = intval($data['id'] ?? 0);

        $fields = array(
            'category_id' => intval($data['category_id'] ?? 0),
            'unit_id' => intval($data['unit_id'] ?? 0),
            'lesson_id' => intval($data['lesson_id'] ?? 0),
            'type' => sanitize_text_field($data['type'] ?? 'mcq'),
            'question_text' => wp_kses_post($data['question_text'] ?? ''),
            'answers_json' => wp_unslash($data['answers_json'] ?? '{}'),
            'difficulty' => sanitize_text_field($data['difficulty'] ?? 'medium'),
            'language' => sanitize_text_field($data['language'] ?? 'ar'),
            'explanation' => sanitize_textarea_field($data['explanation'] ?? ''),
            'image_filename' => sanitize_file_name($data['image_filename'] ?? ''),
            'updated_at' => current_time('mysql'),
        );

        if (empty($fields['question_text'])) {
            return new WP_Error('empty_question', olama_exam_translate('Question text is required.'));
        }

        if ($id > 0) {
            // Update: increment version
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET 
                    category_id = %d, unit_id = %d, lesson_id = %d, type = %s, question_text = %s, answers_json = %s,
                    difficulty = %s, language = %s, explanation = %s, image_filename = %s,
                    version = version + 1, updated_at = %s
                WHERE id = %d",
                $fields['category_id'],
                $fields['unit_id'],
                $fields['lesson_id'],
                $fields['type'],
                $fields['question_text'],
                $fields['answers_json'],
                $fields['difficulty'],
                $fields['language'],
                $fields['explanation'],
                $fields['image_filename'],
                $fields['updated_at'],
                $id
            ));
            return $id;
        } else {
            // Insert
            $fields['version'] = 1;
            $fields['created_at'] = current_time('mysql');
            $wpdb->insert($table, $fields);
            return $wpdb->insert_id;
        }
    }

    /**
     * Delete a question and its image
     */
    public static function delete_question($id)
    {
        global $wpdb;
        $question = self::get_question($id);
        if (!$question) {
            return new WP_Error('not_found', 'Question not found.');
        }

        // Delete associated image
        if (!empty($question->image_filename)) {
            Olama_Exam_Question_Images::delete_image($question->image_filename);
        }

        return $wpdb->delete("{$wpdb->prefix}olama_exam_questions", array('id' => intval($id)));
    }

    /**
     * Duplicate a question (resets version to 1)
     */
    public static function duplicate_question($id)
    {
        $original = self::get_question($id);
        if (!$original) {
            return new WP_Error('not_found', 'Question not found.');
        }

        return self::save_question(array(
            'category_id' => $original->category_id,
            'unit_id' => $original->unit_id,
            'lesson_id' => $original->lesson_id,
            'type' => $original->type,
            'question_text' => $original->question_text . ' (copy)',
            'answers_json' => $original->answers_json,
            'difficulty' => $original->difficulty,
            'language' => $original->language,
            'explanation' => $original->explanation,
            'image_filename' => '', // don't duplicate file
        ));
    }

    /**
     * Bulk delete questions
     */
    public static function bulk_delete($ids)
    {
        if (empty($ids) || !is_array($ids)) {
            return 0;
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $result = self::delete_question(intval($id));
            if (!is_wp_error($result) && $result) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
