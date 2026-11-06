<?php

namespace App\Services;

use RuntimeException;

class VideoCommentSettings
{
    private const FILE = '/etc/video/efv-media/comments.json';

    public function enabled(): bool
    {
        if (!file_exists(self::FILE)) return true;
        $data = json_decode((string) @file_get_contents(self::FILE), true);
        return is_array($data) && ($data['enabled'] ?? null) === true;
    }

    public function save(bool $enabled): void
    {
        $dir = dirname(self::FILE);
        $file = @tempnam($dir, '.comments-');
        if ($file === false) throw new RuntimeException('Cannot create settings file');
        try {
            $json = json_encode(['enabled' => $enabled], JSON_THROW_ON_ERROR);
            if (file_put_contents($file, $json) === false || !chmod($file, 0644) || !rename($file, self::FILE)) {
                throw new RuntimeException('Cannot save settings file');
            }
        } finally {
            if (is_file($file)) unlink($file);
        }
    }
}
