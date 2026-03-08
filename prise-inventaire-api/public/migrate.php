<?php

// Script temporaire pour exécuter les migrations
// À SUPPRIMER après utilisation

$secret = 'prise-migrate-2026-secret';

if (!isset($_GET['key']) || $_GET['key'] !== $secret) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->call('migrate', ['--force' => true]);

echo json_encode([
    'success' => true,
    'output' => $kernel->output()
]);
