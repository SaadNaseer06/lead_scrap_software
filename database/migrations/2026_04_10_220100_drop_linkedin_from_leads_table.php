<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'linkedin') && Schema::hasColumn('leads', 'social_links')) {
            DB::table('leads')
                ->whereNotNull('linkedin')
                ->where('linkedin', '!=', '')
                ->where(function ($query) {
                    $query->whereNull('social_links')
                        ->orWhere('social_links', '');
                })
                ->update([
                    'social_links' => DB::raw('linkedin'),
                ]);
        }

        if (Schema::hasColumn('leads', 'linkedin')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('linkedin');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('leads', 'linkedin')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->string('linkedin')->nullable()->after('platform');
            });
        }
    }
};
