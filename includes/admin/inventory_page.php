<?php

namespace ETracker\Admin;

use ETracker\Repositories\InventoryRepository;
use ETracker\Services\ExceptionManager;
use ETracker\Services\AuditLogger;
use Throwable;
use function __;
use function add_query_arg;
use function add_settings_error;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function get_current_user_id;
use function sanitize_html_class;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function settings_errors;
use function get_date_from_gmt;
use function get_option;
use function is_array;
use function json_decode;
use function wp_enqueue_style;
use function wp_get_current_user;
use function wp_die;
use function wp_nonce_field;
use function wp_unslash;
use function esc_url;
use function submit_button;
use function selected;
use function checked;
use function sprintf;
use function wp_strip_all_tags;
use function implode;
use function array_values;
use function array_filter;
use function number_format_i18n;
use function strtotime;
use function gmdate;

class Inventory_Page
{
    private InventoryRepository $repository;
    private ExceptionManager $exceptionManager;
    private AuditLogger $auditLogger;

    public function __construct(
        ?InventoryRepository $repository = null,
        ?ExceptionManager $exceptionManager = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->repository = $repository ?? new InventoryRepository();
        $this->exceptionManager = $exceptionManager ?? new ExceptionManager($this->repository);
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    public function render(): void
    {
        wp_enqueue_style('etracker-ui');

        if (! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'etracker'));
        }

        $this->maybeHandlePost();

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'view' && isset($_GET['document_id'])) {
            $documentId = sanitize_text_field(wp_unslash($_GET['document_id']));
            $this->renderDetail($documentId);
            return;
        }

        $this->renderList();
    }

    private function renderList(): void
    {
        $criteria = [
            'hostname' => isset($_GET['hostname']) ? sanitize_text_field(wp_unslash($_GET['hostname'])) : '',
            'reference_code' => isset($_GET['reference_code']) ? sanitize_text_field(wp_unslash($_GET['reference_code'])) : '',
            'owned_by' => isset($_GET['owned_by']) ? sanitize_text_field(wp_unslash($_GET['owned_by'])) : '',
            'managed_by' => isset($_GET['managed_by']) ? sanitize_text_field(wp_unslash($_GET['managed_by'])) : '',
            'gvp' => isset($_GET['gvp']) ? sanitize_text_field(wp_unslash($_GET['gvp'])) : '',
            'application' => isset($_GET['application']) ? sanitize_text_field(wp_unslash($_GET['application'])) : '',
        ];

        $table = new Inventory_List_Table($this->repository);
        $table->setCriteria($criteria);
        $table->prepare_items();
        $error = $table->getLastError();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Server Inventory', 'etracker') . '</h1>';

        if ($error !== null) {
            $message = sprintf(
                esc_html__('Unable to load inventory: %s', 'etracker'),
                wp_strip_all_tags($error)
            );
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
        }

        echo $this->renderShortcodeInfoCard();

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="etracker" />';

        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        $this->renderFilters($criteria);
        submit_button(esc_html__('Filter', 'etracker'), 'secondary', '', false);
        echo '</div>';
        echo '</div>';

        $table->display();

        echo '</form>';
        echo '</div>';
    }

    private function renderFilters(array $criteria): void
    {
        $fields = [
            'hostname' => esc_html__('Hostname', 'etracker'),
            'reference_code' => esc_html__('Reference Code', 'etracker'),
            'owned_by' => esc_html__('Owned By', 'etracker'),
            'managed_by' => esc_html__('Managed By', 'etracker'),
            'gvp' => esc_html__('GVP', 'etracker'),
            'application' => esc_html__('Application', 'etracker'),
        ];

        foreach ($fields as $key => $label) {
            $value = isset($criteria[$key]) ? esc_attr($criteria[$key]) : '';
            printf(
                '<label for="etracker-filter-%1$s" class="screen-reader-text">%2$s</label><input type="text" id="etracker-filter-%1$s" name="%1$s" value="%3$s" placeholder="%2$s" /> ',
                esc_attr($key),
                esc_attr($label),
                $value
            );
        }
    }

    private function renderDetail(string $documentId): void
    {
        $document = $this->repository->findById($documentId);

        echo '<div class="wrap">';
        echo '<div class="etracker-wrap">';
        echo '<h1>' . esc_html__('Server Enforcement', 'etracker') . '</h1>';

        $listUrl = add_query_arg('page', 'etracker', admin_url('admin.php'));
        printf('<p><a href="%s" class="button">%s</a></p>', esc_url($listUrl), esc_html__('Back to list', 'etracker'));

        settings_errors('etracker_inventory');

        if ($document === null) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Document not found or no longer exists.', 'etracker') . '</p></div>';
            echo '</div></div>';
            return;
        }

        $this->renderSummary($document);
        echo $this->renderShortcodeInfoCard();
        $this->renderEnforcementSection('CIS', $documentId, $document['Enforced']['CIS'] ?? []);
        $this->renderEnforcementSection('Agents', $documentId, $document['Enforced']['Agents'] ?? []);
        $this->renderAuditHistory($documentId);

        echo '</div></div>';
    }

    private function renderSummary(array $document): void
    {
        $esd = $document['ESD'] ?? [];
        $system = $esd['system'] ?? [];
        $network = $esd['network'] ?? [];
        $os = $esd['os'] ?? [];
        $instance = $esd['instance'] ?? [];
        $stats = $this->calculateEnforcementStats($document);

        echo '<div class="etracker-card etracker-card--summary">';
        echo '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Summary', 'etracker') . '</h2></div>';
        echo '<div class="etracker-card__body">';

        echo '<div class="etracker-summary-intro">';
        echo '<div class="etracker-summary-heading">';
        echo '<span class="etracker-summary-heading__label">' . esc_html__('Hostname', 'etracker') . '</span>';
        echo '<span class="etracker-summary-heading__value">' . esc_html((string) ($esd['hostname'] ?? '')) . '</span>';
        echo '</div>';

        $metricChips = array_filter([
            [
                'label' => esc_html__('Controls', 'etracker'),
                'value' => number_format_i18n((int) $stats['total']),
            ],
            [
                'label' => esc_html__('Active Exceptions', 'etracker'),
                'value' => number_format_i18n((int) $stats['active_exceptions']),
                'context' => $stats['active_exceptions'] > 0 ? 'warning' : 'neutral',
            ],
            [
                'label' => esc_html__('Not Enforced', 'etracker'),
                'value' => number_format_i18n((int) $stats['unenforced']),
                'context' => $stats['unenforced'] > 0 ? 'info' : 'neutral',
            ],
        ], static fn ($chip) => (int) ($chip['value'] ?? 0) >= 0);

        if (! empty($metricChips)) {
            echo '<div class="etracker-summary-metrics">';
            foreach ($metricChips as $chip) {
                $classNames = ['etracker-summary-metric'];
                if (! empty($chip['context'])) {
                    $classNames[] = 'is-' . sanitize_html_class($chip['context']);
                }

                echo '<div class="' . esc_attr(implode(' ', $classNames)) . '">';
                echo '<span class="etracker-summary-metric__value">' . esc_html($chip['value']) . '</span>';
                echo '<span class="etracker-summary-metric__label">' . esc_html($chip['label']) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div>';

        $rows = [
            esc_html__('FQDN', 'etracker') => $esd['fqdn'] ?? '',
            esc_html__('Application', 'etracker') => $instance['name'] ?? '',
            esc_html__('Reference Code', 'etracker') => $instance['reference_code'] ?? '',
            esc_html__('Owned By', 'etracker') => $instance['owned_by']['name'] ?? '',
            esc_html__('Managed By', 'etracker') => $instance['managed_by']['name'] ?? '',
            esc_html__('GVP', 'etracker') => $instance['gvp']['name'] ?? '',
            esc_html__('Environment', 'etracker') => $system['environment'] ?? '',
            esc_html__('Primary IP', 'etracker') => $network['primary_ip'] ?? '',
            esc_html__('Patch Cycle', 'etracker') => $os['patch_cycle'] ?? '',
            esc_html__('OS Version', 'etracker') => $os['full_name'] ?? '',
        ];

        $rows = array_filter($rows, static fn ($value) => $value !== null && $value !== '');

        if (empty($rows)) {
            echo '<p class="etracker-empty-state">' . esc_html__('No summary data available for this server.', 'etracker') . '</p>';
        } else {
            echo '<div class="etracker-summary-grid">';
            foreach ($rows as $label => $value) {
                echo '<div class="etracker-summary-grid__cell">';
                echo '<span class="etracker-summary-grid__label">' . esc_html($label) . '</span>';
                echo '<span class="etracker-summary-grid__value">' . esc_html((string) $value) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div></div>';
    }

    private function renderEnforcementSection(string $group, string $documentId, array $items): void
    {
        $itemCount = count($items);

        echo '<div class="etracker-card etracker-card--enforcement">';
        echo '<div class="etracker-card__header">';
        echo '<div class="etracker-card__header-row">';
        echo '<h2 class="etracker-card__title">' . esc_html(sprintf(esc_html__('%s Enforcement', 'etracker'), $group)) . '</h2>';
        echo '<span class="etracker-chip" aria-hidden="true">' . esc_html($itemCount) . '</span>';
        echo '</div>';
        echo '<p class="etracker-card__subtitle">' . esc_html__('Review controls, active exceptions, and context for this server.', 'etracker') . '</p>';
        echo '</div>';
        echo '<div class="etracker-card__body">';

        if (empty($items)) {
            echo '<p class="etracker-empty-state">' . esc_html__('No enforced items found.', 'etracker') . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<div class="etracker-enforcement-list">';

        foreach ($items as $key => $item) {
            $enforced = ! empty($item['enforced']);
            $exception = isset($item['exception']) && is_array($item['exception']) ? $item['exception'] : [];
            $exceptionActive = ! empty($exception['active']);
            $reason = $exception['reason'] ?? '';
            $metadata = isset($exception['metadata']) && is_array($exception['metadata']) ? $exception['metadata'] : [];
            $metadataNote = $metadata['note'] ?? '';
            $approver = $exception['approver']['name'] ?? '';
            $updatedAtDisplay = $this->formatIsoDatetime($exception['updated_at'] ?? null);
            $expiresAtIso = $exception['expires_at'] ?? null;
            $expiresAtDisplay = $this->formatIsoDatetime($expiresAtIso);
            $expiresAtInput = $this->datetimeToInputValue($expiresAtIso);

            $metadataParts = [];
            foreach ($metadata as $metaKey => $metaValue) {
                if ($metaValue === '' || $metaValue === null) {
                    continue;
                }

                $metadataParts[] = wp_strip_all_tags((string) $metaKey) . ': ' . wp_strip_all_tags((string) $metaValue);
            }
            $metadataDisplay = implode(', ', $metadataParts);

            $platforms = isset($item['platforms']) && is_array($item['platforms']) ? $item['platforms'] : [];
            $hardware = isset($item['hardware']) && is_array($item['hardware']) ? $item['hardware'] : [];
            $environment = isset($item['environment']) && is_array($item['environment']) ? $item['environment'] : [];

            $enforcedKey = isset($item['enforced_key']) ? (string) $item['enforced_key'] : '';
            $enforcedKeyOptions = $this->buildEnforcedKeyOptions($item);
            $enforcedKeyDisplay = $this->formatEnforcedKeyLabel($enforcedKey, $enforcedKeyOptions);

            $platformsDisplay = $this->formatCollectionForDisplay($platforms);
            $hardwareDisplay = $this->formatCollectionForDisplay($hardware);
            $environmentDisplay = $this->formatCollectionForDisplay($environment);

            $rowClasses = ['etracker-enforcement-item'];
            if (! $enforced) {
                $rowClasses[] = 'is-not-enforced';
            }
            if ($exceptionActive) {
                $rowClasses[] = 'has-exception';
            }

            $statusPills = [];
            $statusPills[] = sprintf(
                '<span class="etracker-status-pill %1$s">%2$s</span>',
                $enforced ? 'is-success' : 'is-alert',
                $enforced ? esc_html__('Enforced', 'etracker') : esc_html__('Not Enforced', 'etracker')
            );

            if ($exceptionActive) {
                $statusPills[] = '<span class="etracker-status-pill is-warning">' . esc_html__('Exception Active', 'etracker') . '</span>';
            }

            $metaFields = array_filter([
                esc_html__('Reason', 'etracker') => $reason,
                esc_html__('Metadata', 'etracker') => $metadataDisplay,
                esc_html__('Approver', 'etracker') => $approver,
                esc_html__('Last Updated', 'etracker') => $updatedAtDisplay,
                esc_html__('Expires On', 'etracker') => $expiresAtDisplay,
                esc_html__('Enforced Key', 'etracker') => $enforcedKeyDisplay,
                esc_html__('Platforms', 'etracker') => $platformsDisplay,
                esc_html__('Hardware', 'etracker') => $hardwareDisplay,
                esc_html__('Environment', 'etracker') => $environmentDisplay,
            ], static fn ($value) => $value !== null && $value !== '');

            $inputIdBase = sanitize_key($group . '-' . (string) $key);

            echo '<section class="' . esc_attr(implode(' ', $rowClasses)) . '" aria-labelledby="enforcement-' . esc_attr($inputIdBase) . '">';
            echo '<div class="etracker-enforcement-item__header">';
            echo '<div class="etracker-enforcement-item__title">';
            echo '<h3 id="enforcement-' . esc_attr($inputIdBase) . '" class="etracker-enforcement-item__key">' . esc_html((string) $key) . '</h3>';
            echo '</div>';
            echo '<div class="etracker-enforcement-item__statuses">' . implode('', $statusPills) . '</div>';
            echo '</div>';

            if (! empty($metaFields)) {
                echo '<div class="etracker-enforcement-item__meta">';
                foreach ($metaFields as $label => $value) {
                    echo '<div class="etracker-meta-field">';
                    echo '<span class="etracker-meta-field__label">' . esc_html($label) . '</span>';
                    echo '<span class="etracker-meta-field__value">' . esc_html($value) . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            }

            echo '<form method="post" class="etracker-enforcement-item__form">';
            wp_nonce_field('etracker_update_enforcement');
            echo '<input type="hidden" name="etracker_action" value="update_enforcement" />';
            echo '<input type="hidden" name="document_id" value="' . esc_attr($documentId) . '" />';
            echo '<input type="hidden" name="group" value="' . esc_attr($group) . '" />';
            echo '<input type="hidden" name="item_key" value="' . esc_attr((string) $key) . '" />';

            echo '<div class="etracker-form-grid">';

            echo '<div class="etracker-form-field">';
            echo '<label class="etracker-form-label" for="enforced-' . esc_attr($inputIdBase) . '">';
            echo esc_html__('Enforcement', 'etracker');
            echo '<select id="enforced-' . esc_attr($inputIdBase) . '" name="enforced">';
            printf('<option value="1" %s>%s</option>', selected($enforced, true, false), esc_html__('Yes', 'etracker'));
            printf('<option value="0" %s>%s</option>', selected($enforced, false, false), esc_html__('No', 'etracker'));
            echo '</select>';
            echo '</label>';
            echo '</div>';

            echo '<div class="etracker-form-field">';
            echo '<label class="etracker-form-label" for="enforced-key-' . esc_attr($inputIdBase) . '">';
            echo esc_html__('Enforced key', 'etracker');
            echo '<select id="enforced-key-' . esc_attr($inputIdBase) . '" name="enforced_key">';
            foreach ($enforcedKeyOptions as $optionValue => $optionLabel) {
                printf(
                    '<option value="%1$s" %2$s>%3$s</option>',
                    esc_attr((string) $optionValue),
                    selected($enforcedKey, (string) $optionValue, false),
                    esc_html($optionLabel)
                );
            }
            echo '</select>';
            echo '<span class="etracker-help-text">' . esc_html__('Override the matching criteria for this control if needed.', 'etracker') . '</span>';
            echo '</label>';
            echo '</div>';

            echo '<div class="etracker-form-field etracker-form-field--checkbox">';
            echo '<label class="etracker-toggle">';
            printf('<input type="checkbox" name="exception_active" value="1" %s />', checked($exceptionActive, true, false));
            echo '<span>' . esc_html__('Exception active', 'etracker') . '</span>';
            echo '</label>';
            echo '</div>';

            echo '<div class="etracker-form-field etracker-form-field--wide">';
            echo '<label class="etracker-form-label" for="exception-reason-' . esc_attr($inputIdBase) . '">';
            echo esc_html__('Exception reason', 'etracker');
            echo '<textarea id="exception-reason-' . esc_attr($inputIdBase) . '" name="exception_reason" rows="3" placeholder="' . esc_attr__('Document why enforcement is waived…', 'etracker') . '">' . esc_textarea($reason) . '</textarea>';
            echo '</label>';
            echo '</div>';

            echo '<div class="etracker-form-field etracker-form-field--half">';
            echo '<label class="etracker-form-label" for="exception-expires-' . esc_attr($inputIdBase) . '">';
            echo esc_html__('Exception expires', 'etracker');
            echo '<input type="date" id="exception-expires-' . esc_attr($inputIdBase) . '" name="exception_expires_at" value="' . esc_attr($expiresAtInput) . '" />';
            echo '<span class="etracker-help-text">' . esc_html__('Defaults to one year when creating a new exception.', 'etracker') . '</span>';
            echo '</label>';
            echo '</div>';

            echo '<div class="etracker-form-field etracker-form-field--half">';
            echo '<label class="etracker-form-label" for="exception-metadata-note-' . esc_attr($inputIdBase) . '">';
            echo esc_html__('Metadata note', 'etracker');
            echo '<input type="text" id="exception-metadata-note-' . esc_attr($inputIdBase) . '" name="exception_metadata[note]" value="' . esc_attr($metadataNote) . '" placeholder="' . esc_attr__('Add optional context', 'etracker') . '" />';
            echo '</label>';
            echo '</div>';

            echo '<div class="etracker-form-actions">';
            submit_button(esc_html__('Save changes', 'etracker'), 'primary', 'submit', false);
            echo '</div>';

            echo '</div>';
            echo '</form>';

            echo '</section>';
        }

        echo '</div>';
        echo '</div></div>';
    }

    private function calculateEnforcementStats(array $document): array
    {
        $stats = [
            'total' => 0,
            'active_exceptions' => 0,
            'unenforced' => 0,
        ];

        if (! isset($document['Enforced']) || ! is_array($document['Enforced'])) {
            return $stats;
        }

        foreach ($document['Enforced'] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $stats['total']++;

                if (empty($item['enforced'])) {
                    $stats['unenforced']++;
                }

                if (! empty($item['exception']['active'])) {
                    $stats['active_exceptions']++;
                }
            }
        }

        return $stats;
    }

    private function buildEnforcedKeyOptions(array $item): array
    {
        $options = [
            '' => esc_html__('Automatic (match best fit)', 'etracker'),
        ];

        $sources = [
            'platforms' => esc_html__('Platform', 'etracker'),
            'hardware' => esc_html__('Hardware', 'etracker'),
            'environment' => esc_html__('Environment', 'etracker'),
        ];

        foreach ($sources as $sourceKey => $label) {
            if (! isset($item[$sourceKey]) || ! is_array($item[$sourceKey])) {
                continue;
            }

            foreach ($item[$sourceKey] as $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $optionValue = $sourceKey . ':' . $value;
                $options[$optionValue] = sprintf('%s — %s', $label, wp_strip_all_tags((string) $value));
            }
        }

        if (! empty($item['enforced_key']) && ! isset($options[$item['enforced_key']])) {
            $options[$item['enforced_key']] = sprintf(
                /* translators: %s Existing enforced key */
                esc_html__('Existing: %s', 'etracker'),
                wp_strip_all_tags((string) $item['enforced_key'])
            );
        }

        return $options;
    }

    private function formatEnforcedKeyLabel(?string $value, array $options): string
    {
        $value = (string) ($value ?? '');

        if (isset($options[$value])) {
            return $options[$value];
        }

        return $value !== '' ? wp_strip_all_tags($value) : '';
    }

    private function renderShortcodeInfoCard(): string
    {
        $shortcode = '[etracker_inventory]';
        $description = esc_html__('Embed the server inventory management UI on any page or dashboard using this shortcode. Only users with the manage capability will see the content.', 'etracker');

        $html = '<div class="etracker-card etracker-card--info">';
        $html .= '<div class="etracker-card__body">';
        $html .= '<h2 class="etracker-card__title">' . esc_html__('Shortcode Available', 'etracker') . '</h2>';
        $html .= '<p class="etracker-card__description">' . $description . '</p>';
        $html .= '<code class="etracker-code-tag">' . esc_html($shortcode) . '</code>';
        $html .= '</div></div>';

        return $html;
    }

    private function formatCollectionForDisplay($values): string
    {
        if (! is_array($values)) {
            return '';
        }

        $clean = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $clean[] = wp_strip_all_tags((string) $value);
        }

        if (empty($clean)) {
            return '';
        }

        return implode(', ', array_values($clean));
    }

    private function formatIsoDatetime(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        return $this->formatTimestamp(gmdate('Y-m-d H:i:s', $timestamp));
    }

    private function datetimeToInputValue(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '';
        }

        return gmdate('Y-m-d', $timestamp);
    }

    private function renderAuditHistory(string $documentId): void
    {
        echo '<div class="etracker-card">';
        echo '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Audit History', 'etracker') . '</h2></div>';
        echo '<div class="etracker-card__body">';

        $history = $this->auditLogger->historyForDocument($documentId, 50);

        if (empty($history)) {
            echo '<p class="etracker-empty-state">' . esc_html__('No changes recorded yet.', 'etracker') . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<table class="etracker-enforcement-table etracker-audit-history">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Item Type', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Item Key', 'etracker') . '</th>';
        echo '<th>' . esc_html__('User', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Reason', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Previous State', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Current State', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Timestamp', 'etracker') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($history as $entry) {
            $itemType = isset($entry['item_type']) ? wp_strip_all_tags((string) $entry['item_type']) : '';
            $itemKey = isset($entry['item_key']) ? wp_strip_all_tags((string) $entry['item_key']) : '';
            $reason = isset($entry['reason']) ? wp_strip_all_tags((string) $entry['reason']) : '';
            $previous = $this->describeState($entry['previous_state'] ?? null);
            $current = $this->describeState($entry['new_state'] ?? null);
            $timestamp = $this->formatTimestamp($entry['created_at'] ?? null);
            $user = $this->formatUser($entry);

            echo '<tr>';
            echo '<td>' . esc_html($itemType) . '</td>';
            echo '<td>' . esc_html($itemKey) . '</td>';
            echo '<td>' . esc_html($user) . '</td>';
            echo '<td>' . esc_html($reason) . '</td>';
            echo '<td>' . esc_html($previous) . '</td>';
            echo '<td>' . esc_html($current) . '</td>';
            echo '<td>' . esc_html($timestamp) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    private function describeState(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return '';
        }

        $parts = [];

        if (array_key_exists('enforced', $decoded)) {
            $parts[] = sprintf(
                '%s %s',
                __('Enforced:', 'etracker'),
                ! empty($decoded['enforced']) ? __('Yes', 'etracker') : __('No', 'etracker')
            );
        }

        if (isset($decoded['enforced_key']) && $decoded['enforced_key'] !== '') {
            $parts[] = sprintf(
                '%s %s',
                __('Enforced key:', 'etracker'),
                wp_strip_all_tags((string) $decoded['enforced_key'])
            );
        }

        $exception = $decoded['exception'] ?? null;
        if (is_array($exception)) {
            $status = ! empty($exception['active']) ? __('Active', 'etracker') : __('Inactive', 'etracker');
            $parts[] = sprintf('%s %s', __('Exception:', 'etracker'), $status);

            if (! empty($exception['reason'])) {
                $parts[] = sprintf('%s %s', __('Reason:', 'etracker'), wp_strip_all_tags((string) $exception['reason']));
            }

            if (! empty($exception['expires_at'])) {
                $parts[] = sprintf('%s %s', __('Expires:', 'etracker'), wp_strip_all_tags((string) $exception['expires_at']));
            }

            if (isset($exception['metadata']) && is_array($exception['metadata'])) {
                $metaParts = [];
                foreach ($exception['metadata'] as $metaKey => $metaValue) {
                    if ($metaValue === '' || $metaValue === null) {
                        continue;
                    }

                    $metaParts[] = wp_strip_all_tags((string) $metaKey) . '=' . wp_strip_all_tags((string) $metaValue);
                }

                if (! empty($metaParts)) {
                    $parts[] = sprintf('%s %s', __('Metadata:', 'etracker'), implode(', ', $metaParts));
                }
            }
        }

        return implode(' | ', array_filter($parts, static fn ($part) => $part !== ''));
    }

    private function formatTimestamp(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $dateFormat = (string) get_option('date_format', 'Y-m-d');
        $timeFormat = (string) get_option('time_format', 'H:i');
        $formatted = get_date_from_gmt($datetime, trim($dateFormat . ' ' . $timeFormat));

        return $formatted !== '' ? $formatted : $datetime;
    }

    private function formatUser(array $entry): string
    {
        $name = isset($entry['user_name']) ? wp_strip_all_tags((string) $entry['user_name']) : '';
        $id = isset($entry['user_id']) ? (int) $entry['user_id'] : 0;

        if ($name !== '' && $id > 0) {
            return sprintf('%s (#%d)', $name, $id);
        }

        if ($name !== '') {
            return $name;
        }

        if ($id > 0) {
            return sprintf('#%d', $id);
        }

        return __('System', 'etracker');
    }

    private function maybeHandlePost(): void
    {
        if (! isset($_POST['etracker_action']) || $_POST['etracker_action'] !== 'update_enforcement') {
            return;
        }

        check_admin_referer('etracker_update_enforcement');

        if (! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'etracker'));
        }

        $documentId = isset($_POST['document_id']) ? sanitize_text_field(wp_unslash($_POST['document_id'])) : '';
        $group = isset($_POST['group']) ? sanitize_text_field(wp_unslash($_POST['group'])) : '';
        $itemKey = isset($_POST['item_key']) ? sanitize_text_field(wp_unslash($_POST['item_key'])) : '';

        $enforced = isset($_POST['enforced']) ? (bool) (int) wp_unslash($_POST['enforced']) : true;
        $exceptionActive = isset($_POST['exception_active']);
        $reason = isset($_POST['exception_reason']) ? sanitize_textarea_field(wp_unslash($_POST['exception_reason'])) : '';
        $metadataNote = isset($_POST['exception_metadata']['note'])
            ? sanitize_text_field(wp_unslash($_POST['exception_metadata']['note']))
            : '';
        $enforcedKey = isset($_POST['enforced_key']) ? sanitize_text_field(wp_unslash($_POST['enforced_key'])) : '';
        $exceptionExpiresAt = isset($_POST['exception_expires_at']) ? sanitize_text_field(wp_unslash($_POST['exception_expires_at'])) : '';

        $payload = [
            'enforced' => $enforced,
            'enforced_key' => $enforcedKey,
            'exception_active' => $exceptionActive,
            'exception_reason' => $reason,
            'exception_metadata' => $exceptionActive ? ['note' => $metadataNote] : [],
            'exception_expires_at' => $exceptionExpiresAt,
        ];

        $user = wp_get_current_user();
        $userContext = [
            'user_id' => get_current_user_id(),
            'user_name' => $user ? $user->display_name : '',
        ];

        try {
            $this->exceptionManager->updateItem($documentId, $group, $itemKey, $payload, $userContext);
            add_settings_error('etracker_inventory', 'etracker-success', esc_html__('Enforcement updated successfully.', 'etracker'), 'updated');
        } catch (Throwable $exception) {
            $details = wp_strip_all_tags($exception->getMessage());
            add_settings_error(
                'etracker_inventory',
                'etracker-error',
                esc_html(sprintf(esc_html__('Failed to update enforcement: %s', 'etracker'), $details)),
                'error'
            );
        }
    }
}

