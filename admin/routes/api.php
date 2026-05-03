<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\VideoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| iOS App API
|--------------------------------------------------------------------------
| 全部前缀 /api，鉴权用 Sanctum bearer token。
*/

// === 公开接口 ===
Route::post('register', [AuthController::class, 'register']);
Route::post('login',    [AuthController::class, 'login']);

Route::get('categories',      [CategoryController::class, 'index']);
Route::get('videos',          [VideoController::class, 'index']);
Route::get('videos/{video}',  [VideoController::class, 'show']);
Route::get('videos/{video}/comments', [CommentController::class, 'index']);

// 全局群聊（未登录也能看，但发消息需登录）
Route::get('chat/global', [ChatController::class, 'globalRoom']);

// === 需要登录 ===
Route::middleware('auth:sanctum')->group(function () {

    // Auth & Me
    Route::post('logout',       [AuthController::class, 'logout']);
    Route::get('me',            [AuthController::class, 'me']);
    Route::patch('me',          [AuthController::class, 'update']);

    // 视频互动
    Route::post('videos/{video}/like', [VideoController::class, 'like']);
    Route::get('me/videos',            [VideoController::class, 'mine']);

    // 评论
    Route::post('videos/{video}/comments', [CommentController::class, 'store']);
    Route::delete('comments/{comment}',    [CommentController::class, 'destroy']);
    Route::post('comments/{comment}/like', [CommentController::class, 'like']);

    // 聊天
    Route::get('chat/rooms',                  [ChatController::class, 'rooms']);
    Route::get('chat/rooms/{room}/messages',  [ChatController::class, 'messages']);
    Route::post('chat/rooms/{room}/messages', [ChatController::class, 'send']);
    Route::post('chat/rooms/{room}/join',     [ChatController::class, 'join']);
    Route::post('chat/rooms/{room}/read',     [ChatController::class, 'markRead']);
});
