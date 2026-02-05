<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sheet_team', function (Blueprint $table) {
            $table->foreignId('lead_sheet_id')->constrained('lead_sheets')->onDelete('cascade');
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->primary(['lead_sheet_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sheet_team');
    }
};
