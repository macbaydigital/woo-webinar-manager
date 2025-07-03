<?php
/**
 * Core functionality for Woo Webinar Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooWebinarManager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_inline_styles'));
        
        // Register shortcodes
        add_shortcode('meine_trainings_table', array($this, 'display_webinar_products'));
        add_shortcode('my_streaming_table', array($this, 'display_streaming_products'));
        add_shortcode('date_format', array($this, 'convert_date_format'));
        add_shortcode('stream_offnen_button_modal', array($this, 'stream_modal_html'));
        
        // AJAX actions
        add_action('wp_ajax_get_attendees', array($this, 'ajax_get_attendees'));
        add_action('wp_ajax_nopriv_get_attendees', array($this, 'ajax_get_attendees'));
        
        // Ninja Tables filter
        add_filter('ninja_tables_get_public_data', array($this, 'filter_ninja_table_data'), 10, 2);
    }
    
    public function init() {
        load_plugin_textdomain('woo-webinar', false, dirname(plugin_basename(WOO_WEBINAR_PLUGIN_FILE)) . '/languages');
    }
    
    public function enqueue_scripts() {
        if (!is_account_page() && !is_page_template('page-my-account.php')) {
            return;
        }
        
        wp_enqueue_style(
            'woo-webinar-style',
            WOO_WEBINAR_PLUGIN_URL . 'assets/css/webinar-style.css',
            array(),
            WOO_WEBINAR_VERSION
        );
        
        wp_enqueue_script(
            'woo-webinar-script',
            WOO_WEBINAR_PLUGIN_URL . 'assets/js/webinar-script.js',
            array('jquery'),
            WOO_WEBINAR_VERSION,
            true
        );
        
        wp_localize_script('woo-webinar-script', 'woo_webinar_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_webinar_nonce')
        ));
    }
    
    public function add_inline_styles() {
        if (!is_account_page() && !is_page_template('page-my-account.php')) {
            return;
        }
        
        ?>
        <style>
        :root {
            --woo-webinar-primary: var(--accent, #1e73be);
            --woo-webinar-secondary: var(--contrast, #222222);
            --woo-webinar-base: var(--base, #f1f1f1);
            --woo-webinar-text: var(--text, #333333);
        }
        </style>
        <?php
    }
    
    public function convert_date_format($atts) {
        $atts = shortcode_atts(array(
            'date' => date('Y-m-d')
        ), $atts);
        
        return date("d.m.Y", strtotime($atts['date']));
    }
    
    public function display_webinar_products($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Sie müssen angemeldet sein, um Ihre Trainings zu sehen.', 'woo-webinar') . '</p>';
        }
        
        $customer_orders = $this->get_customer_orders(array('webinar', 'stream'));
        $order_data = $this->process_order_data($customer_orders);
        
        if (empty($order_data)) {
            return '<p>' . __('Keine Webinare gefunden.', 'woo-webinar') . '</p>';
        }
        
        $output = '<div class="woo-webinar-container">';
        $output .= '<ul class="meine-trainings-main">';
        
        foreach ($order_data as $orders) {
            foreach ($orders as $product_data) {
                $output .= $this->render_webinar_item($product_data);
            }
        }
        
        $output .= '</ul>';
        $output .= '</div>';
        
        return $output;
    }
    
    public function display_streaming_products($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Sie müssen angemeldet sein, um Ihre Streams zu sehen.', 'woo-webinar') . '</p>';
        }
        
        $customer_orders = $this->get_customer_orders_by_category(40);
        $order_data = $this->process_order_data($customer_orders);
        
        if (empty($order_data)) {
            return '<p>' . __('Keine Streams gefunden.', 'woo-webinar') . '</p>';
        }
        
        $output = '<div class="woo-streaming-container">';
        $output .= '<ul class="meine-trainings-main">';
        
        foreach ($order_data as $orders) {
            foreach ($orders as $product_data) {
                $output .= $this->render_streaming_item($product_data);
            }
        }
        
        $output .= '</ul>';
        $output .= '</div>';
        
        return $output;
    }
    
    private function get_customer_orders($categories = array()) {
        return get_posts(array(
            'numberposts' => -1,
            'meta_key' => '_customer_user',
            'meta_value' => get_current_user_id(),
            'post_type' => wc_get_order_types(),
            'post_status' => array('wc-processing', 'wc-completed'),
            'orderby' => 'date',
            'order' => 'DESC'
        ));
    }
    
    private function get_customer_orders_by_category($category_id) {
        return get_posts(array(
            'numberposts' => -1,
            'meta_key' => '_customer_user',
            'meta_value' => get_current_user_id(),
            'post_type' => wc_get_order_types(),
            'post_status' => array_keys(wc_get_order_statuses()),
            'orderby' => 'date',
            'order' => 'DESC'
        ));
    }
    
    private function process_order_data($customer_orders) {
        $order_data = array();
        
        foreach ($customer_orders as $customer_order) {
            $order = wc_get_order($customer_order);
            $order_id = $order->get_id();
            $order_status = $order->get_status();
            
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);
                
                if (!$product) continue;
                
                $terms = get_the_terms($product_id, 'product_cat');
                $category_slugs = array();
                
                if ($terms) {
                    foreach ($terms as $term) {
                        $category_slugs[] = $term->slug;
                    }
                }
                
                if (!array_intersect($category_slugs, array('webinar', 'stream', 'streaming'))) {
                    continue;
                }
                
                $order_data[$order_id][] = array(
                    'product_id' => $product_id,
                    'product_name' => $item->get_name(),
                    'eventdate' => get_post_meta($product_id, 'eventdate', true),
                    'eventtime_start' => get_post_meta($product_id, 'eventtime_start', true),
                    'eventtime_end' => get_post_meta($product_id, 'eventtime_end', true),
                    'meetingroom_url' => get_post_meta($product_id, 'meeting-room_url', true),
                    'recording_url' => get_post_meta($product_id, 'recording_url', true),
                    'worksheet' => get_post_meta($product_id, 'worksheet', true),
                    'foliensatz' => get_post_meta($product_id, 'foliensatz', true),
                    'category_slugs' => $category_slugs,
                    'order_status' => $order_status
                );
            }
        }
        
        return $order_data;
    }
    
    private function render_webinar_item($product_data) {
        $output = '<li class="webinar-item">';
        $output .= '<div class="training-detail">';
        $output .= '<h3 class="event-name">' . esc_html($product_data['product_name']) . '</h3>';
        
        if (!empty($product_data['eventdate'])) {
            $output .= '<div class="event-date">';
            $output .= date("d.m.Y", strtotime($product_data['eventdate']));
            if (!empty($product_data['eventtime_start']) && !empty($product_data['eventtime_end'])) {
                $output .= ' von ' . $product_data['eventtime_start'] . ' - ' . $product_data['eventtime_end'] . ' Uhr';
            }
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '<div class="webinar-actions">';
        $output .= $this->get_webinar_action_buttons($product_data);
        $output .= '</div>';
        $output .= '</li>';
        
        return $output;
    }
    
    private function render_streaming_item($product_data) {
        $output = '<li class="streaming-item">';
        $output .= '<div class="training-detail">';
        $output .= '<h3 class="event-name">' . esc_html($product_data['product_name']) . '</h3>';
        
        if (!empty($product_data['eventdate'])) {
            $output .= '<div class="event-date">';
            $output .= date("d.m.Y", strtotime($product_data['eventdate']));
            if (!empty($product_data['eventtime_start']) && !empty($product_data['eventtime_end'])) {
                $output .= ' von ' . $product_data['eventtime_start'] . ' - ' . $product_data['eventtime_end'] . ' Uhr';
            }
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '<div class="streaming-actions">';
        $output .= $this->get_streaming_action_buttons($product_data);
        $output .= '</div>';
        $output .= '</li>';
        
        return $output;
    }
    
    private function get_webinar_action_buttons($product_data) {
        $output = '';
        
        $event_passed = false;
        if (!empty($product_data['eventdate']) && !empty($product_data['eventtime_end'])) {
            date_default_timezone_set("Europe/Berlin");
            $event_end = new DateTime($product_data['eventdate'] . ' ' . $product_data['eventtime_end']);
            $current_time = new DateTime();
            $event_passed = $event_end < $current_time;
        }
        
        if ($event_passed && !empty($product_data['recording_url'])) {
            $output .= '<a href="#" class="btn btn-primary open-recording" data-url="' . esc_url($product_data['recording_url']) . '">';
            $output .= '<i class="fas fa-play-circle"></i> ' . __('Webinar-Aufzeichnung ansehen', 'woo-webinar');
            $output .= '</a>';
        } elseif (!$event_passed && !empty($product_data['meetingroom_url'])) {
            $output .= '<a href="' . esc_url($product_data['meetingroom_url']) . '" class="btn btn-primary" target="_blank">';
            $output .= __('Trainingsraum betreten', 'woo-webinar');
            $output .= '</a>';
        } else {
            $message = $event_passed ? 
                __('Die Aufzeichnung wird in Kürze bereit gestellt', 'woo-webinar') : 
                __('Der Zoom-Link erscheint spätestens 1 Stunde vor dem Training', 'woo-webinar');
            $output .= '<span class="btn btn-disabled">' . $message . '</span>';
        }
        
        if (!empty($product_data['worksheet'])) {
            $output .= '<a href="' . esc_url($product_data['worksheet']) . '" class="btn btn-secondary download-btn" download>';
            $output .= '<i class="fas fa-download"></i> ' . __('Handout/Unterlagen', 'woo-webinar');
            $output .= '</a>';
        }
        
        if (!empty($product_data['foliensatz'])) {
            $output .= '<a href="' . esc_url($product_data['foliensatz']) . '" class="btn btn-secondary download-btn" download>';
            $output .= '<i class="fas fa-download"></i> ' . __('Foliensatz', 'woo-webinar');
            $output .= '</a>';
        }
        
        return $output;
    }
    
    private function get_streaming_action_buttons($product_data) {
        $output = '';
        
        switch ($product_data['order_status']) {
            case 'completed':
                $output .= $this->get_webinar_action_buttons($product_data);
                break;
                
            case 'pending':
            case 'on-hold':
                $output .= '<div class="status-note status-pending">';
                $output .= '<p>' . __('Ihre Bestellung ist eingegangen und wird nach Bestätigung Ihres Zahlungseingangs automatisch freigeschaltet.', 'woo-webinar') . '</p>';
                $output .= '</div>';
                break;
                
            case 'failed':
            case 'refunded':
            case 'cancelled':
                $output .= '<div class="status-note status-cancelled">';
                $output .= '<p>' . __('Der Zugriff wurde gekündigt.', 'woo-webinar') . '</p>';
                $output .= '</div>';
                break;
        }
        
        return $output;
    }
    
    public function stream_modal_html($atts) {
        return '<div class="video-modal-container" id="stream-modal">
            <div class="video-modal-content">
                <span class="video-modal-close">&times;</span>
                <video controls class="stream-video">
                    <source src="" type="video/mp4">
                    ' . __('Ihr Browser unterstützt das Video-Element nicht.', 'woo-webinar') . '
                </video>
            </div>
        </div>';
    }
    
    public function ajax_get_attendees() {
        check_ajax_referer('woo_webinar_nonce', 'nonce');
        echo do_shortcode('[meine_trainings_table]');
        wp_die();
    }
    
    public function filter_ninja_table_data($formatted_data, $table_id) {
        $filtered_data = array();
        
        if ($formatted_data) {
            foreach ($formatted_data as $data) {
                if (!isset($data['woo_product_buy'])) {
                    $filtered_data[] = $data;
                    continue;
                }
                
                $product_id = $this->extract_product_id($data['woo_product_buy']);
                
                if (!$product_id) {
                    $filtered_data[] = $data;
                    continue;
                }
                
                $terms = get_the_terms($product_id, 'product_cat');
                $has_live_training = false;
                
                if ($terms) {
                    foreach ($terms as $term) {
                        if ($term->term_id == 38) {
                            $has_live_training = true;
                            break;
                        }
                    }
                }
                
                if ($has_live_training) {
                    $event_date = get_post_meta($product_id, 'eventdate', true);
                    $today = date('Y-m-d');
                    
                    if (!empty($event_date) && $event_date >= $today) {
                        $filtered_data[] = $data;
                    }
                } else {
                    $filtered_data[] = $data;
                }
            }
        }
        
        return $filtered_data;
    }
    
    private function extract_product_id($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        $elements = $xpath->query('//a[@data-product_id]');
        
        foreach ($elements as $element) {
            return $element->getAttribute('data-product_id');
        }
        
        return null;
    }
}
