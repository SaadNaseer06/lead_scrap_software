<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('lead_sheet_id')->nullable()->after('opened_by')->constrained('lead_sheets')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lead_sheet_id']);
            $table->dropColumn('lead_sheet_id');
        });

        Schema::dropIfExists('lead_sheets');
    }
};
