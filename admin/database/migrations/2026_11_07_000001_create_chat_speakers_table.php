<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Users an admin has allowed to speak in group chats; everyone else (guests and
// registered users) sees 全体禁言. A separate table, not a users column, because the
// Go API reads users with SELECT * into a fixed struct and an extra column breaks it.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_speakers')) {
            return;
        }
        Schema::create('chat_speakers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('created_at')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_speakers');
    }
};
