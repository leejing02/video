<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Policies\CategoryPolicy;
use App\Policies\ChatMessagePolicy;
use App\Policies\ChatRoomPolicy;
use App\Policies\CommentPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Policies\VideoPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // ── Policies ─────────────────────────────────────────────
        Gate::policy(User::class,        UserPolicy::class);
        Gate::policy(Role::class,        RolePolicy::class);
        Gate::policy(Video::class,       VideoPolicy::class);
        Gate::policy(Category::class,    CategoryPolicy::class);
        Gate::policy(Comment::class,     CommentPolicy::class);
        Gate::policy(ChatRoom::class,    ChatRoomPolicy::class);
        Gate::policy(ChatMessage::class, ChatMessagePolicy::class);

        // 注：Laravel 广播被禁用，WebSocket 走 Go API
        // 想恢复就放开 Broadcast::routes(['middleware' => ['auth:sanctum']]);
    }
}
