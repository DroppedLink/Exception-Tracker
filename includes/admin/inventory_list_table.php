<?php

namespace ETracker\Admin;

use ETracker\Repositories\InventoryRepository;
use ETracker\Support\DocumentHelper;
use Throwable;
use WP_List_Table;
use function add_query_arg;
use function admin_url;
use function esc_html;
use function esc_html__;
use function esc_url;
use function is_array;
use function number_format_i18n;
use function sprintf;

if (! class_exists(WP_List_Table::class)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Inventory_List_Table extends WP_List_Table
{
    private InventoryRepository $repository;
    private array $criteria = [];
    private ?string $lastError = null;

    public function __construct(InventoryRepository $repository)
    {
        parent::__construct([
            'singular' => 'server',
            'plural' => 'servers',
            'ajax' => false,
        ]);

        $this->repository = $repository;
    }

    public function setCriteria(array $criteria): void
    {
        $this->criteria = $criteria;
    }

    public function prepare_items(): void
    {
        $perPage = $this->get_items_per_page('etracker_servers_per_page', 20);
        $currentPage = max(1, $this->get_pagenum());
        $skip = ($currentPage - 1) * $perPage;

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->lastError = null;

        try {
            $result = $this->repository->search($this->criteria, $perPage, $skip);
        } catch (Throwable $exception) {
            $this->items = [];
            $this->lastError = $exception->getMessage();
            $this->set_pagination_args([
                'total_items' => 0,
                'per_page' => $perPage,
                'total_pages' => 1,
            ]);

            return;
        }

        $this->items = $result['items'];
        $total = isset($result['total']) ? (int) $result['total'] : count($this->items);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function get_columns(): array
    {
        return [
            'hostname' => esc_html__('Hostname', 'etracker'),
            'fqdn' => esc_html__('FQDN', 'etracker'),
            'application' => esc_html__('Application', 'etracker'),
            'reference_code' => esc_html__('Reference Code', 'etracker'),
            'owned_by' => esc_html__('Owned By', 'etracker'),
            'managed_by' => esc_html__('Managed By', 'etracker'),
            'gvp' => esc_html__('GVP', 'etracker'),
            'exceptions' => esc_html__('Exceptions', 'etracker'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [];
    }

    public function column_default($item, $column_name): string
    {
        return match ($column_name) {
            'fqdn' => esc_html($item['ESD']['fqdn'] ?? ''),
            'application' => esc_html($item['ESD']['instance']['name'] ?? ''),
            'reference_code' => esc_html($item['ESD']['instance']['reference_code'] ?? ''),
            'owned_by' => esc_html($item['ESD']['instance']['owned_by']['name'] ?? ''),
            'managed_by' => esc_html($item['ESD']['instance']['managed_by']['name'] ?? ''),
            'gvp' => esc_html($item['ESD']['instance']['gvp']['name'] ?? ''),
            'exceptions' => number_format_i18n($this->countExceptions($item)),
            default => '',
        };
    }

    public function column_hostname($item): string
    {
        $hostname = $item['ESD']['hostname'] ?? '';
        $documentId = DocumentHelper::getId($item);

        if ($documentId === '') {
            return esc_html($hostname);
        }

        $url = add_query_arg(
            [
                'page' => 'etracker',
                'action' => 'view',
                'document_id' => $documentId,
            ],
            admin_url('admin.php')
        );

        $link = sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($hostname));

        $actions = [
            'view' => sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('View details', 'etracker')),
        ];

        return $link . $this->row_actions($actions);
    }

    public function no_items(): void
    {
        echo esc_html__('No servers found. Adjust your filters and try again.', 'etracker');
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
}

