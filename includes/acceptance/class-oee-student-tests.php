<?php
/**
 * CRUD for Student Acceptance Tests
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEE_Student_Tests
{
    public static function get_all($filters = array())
    {
        global $wpdb;
        $where = array('1=1');
        if (!empty($filters['grade_level_id'])) {
            $where[] = $wpdb->prepare('st.grade_level_id = %d', intval($filters['grade_level_id']));
        }
        if (!empty($filters['status'])) {
            $where[] = $wpdb->prepare('st.status = %s', sanitize_text_field($filters['status']));
        }
        $where_sql = implode(' AND ', $where);
        return $wpdb->get_results(
            "SELECT st.*, gl.name_ar AS grade_name_ar
             FROM {$wpdb->prefix}oee_student_tests st
             LEFT JOIN {$wpdb->prefix}oee_grade_levels gl ON gl.id = st.grade_level_id
             WHERE $where_sql
             ORDER BY st.created_at DESC"
        );
    }

    public static function get($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT st.*, gl.name_ar AS grade_name_ar
             FROM {$wpdb->prefix}oee_student_tests st
             LEFT JOIN {$wpdb->prefix}oee_grade_levels gl ON gl.id = st.grade_level_id
             WHERE st.id = %d",
            intval($id)
        ));
    }

    /**
     * Validates token, status active, and expiry not passed.
     */
    public static function get_by_token($token)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT st.*, gl.name_ar AS grade_name_ar
             FROM {$wpdb->prefix}oee_student_tests st
             LEFT JOIN {$wpdb->prefix}oee_grade_levels gl ON gl.id = st.grade_level_id
             WHERE st.public_token = %s
               AND st.status = 'active'
               AND (st.expires_at IS NULL OR st.expires_at > NOW())
             LIMIT 1",
            sanitize_text_field($token)
        ));
    }

    /**
     * Insert or update. Auto-generates public_token on insert.
     */
    public static function save($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'oee_student_tests';
        $id = isset($data['id']) ? intval($data['id']) : 0;

        $fields = array(
            'grade_level_id' => intval($data['grade_level_id'] ?? 0),
            'title'          => sanitize_text_field($data['title'] ?? ''),
            'duration_min'   => intval($data['duration_min'] ?? 60),
            'pass_score_pct' => intval($data['pass_score_pct'] ?? 60),
            'subject_config' => wp_unslash($data['subject_config'] ?? ''),
            'status'         => sanitize_text_field($data['status'] ?? 'active'),
            'expires_at'     => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
        );

        if (empty($fields['title'])) {
            return new WP_Error('empty_title', olama_exam_translate('Title is required.'));
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update test.');
            }
            return $id;
        } else {
            if (empty($data['public_token'])) {
                $fields['public_token'] = wp_generate_password(32, false);
            } else {
                $fields['public_token'] = sanitize_text_field($data['public_token']);
            }
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to insert test.');
            }
            return $wpdb->insert_id;
        }
    }

    public static function delete($id)
    {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'oee_student_tests', array('id' => intval($id)));
    }

    /**
     * Draws random questions grouped by subject from subject_config.
     *
     * @param  array $subject_config  Decoded JSON: [['category_id'=>5,'num_questions'=>10], ...]
     * @return array  Array of ['category_id'=>int, 'category_name_ar'=>string, 'questions'=>array]
     */
    public static function get_questions_by_subject($subject_config, $grade_level_id = 0)
    {
        global $wpdb;
        $grouped = array();

        if (intval($grade_level_id) > 0) {
            // Count total questions requested in the configuration
            $total_questions = 0;
            if (is_array($subject_config)) {
                foreach ($subject_config as $subject) {
                    $total_questions += intval($subject['num_questions'] ?? 0);
                }
            }
            if ($total_questions <= 0) {
                $total_questions = 10; // safety fallback
            }

            $questions = $wpdb->get_results($wpdb->prepare(
                "SELECT q.*
                 FROM {$wpdb->prefix}olama_exam_questions q
                 WHERE q.grade_level_id = %d
                 ORDER BY RAND()
                 LIMIT %d",
                intval($grade_level_id),
                intval($total_questions)
            ));

            $grouped[] = array(
                'category_id'      => 0,
                'category_name_ar' => '',
                'questions'        => $questions,
            );
        } else {
            // Fallback legacy support
            if (is_array($subject_config)) {
                foreach ($subject_config as $subject) {
                    $cat_id = intval($subject['category_id'] ?? 0);
                    $num    = intval($subject['num_questions'] ?? 0);

                    $questions = $wpdb->get_results($wpdb->prepare(
                        "SELECT q.*
                         FROM {$wpdb->prefix}olama_exam_questions q
                         WHERE q.grade_level_id IS NOT NULL
                           AND q.category_id = %d
                         ORDER BY RAND()
                         LIMIT %d",
                        $cat_id,
                        $num
                    ));

                    // Get the category name for section headers
                    $cat_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT name FROM {$wpdb->prefix}olama_exam_question_categories WHERE id = %d LIMIT 1",
                        $cat_id
                    ));

                    $grouped[] = array(
                        'category_id'      => $cat_id,
                        'category_name_ar' => $cat_name ?: '',
                        'questions'        => $questions,
                    );
                }
            }
        }

        return $grouped;
    }
}
