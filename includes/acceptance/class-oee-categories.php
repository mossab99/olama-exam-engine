<?php
/**
 * CRUD for Question Categories
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEE_Question_Categories
{
    /**
     * Get all categories
     */
    public static function get_all($language = '')
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_question_categories";
        $where = $language ? $wpdb->prepare("WHERE language = %s", $language) : '';
        return $wpdb->get_results(
            "SELECT c.*, s.subject_name 
             FROM $table c
             LEFT JOIN {$wpdb->prefix}olama_subjects s ON c.subject_id = s.id
             $where 
             ORDER BY c.name ASC"
        );
    }

    /**
     * Get single category
     */
    public static function get($id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_question_categories";
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            intval($id)
        ));
    }

    /**
     * Save category (insert or update)
     */
    public static function save($data)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_question_categories";
        $id = isset($data['id']) ? intval($data['id']) : 0;

        $fields = array(
            'name'       => sanitize_text_field($data['name'] ?? ''),
            'subject_id' => intval($data['subject_id'] ?? 0),
            'language'   => sanitize_text_field($data['language'] ?? 'ar'),
        );

        if (empty($fields['name'])) {
            return new WP_Error('empty_name', olama_exam_translate('Category name is required.'));
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update category.');
            }
            return $id;
        } else {
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to insert category.');
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Delete category
     */
    public static function delete($id)
    {
        global $wpdb;
        $id = intval($id);

        // Check if linked to any questions
        $question_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions WHERE category_id = %d",
            $id
        ));
        if ($question_count > 0) {
            return new WP_Error('linked_questions', sprintf(
                olama_exam_translate('Cannot delete: this category has %d question(s).'),
                $question_count
            ));
        }

        $result = $wpdb->delete("{$wpdb->prefix}olama_exam_question_categories", array('id' => $id));
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete category.');
        }
        return true;
    }
}
