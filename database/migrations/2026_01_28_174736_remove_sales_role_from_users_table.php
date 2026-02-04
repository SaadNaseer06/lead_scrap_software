<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate existing 'sales' users to 'front_sale'
        DB::statement("UPDATE users SET role = 'front_sale' WHERE role = 'sales'");
        
        // Update enum to remove 'sales' and change default to 'front_sale'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'front_sale', 'upsale', 'scrapper') NOT NULL DEFAULT 'front_sale'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore 'sales' role to enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sales', 'front_sale', 'upsale', 'scrapper') NOT NULL DEFAULT 'sales'");
        
        // Migrate 'front_sale' users back to 'sales' (optional - only if they were originally 'sales')
        // Note: This is a best-effort rollback as we can't distinguish original 'sales' from 'front_sale'
    }
};
