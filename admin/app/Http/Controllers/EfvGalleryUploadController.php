<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Video;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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
        return $this->submit($data, $request->file('file'), $admin->getKey());
    }

    public function chunk(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->is_active, 403);
        Gate::forUser($admin)->authorize('uploadGallery', Video::class);

        $data = $request->validate([
            'batch' => ['required', 'uuid'],
            'category' => ['required', 'integer'],
            'order' => ['required', 'integer', 'between:0,99'],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'between:1,47185920'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:512'],
        ]);
        $size = (int) $data['size'];
        $offset = (int) $data['offset'];
        $chunk = $request->file('chunk');
        abort_unless($chunk->getSize() === min(524288, $size - $offset)
            && $offset % 524288 === 0 && $offset < $size, 422, '分片大小或位置不正确');

        // Keep at most one partial file per administrator, outside public storage.
        $adminId = $admin->getKey();
        $key = 'efv-upload-chunk:'.$adminId;
        $path = storage_path('app/efv-upload/'.$adminId.'.part');
        $identity = [$data['batch'], $data['category'], $data['order'], $data['name'], $size];
        $lock = Cache::lock($key.':lock', 70);
        abort_unless($lock->get(), 409, '当前有分片正在处理，请稍后重试');

        try {
            $state = Cache::get($key);
            if (($state['identity'] ?? null) !== $identity) {
                if ($offset !== 0) {
                    return response()->json(['next_offset' => 0]);
                }
                File::ensureDirectoryExists(dirname($path), 0700);
                abort_if(file_put_contents($path, '') === false, 500, '无法创建上传临时文件');
                $state = ['identity' => $identity];
                Cache::put($key, $state, now()->addDay());
            }
            if (isset($state['result'])) {
                return response()->json($state['result']);
            }

            clearstatcache(true, $path);
            $received = is_file($path) ? filesize($path) : 0;
            if ($received !== $size) {
                $boundary = $received - $received % 524288;
                if ($boundary !== $offset) {
                    return response()->json(['next_offset' => $boundary]);
                }
            }
            if ($received !== $size) {
                $stream = fopen($path, 'c+b');
                abort_unless($stream, 500, '无法打开上传临时文件');
                try {
                    $bytes = file_get_contents($chunk->getPathname());
                    abort_unless($bytes !== false && fseek($stream, $offset) === 0, 500, '无法读取上传分片');
                    if (fwrite($stream, $bytes) !== strlen($bytes)) {
                        ftruncate($stream, $offset);
                        abort(500, '保存分片失败，请检查后台磁盘空间');
                    }
                } finally {
                    fclose($stream);
                }
                $received = $offset + strlen($bytes);
            }

            if ($received < $size) {
                return response()->json(['next_offset' => $received]);
            }

            $file = new UploadedFile($path, $data['name'], null, null, true);
            $result = $this->submit($data, $file, $adminId)->getData(true);
            $state['result'] = $result;
            Cache::put($key, $state, now()->addDay());
            File::delete($path);

            return response()->json($result);
        } finally {
            $lock->release();
        }
    }

    private function submit(array $data, UploadedFile $file, int $adminId): JsonResponse
    {
        $category = Category::query()->where('type', Category::TYPE_SHORT)
            ->where('is_active', true)->findOrFail($data['category']);
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['mp4', 'mkv', 'avi', 'dat', 'mpg', 'rmvb', 'rm', 'flv', 'mpeg', 'mov', 'wmv', 'vob', 'asf', 'ts'], true), 422, '不支持此视频格式');

        $config = config('services.efv');
        $base = rtrim($config['endpoint'], '/');
        abort_unless(parse_url($base, PHP_URL_SCHEME) === 'https', 503, 'EFV_ENDPOINT 必须使用 HTTPS');
        abort_if(blank($config['upload_key']), 503, '后台容器未读取到 EFV_UPLOAD_KEY');
        $http = Http::baseUrl($base)->acceptJson()->connectTimeout(5)->withoutRedirecting();
        $batch = strtolower($data['batch']);
        $batches = DB::table('efv_gallery_batches');
        $lock = Cache::lock('efv-gallery:'.$batch, 55);
        abort_unless($lock->get(), 409, '这一批文件正在上传，请稍后重试');

        try {
            $batches->insertOrIgnore([
                'id' => $batch, 'admin_id' => $adminId,
                'category_id' => $category->getKey(), 'section' => 'short',
                'created_at' => now(),
            ]);
            $record = $batches->where('id', $batch)->first();
            abort_unless($record && (int) $record->admin_id === $adminId, 403);
            abort_unless((int) $record->category_id === (int) $category->getKey(), 409, '该批次已锁定分类');
            abort_unless($record->section === 'short', 409, '这是旧图集批次，请清空后重新选择视频');

            $files = json_decode($record->files ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $order = (int) $data['order'];
            $entry = $files[$order] ?? [];
            abort_if(isset($entry['name']) && $entry['name'] !== $file->getClientOriginalName(), 409, '该位置已关联其他文件，请新建批次');
            if (empty($entry['efv_id'])) {
                $stream = fopen($file->getPathname(), 'rb');
                abort_unless($stream, 422, '无法读取上传文件');
                try {
                    $response = $http->timeout(40)->withHeaders(['token' => $config['upload_key']])
                        ->attach('file', $stream, $file->getClientOriginalName())
                        ->post('/api/simpleupload', [
                            'originalname' => $file->getClientOriginalName(),
                            'category' => $category->name,
                            'clientId' => $batch.':'.$order,
                        ]);
                } finally {
                    fclose($stream);
                }

                $id = $response->json('id');
                abort_unless($response->successful() && $response->json('success')
                    && is_string($id) && preg_match('/^[a-f0-9]{24}$/iD', $id), 502, $this->efvFailure('提交视频', $response, $config));
                $entry = [
                    'name' => $file->getClientOriginalName(),
                    'status' => '视频已提交转码，等待创建本地草稿',
                    'efv_id' => $id,
                ];
                $files[$order] = $entry;
                $batches->where('id', $batch)->update([
                    'files' => json_encode($files, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
            }

            if (empty($entry['video_id'])) {
                $entry = DB::transaction(function () use ($entry, $files, $order, $batches, $batch, $category) {
                    $title = pathinfo($entry['name'], PATHINFO_FILENAME);
                    $video = Video::create([
                        'user_id' => User::adminUploadOwner()->id,
                        'category_id' => $category->getKey(),
                        'type' => Video::TYPE_SHORT,
                        'title' => Str::limit($title ?: '未命名短视频', 150, ''),
                        'url' => '',
                        'status' => Video::STATUS_DRAFT,
                    ]);
                    $entry['video_id'] = $video->getKey();
                    $entry['status'] = '视频已提交转码，已创建本地草稿';
                    $files[$order] = $entry;
                    ksort($files);
                    $batches->where('id', $batch)->update([
                        'files' => json_encode($files, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);

                    return $entry;
                });
            }

            return response()->json(['message' => $entry['status'], 'video_id' => $entry['video_id'] ?? null]);
        } catch (ConnectionException) {
            abort(502, 'EFV 提交视频时连接失败或超时，请先检查 EFV 视频库是否已收到文件');
        } finally {
            $lock->release();
        }
    }

    private function efvFailure(string $stage, \Illuminate\Http\Client\Response $response, array $config): string
    {
        $message = $response->json('message') ?? $response->json('error');
        $detail = '';
        if (is_string($message)) {
            $detail = strip_tags($message);
            $detail = str_replace(array_filter([$config['upload_key'], $config['api_key'], $config['api_secret']]), '[隐藏]', $detail);
            $detail = trim(preg_replace('/\s+/u', ' ', $detail) ?? '');
        }

        return 'EFV '.$stage.'失败（HTTP '.$response->status().'）'
            .($detail !== '' ? '：'.Str::limit($detail, 160) : '');
    }
}
