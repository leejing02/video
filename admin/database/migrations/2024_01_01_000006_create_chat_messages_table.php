<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['text', 'image', 'video', 'system'])->default('text');
            $table->text('content');
            // 引用消息 / 回复
            $table->foreignId('reply_to_id')
                ->nullable()
                ->constrained('chat_messages')
                ->nullOnDelete();
            // 附件 url（图片 / 视频）
            $table->string('attachment_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['chat_room_id', 'created_at']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
