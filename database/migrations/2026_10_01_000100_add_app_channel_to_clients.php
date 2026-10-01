<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('channel', 10)->default('whatsapp')->after('phone'); // whatsapp | app
            $table->string('app_token', 64)->nullable()->unique()->after('channel'); // identifies the device in the Safara app
            $table->string('contact_phone', 20)->nullable()->after('app_token');    // number an app client typed in (digits only)
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['app_token']);
            $table->dropColumn(['channel', 'app_token', 'contact_phone']);
        });
    }
};
