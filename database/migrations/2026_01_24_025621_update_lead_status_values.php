<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE leads MODIFY COLUMN status ENUM('wrong number','follow up','hired us','hired someone','no response') DEFAULT 'no response'"
            );
        }

        DB::table('leads')->where('status', 'new')->update(['status' => 'no response']);
        DB::table('leads')->where('status', 'opened')->update(['status' => 'follow up']);
        DB::table('leads')->where('status', 'contacted')->update(['status' => 'follow up']);
        DB::table('leads')->where('status', 'qualified')->update(['status' => 'follow up']);
        DB::table('leads')->where('status', 'converted')->update(['status' => 'hired us']);
        DB::table('leads')->where('status', 'lost')->update(['status' => 'hired someone']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE leads MODIFY COLUMN status ENUM('new','opened','contacted','qualified','converted','lost') DEFAULT 'new'"
            );
        }

        DB::table('leads')->where('status', 'no response')->update(['status' => 'new']);
        DB::table('leads')->where('status', 'follow up')->update(['status' => 'opened']);
        DB::table('leads')->where('status', 'hired us')->update(['status' => 'converted']);
        DB::table('leads')->where('status', 'hired someone')->update(['status' => 'lost']);
    }
};
