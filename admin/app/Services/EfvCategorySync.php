<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EfvCategorySync
{
    public function ensure(string $name): string
    {
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');
        $key = (string) config('services.efv.api_key');
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || !$key) {
            throw new DomainException('请配置 EFV_ENDPOINT 和 EFV_API_KEY 后重试。');
        }

        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->withHeaders(['token' => $key])
                ->post($endpoint.'/api/ensurecategory', ['title' => trim($name)]);
        } catch (ConnectionException) {
            throw new DomainException('云转码分类同步超时，请稍后重试。');
        }

        $id = $response->json('id');
        if (!$response->successful() || (int) $response->json('success') !== 1
            || !is_string($id) || !preg_match('/^[a-f0-9]{24}$/iD', $id)) {
            throw new DomainException('云转码分类同步失败，请确认 EFV 已更新分类接口、API 已开启且密钥正确。');
        }

        return $id;
    }
}
