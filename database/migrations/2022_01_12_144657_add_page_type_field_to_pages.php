<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPageTypeFieldToPages extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->enum('page_type', ['information', 'landing'])->default('information');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('page_type');
        });
    }
}
