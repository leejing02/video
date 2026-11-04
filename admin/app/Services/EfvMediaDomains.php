<?php

namespace App\Services;

use RuntimeException;

class EfvMediaDomains
{
    public function __construct(private readonly string $path = '/etc/video/efv-media/domains.json')
    {
    }

    public function read(): array
    {
        $json = @file_get_contents($this->path);
        $data = $json === false ? [] : json_decode($json, true);

        return [
            'image_origin' => self::origin($data['image_origin'] ?? null),
            'm3u8_origin' => self::origin($data['m3u8_origin'] ?? null),
        ];
    }

    public static function origin(mixed $value): ?string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return rtrim($value, '/');
    }

    public function save(array $data): void
    {
        $domains = [
            'image_origin' => self::origin($data['image_origin'] ?? null),
            'm3u8_origin' => self::origin($data['m3u8_origin'] ?? null),
        ];
        if (in_array(null, $domains, true)) {
            throw new RuntimeException('媒体域名无效。');
        }

        $directory = realpath(dirname($this->path));
        if ($directory === false || !is_writable($directory)) {
            throw new RuntimeException('媒体配置目录不可写，请检查后台容器挂载。');
        }

        $json = json_encode($domains, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = @tempnam($directory, '.domains-');
        if ($temporary === false) {
            throw new RuntimeException('无法创建媒体配置文件。');
        }

        try {
            if (dirname($temporary) !== $directory || @file_put_contents($temporary, $json) !== strlen($json)
                || !@chmod($temporary, 0644) || !@rename($temporary, $this->path)) {
                throw new RuntimeException('媒体配置保存失败，请检查目录权限。');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
