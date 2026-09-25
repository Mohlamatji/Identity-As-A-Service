#!/usr/bin/env php
<?php
/**
 * Run the configured data retention policy (config/retention.php).
 *
 * Schedule this via cron, e.g. daily:
 *   0 3 * * * /usr/bin/php /path/to/identity-vault/bin/purge-expired-data.php >> /path/to/retention.log 2>&1
 *
 * Also available as POST /api/admin/retention/run for on-demand/demo use
 * (see AdminController::runRetention).
 */

require __DIR__ . '/../vendor_autoload.php';
if (file_exists(__DIR__ . '/../config/local.php')) {
    require __DIR__ . '/../config/local.php';
}
require __DIR__ . '/../config/database.php';

use App\Services\RetentionService;

$pdo = get_pdo();
$service = new RetentionService();
$result = $service->runAll($pdo);

echo '[' . date('Y-m-d H:i:s') . "] Retention run complete:\n";
foreach ($result as $key => $count) {
    echo "  {$key}: {$count}\n";
}
