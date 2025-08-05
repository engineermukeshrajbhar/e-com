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
        Schema::table('orders', function (Blueprint $table) {
            // Option 1: Change to string with sufficient length
            $table->string('status', 50)->default('pending')->change();
        });
        // Schema::table('orders', function (Blueprint $table) {
        //      $table->enum('status', ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'failed'])
        //       ->default('pending')
        //       ->change();
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 10)->default('pending')->change();
        });
    }
};
