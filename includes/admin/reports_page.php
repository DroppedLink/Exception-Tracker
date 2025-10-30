<?php

namespace ETracker\Admin;

use ETracker\Services\ReportService;
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function max;
use function number_format_i18n;
use function sprintf;
use function wp_die;
use function wp_enqueue_style;
use function get_date_from_gmt;
use function get_option;

class Reports_Page
{
    private ReportService $reportService;

    public function __construct(?ReportService $reportService = null)
    {
        $this->reportService = $reportService ?? new ReportService();
    }

    public function render(): void
    {
        wp_enqueue_style('etracker-ui');

        if (! current_user_can(ETRACKER_MANAGE_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'etracker'));
        }

        $expiringDays = isset($_GET['expiring_window']) ? max(1, (int) $_GET['expiring_window']) : 45;
        $unenforcedLimit = isset($_GET['unenforced_limit']) ? max(10, (int) $_GET['unenforced_limit']) : 150;

        $report = $this->reportService->compile($expiringDays, $unenforcedLimit);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('ETracker Reports', 'etracker') . '</h1>';

        $this->renderFilterBar($expiringDays, $unenforcedLimit);
        $this->renderSummary($report['summary']);
        $this->renderExpiringTable($report['expiring'], $expiringDays);
        $this->renderUnenforcedTable($report['unenforced'], $unenforcedLimit);

        echo '</div>';
    }

    private function renderFilterBar(int $expiringDays, int $unenforcedLimit): void
    {
        $currentUrl = esc_url(add_query_arg([]));

        echo '<form method="get" action="' . $currentUrl . '" class="etracker-card etracker-report-filters">';
        foreach (['page', 'expiring_window', 'unenforced_limit'] as $param) {
            if ($param === 'page') {
                echo '<input type="hidden" name="page" value="etracker-reports" />';
            }
        }

        echo '<div class="etracker-card__body etracker-report-filters__grid">';
        echo '<label>';
        echo '<span>' . esc_html__('Expiring window (days)', 'etracker') . '</span>';
        printf('<input type="number" min="1" name="expiring_window" value="%d" />', $expiringDays);
        echo '</label>';

        echo '<label>';
        echo '<span>' . esc_html__('Unenforced row cap', 'etracker') . '</span>';
        printf('<input type="number" min="10" step="10" name="unenforced_limit" value="%d" />', $unenforcedLimit);
        echo '</label>';

        echo '<div class="etracker-report-filters__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Update', 'etracker') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }

    private function renderSummary(array $summary): void
    {
        echo '<div class="etracker-card">';
        echo '<div class="etracker-card__header"><h2 class="etracker-card__title">' . esc_html__('Active Exception Overview', 'etracker') . '</h2></div>';
        echo '<div class="etracker-card__body">';

        echo '<div class="etracker-summary-metrics">';
        $metrics = [
            ['label' => esc_html__('Active Exceptions', 'etracker'), 'value' => number_format_i18n((int) ($summary['total_active'] ?? 0))],
            ['label' => esc_html__('Overdue', 'etracker'), 'value' => number_format_i18n((int) ($summary['overdue'] ?? 0)), 'context' => 'warning'],
            ['label' => esc_html__('Due Soon', 'etracker'), 'value' => number_format_i18n((int) ($summary['due_soon'] ?? 0)), 'context' => 'info'],
            ['label' => esc_html__('Due Later', 'etracker'), 'value' => number_format_i18n((int) ($summary['due_later'] ?? 0))],
        ];

        foreach ($metrics as $metric) {
            $classes = ['etracker-summary-metric'];
            if (! empty($metric['context'])) {
                $classes[] = 'is-' . esc_attr($metric['context']);
            }

            echo '<div class="' . esc_attr(implode(' ', $classes)) . '">';
            echo '<span class="etracker-summary-metric__value">' . esc_html($metric['value']) . '</span>';
            echo '<span class="etracker-summary-metric__label">' . esc_html($metric['label']) . '</span>';
            echo '</div>';
        }
        echo '</div>';

        if (! empty($summary['by_group'])) {
            echo '<div class="etracker-report-groups">';
            echo '<h3>' . esc_html__('By Control Group', 'etracker') . '</h3>';
            echo '<ul class="etracker-report-group-list">';
            foreach ($summary['by_group'] as $group => $count) {
                echo '<li><span class="etracker-report-group">' . esc_html((string) $group) . '</span><span class="etracker-report-count">' . esc_html(number_format_i18n((int) $count)) . '</span></li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function renderExpiringTable(array $rows, int $expiringDays): void
    {
        echo '<div class="etracker-card">';
        echo '<div class="etracker-card__header">';
        echo '<h2 class="etracker-card__title">' . esc_html__('Exceptions Approaching Renewal', 'etracker') . '</h2>';
        echo '<p class="etracker-card__subtitle">' . esc_html(sprintf(esc_html__('Showing exceptions expiring within %d days or already expired.', 'etracker'), $expiringDays)) . '</p>';
        echo '</div>';
        echo '<div class="etracker-card__body">';

        if (empty($rows)) {
            echo '<p class="etracker-empty-state">' . esc_html__('No exceptions are nearing their expiration window.', 'etracker') . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<div class="etracker-report-table-wrapper">';
        echo '<table class="etracker-report-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Server', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Control', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Group', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Expires On', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Time Remaining', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Approver', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Reason', 'etracker') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $link = $this->buildDetailLink($row['document_id'] ?? '');
            $hostname = $row['hostname'] ?? '';
            $control = $row['item_key'] ?? '';
            $group = $row['group'] ?? '';
            $expires = $row['expires_at'] ?? '';
            $timeRemaining = $this->formatTimeRemaining($row['days_until'] ?? null);
            $approver = $row['approver'] ?? '';
            $reason = $row['reason'] ?? '';

            echo '<tr>';
            echo '<td><a href="' . esc_url($link) . '">' . esc_html($hostname) . '</a></td>';
            echo '<td>' . esc_html($control) . '</td>';
            echo '<td>' . esc_html($group) . '</td>';
            echo '<td>' . esc_html($this->formatIsoDate($expires)) . '</td>';
            echo '<td>' . esc_html($timeRemaining) . '</td>';
            echo '<td>' . esc_html($approver) . '</td>';
            echo '<td>' . esc_html($reason) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div></div>';
    }

    private function renderUnenforcedTable(array $rows, int $limit): void
    {
        echo '<div class="etracker-card">';
        echo '<div class="etracker-card__header">';
        echo '<h2 class="etracker-card__title">' . esc_html__('Controls Marked Not Enforced', 'etracker') . '</h2>';
        echo '<p class="etracker-card__subtitle">' . esc_html(sprintf(esc_html__('Limited to the first %d records.', 'etracker'), $limit)) . '</p>';
        echo '</div>';
        echo '<div class="etracker-card__body">';

        if (empty($rows)) {
            echo '<p class="etracker-empty-state">' . esc_html__('Great news! All controls are currently enforced.', 'etracker') . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<div class="etracker-report-table-wrapper">';
        echo '<table class="etracker-report-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Server', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Control', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Group', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Exception', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Enforced Key', 'etracker') . '</th>';
        echo '<th>' . esc_html__('Reason', 'etracker') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $link = $this->buildDetailLink($row['document_id'] ?? '');
            $hostname = $row['hostname'] ?? '';
            $control = $row['item_key'] ?? '';
            $group = $row['group'] ?? '';
            $enforcedKey = $row['enforced_key'] ?? '';
            $reason = $row['reason'] ?? '';

            $exceptionBadge = ! empty($row['exception_active'])
                ? '<span class="etracker-status-pill is-warning">' . esc_html__('Active', 'etracker') . '</span>'
                : '<span class="etracker-status-pill">' . esc_html__('None', 'etracker') . '</span>';

            if (! empty($row['exception_expires_at'])) {
                $exceptionBadge .= '<span class="etracker-report-subtext">' . esc_html($this->formatIsoDate($row['exception_expires_at'])) . '</span>';
            }

            echo '<tr>';
            echo '<td><a href="' . esc_url($link) . '">' . esc_html($hostname) . '</a></td>';
            echo '<td>' . esc_html($control) . '</td>';
            echo '<td>' . esc_html($group) . '</td>';
            echo '<td class="etracker-report-badges">' . $exceptionBadge . '</td>';
            echo '<td>' . esc_html($enforcedKey) . '</td>';
            echo '<td>' . esc_html($reason) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div></div>';
    }

    private function buildDetailLink(string $documentId): string
    {
        if ($documentId === '') {
            return '#';
        }

        return add_query_arg(
            [
                'page' => 'etracker',
                'action' => 'view',
                'document_id' => $documentId,
            ],
            admin_url('admin.php')
        );
    }

    private function formatIsoDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        $timestamp = strtotime($iso);
        if ($timestamp === false) {
            return $iso;
        }

        $date = get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp), get_option('date_format', 'Y-m-d'));
        return $date !== '' ? $date : $iso;
    }

    private function formatTimeRemaining(?int $days): string
    {
        if ($days === null) {
            return esc_html__('Unknown', 'etracker');
        }

        if ($days < 0) {
            return sprintf(esc_html__('%d days overdue', 'etracker'), abs($days));
        }

        if ($days === 0) {
            return esc_html__('Due today', 'etracker');
        }

        return sprintf(esc_html__('%d days remaining', 'etracker'), $days);
    }
}

