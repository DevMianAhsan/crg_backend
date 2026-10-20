<?php
/**
 * CRG Portal - Production Cache Clear Utility
 * Usage: https://crgportal.thewebconcept.com/crg/public/clear-cache.php?key=crg2026clear
 */

$secretKey = 'crg2026clear';
$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';

header('Content-Type: application/json');

if ($providedKey !== $secretKey) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing secret key.',
    ], JSON_PRETTY_PRINT);
    exit;
}

$cachePath = __DIR__ . '/../bootstrap/cache/';
$targets = [
    'routes-v7.php',
    'config.php',
    'services.php',
    'packages.php',
];

$cleared = [];
$notFound = [];
$errors = [];

foreach ($targets as $file) {
    $filePath = $cachePath . $file;
    if (file_exists($filePath)) {
        if (@unlink($filePath)) {
            $cleared[] = $file;
        } else {
            $errors[] = "Failed to delete: {$file} (permission denied)";
        }
    } else {
        $notFound[] = $file;
    }
}

// Optionally run Artisan clear if available
$artisanOutput = [];
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->call('optimize:clear');
    $artisanOutput[] = trim($kernel->output());
} catch (\Throwable $e) {
    $artisanOutput[] = 'Artisan invocation skipped: ' . $e->getMessage();
}

echo json_encode([
    'status' => 'success',
    'message' => 'Cache files cleared successfully.',
    'deleted_cache_files' => $cleared,
    'not_cached_files' => $notFound,
    'errors' => $errors,
    'artisan' => $artisanOutput,
], JSON_PRETTY_PRINT);
