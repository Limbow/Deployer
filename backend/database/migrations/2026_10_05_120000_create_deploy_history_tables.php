<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('build_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version');
            $table->text('changes')->nullable();
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->longText('log')->nullable();
            $table->text('remote_path');
            $table->text('public_remote_path')->nullable();
            $table->string('version_file_name')->nullable();
            $table->string('git_commit')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'server_id', 'status']);
        });
        Schema::create('deploy_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_id')->constrained()->cascadeOnDelete();
            $table->text('relative_path');
            $table->text('remote_path');
            $table->string('hash', 40);
            $table->unsignedBigInteger('size');
            $table->string('status');
            $table->text('backup_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_files');
        Schema::dropIfExists('deploys');
    }
};
