<?php

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'ETracker\\') !== 0) {
        return;
    }

    $relative = substr($class, strlen('ETracker\\'));
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $path = ETRACKER_PLUGIN_DIR . 'includes/' . strtolower($relative) . '.php';

    if (file_exists($path)) {
        require_once $path;
        return;
    }

    // Fallback without strtolower for backwards compatibility.
    $path = ETRACKER_PLUGIN_DIR . 'includes/' . $relative . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

