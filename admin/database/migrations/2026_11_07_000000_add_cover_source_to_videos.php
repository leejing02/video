<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// EFV path of the cover the admin synced (封面1, e.g. /videos/202611/05/<id>/cover.webp).
// The front end loads that encrypted image from the configured image domain and
// decrypts it; `cover` keeps the local copy for the admin preview and as fallback.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('videos', 'cover_source')) {
            return;
        }
        Schema::table('videos', function (Blueprint $table) {
            $table->string('cover_source', 255)->nullable()->after('cover');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('videos', 'cover_source')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->dropColumn('cover_source');
            });
        }
    }
};
