<?php

namespace ETracker\Services;

use ETracker\Repositories\InventoryRepository;
use ETracker\Support\DocumentHelper;
use function array_slice;
use function is_array;
use function max;
use function strtotime;
use function time;
use function arsort;
use function usort;
use function strcmp;
use function count;
use const DAY_IN_SECONDS;

class ReportService
{
    private InventoryRepository $repository;

    public function __construct(?InventoryRepository $repository = null)
    {
        $this->repository = $repository ?? new InventoryRepository();
    }

    /**
     * @return array{
     *     expiring: array<int, array>,
     *     summary: array,
     *     unenforced: array<int, array>
     * }
     */
    public function compile(int $expiringWindowDays = 45, int $unenforcedLimit = 150): array
    {
        $now = time();
        $threshold = $now + (max(1, $expiringWindowDays) * DAY_IN_SECONDS);

        $expiring = [];
        $summary = [
            'total_active' => 0,
            'by_group' => [],
            'overdue' => 0,
            'due_soon' => 0,
            'due_later' => 0,
        ];
        $unenforced = [];

        foreach ($this->repository->iterateAll(200) as $document) {
            $documentId = DocumentHelper::getId($document);
            $hostname = $document['ESD']['hostname'] ?? '';
            $application = $document['ESD']['instance']['name'] ?? '';

            foreach (DocumentHelper::iterateEnforcedItems($document) as $item) {
                $group = $item['group'];
                $itemKey = $item['item_key'];
                $data = $item['data'];

                $enforced = ! empty($data['enforced']);
                $enforcedKey = $data['enforced_key'] ?? '';

                $exception = is_array($data['exception'] ?? null) ? $data['exception'] : [];
                $exceptionActive = ! empty($exception['active']);
                $expiresAtIso = isset($exception['expires_at']) ? (string) $exception['expires_at'] : '';
                $expiresTimestamp = $expiresAtIso !== '' ? strtotime($expiresAtIso) : null;
                $daysUntil = $expiresTimestamp !== null ? (int) floor(($expiresTimestamp - $now) / DAY_IN_SECONDS) : null;

                if ($exceptionActive) {
                    $summary['total_active']++;
                    $summary['by_group'][$group] = ($summary['by_group'][$group] ?? 0) + 1;

                    if ($expiresTimestamp !== null) {
                        if ($expiresTimestamp < $now) {
                            $summary['overdue']++;
                        } elseif ($expiresTimestamp <= $threshold) {
                            $summary['due_soon']++;
                        } else {
                            $summary['due_later']++;
                        }
                    }

                    if ($expiresTimestamp !== null && $expiresTimestamp <= $threshold) {
                        $expiring[] = [
                            'document_id' => $documentId,
                            'hostname' => $hostname,
                            'application' => $application,
                            'group' => $group,
                            'item_key' => $itemKey,
                            'enforced_key' => $enforcedKey,
                            'reason' => $exception['reason'] ?? '',
                            'approver' => $exception['approver']['name'] ?? '',
                            'updated_at' => $exception['updated_at'] ?? '',
                            'expires_at' => $expiresAtIso,
                            'days_until' => $daysUntil,
                        ];
                    }
                }

                if (! $enforced) {
                    $unenforced[] = [
                        'document_id' => $documentId,
                        'hostname' => $hostname,
                        'application' => $application,
                        'group' => $group,
                        'item_key' => $itemKey,
                        'exception_active' => $exceptionActive,
                        'exception_expires_at' => $expiresAtIso,
                        'reason' => $exception['reason'] ?? '',
                        'enforced_key' => $enforcedKey,
                    ];
                }
            }
        }

        usort($expiring, static function (array $a, array $b): int {
            $aTs = isset($a['expires_at']) ? strtotime((string) $a['expires_at']) : null;
            $bTs = isset($b['expires_at']) ? strtotime((string) $b['expires_at']) : null;

            if ($aTs === $bTs) {
                return strcmp($a['hostname'] ?? '', $b['hostname'] ?? '');
            }

            if ($aTs === null) {
                return 1;
            }

            if ($bTs === null) {
                return -1;
            }

            return $aTs <=> $bTs;
        });

        $summary['by_group'] = $this->sortAssociativeDescending($summary['by_group']);

        if ($unenforcedLimit > 0 && count($unenforced) > $unenforcedLimit) {
            $unenforced = array_slice($unenforced, 0, $unenforcedLimit);
        }

        return [
            'expiring' => $expiring,
            'summary' => $summary,
            'unenforced' => $unenforced,
        ];
    }

    private function sortAssociativeDescending(array $values): array
    {
        arsort($values);

        return $values;
    }
}

