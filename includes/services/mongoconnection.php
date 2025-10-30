<?php

namespace ETracker\Services;

use ETracker\Settings;
use MongoDB\Driver\Manager;
use RuntimeException;

class MongoConnection
{
    private ?Manager $manager = null;

    public function get_manager(): Manager
    {
        if ($this->manager instanceof Manager) {
            return $this->manager;
        }

        $uri = Settings::get('mongo_uri');
        if ($uri === '') {
            throw new RuntimeException('Mongo URI is not configured.');
        }

        $this->manager = new Manager($uri);

        return $this->manager;
    }

    public function get_database(): string
    {
        $database = Settings::get('mongo_database');
        if ($database === '') {
            throw new RuntimeException('Mongo database is not configured.');
        }

        return $database;
    }

    public function get_collection(): string
    {
        $collection = Settings::get('mongo_collection');
        if ($collection === '') {
            throw new RuntimeException('Mongo collection is not configured.');
        }

        return $collection;
    }

    public function get_namespace(): string
    {
        return $this->get_database() . '.' . $this->get_collection();
    }

    public function is_configured(): bool
    {
        return Settings::get('mongo_uri') !== ''
            && Settings::get('mongo_database') !== ''
            && Settings::get('mongo_collection') !== '';
    }
}

