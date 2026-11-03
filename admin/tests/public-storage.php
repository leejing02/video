<?php

// Standalone regression check; no database or application secrets required.
require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\StorageLinkCommand;
use Symfony\Component\Console\Tester\CommandTester;

$entrypoint = file_get_contents(__DIR__.'/../docker/entrypoint.sh');
if (!str_contains($entrypoint, 'mkdir -p storage/app/public') ||
    !str_contains($entrypoint, 'php artisan storage:link --force')) {
    throw new RuntimeException('Container startup must prepare and link public uploads.');
}

$root = sys_get_temp_dir().'/video-storage-'.bin2hex(random_bytes(8));
$files = new Filesystem;
$files->makeDirectory($root.'/storage/app/public/categories/short', 0755, true);
$files->makeDirectory($root.'/public', 0755, true);
file_put_contents($root.'/storage/app/public/categories/short/cover.jpg', 'uploaded-cover');
$app = new Application($root);
$app->instance('config', new Repository([
    'filesystems' => ['links' => [$root.'/public/storage' => $root.'/storage/app/public']],
]));
$app->instance('files', $files);
$command = new StorageLinkCommand;
$command->setLaravel($app);
$test = new CommandTester($command);
try {
    // First boot, repeated boot, and an old absolute link from another build.
    foreach (['first', 'repeat', 'stale'] as $scenario) {
        if ($scenario === 'stale') {
            unlink($root.'/public/storage');
            symlink($root.'/missing-old-storage', $root.'/public/storage');
        }
        $test->execute(['--force' => true]);
        if (file_get_contents($root.'/public/storage/categories/short/cover.jpg') !== 'uploaded-cover') {
            throw new RuntimeException("Upload is not accessible after {$scenario} boot.");
        }
    }
    echo "Public storage link: first, repeated and stale-link boots passed.\n";
} finally {
    if (is_link($root.'/public/storage')) unlink($root.'/public/storage');
    $files->deleteDirectory($root);
}
