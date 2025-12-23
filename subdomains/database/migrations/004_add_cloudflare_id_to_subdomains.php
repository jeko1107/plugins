<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subdomains', 'cloudflare_id')) {
            Schema::table('subdomains', function (Blueprint $table) {
                $table->string('cloudflare_id')->nullable()->after('record_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subdomains', 'cloudflare_id')) {
            Schema::table('subdomains', function (Blueprint $table) {
                $table->dropColumn('cloudflare_id');
            });
        }
    }
};
