<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sales', 'front_sale', 'upsale', 'scrapper') NOT NULL DEFAULT 'sales'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET role = 'sales' WHERE role IN ('front_sale', 'upsale')");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sales', 'scrapper') NOT NULL DEFAULT 'sales'");
    }
};
