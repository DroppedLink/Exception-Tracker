<?php

namespace ETracker\CLI;

use ETracker\Services\MongoConnection;
use MongoDB\Driver\Command as MongoCommand;
use Throwable;
use function sprintf;

class Command
{
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_command('etracker', static::class);
    }

    public function __invoke(): void
    {
        \WP_CLI::line('Usage: wp etracker test-connection');
    }

    /**
     * Test the configured MongoDB connection.
     *
     * ## EXAMPLES
     *
     *     wp etracker test-connection
     */
    public function test_connection(array $args, array $assoc_args): void
    {
        try {
            $connection = new MongoConnection();

            if (! $connection->is_configured()) {
                \WP_CLI::error('Mongo connection is not configured. Set URI, database, and collection in the admin settings.');
                return;
            }

            $manager = $connection->get_manager();
            $database = $connection->get_database();

            $command = new MongoCommand(['ping' => 1]);
            $manager->executeCommand($database, $command);

            $namespace = $connection->get_namespace();
        } catch (Throwable $exception) {
            \WP_CLI::error(sprintf('Mongo connection failed: %s', $exception->getMessage()));
            return;
        }

        \WP_CLI::success(sprintf('Mongo connection OK for %s', $namespace));
    }
}


