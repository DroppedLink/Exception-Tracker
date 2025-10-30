<?php

namespace ETracker;

use ETracker\Admin\Access_Page;
use ETracker\Admin\Inventory_Page;
use ETracker\Admin\Menu;
use ETracker\Admin\Settings_Page;
use ETracker\Admin\Reports_Page;
use ETracker\Frontend\Shortcode;
use function add_action;
use function apply_filters;
use function dirname;
use function do_action;
use function get_role;
use function is_admin;
use function load_plugin_textdomain;
use function plugin_basename;
use function wp_register_style;

final class ETracker
{
    private static ?self $instance = null;

    private ?Settings_Page $settings_page = null;
    private ?Inventory_Page $inventory_page = null;
    private ?Menu $menu = null;
    private ?Shortcode $shortcode = null;
    private ?Access_Page $access_page = null;
    private ?Reports_Page $reports_page = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        add_action('init', [$this, 'ensure_capability']);
        add_action('init', [$this, 'register_assets']);
        add_action('init', [$this, 'bootstrap_shortcode']);
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_init', [$this, 'bootstrap_admin']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        $this->load_textdomain();

        do_action('etracker/loaded');
    }

    public function ensure_capability(): void
    {
        $capability = ETRACKER_MANAGE_CAPABILITY;
        $roles = apply_filters('etracker/manage_capability_roles', ['administrator']);

        foreach ((array) $roles as $role_name) {
            $role = get_role($role_name);
            if (! $role instanceof \WP_Role) {
                continue;
            }

            if (! $role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }

    public function register_admin_pages(): void
    {
        if (! is_admin()) {
            return;
        }

        $menu = $this->get_menu();
        if ($menu instanceof Menu) {
            $menu->register();
        }
    }

    public function bootstrap_admin(): void
    {
        if (! is_admin()) {
            return;
        }

        $settings = $this->get_settings_page();
        if ($settings instanceof Settings_Page) {
            $settings->register();
        }

        $access = $this->get_access_page();
        if ($access instanceof Access_Page) {
            $access->register_hooks();
        }

        do_action('etracker/admin_init');
    }

    public function register_assets(): void
    {
        wp_register_style(
            'etracker-ui',
            ETRACKER_PLUGIN_URL . 'assets/css/etracker.css',
            [],
            ETRACKER_PLUGIN_VERSION
        );
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $hooks = [
            'toplevel_page_etracker',
            'etracker_page_etracker',
            'etracker_page_etracker-settings',
        ];

        if (in_array($hook, $hooks, true)) {
            wp_enqueue_style('etracker-ui');
        }
    }

    public function bootstrap_shortcode(): void
    {
        $shortcode = $this->get_shortcode();
        if ($shortcode instanceof Shortcode) {
            $shortcode->register();
        }
    }

    private function load_textdomain(): void
    {
        load_plugin_textdomain(
            'etracker',
            false,
            dirname(plugin_basename(ETRACKER_PLUGIN_FILE)) . '/languages'
        );
    }

    private function get_settings_page(): ?Settings_Page
    {
        if (! class_exists('ETracker\\Admin\\Settings_Page')) {
            return null;
        }

        if (! $this->settings_page instanceof Settings_Page) {
            $this->settings_page = new Settings_Page();
        }

        return $this->settings_page;
    }

    private function get_inventory_page(): ?Inventory_Page
    {
        if (! class_exists('ETracker\\Admin\\Inventory_Page')) {
            return null;
        }

        if (! $this->inventory_page instanceof Inventory_Page) {
            $this->inventory_page = new Inventory_Page();
        }

        return $this->inventory_page;
    }

    private function get_menu(): ?Menu
    {
        if (! class_exists('ETracker\\Admin\\Menu')) {
            return null;
        }

        if (! $this->menu instanceof Menu) {
            $this->menu = new Menu(
                $this->get_settings_page(),
                $this->get_inventory_page(),
                $this->get_access_page(),
                $this->get_reports_page()
            );
        }

        return $this->menu;
    }

    private function get_shortcode(): ?Shortcode
    {
        if (! class_exists('ETracker\\Frontend\\Shortcode')) {
            return null;
        }

        if (! $this->shortcode instanceof Shortcode) {
            $this->shortcode = new Shortcode();
        }

        return $this->shortcode;
    }

    private function get_access_page(): ?Access_Page
    {
        if (! class_exists('ETracker\\Admin\\Access_Page')) {
            return null;
        }

        if (! $this->access_page instanceof Access_Page) {
            $this->access_page = new Access_Page();
        }

        return $this->access_page;
    }

    private function get_reports_page(): ?Reports_Page
    {
        if (! class_exists('ETracker\\Admin\\Reports_Page')) {
            return null;
        }

        if (! $this->reports_page instanceof Reports_Page) {
            $this->reports_page = new Reports_Page();
        }

        return $this->reports_page;
    }
}

