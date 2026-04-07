<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('read');
            $table->index(['user_id', 'read_at']);
        });

        DB::table('notifications')
            ->where('read', true)
            ->whereNull('read_at')
            ->update(['read_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
            $table->dropColumn('read_at');
        });
    }
};
