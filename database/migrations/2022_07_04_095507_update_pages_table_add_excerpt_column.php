<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePagesTableAddExcerptColumn extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('excerpt')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('excerpt');
        });
    }
}
