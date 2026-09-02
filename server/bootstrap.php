<?php
// server/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env ONLY ONCE (check if already loaded)
if (!isset($_ENV['MONGODB_URL'])) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Optional: Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Increase timeout for long-running operations
set_time_limit(0);