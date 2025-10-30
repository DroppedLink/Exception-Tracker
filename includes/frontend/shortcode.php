<?php

namespace ETracker\Frontend;

use ETracker\Repositories\InventoryRepository;
use ETracker\Services\AuditLogger;
use ETracker\Services\ExceptionManager;
use ETracker\Support\DocumentHelper;
use Throwable;
use function add_query_arg;
use function add_shortcode;
use function array_filter;
use function check_admin_referer;
use function checked;
use function current_user_can;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function get_current_user_id;
use function get_date_from_gmt;
use function get_option;
use function get_permalink;
use function is_array;
use function is_user_logged_in;
use function json_decode;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function selected;
use function sprintf;
use function wp_enqueue_style;
use function wp_get_current_user;
use function wp_nonce_field;
use function wp_strip_all_tags;
use function wp_unslash;

class Shortcode
{
    private InventoryRepository $repository;
    private ExceptionManager $exceptionManager;
    private AuditLogger $auditLogger;
    private array $messages = [];
    private array $errors = [];
    private array $criteria = [];

    public function __construct(?InventoryRepository $repository = null, ?ExceptionManager $exceptionManager = null, ?AuditLogger $auditLogger = null)
    {
        $this->repository = $repository ?? new InventoryRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->exceptionManager = $exceptionManager ?? new ExceptionManager($this->repository, $this->auditLogger);
    }

    public function register(): void
    {
        add_shortcode('etracker_inventory', [$this, 'render']);
    }

    public function render($atts = [], $content = '', $tag = ''): string
    {
        if (! is_user_logged_in() || ! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            return '<div class="etracker-wrap"><div class="etracker-message error">' . esc_html__('You do not have permission to view this inventory.', 'etracker') . '</div></div>';
        }

        wp_enqueue_style('etracker-ui');

        $this->criteria = $this->collectCriteria();
        $this->maybeHandlePost();

        $documentId = isset($_GET['etracker_doc'])
            ? sanitize_text_field(wp_unslash($_GET['etracker_doc']))
            : '';

        $output = '<div class="etracker-wrap etracker-frontend">';
        $output .= $this->renderMessages();

        if ($documentId !== '') {
            $output .= $this->renderDetailView($documentId);
        } else {
            $output .= $this->renderListView();
        }

        $output .= '</div>';

        return $output;
    }

    private function renderMessages(): string
    {
        if (empty($this->messages) && empty($this->errors)) {
            return '';
        }

        $html = '<div class="etracker-messages">';

        foreach ($this->messages as $message) {
            $html .= '<div class="etracker-message">' . esc_html($message) . '</div>';
        }

        foreach ($this->errors as $error) {
            $html .= '<div class="etracker-message error">' . esc_html($error) . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderListView(): string
    {
        $criteria = $this->criteria;
        $result = $this->repository->search($criteria, 25, 0);
        $items = $result['items'] ?? [];
        $activeCriteria = $this->getActiveCriteria();

        $html = '<div class="etracker-card">';
        $html .= '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Inventory Search', 'etracker') . '</h2></div>';
        $html .= '<div class="etracker-card__body">';
        $html .= '<form method="get" class="etracker-search-form">';

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
            $html .= '<label>' . esc_html($label);
            $html .= '<input type="text" name="' . esc_attr($key) . '" value="' . $value . '" />';
            $html .= '</label>';
        }

        $html .= '<div class="etracker-search-actions">';
        $html .= '<button type="submit" class="etracker-button">' . esc_html__('Filter', 'etracker') . '</button>';

        if (! empty($activeCriteria)) {
            $html .= '<a class="etracker-button secondary" href="' . esc_url(get_permalink()) . '">' . esc_html__('Clear', 'etracker') . '</a>';
        }

        $html .= '</div>';
        $html .= '</form>';

        if (empty($items)) {
            $html .= '<div class="etracker-empty-state">' . esc_html__('No servers match your filters yet. Adjust the search and try again.', 'etracker') . '</div>';
        } else {
            $html .= '<div class="etracker-search-results">';
            $html .= '<table class="etracker-search-table">';
            $html .= '<thead><tr>';
            $html .= '<th>' . esc_html__('Hostname', 'etracker') . '</th>';
            $html .= '<th>' . esc_html__('Application', 'etracker') . '</th>';
            $html .= '<th>' . esc_html__('Reference Code', 'etracker') . '</th>';
            $html .= '<th>' . esc_html__('Owned By', 'etracker') . '</th>';
            $html .= '<th>' . esc_html__('Managed By', 'etracker') . '</th>';
            $html .= '<th>' . esc_html__('Exceptions', 'etracker') . '</th>';
            $html .= '<th>' . esc_html__('Actions', 'etracker') . '</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($items as $item) {
                $documentId = DocumentHelper::getId($item);
                if ($documentId === '') {
                    continue;
                }

                $detailUrl = add_query_arg(
                    array_merge($activeCriteria, ['etracker_doc' => $documentId]),
                    get_permalink()
                );

                $html .= '<tr>';
                $html .= '<td>' . esc_html($item['ESD']['hostname'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($item['ESD']['instance']['name'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($item['ESD']['instance']['reference_code'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($item['ESD']['instance']['owned_by']['name'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($item['ESD']['instance']['managed_by']['name'] ?? '') . '</td>';
                $html .= '<td>' . esc_html((string) $this->countExceptions($item)) . '</td>';
                $html .= '<td><a href="' . esc_url($detailUrl) . '" class="etracker-button secondary">' . esc_html__('Review', 'etracker') . '</a></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }

    private function renderDetailView(string $documentId): string
    {
        $baseUrl = add_query_arg($this->getActiveCriteria(), get_permalink());
        $document = $this->repository->findById($documentId);

        $html = '<div class="etracker-back-link"><a href="' . esc_url($baseUrl) . '">' . esc_html__('← Back to results', 'etracker') . '</a></div>';

        if ($document === null) {
            $this->errors[] = esc_html__('Inventory document not found or no longer exists.', 'etracker');
            return $html;
        }

        $html .= $this->renderSummaryCard($document);
        $html .= $this->renderEnforcementCard('CIS', $documentId, $document['Enforced']['CIS'] ?? []);
        $html .= $this->renderEnforcementCard('Agents', $documentId, $document['Enforced']['Agents'] ?? []);
        $html .= $this->renderAuditHistory($documentId);

        return $html;
    }

    private function renderSummaryCard(array $document): string
    {
        $esd = $document['ESD'] ?? [];

        $rows = [
            esc_html__('Hostname', 'etracker') => $esd['hostname'] ?? '',
            esc_html__('FQDN', 'etracker') => $esd['fqdn'] ?? '',
            esc_html__('Application', 'etracker') => $esd['instance']['name'] ?? '',
            esc_html__('Reference Code', 'etracker') => $esd['instance']['reference_code'] ?? '',
            esc_html__('Owned By', 'etracker') => $esd['instance']['owned_by']['name'] ?? '',
            esc_html__('Managed By', 'etracker') => $esd['instance']['managed_by']['name'] ?? '',
            esc_html__('GVP', 'etracker') => $esd['instance']['gvp']['name'] ?? '',
        ];

        $html = '<div class="etracker-card">';
        $html .= '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Summary', 'etracker') . '</h2></div>';
        $html .= '<div class="etracker-card__body">';
        $html .= '<table class="etracker-summary-table"><tbody>';

        foreach ($rows as $label => $value) {
            $html .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div></div>';

        return $html;
    }

    private function renderEnforcementCard(string $group, string $documentId, array $items): string
    {
        $html = '<div class="etracker-card">';
        $html .= '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html(sprintf(esc_html__('%s Enforcement', 'etracker'), $group)) . '</h2></div>';
        $html .= '<div class="etracker-card__body">';

        if (empty($items)) {
            $html .= '<p class="etracker-empty-state">' . esc_html__('No enforced items found.', 'etracker') . '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $html .= '<table class="etracker-enforcement-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('Item', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Status', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Reason', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Metadata', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Approver', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Last Updated', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Actions', 'etracker') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($items as $key => $item) {
            $enforced = ! empty($item['enforced']);
            $exception = $item['exception'] ?? [];
            $exceptionActive = ! empty($exception['active']);
            $reason = $exception['reason'] ?? '';
            $metadata = isset($exception['metadata']) && is_array($exception['metadata']) ? $exception['metadata'] : [];
            $metadataNote = $metadata['note'] ?? '';
            $metadataDisplay = $this->formatMetadata($metadata);
            $approver = $exception['approver']['name'] ?? '';
            $updatedAt = $exception['updated_at'] ?? '';

            $rowClasses = ['etracker-enforcement-row'];
            if ($exceptionActive) {
                $rowClasses[] = 'exception-active';
            }

            $html .= '<tr class="' . esc_attr(implode(' ', $rowClasses)) . '">';
            $html .= '<td data-label="' . esc_attr__('Item', 'etracker') . '">' . esc_html((string) $key) . '</td>';

            $statusBadges = '<span class="etracker-pill' . ($enforced ? ' active' : ' warning') . '">' . ($enforced ? esc_html__('Enforced', 'etracker') : esc_html__('Not Enforced', 'etracker')) . '</span>';
            if ($exceptionActive) {
                $statusBadges .= ' <span class="etracker-pill warning">' . esc_html__('Exception', 'etracker') . '</span>';
            }

            $html .= '<td data-label="' . esc_attr__('Status', 'etracker') . '">' . $statusBadges . '</td>';
            $html .= '<td data-label="' . esc_attr__('Reason', 'etracker') . '">' . esc_html($reason) . '</td>';
            $html .= '<td data-label="' . esc_attr__('Metadata', 'etracker') . '">' . esc_html($metadataDisplay) . '</td>';
            $html .= '<td data-label="' . esc_attr__('Approver', 'etracker') . '">' . esc_html($approver) . '</td>';
            $html .= '<td data-label="' . esc_attr__('Last Updated', 'etracker') . '">' . esc_html($updatedAt) . '</td>';

            $html .= '<td data-label="' . esc_attr__('Actions', 'etracker') . '">';
            $html .= '<form method="post" class="etracker-action-form">';
            wp_nonce_field('etracker_shortcode_update');
            $html .= '<input type="hidden" name="etracker_shortcode_action" value="update_enforcement" />';
            $html .= '<input type="hidden" name="document_id" value="' . esc_attr($documentId) . '" />';
            $html .= '<input type="hidden" name="group" value="' . esc_attr($group) . '" />';
            $html .= '<input type="hidden" name="item_key" value="' . esc_attr((string) $key) . '" />';

            $html .= '<label>';
            $html .= '<span class="screen-reader-text">' . esc_html__('Enforced', 'etracker') . '</span>';
            $html .= '<select name="enforced">';
            $html .= '<option value="1"' . selected($enforced, true, false) . '>' . esc_html__('Yes', 'etracker') . '</option>';
            $html .= '<option value="0"' . selected($enforced, false, false) . '>' . esc_html__('No', 'etracker') . '</option>';
            $html .= '</select>';
            $html .= '</label>';

            $html .= '<label class="etracker-action-form__checkbox">';
            $html .= '<input type="checkbox" name="exception_active" value="1"' . checked($exceptionActive, true, false) . ' /> ' . esc_html__('Exception Active', 'etracker');
            $html .= '</label>';

            $html .= '<label class="screen-reader-text" for="shortcode-exception-reason-' . esc_attr((string) $key) . '">' . esc_html__('Reason', 'etracker') . '</label>';
            $html .= '<textarea id="shortcode-exception-reason-' . esc_attr((string) $key) . '" name="exception_reason" rows="2" cols="20">' . esc_textarea($reason) . '</textarea>';

            $html .= '<label class="screen-reader-text" for="shortcode-exception-metadata-note-' . esc_attr((string) $key) . '">' . esc_html__('Metadata Note', 'etracker') . '</label>';
            $html .= '<input type="text" id="shortcode-exception-metadata-note-' . esc_attr((string) $key) . '" name="exception_metadata[note]" value="' . esc_attr($metadataNote) . '" placeholder="' . esc_attr__('Metadata note', 'etracker') . '" />';

            $html .= '<button type="submit" class="etracker-button">' . esc_html__('Save', 'etracker') . '</button>';
            $html .= '</form>';
            $html .= '</td>';

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div></div>';

        return $html;
    }

    private function renderAuditHistory(string $documentId): string
    {
        $history = $this->auditLogger->historyForDocument($documentId, 50);

        $html = '<div class="etracker-card">';
        $html .= '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Audit History', 'etracker') . '</h2></div>';
        $html .= '<div class="etracker-card__body">';

        if (empty($history)) {
            $html .= '<p class="etracker-empty-state">' . esc_html__('No changes recorded yet.', 'etracker') . '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $html .= '<table class="etracker-enforcement-table etracker-audit-history">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('Item Type', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Item Key', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('User', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Reason', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Previous State', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Current State', 'etracker') . '</th>';
        $html .= '<th>' . esc_html__('Timestamp', 'etracker') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($history as $entry) {
            $previous = $this->describeState($entry['previous_state'] ?? null);
            $current = $this->describeState($entry['new_state'] ?? null);
            $user = $this->formatUser($entry);

            $html .= '<tr>';
            $html .= '<td>' . esc_html($entry['item_type'] ?? '') . '</td>';
            $html .= '<td>' . esc_html($entry['item_key'] ?? '') . '</td>';
            $html .= '<td>' . esc_html($user) . '</td>';
            $html .= '<td>' . esc_html($entry['reason'] ?? '') . '</td>';
            $html .= '<td>' . esc_html($previous) . '</td>';
            $html .= '<td>' . esc_html($current) . '</td>';
            $html .= '<td>' . esc_html($this->formatTimestamp($entry['created_at'] ?? null)) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div></div>';

        return $html;
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
                esc_html__('Enforced:', 'etracker'),
                ! empty($decoded['enforced']) ? esc_html__('Yes', 'etracker') : esc_html__('No', 'etracker')
            );
        }

        $exception = $decoded['exception'] ?? null;
        if (is_array($exception)) {
            $status = ! empty($exception['active']) ? esc_html__('Active', 'etracker') : esc_html__('Inactive', 'etracker');
            $parts[] = sprintf('%s %s', esc_html__('Exception:', 'etracker'), $status);

            if (! empty($exception['reason'])) {
                $parts[] = sprintf('%s %s', esc_html__('Reason:', 'etracker'), wp_strip_all_tags((string) $exception['reason']));
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
                    $parts[] = sprintf('%s %s', esc_html__('Metadata:', 'etracker'), implode(', ', $metaParts));
                }
            }
        }

        return implode(' | ', $parts);
    }

    private function formatMetadata(array $metadata): string
    {
        if (empty($metadata)) {
            return '';
        }

        $parts = [];
        foreach ($metadata as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $parts[] = wp_strip_all_tags((string) $key) . ': ' . wp_strip_all_tags((string) $value);
        }

        return implode(', ', $parts);
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

        return esc_html__('System', 'etracker');
    }

    private function maybeHandlePost(): void
    {
        if (! isset($_POST['etracker_shortcode_action']) || $_POST['etracker_shortcode_action'] !== 'update_enforcement') {
            return;
        }

        if (! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            $this->errors[] = esc_html__('You do not have permission to update enforcement.', 'etracker');
            return;
        }

        check_admin_referer('etracker_shortcode_update');

        $documentId = isset($_POST['document_id']) ? sanitize_text_field(wp_unslash($_POST['document_id'])) : '';
        $group = isset($_POST['group']) ? sanitize_text_field(wp_unslash($_POST['group'])) : '';
        $itemKey = isset($_POST['item_key']) ? sanitize_text_field(wp_unslash($_POST['item_key'])) : '';

        $enforced = isset($_POST['enforced']) ? (bool) (int) wp_unslash($_POST['enforced']) : true;
        $exceptionActive = isset($_POST['exception_active']);
        $reason = isset($_POST['exception_reason']) ? sanitize_textarea_field(wp_unslash($_POST['exception_reason'])) : '';
        $metadataNote = isset($_POST['exception_metadata']['note']) ? sanitize_text_field(wp_unslash($_POST['exception_metadata']['note'])) : '';

        $payload = [
            'enforced' => $enforced,
            'exception_active' => $exceptionActive,
            'exception_reason' => $reason,
            'exception_metadata' => $exceptionActive ? ['note' => $metadataNote] : [],
        ];

        $user = wp_get_current_user();
        $userContext = [
            'user_id' => get_current_user_id(),
            'user_name' => $user ? $user->display_name : '',
        ];

        try {
            $result = $this->exceptionManager->updateItem($documentId, $group, $itemKey, $payload, $userContext);

            if ($result['previous'] === $result['current']) {
                $this->messages[] = esc_html__('No changes were detected for this item.', 'etracker');
            } else {
                $this->messages[] = esc_html__('Enforcement updated successfully.', 'etracker');
            }
        } catch (Throwable $exception) {
            $this->errors[] = esc_html(sprintf(esc_html__('Failed to update enforcement: %s', 'etracker'), wp_strip_all_tags($exception->getMessage())));
        }
    }

    private function countExceptions(array $document): int
    {
        $count = 0;

        if (! isset($document['Enforced']) || ! is_array($document['Enforced'])) {
            return 0;
        }

        foreach ($document['Enforced'] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (! empty($item['exception']['active'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function collectCriteria(): array
    {
        $fields = ['hostname', 'reference_code', 'owned_by', 'managed_by', 'gvp', 'application'];
        $criteria = [];

        foreach ($fields as $field) {
            if (isset($_GET[$field])) {
                $criteria[$field] = sanitize_text_field(wp_unslash($_GET[$field]));
            }
        }

        return $criteria;
    }

    private function getActiveCriteria(): array
    {
        return array_filter(
            $this->criteria,
            static fn ($value) => $value !== null && $value !== ''
        );
    }
}


