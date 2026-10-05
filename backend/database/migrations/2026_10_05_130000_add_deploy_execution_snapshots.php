<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploys', function (Blueprint $table) {
            $table->string('project_name')->nullable()->after('build_id');
            $table->string('project_type')->nullable()->after('project_name');
            $table->string('server_name')->nullable()->after('project_type');
            $table->text('source_path')->nullable()->after('server_name');
            $table->boolean('delete_obsolete')->default(false)->after('public_remote_path');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('progress');
        });
        Schema::table('deploy_files', function (Blueprint $table) {
            $table->text('local_path')->nullable()->after('remote_path');
        });
    }

    public function down(): void
    {
        Schema::table('deploy_files', function (Blueprint $table) {
            $table->dropColumn('local_path');
        });
        Schema::table('deploys', function (Blueprint $table) {
            $table->dropColumn(['project_name', 'project_type', 'server_name', 'source_path', 'delete_obsolete', 'duration_ms']);
        });
    }
};
