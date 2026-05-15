<?php

if (!defined('ABSPATH')) {
    exit;
}

class Export_To_Word {
    /**
     * Flag to prevent infinite loop when applying 'the_content' filter.
     *
     * @var bool
     */
    private $is_exporting = false;

    public function run() {
        add_filter('the_content', array($this, 'add_export_button'));
        add_action('template_redirect', array($this, 'handle_export'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_export_button($content) {
        // Don't add the button if we're currently generating the export or if it's not a single post
        if ($this->is_exporting || !is_single() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $nonce = wp_create_nonce('etw_export_nonce');
        $button = sprintf(
            '<form method="post" class="export-to-word-form">
                <input type="hidden" name="etw_export" value="1">
                <input type="hidden" name="etw_nonce" value="%s">
                <button type="submit" class="export-to-word-button">%s</button>
            </form>',
            esc_attr($nonce),
            esc_html__('Export to Word', 'export-to-word')
        );
        return $content . $button;
    }

    public function handle_export() {
        if (isset($_POST['etw_export']) && isset($_POST['etw_nonce']) && is_single()) {
            if (!wp_verify_nonce($_POST['etw_nonce'], 'etw_export_nonce')) {
                wp_die(__('Security check failed', 'export-to-word'));
            }

            if (!current_user_can('read')) {
                wp_die(__('You do not have permission to export this post', 'export-to-word'));
            }

            $post_id = get_the_ID();
            if (!$post_id) {
                global $post;
                $post_id = isset($post->ID) ? $post->ID : 0;
            }

            if (!$post_id) {
                wp_die(__('Invalid post ID', 'export-to-word'));
            }

            try {
                $this->export_post_to_word($post_id);
            } catch (Exception $e) {
                wp_die(sprintf(__('Export failed: %s', 'export-to-word'), $e->getMessage()));
            }
        }
    }

    private function export_post_to_word($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(__('Post not found', 'export-to-word'));
        }

        // Set exporting flag to true to prevent the export button from being added to the content
        $this->is_exporting = true;

        if (!class_exists('\PhpOffice\PhpWord\PhpWord')) {
            throw new Exception(__('PHPWord library not found. Please run composer install.', 'export-to-word'));
        }

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();

        // Add the post title
        $section->addText($post->post_title, array('bold' => true, 'size' => 16));

        // Add the post content
        $html = apply_filters('the_content', $post->post_content);

        // Reset exporting flag
        $this->is_exporting = false;

        // Clean up the HTML
        $html = preg_replace('/<div class="rp4wp-related-posts">.*?<\/div>/s', '', $html);

        // Ensure HTML is UTF-8 encoded for PHPWord
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        // Allow developers to modify the HTML before export
        $html = apply_filters('etw_export_html', $html, $post);

        \PhpOffice\PhpWord\Shared\Html::addHtml($section, $html, false, false);

        // Add footer
        $footer = $section->addFooter();
        $footer_text = get_option('etw_footer_text', 'CAPS 123 | caps123.co.za');
        $footer_text = apply_filters('etw_export_footer_text', $footer_text);
        $footer->addText($footer_text, array('size' => 10));

        // Clear any previous output buffers to avoid file corruption
        if (ob_get_length()) {
            ob_clean();
        }

        // Check if headers are already sent
        if (headers_sent($file, $line)) {
            throw new Exception(sprintf(__('Headers already sent in %s on line %d. Cannot download document.', 'export-to-word'), $file, $line));
        }

        // Generate the Word document
        try {
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        } catch (Exception $e) {
            throw new Exception(sprintf(__('Failed to create Word writer: %s', 'export-to-word'), $e->getMessage()));
        }

        // Set the appropriate headers for download
        $filename = sanitize_file_name($post->post_title) . '.docx';
        if (empty($filename)) {
            $filename = 'post-' . $post_id . '.docx';
        }
        header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header("Cache-Control: max-age=0");
        header("Pragma: public");

        // Output the file
        try {
            $writer->save("php://output");
        } catch (Exception $e) {
            // Since we've already sent headers, we can't wp_die gracefully here with a pretty page,
            // but we can try to log it or at least terminate.
            error_log(sprintf('Export to Word failed during save: %s', $e->getMessage()));
            exit;
        }
        exit;
    }

    public function enqueue_scripts() {
        if (is_single()) {
            wp_enqueue_style('export-to-word-style', ETW_PLUGIN_URL . 'css/export-to-word.css', array(), ETW_VERSION);
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('Export to Word Settings', 'export-to-word'),
            __('Export to Word', 'export-to-word'),
            'manage_options',
            'export-to-word',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('etw_settings_group', 'etw_footer_text');
        add_settings_section(
            'etw_main_section',
            __('Main Settings', 'export-to-word'),
            null,
            'export-to-word'
        );
        add_settings_field(
            'etw_footer_text',
            __('Footer Text', 'export-to-word'),
            array($this, 'footer_text_callback'),
            'export-to-word',
            'etw_main_section'
        );
    }

    public function footer_text_callback() {
        $footer_text = get_option('etw_footer_text', 'CAPS 123 | caps123.co.za');
        echo '<input type="text" name="etw_footer_text" value="' . esc_attr($footer_text) . '" class="regular-text">';
        echo '<p class="description">' . __('This text will appear in the footer of the exported Word document.', 'export-to-word') . '</p>';
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Export to Word Settings', 'export-to-word'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('etw_settings_group');
                do_settings_sections('export-to-word');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
