<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table) {
            $table->id();
            // OIDC identifiers compare byte-for-byte, including case.
            $table->binary('issuer', 512);
            $table->binary('subject', 255);
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unique(['issuer', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identities');
    }
};
