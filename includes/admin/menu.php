<?php

namespace ETracker\Admin;

use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function esc_html__;
use function esc_url;
use function printf;

class Menu
{
    private const MENU_SLUG = 'etracker';
    private const INVENTORY_SLUG = 'etracker';
    private const SETTINGS_SLUG = 'etracker-settings';
    private const ACCESS_SLUG = 'etracker-access';
    private const REPORTS_SLUG = 'etracker-reports';

    private ?Settings_Page $settings_page;
    private ?Inventory_Page $inventory_page;
    private ?Access_Page $access_page = null;
    private ?Reports_Page $reports_page = null;

    public function __construct(?Settings_Page $settings_page = null, ?Inventory_Page $inventory_page = null, ?Access_Page $access_page = null, ?Reports_Page $reports_page = null)
    {
        $this->settings_page = $settings_page;
        $this->inventory_page = $inventory_page;
        $this->access_page = $access_page;
        $this->reports_page = $reports_page;
    }

    public function register(): void
    {
        add_menu_page(
            esc_html__('ETracker', 'etracker'),
            esc_html__('ETracker', 'etracker'),
            ETRACKER_MANAGE_CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_inventory_page'],
            'dashicons-shield',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Inventory', 'etracker'),
            esc_html__('Inventory', 'etracker'),
            ETRACKER_MANAGE_CAPABILITY,
            self::INVENTORY_SLUG,
            [$this, 'render_inventory_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Settings', 'etracker'),
            esc_html__('Settings', 'etracker'),
            ETRACKER_MANAGE_CAPABILITY,
            self::SETTINGS_SLUG,
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Access', 'etracker'),
            esc_html__('Access', 'etracker'),
            ETRACKER_MANAGE_CAPABILITY,
            self::ACCESS_SLUG,
            [$this, 'render_access_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Reports', 'etracker'),
            esc_html__('Reports', 'etracker'),
            ETRACKER_MANAGE_CAPABILITY,
            self::REPORTS_SLUG,
            [$this, 'render_reports_page']
        );
    }

    public function render_inventory_page(): void
    {
        if ($this->inventory_page instanceof Inventory_Page) {
            $this->inventory_page->render();
            return;
        }

        $this->render_placeholder(esc_html__('Inventory page is unavailable at the moment.', 'etracker'));
    }

    public function render_settings_page(): void
    {
        if ($this->settings_page instanceof Settings_Page) {
            $this->settings_page->render();
            return;
        }

        $this->render_placeholder(esc_html__('Settings page is unavailable at the moment.', 'etracker'));
    }

    public function render_access_page(): void
    {
        if ($this->access_page instanceof Access_Page) {
            $this->access_page->render();
            return;
        }

        $this->render_placeholder(esc_html__('Access management is unavailable at the moment.', 'etracker'));
    }

    public function render_reports_page(): void
    {
        if ($this->reports_page instanceof Reports_Page) {
            $this->reports_page->render();
            return;
        }

        $this->render_placeholder(esc_html__('Reports are unavailable at the moment.', 'etracker'));
    }

    private function render_placeholder(string $message): void
    {
        printf(
            '<div class="wrap"><h1>%s</h1><p>%s</p></div>',
            esc_html__('ETracker', 'etracker'),
            esc_html($message)
        );
    }
}

