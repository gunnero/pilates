<?php

use App\Contracts\Accounts\InvitationDelivery;
use Illuminate\Http\Request;

// Test-only HTTP entry point. Capture transient invitation delivery privately,
// without adding a debug capability or secret-returning API to the application.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__.'/../../public'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, realpath(__DIR__.'/../../public').DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.e2e');
$app->booted(function () use ($app) {
    if (! $app->environment('e2e') || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.url') || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Isolated browser database required');
    }
    $app->instance(InvitationDelivery::class, new class implements InvitationDelivery
    {
        public function send(string $email, string $url, string $locale): void
        {
            umask(0077);
            file_put_contents(base_path('.local/browser-invite-'.hash('sha256', $email).'.json'), json_encode(['url' => $url], JSON_THROW_ON_ERROR));
        }
    });
});
$app->handleRequest(Request::capture());
