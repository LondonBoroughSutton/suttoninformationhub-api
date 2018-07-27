<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class MigrationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        /*
         * ID Foreign Key Helper.
         */
        Blueprint::macro('foreignId', function (string $column, string $referencesTable, string $referencedColumn = 'id', bool $nullable = false) {
            $this->unsignedInteger($column)->nullable($nullable);
            $this->foreign($column)->references($referencedColumn)->on($referencesTable);
        });

        /*
         * ID Nullable Foreign Key Helper.
         */
        Blueprint::macro('nullableForeignId', function (string $column, string $referencesTable, string $referencedColumn = 'id') {
            $this->foreignId($column, $referencesTable, $referencedColumn, true);
        });

        /*
         * UUID Foreign Key Helper.
         */
        Blueprint::macro('foreignUuid', function (string $column, string $referencesTable, string $referencedColumn = 'id', bool $nullable = false) {
            $this->uuid($column)->nullable($nullable);
            $this->foreign($column)->references($referencedColumn)->on($referencesTable);
        });

        /*
         * UUID Nullable Foreign Key Helper.
         */
        Blueprint::macro('nullableForeignUuid', function (string $column, string $referencesTable, string $referencedColumn = 'id') {
            $this->foreignUuid($column, $referencesTable, $referencedColumn, true);
        });

        /*
         * UUID Polymorphic Helper.
         */
        Blueprint::macro('morphsUuid', function (string $name, string $indexName = null) {
            $this->string("{$name}_type");

            $this->uuid("{$name}_id");

            $this->index(["{$name}_type", "{$name}_id"], $indexName);
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}