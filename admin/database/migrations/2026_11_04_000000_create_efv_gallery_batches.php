<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('efv_section', 16)->default('gallery');
        });
        Schema::create('efv_gallery_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('category_id')->index();
            $table->string('section', 16);
            $table->string('group_id', 24)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efv_gallery_batches');
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('efv_section');
        });
    }
};
