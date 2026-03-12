<?php
/**
 * Question Image Handler
 * Manages image uploads for exam questions in a dedicated secure directory.
 * Follows the same pattern as Olama_School_Exam_Attachment.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Question_Images
{
    /** Allowed image extensions */
    const ALLOWED_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg');

    /** Max file size in bytes (5MB) */
    const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Get the base directory for question image uploads based on OS
     */
    public static function get_upload_base_dir()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: outside the public/ folder
            $public_dir = wp_normalize_path(untrailingslashit(ABSPATH));
            $app_dir = dirname($public_dir);
            $site_dir = dirname($app_dir);
            $base = $site_dir . '/olama_question_images/';
        } else {
            // Linux (production VPS)
            $base = '/srv/exams/question_images/';
        }

        return wp_normalize_path($base);
    }

    /**
     * Ensure the upload directory exists with security files
     */
    public static function ensure_dir_exists()
    {
        $dir = self::get_upload_base_dir();
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error(
                    'dir_creation_failed',
                    sprintf('Could not create question images directory: %s', $dir)
                );
            }
            // Security: prevent direct access
            @file_put_contents($dir . 'index.php', '<?php // Silence is golden');
            @file_put_contents($dir . '.htaccess', "Order deny,allow\nDeny from all");
        }

        if (!is_writable($dir)) {
            return new WP_Error(
                'dir_not_writable',
                sprintf('Question images directory is not writable: %s', $dir)
            );
        }

        return $dir;
    }

    /**
     * Validate an uploaded image file
     */
    public static function validate_file($file)
    {
        // Check for upload errors
        if (!empty($file['error'])) {
            return new WP_Error('upload_error', 'File upload error: ' . $file['error']);
        }

        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS)) {
            return new WP_Error(
                'invalid_extension',
                'Allowed image types: ' . implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }

        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new WP_Error(
                'file_too_large',
                'Maximum image size is 5MB.'
            );
        }

        // Verify it's actually an image
        $image_info = @getimagesize($file['tmp_name']);
        if ($image_info === false && $ext !== 'svg') {
            return new WP_Error('not_an_image', 'The uploaded file is not a valid image.');
        }

        return true;
    }

    /**
     * Handle image upload for a question
     *
     * @param array $file The $_FILES entry
     * @return string|WP_Error The stored filename on success
     */
    public static function handle_upload($file)
    {
        $validation = self::validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $upload_dir = self::ensure_dir_exists();
        if (is_wp_error($upload_dir)) {
            return $upload_dir;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $stored_filename = 'q_' . wp_generate_uuid4() . '.' . $ext;
        $destination = $upload_dir . $stored_filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return $stored_filename;
        }

        return new WP_Error('upload_failed', 'Failed to move uploaded image file.');
    }

    /**
     * Delete a question image file
     *
     * @param string $filename The stored filename
     * @return bool
     */
    public static function delete_image($filename)
    {
        if (empty($filename)) {
            return true;
        }

        $filepath = self::get_upload_base_dir() . $filename;
        if (file_exists($filepath)) {
            return @unlink($filepath);
        }

        return true;
    }

    /**
     * Stream an image file securely via PHP
     * Use this as an AJAX or URL handler for serving question images.
     *
     * @param string $filename The stored filename
     */
    public static function stream_image($filename)
    {
        if (empty($filename)) {
            wp_die('No image specified.');
        }

        // Sanitize to prevent directory traversal
        $filename = basename($filename);
        $filepath = self::get_upload_base_dir() . $filename;

        if (!file_exists($filepath)) {
            wp_die('Image not found.');
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime_types = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        );

        $mime = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';

        // Clean output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: public, max-age=86400'); // Cache for 24h
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

        readfile($filepath);
        exit;
    }

    /**
     * Get the URL for streaming an image via AJAX
     *
     * @param string $filename The stored filename
     * @return string The URL to stream the image
     */
    public static function get_image_url($filename)
    {
        if (empty($filename)) {
            return '';
        }
        return admin_url('admin-ajax.php?action=olama_exam_stream_image&file=' . urlencode($filename));
    }
}
