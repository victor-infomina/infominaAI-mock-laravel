<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InteractsWithDevDb
{
    protected function setUpDevDbSchema(): void
    {
        config([
            'database.connections.be_pgsql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('be_pgsql');

        Schema::connection('be_pgsql')->create('datasource_requests', function ($table) {
            $table->uuid('id')->primary();
            $table->string('subject_name');
            $table->string('subject_reg_no');
            $table->string('type');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::connection('be_pgsql')->create('datasource_transactions', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id_id');
        });

        Schema::connection('be_pgsql')->create('datasource_purchased', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id_id');
            $table->string('raw_s3_url')->nullable();
            $table->uuid('report_id')->nullable();
        });

        Schema::connection('be_pgsql')->create('datasource_reports', function ($table) {
            $table->uuid('id')->primary();
            $table->string('s3_url')->nullable();
        });

        Schema::connection('be_pgsql')->create('idaman_document', function ($table) {
            $table->uuid('id')->primary();
            $table->string('entity_number');
            $table->string('version_id');
            $table->string('form');
            $table->date('document_date');
            $table->string('s3_url')->nullable();
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
        });
    }
}
