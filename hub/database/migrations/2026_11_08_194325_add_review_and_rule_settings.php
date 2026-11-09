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
        Schema::table('servers', function (Blueprint $table) {
            $table->json('ssh_allowlist')->nullable()->after('notes');
            $table->json('known_failed_units')->nullable()->after('ssh_allowlist');
        });

        Schema::table('rule_hits', function (Blueprint $table) {
            $table->string('title')->after('rule');
            $table->unique(['server_id', 'date', 'rule']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('verdict', 10)->nullable()->change();
            $table->text('summary')->nullable()->after('confidence');
            $table->text('error')->nullable()->after('findings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['summary', 'error']);
            $table->string('verdict', 10)->nullable(false)->change();
        });

        Schema::table('rule_hits', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'date', 'rule']);
            $table->dropColumn('title');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['ssh_allowlist', 'known_failed_units']);
        });
    }
};
