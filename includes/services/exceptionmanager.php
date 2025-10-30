<?php

namespace ETracker\Services;

use ETracker\Repositories\InventoryRepository;
use ETracker\Settings;
use RuntimeException;
use function array_filter;
use function gmdate;
use function is_array;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function trim;
use function max;
use function strlen;
use function time;
use function strtotime;
use function strpos;

class ExceptionManager
{
    private InventoryRepository $repository;
    private ?AuditLogger $auditLogger;

    public function __construct(?InventoryRepository $repository = null, ?AuditLogger $auditLogger = null)
    {
        $this->repository = $repository ?? new InventoryRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * @param array{
     *     enforced?:bool,
     *     enforced_key?:string|null,
     *     exception_active?:bool,
     *     exception_reason?:string,
     *     exception_metadata?:array|null,
     *     exception_expires_at?:string|null
     * } $payload
     * @param array{user_id:int, user_name:string} $user
     *
     * @return array{previous:array, current:array}
     */
    public function updateItem(string $documentId, string $group, string $itemKey, array $payload, array $user): array
    {
        $document = $this->repository->findById($documentId);
        if ($document === null) {
            throw new RuntimeException('Inventory document not found.');
        }

        $previousState = $this->extractItemState($document, $group, $itemKey);
        $previousException = isset($previousState['exception']) && is_array($previousState['exception'])
            ? $previousState['exception']
            : [];

        $reason = '';
        if (isset($payload['exception_reason'])) {
            $reason = sanitize_textarea_field($payload['exception_reason']);
        }
        if ($reason === '' && isset($previousException['reason'])) {
            $reason = (string) $previousException['reason'];
        }

        $exceptionActive = isset($payload['exception_active'])
            ? (bool) $payload['exception_active']
            : (bool) ($previousException['active'] ?? false);

        $rawEnforcedKey = $payload['enforced_key'] ?? ($previousState['enforced_key'] ?? null);
        $sanitizedEnforcedKey = $rawEnforcedKey !== null ? sanitize_text_field((string) $rawEnforcedKey) : null;

        $enforced = array_key_exists('enforced', $payload)
            ? (bool) $payload['enforced']
            : (bool) ($previousState['enforced'] ?? true);

        if ($exceptionActive) {
            $enforced = false;
        }

        $enforcedKey = $this->normalizeEnforcedKey($sanitizedEnforcedKey, $exceptionActive);

        $exception = null;

        if ($exceptionActive) {
            $metadata = [];
            if (! empty($payload['exception_metadata']) && is_array($payload['exception_metadata'])) {
                foreach ($payload['exception_metadata'] as $metaKey => $metaValue) {
                    $metadata[$metaKey] = sanitize_text_field((string) $metaValue);
                }
            }

            $now = gmdate('c');
            $expiration = $this->resolveExpirationDate($payload['exception_expires_at'] ?? null, $previousException);

            $exception = array_filter([
                'active' => true,
                'reason' => $reason,
                'approver' => [
                    'id' => (int) ($user['user_id'] ?? 0),
                    'name' => sanitize_text_field((string) ($user['user_name'] ?? '')),
                ],
                'created_at' => isset($previousException['created_at']) ? (string) $previousException['created_at'] : $now,
                'updated_at' => $now,
                'expires_at' => $expiration,
                'metadata' => empty($metadata) ? null : $metadata,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        $updates = [
            'enforced' => $enforced,
            'exception' => $exception,
            'enforced_key' => $enforcedKey,
        ];

        $updated = $this->repository->updateEnforcedItem($documentId, $group, $itemKey, $updates);
        if (! $updated) {
            return [
                'previous' => $previousState,
                'current' => $previousState,
            ];
        }

        $currentState = $this->repository->findById($documentId);
        $newState = $currentState ? $this->extractItemState($currentState, $group, $itemKey) : $updates;

        $this->auditLogger?->log([
            'document_id' => $documentId,
            'group' => $group,
            'item_key' => $itemKey,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'reason' => $reason,
            'user_id' => (int) ($user['user_id'] ?? 0),
            'user_name' => (string) ($user['user_name'] ?? ''),
        ]);

        return [
            'previous' => $previousState,
            'current' => $newState,
        ];
    }

    private function resolveExpirationDate($requested, array $previousException): string
    {
        $normalized = $this->normalizeExpirationDate($requested);
        if ($normalized !== null) {
            return $normalized;
        }

        if (isset($previousException['expires_at']) && $previousException['expires_at'] !== '') {
            return (string) $previousException['expires_at'];
        }

        $days = $this->getDefaultExceptionDurationDays();
        $timestamp = time() + (max(1, $days) * \DAY_IN_SECONDS);

        return gmdate('c', $timestamp);
    }

    private function normalizeEnforcedKey($value, bool $exceptionActive): ?string
    {
        if ($exceptionActive) {
            return 'exception:manual';
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (strpos($value, 'exception:') === 0) {
            return null;
        }

        return $value;
    }

    private function normalizeExpirationDate($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (strlen($value) === 10) {
            $value .= ' 23:59:59 UTC';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    private function getDefaultExceptionDurationDays(): int
    {
        $configured = Settings::get('default_exception_duration_days', '365');
        $days = (int) $configured;

        return $days > 0 ? $days : 365;
    }

    private function extractItemState(array $document, string $group, string $itemKey): array
    {
        return $document['Enforced'][$group][$itemKey] ?? [];
    }
}

