<?php

namespace ETracker\Repositories;

use ETracker\Services\MongoConnection;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\Exception as MongoException;
use MongoDB\Driver\Query;
use RuntimeException;
use stdClass;
use function array_filter;
use function current;
use function is_array;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function trim;
use function max;

class InventoryRepository
{
    private MongoConnection $connection;

    public function __construct(?MongoConnection $connection = null)
    {
        $this->connection = $connection ?? new MongoConnection();
    }

    /**
     * @param array $criteria
     * @param int   $limit
     * @param int   $skip
     * @return array{items: array<int, array>, total: int}
     */
    public function search(array $criteria = [], int $limit = 20, int $skip = 0): array
    {
        $filter = $this->buildFilter($criteria);
        $options = [
            'limit' => max(1, $limit),
            'skip' => max(0, $skip),
            'sort' => ['ESD.hostname' => 1],
        ];

        $manager = $this->connection->get_manager();
        $cursor = $manager->executeQuery(
            $this->connection->get_namespace(),
            new Query($filter, $options)
        );

        $documents = [];
        foreach ($cursor as $document) {
            $documents[] = $this->toArray($document);
        }

        return [
            'items' => $documents,
            'total' => $this->count($filter),
        ];
    }

    public function findById(string $id): ?array
    {
        $filter = ['_id' => $this->normalizeId($id)];

        $manager = $this->connection->get_manager();
        $cursor = $manager->executeQuery(
            $this->connection->get_namespace(),
            new Query($filter, ['limit' => 1])
        );

        $document = current(iterator_to_array($cursor));

        return $document ? $this->toArray($document) : null;
    }

    /**
     * @param string $documentId
     * @param string $group      Either CIS or Agents
     * @param string $itemKey    Key within the group
     * @param array  $updates    Update payload (supports enforced, enforced_key, exception)
     */
    public function updateEnforcedItem(string $documentId, string $group, string $itemKey, array $updates): bool
    {
        $groupPath = $this->normalizeGroup($group);
        $pathPrefix = "Enforced.{$groupPath}.{$itemKey}";

        $set = [];
        $unset = [];

        if (array_key_exists('enforced', $updates)) {
            $set["{$pathPrefix}.enforced"] = (bool) $updates['enforced'];
        }

        if (array_key_exists('enforced_key', $updates)) {
            $value = $updates['enforced_key'];
            if ($value === null || $value === '') {
                $unset["{$pathPrefix}.enforced_key"] = '';
            } else {
                $set["{$pathPrefix}.enforced_key"] = (string) $value;
            }
        }

        if (array_key_exists('exception', $updates)) {
            if ($updates['exception'] === null) {
                $unset["{$pathPrefix}.exception"] = '';
            } else {
                $set["{$pathPrefix}.exception"] = $updates['exception'];
            }
        }

        if (empty($set) && empty($unset)) {
            return false;
        }

        $update = [];
        if (! empty($set)) {
            $update['$set'] = $set;
        }
        if (! empty($unset)) {
            $update['$unset'] = $unset;
        }

        $bulk = new BulkWrite();
        $bulk->update(
            ['_id' => $this->normalizeId($documentId)],
            $update,
            ['multi' => false, 'upsert' => false]
        );

        $result = $this->connection->get_manager()->executeBulkWrite(
            $this->connection->get_namespace(),
            $bulk
        );

        return $result->getModifiedCount() > 0 || $result->getUpsertedCount() > 0;
    }

    public function count(array $filter = []): int
    {
        $command = new Command([
            'count' => $this->connection->get_collection(),
            'query' => empty($filter) ? new stdClass() : $filter,
        ]);

        $cursor = $this->connection->get_manager()->executeCommand(
            $this->connection->get_database(),
            $command
        );

        $result = current($cursor->toArray());

        if ($result && isset($result->n)) {
            return (int) $result->n;
        }

        return 0;
    }

    private function buildFilter(array $criteria): array
    {
        $criteria = array_filter($criteria, static fn ($value) => $value !== null && $value !== '');
        if (empty($criteria)) {
            return [];
        }

        $filter = [];

        if (isset($criteria['hostname'])) {
            $filter['ESD.hostname'] = new Regex(trim((string) $criteria['hostname']), 'i');
        }

        if (isset($criteria['reference_code'])) {
            $filter['ESD.instance.reference_code'] = new Regex(trim((string) $criteria['reference_code']), 'i');
        }

        if (isset($criteria['owned_by'])) {
            $filter['ESD.instance.owned_by.name'] = new Regex(trim((string) $criteria['owned_by']), 'i');
        }

        if (isset($criteria['managed_by'])) {
            $filter['ESD.instance.managed_by.name'] = new Regex(trim((string) $criteria['managed_by']), 'i');
        }

        if (isset($criteria['gvp'])) {
            $filter['ESD.instance.gvp.name'] = new Regex(trim((string) $criteria['gvp']), 'i');
        }

        if (isset($criteria['application'])) {
            $filter['ESD.instances.name'] = new Regex(trim((string) $criteria['application']), 'i');
        }

        return $filter;
    }

    private function toArray($document): array
    {
        if ($document instanceof \MongoDB\Model\BSONDocument) {
            return json_decode(json_encode($document), true);
        }

        if ($document instanceof \stdClass) {
            return json_decode(json_encode($document), true);
        }

        if (is_array($document)) {
            return $document;
        }

        return [];
    }

    private function normalizeId(string $id)
    {
        try {
            return new ObjectId($id);
        } catch (MongoException $exception) {
            // Allow passing in already converted IDs
        } catch (\Exception $exception) {
            // Fallback to raw string
        }

        return $id;
    }

    private function normalizeGroup(string $group): string
    {
        $group = trim($group);
        if ($group === '') {
            throw new RuntimeException('Group name cannot be empty.');
        }

        return $group;
    }

    /**
     * @return iterable<array>
     */
    public function iterateAll(int $batchSize = 200): iterable
    {
        $batchSize = max(1, $batchSize);
        $skip = 0;
        $total = null;

        do {
            $result = $this->search([], $batchSize, $skip);
            $items = $result['items'] ?? [];

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                yield $item;
            }

            $skip += $batchSize;
            $total = isset($result['total']) ? (int) $result['total'] : null;
        } while ($total === null || $skip < $total);
    }
}

