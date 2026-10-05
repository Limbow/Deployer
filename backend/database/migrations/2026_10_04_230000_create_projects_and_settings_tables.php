<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('local_path', 768)->unique();
            $table->string('angular_project');
            $table->string('build_output_path', 2048);
            $table->text('build_command');
            $table->json('ignore_patterns');
            $table->string('current_version');
            $table->timestamps();
        });

        Schema::create('project_server', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('production');
            $table->string('remote_path_override', 1024)->nullable();
            $table->primary(['project_id', 'server_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_server');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('settings');
    }
};
