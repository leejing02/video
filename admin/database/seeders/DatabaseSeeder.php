<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. 用户（先建用户，后面 PermissionSeeder 会给 admin 分配角色）
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'      => '管理员',
                'username'  => 'admin',
                'password'  => Hash::make('password'),
                'role'      => User::ROLE_ADMIN,
                'is_active' => true,
            ],
        );

        $alice = User::firstOrCreate(
            ['email' => 'alice@example.com'],
            [
                'name'     => 'Alice',
                'username' => 'alice',
                'password' => Hash::make('password'),
                'role'     => User::ROLE_USER,
            ],
        );

        $bob = User::firstOrCreate(
            ['email' => 'bob@example.com'],
            [
                'name'     => 'Bob',
                'username' => 'bob',
                'password' => Hash::make('password'),
                'role'     => User::ROLE_USER,
            ],
        );

        // 2. 分类（长 / 短各几个）
        $longCats = [
            ['name' => '影视剧',   'kind' => Category::KIND_LONG,  'icon' => 'film.fill'],
            ['name' => '纪录片',   'kind' => Category::KIND_LONG,  'icon' => 'play.tv.fill'],
            ['name' => '教育',     'kind' => Category::KIND_LONG,  'icon' => 'book.fill'],
        ];
        $shortCats = [
            ['name' => '搞笑',     'kind' => Category::KIND_SHORT, 'icon' => 'face.smiling'],
            ['name' => '美食',     'kind' => Category::KIND_SHORT, 'icon' => 'fork.knife'],
            ['name' => '旅行',     'kind' => Category::KIND_SHORT, 'icon' => 'airplane'],
            ['name' => '科技',     'kind' => Category::KIND_SHORT, 'icon' => 'cpu'],
        ];

        foreach (array_merge($longCats, $shortCats) as $i => $cat) {
            Category::firstOrCreate(
                ['name' => $cat['name']],
                ['kind' => $cat['kind'], 'icon' => $cat['icon'], 'sort' => $i, 'is_active' => true],
            );
        }

        // 3. 视频示例
        $sampleLong  = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4';
        $sampleShort = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4';

        $longCat  = Category::where('name', '影视剧')->first();
        $shortCat = Category::where('name', '搞笑')->first();

        for ($i = 1; $i <= 6; $i++) {
            Video::firstOrCreate(
                ['title' => "示例长视频 $i"],
                [
                    'user_id'      => $alice->id,
                    'category_id'  => $longCat->id,
                    'type'         => Video::TYPE_LONG,
                    'description'  => "这是一个长视频示例 #{$i}",
                    'cover'        => null,
                    'url'          => $sampleLong,
                    'duration'     => 600,
                    'status'       => Video::STATUS_PUBLISHED,
                    'published_at' => now()->subDays($i),
                ],
            );
        }
        for ($i = 1; $i <= 8; $i++) {
            Video::firstOrCreate(
                ['title' => "示例短视频 $i"],
                [
                    'user_id'      => $bob->id,
                    'category_id'  => $shortCat->id,
                    'type'         => Video::TYPE_SHORT,
                    'description'  => "这是一个短视频示例 #{$i}",
                    'url'          => $sampleShort,
                    'duration'     => 30,
                    'status'       => Video::STATUS_PUBLISHED,
                    'published_at' => now()->subHours($i),
                ],
            );
        }

        // 4. 全局聊天室
        $global = ChatRoom::firstOrCreate(
            ['kind' => ChatRoom::KIND_GLOBAL],
            [
                'name'        => '广场',
                'slug'        => 'global-square',
                'description' => '所有人都能进的全局群聊',
                'is_active'   => true,
                'owner_id'    => $admin->id,
            ],
        );

        // 默认所有用户都加入全局群
        foreach ([$admin, $alice, $bob] as $u) {
            $u->chatRooms()->syncWithoutDetaching([
                $global->id => ['role' => $u->isAdmin() ? 'admin' : 'member', 'joined_at' => now()],
            ]);
        }

        // 几条欢迎消息
        if ($global->messages()->count() === 0) {
            ChatMessage::create([
                'chat_room_id' => $global->id,
                'user_id'      => $admin->id,
                'type'         => ChatMessage::TYPE_SYSTEM,
                'content'      => '欢迎来到广场，请文明发言～',
            ]);
            ChatMessage::create([
                'chat_room_id' => $global->id,
                'user_id'      => $alice->id,
                'type'         => ChatMessage::TYPE_TEXT,
                'content'      => '大家好，我是 Alice 👋',
            ]);
            ChatMessage::create([
                'chat_room_id' => $global->id,
                'user_id'      => $bob->id,
                'type'         => ChatMessage::TYPE_TEXT,
                'content'      => 'Hi Alice，今天看什么视频？',
            ]);
        }

        // 5. 角色 + 权限（必须放最后，因为它要找 admin@example.com 给它分角色）
        $this->call(PermissionSeeder::class);
    }
}
