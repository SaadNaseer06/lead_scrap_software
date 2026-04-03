<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_sheet_id')->constrained('lead_sheets')->onDelete('cascade');
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('lead_group_id')->nullable()->after('lead_sheet_id')->constrained('lead_groups')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lead_group_id']);
            $table->dropColumn('lead_group_id');
        });
        Schema::dropIfExists('lead_groups');
    }
};
