<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('type')->default('angular');
            $table->string('target_platform')->default('web');
            $table->string('angular_project')->nullable()->change();
            $table->text('build_command')->nullable()->change();
        });
        Schema::table('project_server', function (Blueprint $table) {
            $table->string('public_remote_path', 1024)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_server', function (Blueprint $table) {
            $table->dropColumn('public_remote_path');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['type', 'target_platform']);
        });
    }
};
