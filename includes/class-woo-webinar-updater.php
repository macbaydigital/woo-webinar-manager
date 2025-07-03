<?php
/**
 * GitHub Plugin Updater
 * 
 * Handles automatic updates from GitHub repository
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooWebinarUpdater {
    
    private $plugin_file;
    private $github_repo;
    private $version;
    private $plugin_slug;
    private $plugin_basename;
    
    public function __construct($plugin_file, $github_repo, $version) {
        $this->plugin_file = $plugin_file;
        $this->github_repo = $github_repo;
        $this->version = $version;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_basename = dirname($this->plugin_slug);
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
        add_action('admin_init', array($this, 'show_upgrade_notification'));
    }
    
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => $this->plugin_basename,
                'new_version' => $remote_version,
                'url' => $this->get_github_repo_url(),
                'package' => $this->get_download_url($remote_version),
                'tested' => '6.4',
                'compatibility' => new stdClass(),
            );
        }
        
        return $transient;
    }
    
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if ($args->slug !== $this->plugin_basename) {
            return $result;
        }
        
        $remote_version = $this->get_remote_version();
        $remote_info = $this->get_remote_info();
        
        return (object) array(
            'name' => 'Woo Webinar Manager',
            'slug' => $this->plugin_basename,
            'version' => $remote_version,
            'author' => 'Macbay Digital',
            'author_profile' => 'https://macbay-digital.com',
            'last_updated' => $remote_info['updated'] ?? date('Y-m-d'),
            'homepage' => $this->get_github_repo_url(),
            'short_description' => 'Verwaltet Webinar-Produkte im WooCommerce Account-Bereich',
            'sections' => array(
                'description' => $this->get_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog' => $this->get_changelog(),
            ),
            'download_link' => $this->get_download_url($remote_version),
            'tested' => '6.4',
            'requires' => '5.0',
            'requires_php' => '7.4',
        );
    }
    
    public function post_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->plugin_slug) {
            activate_plugin($this->plugin_slug);
        }
        
        return $result;
    }
    
    public function show_upgrade_notification() {
        $screen = get_current_screen();
        
        if ($screen->id !== 'plugins') {
            return;
        }
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Woo Webinar Manager:</strong> ';
            printf(
                __('Eine neue Version (%s) ist verfügbar. <a href="%s">Jetzt aktualisieren</a>.', 'woo-webinar'),
                $remote_version,
                wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . $this->plugin_slug), 'upgrade-plugin_' . $this->plugin_slug)
            );
            echo '</p>';
            echo '</div>';
        }
    }
    
    private function get_remote_version() {
        $request = wp_remote_get($this->get_api_url());
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['tag_name'])) {
                return ltrim($data['tag_name'], 'v');
            }
        }
        
        return false;
    }
    
    private function get_remote_info() {
        $request = wp_remote_get($this->get_api_url());
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            return json_decode($body, true);
        }
        
        return array();
    }
    
    private function get_api_url() {
        return sprintf('https://api.github.com/repos/%s/releases/latest', $this->github_repo);
    }
    
    private function get_github_repo_url() {
        return sprintf('https://github.com/%s', $this->github_repo);
    }
    
    private function get_download_url($version) {
        return sprintf('https://github.com/%s/archive/refs/tags/v%s.zip', $this->github_repo, $version);
    }
    
    private function get_description() {
        return 'Woo Webinar Manager erweitert WooCommerce um eine umfassende Webinar-Verwaltung im Kundenbereich. 
                Kunden können ihre erworbenen Webinare und Streams verwalten, Aufzeichnungen ansehen, 
                Unterlagen herunterladen und Trainingsräume betreten. Vollständig integriert in das GeneratePress Theme.';
    }
    
    private function get_installation_instructions() {
        return '1. Laden Sie das Plugin herunter
                2. Entpacken Sie die ZIP-Datei
                3. Laden Sie den Ordner in das /wp-content/plugins/ Verzeichnis hoch
                4. Aktivieren Sie das Plugin über das WordPress Admin Panel
                5. Stellen Sie sicher, dass WooCommerce aktiv ist';
    }
    
    private function get_changelog() {
        $changelog = wp_remote_get(sprintf('https://api.github.com/repos/%s/releases', $this->github_repo));
        
        if (!is_wp_error($changelog) && wp_remote_retrieve_response_code($changelog) === 200) {
            $releases = json_decode(wp_remote_retrieve_body($changelog), true);
            $changelog_text = '';
            
            foreach (array_slice($releases, 0, 5) as $release) {
                $changelog_text .= '<h4>' . $release['tag_name'] . '</h4>';
                $changelog_text .= '<p>' . $release['body'] . '</p>';
            }
            
            return $changelog_text;
        }
        
        return '<h4>1.0.0</h4><p>Erste Version des Plugins</p>';
    }
}
