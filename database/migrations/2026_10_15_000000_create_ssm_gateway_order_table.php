<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssm_gateway_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients');
            $table->string('kind');
            $table->string('subject_key');
            $table->string('reg_no');
            $table->string('name');
            $table->unsignedInteger('variant_id');
            $table->string('bill_id')->nullable()->unique();
            $table->string('ref_no')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssm_gateway_order');
    }
};
