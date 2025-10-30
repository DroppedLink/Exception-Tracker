<?php

namespace ETracker\Admin;

use ETracker\Settings;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use Throwable;
use function add_settings_error;
use function add_settings_field;
use function add_settings_section;
use function current_user_can;
use function do_settings_sections;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function get_option;
use function register_setting;
use function settings_errors;
use function settings_fields;
use function submit_button;
use function wp_die;

class Settings_Page
{
    private const SETTINGS_GROUP = 'etracker_settings_group';
    private const PAGE_SLUG = 'etracker-settings';

    private static bool $registered = false;

    public function register(): void
    {
        if (self::$registered) {
            return;
        }

        register_setting(
            self::SETTINGS_GROUP,
            Settings::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default' => [],
            ]
        );

        add_settings_section(
            'etracker_mongo_connection',
            esc_html__('MongoDB Connection', 'etracker'),
            [$this, 'render_connection_section'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'mongo_uri',
            esc_html__('Mongo URI', 'etracker'),
            [$this, 'render_mongo_uri_field'],
            self::PAGE_SLUG,
            'etracker_mongo_connection'
        );

        add_settings_field(
            'mongo_database',
            esc_html__('Database', 'etracker'),
            [$this, 'render_mongo_database_field'],
            self::PAGE_SLUG,
            'etracker_mongo_connection'
        );

        add_settings_field(
            'mongo_collection',
            esc_html__('Collection', 'etracker'),
            [$this, 'render_mongo_collection_field'],
            self::PAGE_SLUG,
            'etracker_mongo_connection'
        );

        add_settings_section(
            'etracker_exception_defaults',
            esc_html__('Exception Defaults', 'etracker'),
            [$this, 'render_exception_defaults_section'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'default_exception_duration_days',
            esc_html__('Default exception duration (days)', 'etracker'),
            [$this, 'render_default_exception_duration_field'],
            self::PAGE_SLUG,
            'etracker_exception_defaults'
        );

        self::$registered = true;
    }

    public function render(): void
    {
        if (! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'etracker'));
        }

        settings_errors('etracker_settings');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('ETracker Settings', 'etracker') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections(self::PAGE_SLUG);

        if ($this->hasEditableFields(['mongo_uri', 'mongo_database', 'mongo_collection', 'default_exception_duration_days'])) {
            submit_button();
        }

        echo '</form>';
        echo '</div>';
    }

    public function sanitize($input): array
    {
        if (! is_array($input)) {
            $input = [];
        }

        $existing = $this->get_raw_settings();
        $output = $existing;

        $fields = ['mongo_uri', 'mongo_database', 'mongo_collection', 'default_exception_duration_days'];

        foreach ($fields as $field) {
            if (Settings::is_locked($field)) {
                $output[$field] = Settings::get($field);
                continue;
            }

            $value = isset($input[$field]) ? trim((string) $input[$field]) : '';

            if ($field === 'default_exception_duration_days') {
                $value = $value !== '' ? (string) \preg_replace('/[^0-9]/', '', $value) : '';
            }

            $output[$field] = $value;
        }

        foreach ($fields as $field) {
            if ($field === 'default_exception_duration_days') {
                $days = isset($output[$field]) ? (int) $output[$field] : 0;

                if ($days <= 0) {
                    add_settings_error(
                        'etracker_settings',
                        'etracker-settings-' . $field,
                        esc_html(sprintf(esc_html__('%s must be a positive number.', 'etracker'), $this->get_field_label($field))),
                        'error'
                    );

                    return $existing;
                }

                $output[$field] = (string) $days;
                continue;
            }

            if (empty($output[$field])) {
                add_settings_error(
                    'etracker_settings',
                    'etracker-settings-' . $field,
                    esc_html(sprintf(esc_html__('%s is required.', 'etracker'), $this->get_field_label($field))),
                    'error'
                );

                return $existing;
            }
        }

        if (! class_exists(Manager::class)) {
            add_settings_error(
                'etracker_settings',
                'etracker-mongo-driver',
                esc_html__('MongoDB PHP driver is not available. Please install the mongodb extension.', 'etracker'),
                'error'
            );

            return $existing;
        }

        try {
            $manager = new Manager($output['mongo_uri']);
            $command = new Command(['ping' => 1]);
            $manager->executeCommand($output['mongo_database'], $command);
        } catch (Throwable $exception) {
            add_settings_error(
                'etracker_settings',
                'etracker-mongo-connection',
                esc_html(sprintf(esc_html__('Unable to connect to MongoDB: %s', 'etracker'), $exception->getMessage())),
                'error'
            );

            return $existing;
        }

        return $output;
    }

    public function render_connection_section(): void
    {
        echo '<p>' . esc_html__('Configure how ETracker connects to the inventory MongoDB cluster. Constants prefixed with ETRACKER_ can override these settings.', 'etracker') . '</p>';
    }

    public function render_mongo_uri_field(): void
    {
        $this->render_input('mongo_uri', 'text', esc_html__('Example: mongodb+srv://user:pass@cluster.mongodb.net', 'etracker'));
    }

    public function render_mongo_database_field(): void
    {
        $this->render_input('mongo_database', 'text', esc_html__('Database name containing inventory documents.', 'etracker'));
    }

    public function render_mongo_collection_field(): void
    {
        $this->render_input('mongo_collection', 'text', esc_html__('Collection storing inventory records.', 'etracker'));
    }

    public function render_exception_defaults_section(): void
    {
        echo '<p>' . esc_html__('Define defaults applied when creating new enforcement exceptions.', 'etracker') . '</p>';
    }

    public function render_default_exception_duration_field(): void
    {
        $field = 'default_exception_duration_days';
        $value = esc_attr(Settings::get($field, '365'));
        $locked = Settings::is_locked($field);
        $readonly = $locked ? ' readonly="readonly" disabled="disabled"' : '';

        printf(
            '<input type="number" id="%1$s" name="%2$s[%1$s]" value="%3$s" class="small-text" min="1" step="1"%4$s />',
            esc_attr($field),
            esc_attr(Settings::OPTION_NAME),
            $value,
            $readonly
        );

        if ($locked) {
            echo '<p class="description">' . esc_html__('This value is locked by a constant.', 'etracker') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Number of days before a new exception expires when no date is supplied.', 'etracker') . '</p>';
        }
    }

    private function render_input(string $field, string $type, string $description): void
    {
        $value = esc_attr(Settings::get($field));
        $locked = Settings::is_locked($field);
        $readonly = $locked ? ' readonly="readonly" disabled="disabled"' : '';

        printf(
            '<input type="%1$s" id="%2$s" name="%3$s[%2$s]" value="%4$s" class="regular-text"%5$s/>',
            esc_attr($type),
            esc_attr($field),
            esc_attr(Settings::OPTION_NAME),
            $value,
            $readonly
        );

        if ($locked) {
            echo '<p class="description">' . esc_html__('This value is locked by a constant.', 'etracker') . '</p>';
        } elseif ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }

    private function hasEditableFields(array $fields): bool
    {
        foreach ($fields as $field) {
            if (! Settings::is_locked($field)) {
                return true;
            }
        }

        return false;
    }

    private function get_raw_settings(): array
    {
        $stored = get_option(Settings::OPTION_NAME, []);

        return is_array($stored) ? $stored : [];
    }

    private function get_field_label(string $key): string
    {
        return match ($key) {
            'mongo_uri' => esc_html__('Mongo URI', 'etracker'),
            'mongo_database' => esc_html__('Database', 'etracker'),
            'mongo_collection' => esc_html__('Collection', 'etracker'),
            'default_exception_duration_days' => esc_html__('Default exception duration (days)', 'etracker'),
            default => ucfirst($key),
        };
    }
}

