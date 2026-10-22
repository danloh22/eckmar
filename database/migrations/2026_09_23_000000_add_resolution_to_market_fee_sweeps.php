<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResolutionToMarketFeeSweeps extends Migration
{
    public function up()
    {
        Schema::table('market_fee_sweeps', function (Blueprint $table) {
            $table->string('resolution', 20)->nullable()->after('error');
            $table->uuid('resolved_by')->nullable()->after('resolution');
            $table->text('resolution_note')->nullable()->after('resolved_by');
            $table->timestamp('resolved_at')->nullable()->after('resolution_note');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('market_fee_sweeps', function (Blueprint $table) {
            $table->dropForeign(['resolved_by']);
            $table->dropColumn(['resolution', 'resolved_by', 'resolution_note', 'resolved_at']);
        });
    }
}
