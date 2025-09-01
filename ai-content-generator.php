<?php
/**
 * Plugin Name: AI Content Generator Pro
 * Description: Genera artículos optimizados para SEO usando IA con una UX excepcional
 * Version: 1.0.0
 * Author: Tu Nombre
 * Text Domain: ai-content-generator
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('AICG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AICG_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AICG_VERSION', '1.0.0');

class AIContentGenerator {
    
    private $api_key;
    private $api_endpoint;
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_aicg_generate_titles', [$this, 'ajax_generate_titles']);
        add_action('wp_ajax_aicg_generate_summary', [$this, 'ajax_generate_summary']);
        add_action('wp_ajax_aicg_generate_content', [$this, 'ajax_generate_content']);
        add_action('wp_ajax_aicg_save_draft', [$this, 'ajax_save_draft']);
        add_action('wp_ajax_aicg_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_aicg_check_status', [$this, 'ajax_check_status']);
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }
    
    public function init() {
        $this->api_key = get_option('aicg_api_key', '');
        $this->api_endpoint = get_option('aicg_api_endpoint', '');
        $this->create_tables();
    }
    
    public function activate() {
        $this->create_tables();
        // Configuración por defecto
        add_option('aicg_api_key', '');
        add_option('aicg_api_endpoint', 'http://localhost:8000');
        add_option('aicg_plan_type', 'free');
        add_option('aicg_articles_limit', 10);
        add_option('aicg_tokens_limit', 50000);
        add_option('aicg_articles_used', 0);
        add_option('aicg_tokens_used', 0);
    }
    
    public function deactivate() {
        // Limpieza si es necesario
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            title text NOT NULL,
            description text NOT NULL,
            content_type varchar(100) NOT NULL,
            tone varchar(50) NOT NULL,
            length int(11) NOT NULL,
            keywords text,
            generated_title text,
            generated_summary text,
            generated_content longtext,
            meta_description text,
            tags text,
            category varchar(100),
            seo_score int(3),
            tokens_used int(11),
            status varchar(20) DEFAULT 'draft',
            post_id bigint(20) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'AI Content Generator',
            'AI Content',
            'manage_options',
            'ai-content-generator',
            [$this, 'admin_page'],
            'dashicons-edit-large',
            30
        );
        
        add_submenu_page(
            'ai-content-generator',
            'Generar Contenido',
            'Generar',
            'manage_options',
            'ai-content-generator',
            [$this, 'admin_page']
        );
        
        add_submenu_page(
            'ai-content-generator',
            'Historial',
            'Historial',
            'manage_options',
            'aicg-history',
            [$this, 'history_page']
        );
        
        add_submenu_page(
            'ai-content-generator',
            'Configuración',
            'Configuración',
            'manage_options',
            'aicg-settings',
            [$this, 'settings_page']
        );
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ai-content-generator') === false && strpos($hook, 'aicg-') === false) {
            return;
        }
        
        wp_enqueue_script(
            'aicg-admin',
            AICG_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            AICG_VERSION,
            true
        );
        
        wp_enqueue_style(
            'aicg-admin',
            AICG_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AICG_VERSION
        );
        
        wp_localize_script('aicg-admin', 'aicg_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicg_nonce'),
            'strings' => [
                'generating' => __('Generando...', 'ai-content-generator'),
                'error' => __('Error en la generación', 'ai-content-generator'),
                'success' => __('Generado exitosamente', 'ai-content-generator'),
                'confirm_regenerate' => __('¿Regenerar contenido? Se perderá el actual', 'ai-content-generator')
            ]
        ]);
    }
    
    public function admin_page() {
        $stats = $this->get_user_stats();
        include AICG_PLUGIN_PATH . 'templates/admin-page.php';
    }
    
    public function history_page() {
        include AICG_PLUGIN_PATH . 'templates/history-page.php';
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('aicg_api_key', sanitize_text_field($_POST['api_key']));
            update_option('aicg_api_endpoint', sanitize_url($_POST['api_endpoint']));
            echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
        }
        include AICG_PLUGIN_PATH . 'templates/settings-page.php';
    }
    
    private function get_user_stats() {
        return [
            'plan_type' => get_option('aicg_plan_type', 'free'),
            'articles_used' => (int)get_option('aicg_articles_used', 0),
            'articles_limit' => (int)get_option('aicg_articles_limit', 10),
            'tokens_used' => (int)get_option('aicg_tokens_used', 0),
            'tokens_limit' => (int)get_option('aicg_tokens_limit', 50000)
        ];
    }
    
    // AJAX Handlers
    public function ajax_generate_titles() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        if (!$this->check_limits()) {
            wp_send_json_error(['message' => 'Límites excedidos']);
            return;
        }
        
        $description = sanitize_text_field($_POST['description']);
        $content_type = sanitize_text_field($_POST['content_type']);
        $tone = sanitize_text_field($_POST['tone']);
        $keywords = sanitize_text_field($_POST['keywords']);
        
        $response = $this->call_api('generate_titles', [
            'description' => $description,
            'content_type' => $content_type,
            'tone' => $tone,
            'keywords' => $keywords
        ]);
        
        if ($response && !is_wp_error($response)) {
            wp_send_json_success($response);
        } else {
            wp_send_json_error(['message' => 'Error generando títulos']);
        }
    }
    
    public function ajax_generate_summary() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        $title = sanitize_text_field($_POST['title']);
        $description = sanitize_text_field($_POST['description']);
        $content_type = sanitize_text_field($_POST['content_type']);
        
        $response = $this->call_api('generate_summary', [
            'title' => $title,
            'description' => $description,
            'content_type' => $content_type
        ]);
        
        if ($response && !is_wp_error($response)) {
            wp_send_json_success($response);
        } else {
            wp_send_json_error(['message' => 'Error generando resumen']);
        }
    }
    
    public function ajax_generate_content() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        $generation_id = $this->save_generation_request();
        
        // Iniciar generación en cola
        $response = $this->call_api('generate_content', [
            'generation_id' => $generation_id,
            'title' => sanitize_text_field($_POST['title']),
            'summary' => sanitize_text_field($_POST['summary']),
            'length' => (int)$_POST['length'],
            'content_type' => sanitize_text_field($_POST['content_type']),
            'tone' => sanitize_text_field($_POST['tone'])
        ]);
        
        wp_send_json_success(['generation_id' => $generation_id]);
    }
    
    private function save_generation_request() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        $wpdb->insert(
            $table_name,
            [
                'user_id' => get_current_user_id(),
                'title' => sanitize_text_field($_POST['original_description']),
                'description' => sanitize_text_field($_POST['original_description']),
                'content_type' => sanitize_text_field($_POST['content_type']),
                'tone' => sanitize_text_field($_POST['tone']),
                'length' => (int)$_POST['length'],
                'keywords' => sanitize_text_field($_POST['keywords']),
                'generated_title' => sanitize_text_field($_POST['title']),
                'generated_summary' => sanitize_text_field($_POST['summary']),
                'status' => 'generating'
            ]
        );
        
        return $wpdb->insert_id;
    }
    
    private function call_api($endpoint, $data) {
        $url = rtrim($this->api_endpoint, '/') . '/api/' . $endpoint;
        
        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode($data)
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function check_limits() {
        $stats = $this->get_user_stats();
        return ($stats['articles_used'] < $stats['articles_limit']) && 
               ($stats['tokens_used'] < $stats['tokens_limit']);
    }
    
    public function ajax_check_status() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        $generation_id = (int)$_POST['generation_id'];
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        $generation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $generation_id
        ));
        
        if (!$generation) {
            wp_send_json_error(['message' => 'Generación no encontrada']);
            return;
        }
        
        wp_send_json_success([
            'status' => $generation->status,
            'content' => $generation->generated_content,
            'seo_score' => $generation->seo_score,
            'tokens_used' => $generation->tokens_used
        ]);
    }

    // Agregar estos métodos a la clase AIContentGenerator

    public function ajax_save_draft() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        $generation_id = (int)$_POST['generation_id'];
        $title = sanitize_text_field($_POST['title']);
        $content = wp_kses_post($_POST['content']);
        $summary = sanitize_textarea_field($_POST['summary']);
        $meta_description = sanitize_text_field($_POST['meta_description']);
        $tags = sanitize_text_field($_POST['tags']);
        $category = sanitize_text_field($_POST['category']);
        $seo_score = (int)$_POST['seo_score'];
        $tokens_used = (int)$_POST['tokens_used'];
        
        // Actualizar la generación existente
        $updated = $wpdb->update(
            $table_name,
            [
                'generated_title' => $title,
                'generated_content' => $content,
                'generated_summary' => $summary,
                'meta_description' => $meta_description,
                'tags' => $tags,
                'category' => $category,
                'seo_score' => $seo_score,
                'tokens_used' => $tokens_used,
                'status' => 'draft',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $generation_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'],
            ['%d']
        );
        
        if ($updated !== false) {
            wp_send_json_success(['message' => 'Borrador guardado exitosamente']);
        } else {
            wp_send_json_error(['message' => 'Error guardando el borrador']);
        }
    }

    public function ajax_publish_article() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        $title = sanitize_text_field($_POST['title']);
        $content = wp_kses_post($_POST['content']);
        $summary = sanitize_textarea_field($_POST['summary']);
        $meta_description = sanitize_text_field($_POST['meta_description']);
        $tags_string = sanitize_text_field($_POST['tags']);
        $category = sanitize_text_field($_POST['category']);
        
        // Crear el post en WordPress
        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $summary,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => get_current_user_id()
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => 'Error creando el post: ' . $post_id->get_error_message()]);
            return;
        }
        
        // Añadir meta description
        update_post_meta($post_id, '_aicg_meta_description', $meta_description);
        
        // Añadir tags
        if (!empty($tags_string)) {
            $tags_array = array_map('trim', explode(',', $tags_string));
            wp_set_post_tags($post_id, $tags_array);
        }
        
        // Añadir categoría
        if (!empty($category)) {
            $cat_id = get_cat_ID($category);
            if (!$cat_id) {
                $cat_id = wp_create_category($category);
            }
            if ($cat_id) {
                wp_set_post_categories($post_id, [$cat_id]);
            }
        }
        
        // Actualizar registro de generación
        if (isset($_POST['generation_id'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'aicg_generations';
            
            $wpdb->update(
                $table_name,
                [
                    'post_id' => $post_id,
                    'status' => 'published',
                    'updated_at' => current_time('mysql')
                ],
                ['id' => (int)$_POST['generation_id']],
                ['%d', '%s', '%s'],
                ['%d']
            );
        }
        
        // Actualizar estadísticas de uso
        $articles_used = (int)get_option('aicg_articles_used', 0);
        update_option('aicg_articles_used', $articles_used + 1);
        
        if (isset($_POST['tokens_used'])) {
            $tokens_used = (int)get_option('aicg_tokens_used', 0);
            update_option('aicg_tokens_used', $tokens_used + (int)$_POST['tokens_used']);
        }
        
        wp_send_json_success([
            'message' => 'Artículo publicado exitosamente',
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id)
        ]);
    }

    public function ajax_get_recent_activity() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, generated_title as title, status, tokens_used, created_at 
                FROM $table_name 
                WHERE user_id = %d 
                ORDER BY created_at DESC 
                LIMIT 5",
                get_current_user_id()
            )
        );
        
        $activities = [];
        foreach ($results as $result) {
            $activities[] = [
                'id' => $result->id,
                'title' => $result->title ?: 'Sin título',
                'status' => $result->status,
                'tokens_used' => number_format($result->tokens_used),
                'created_at' => human_time_diff(strtotime($result->created_at)) . ' ago'
            ];
        }
        
        wp_send_json_success($activities);
    }

    public function ajax_delete_generation() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        $generation_id = (int)$_POST['generation_id'];
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        // Verificar que el usuario es el propietario
        $generation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $generation_id,
            get_current_user_id()
        ));
        
        if (!$generation) {
            wp_send_json_error(['message' => 'Generación no encontrada']);
            return;
        }
        
        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $generation_id],
            ['%d']
        );
        
        if ($deleted) {
            wp_send_json_success(['message' => 'Generación eliminada']);
        } else {
            wp_send_json_error(['message' => 'Error eliminando la generación']);
        }
    }

    public function ajax_get_history() {
        check_ajax_referer('aicg_nonce', 'nonce');
        
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aicg_generations';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE user_id = %d 
            ORDER BY created_at DESC 
            LIMIT %d OFFSET %d",
            get_current_user_id(),
            $per_page,
            $offset
        ));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
            get_current_user_id()
        ));
        
        $generations = [];
        foreach ($results as $result) {
            $generations[] = [
                'id' => $result->id,
                'title' => $result->generated_title ?: $result->title,
                'description' => $result->description,
                'status' => $result->status,
                'content_type' => $result->content_type,
                'seo_score' => $result->seo_score,
                'tokens_used' => $result->tokens_used,
                'created_at' => $result->created_at,
                'updated_at' => $result->updated_at,
                'post_id' => $result->post_id
            ];
        }
        
        wp_send_json_success([
            'generations' => $generations,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ]);
    }

}

// Inicializar el plugin
new AIContentGenerator();
