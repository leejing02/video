<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 后台运营账号专用表，与 C 端 users 表物理隔离。
 * - 字段比 users 简单：不要 avatar / phone / bio 等社交属性
 * - 只承载"后台员工"语义：登陆、MFA、IP 白名单、最后登录审计
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();

            $table->string('name', 60);
            $table->string('username', 60)->unique();
            $table->string('email', 120)->unique();
            $table->string('password');

            $table->boolean('is_active')->default(true);

            // 审计 / 安全
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
