<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_files', function (Blueprint $table) {
            $table->boolean('manual_delete')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('deploy_files', fn (Blueprint $table) => $table->dropColumn('manual_delete'));
    }
};
