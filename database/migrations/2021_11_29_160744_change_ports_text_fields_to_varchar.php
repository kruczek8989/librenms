<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->string('ifAlias')->nullable()->change();
            $table->string('ifType', 64)->nullable()->change();
            $table->string('ifPhysAddress', 64)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->text('ifAlias')->nullable()->change();
            $table->text('ifType')->nullable()->change();
            $table->text('ifPhysAddress')->nullable()->change();
        });
    }
};
