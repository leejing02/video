<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Video;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

class EfvGalleryUploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->is_active, 403);
        Gate::forUser($admin)->authorize('uploadGallery', Video::class);

        $data = $request->validate([
            'batch' => ['required', 'uuid'],
            'category' => ['required', 'integer'],
            'order' => ['required', 'integer', 'between:0,99'],
            'file' => ['required', 'file', 'max:46080'],
        ]);
        $category = Category::query()->where('type', Category::TYPE_SHORT)
            ->where('is_active', true)->findOrFail($data['category']);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $image = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
        if ($image) {
            $info = @getimagesize($file->getPathname());
            abort_unless($file->getSize() <= 15 * 1024 * 1024
                && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true), 422, '请上传 15 MB 以内的 JPG、PNG 或 WebP 图片');
        } else {
            abort_unless(in_array($extension, ['mp4', 'mkv', 'avi', 'dat', 'mpg', 'rmvb', 'rm', 'flv', 'mpeg', 'mov', 'wmv', 'vob', 'asf', 'ts'], true), 422, '不支持此视频格式');
        }

        $uploader = $image ? null : $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ])['user_id'];

        $config = config('services.efv');
        $base = rtrim($config['endpoint'], '/');
        abort_unless(parse_url($base, PHP_URL_SCHEME) === 'https', 503, 'EFV_ENDPOINT 必须使用 HTTPS');
        $missing = [];
        foreach (['upload_key', 'api_key', 'api_secret'] as $key) {
            if (trim((string) $config[$key]) === '') {
                $missing[] = 'EFV_'.strtoupper($key);
            }
        }
        abort_if($missing, 503, '后台容器未读取到 '.implode('、', $missing));
        $http = Http::baseUrl($base)->acceptJson()->connectTimeout(5)->withoutRedirecting();
        $batch = strtolower($data['batch']);
        $batches = DB::table('efv_gallery_batches');
        $lock = Cache::lock('efv-gallery:'.$batch, 55);
        abort_unless($lock->get(), 409, '这一批文件正在上传，请稍后重试');

        try {
            $batches->insertOrIgnore([
                'id' => $batch, 'admin_id' => $admin->getKey(),
                'category_id' => $category->getKey(), 'section' => $category->efv_section,
                'created_at' => now(),
            ]);
            $record = $batches->where('id', $batch)->first();
            abort_unless($record && (int) $record->admin_id === (int) $admin->getKey(), 403);
            abort_unless((int) $record->category_id === (int) $category->getKey(), 409, '该批次已锁定分类');
            $section = $record->section;
            abort_unless(in_array($section, ['gallery', 'chigua'], true), 422, 'EFV 栏目设置无效');
            $group = $record->group_id;
            if (!$group) {
                $response = (clone $http)->timeout(8)->withHeaders([
                    'apikey' => $config['api_key'], 'apisecret' => $config['api_secret'],
                ])->post('/api/gallerymanage', ['action' => 'create', 'caption' => '', 'section' => $section]);
                $group = $response->json('id');
                abort_unless($response->successful() && $response->json('success')
                    && $response->json('section') === $section && is_string($group)
                    && preg_match('/^[a-f0-9]{24}$/i', $group), 502, 'EFV 图集创建失败，请检查接口部署和管理凭证');
                $batches->where('id', $batch)->update(['group_id' => $group]);
            }

            $item = substr(hash('sha256', $group.':'.$data['order']), 0, 24);
            $stream = fopen($file->getPathname(), 'rb');
            abort_unless($stream, 422, '无法读取上传文件');
            try {
                $response = $http->timeout(40)->withHeaders(['token' => $config['upload_key']])
                    ->attach('file', $stream, $item.'.'.$extension)
                    ->post($image ? '/api/galleryimageupload' : '/api/simpleupload', [
                        'originalname' => $file->getClientOriginalName(),
                        'category' => '图集',
                        'galleryGroup' => $group,
                        'galleryGroupOrder' => (string) $data['order'],
                        'galleryItem' => $item,
                        'gallerySection' => $section,
                    ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            abort_unless($response->successful() && $response->json('success'), 502, 'EFV 未接受文件（HTTP '.$response->status().'）');
            $files = json_decode($record->files ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $entry = $files[$data['order']] ?? [];
            DB::transaction(function () use (&$entry, $files, $data, $batches, $batch, $image, $file, $uploader, $category) {
                $entry['name'] = $file->getClientOriginalName();
                $entry['status'] = $image ? '图片已加入图集' : '视频已提交转码，已创建本地草稿';
                if (!$image && empty($entry['video_id'])) {
                    $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $video = Video::create([
                        'user_id' => $uploader,
                        'category_id' => $category->getKey(),
                        'type' => Video::TYPE_SHORT,
                        'title' => Str::limit($title ?: '未命名短视频', 150, ''),
                        'url' => '',
                        'status' => Video::STATUS_DRAFT,
                    ]);
                    $entry['video_id'] = $video->getKey();
                }
                $files[$data['order']] = $entry;
                ksort($files);
                $batches->where('id', $batch)->update([
                    'files' => json_encode($files, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            });

            return response()->json(['message' => $entry['status'], 'video_id' => $entry['video_id'] ?? null]);
        } catch (ConnectionException) {
            abort(502, '连接 EFV 超时或失败，可重试当前文件');
        } finally {
            $lock->release();
        }
    }
}
