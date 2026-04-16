<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['created_by', 'deleted_at', 'id'], 'leads_creator_deleted_id_idx');
            $table->index(['created_by', 'lead_sheet_id', 'deleted_at', 'id'], 'leads_creator_sheet_deleted_id_idx');
            $table->index(['created_by', 'lead_sheet_id', 'lead_group_id', 'deleted_at', 'id'], 'leads_creator_sheet_group_deleted_idx');
            $table->index(['created_by', 'status', 'deleted_at', 'id'], 'leads_creator_status_deleted_id_idx');
            $table->index(['lead_sheet_id', 'lead_group_id', 'deleted_at', 'id'], 'leads_sheet_group_deleted_id_idx');
        });

        Schema::table('lead_groups', function (Blueprint $table) {
            $table->index(['lead_sheet_id', 'deleted_at', 'sort_order', 'id'], 'lead_groups_sheet_deleted_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lead_groups', function (Blueprint $table) {
            $table->dropIndex('lead_groups_sheet_deleted_sort_idx');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_creator_deleted_id_idx');
            $table->dropIndex('leads_creator_sheet_deleted_id_idx');
            $table->dropIndex('leads_creator_sheet_group_deleted_idx');
            $table->dropIndex('leads_creator_status_deleted_id_idx');
            $table->dropIndex('leads_sheet_group_deleted_id_idx');
        });
    }
};
