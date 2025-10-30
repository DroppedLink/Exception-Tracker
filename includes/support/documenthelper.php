<?php

namespace ETracker\Support;

class DocumentHelper
{
    public static function getId(array $document): string
    {
        if (isset($document['_id']['$oid'])) {
            return (string) $document['_id']['$oid'];
        }

        if (isset($document['_id']) && is_string($document['_id'])) {
            return $document['_id'];
        }

        return '';
    }

    public static function getItem(array $document, string $group, string $itemKey): array
    {
        return $document['Enforced'][$group][$itemKey] ?? [];
    }

    public static function hasActiveException(array $item): bool
    {
        return ! empty($item['exception']['active']);
    }

    /**
     * @return iterable<array{group:string,item_key:string,data:array}>
     */
    public static function iterateEnforcedItems(array $document): iterable
    {
        if (! isset($document['Enforced']) || ! is_array($document['Enforced'])) {
            return;
        }

        foreach ($document['Enforced'] as $groupName => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $itemKey => $itemData) {
                if (! is_array($itemData)) {
                    continue;
                }

                yield [
                    'group' => (string) $groupName,
                    'item_key' => (string) $itemKey,
                    'data' => $itemData,
                ];
            }
        }
    }
}

