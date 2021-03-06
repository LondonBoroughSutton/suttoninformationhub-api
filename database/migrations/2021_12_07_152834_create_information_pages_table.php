<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInformationPagesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('information_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('content');
            $table->boolean('enabled')->default(false);
            $table->uuid('parent_uuid')->nullable();
            $table->timestamps();
            $table->nestedSet();
        });

        Schema::table('information_pages', function (Blueprint $table) {
            $table->nullableForeignUuid('image_file_id', 'files');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('information_pages', function (Blueprint $table) {
            $table->dropForeign(['image_file_id']);
        });
        Schema::dropIfExists('information_pages');
    }
}
