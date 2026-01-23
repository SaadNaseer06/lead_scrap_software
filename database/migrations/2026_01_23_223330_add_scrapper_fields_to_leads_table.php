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
        Schema::table('leads', function (Blueprint $table) {
            $table->date('lead_date')->nullable()->after('lead_sheet_id');
            $table->string('services')->nullable()->after('lead_date');
            $table->string('location')->nullable()->after('services');
            $table->string('position')->nullable()->after('location');
            $table->string('platform')->nullable()->after('position');
            $table->string('linkedin')->nullable()->after('platform');
            $table->text('detail')->nullable()->after('linkedin');
            $table->string('web_link')->nullable()->after('detail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'lead_date',
                'services',
                'location',
                'position',
                'platform',
                'linkedin',
                'detail',
                'web_link',
            ]);
        });
    }
};
