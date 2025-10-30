<?php

namespace ETracker\Services;

use wpdb;
use function current_time;
use function wp_json_encode;

class AuditLogger
{
    private wpdb $wpdb;
    private string $table;

    public function __construct(?wpdb $wpdbInstance = null)
    {
        global $wpdb;

        $this->wpdb = $wpdbInstance instanceof wpdb ? $wpdbInstance : $wpdb;
        $this->table = $this->wpdb->prefix . 'etracker_audit';
    }

    /**
     * @param array{
     *     document_id:string,
     *     group:string,
     *     item_key:string,
     *     previous_state:array,
     *     new_state:array,
     *     reason:string,
     *     user_id:int|null,
     *     user_name:string|null
     * } $payload
     */
    public function log(array $payload): void
    {
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

        $data = [
            'document_id' => $payload['document_id'],
            'item_type' => $payload['group'],
            'item_key' => $payload['item_key'],
            'previous_state' => wp_json_encode($payload['previous_state']),
            'new_state' => wp_json_encode($payload['new_state']),
            'reason' => $payload['reason'],
            'user_id' => $userId,
            'user_name' => $payload['user_name'],
            'created_at' => current_time('mysql', true),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'];

        $this->wpdb->insert($this->table, $data, $formats);
    }

    public function history(string $documentId, string $itemKey, int $limit = 20): array
    {
        $limit = max(1, $limit);

        $sql = $this->wpdb->prepare(
            "SELECT document_id, item_type, item_key, previous_state, new_state, reason, user_id, user_name, created_at
            FROM {$this->table}
            WHERE document_id = %s AND item_key = %s
            ORDER BY created_at DESC
            LIMIT %d",
            $documentId,
            $itemKey,
            $limit
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function historyForDocument(string $documentId, int $limit = 50): array
    {
        $limit = max(1, $limit);

        $sql = $this->wpdb->prepare(
            "SELECT document_id, item_type, item_key, previous_state, new_state, reason, user_id, user_name, created_at
            FROM {$this->table}
            WHERE document_id = %s
            ORDER BY created_at DESC
            LIMIT %d",
            $documentId,
            $limit
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }
}

