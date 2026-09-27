<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();     // international, digits only
            $table->string('name')->nullable();          // WhatsApp profile name, then passport name
            $table->string('city')->nullable();
            $table->string('language', 2)->default('en'); // en | ha
            $table->text('notes')->nullable();
            $table->timestamp('last_inbound_at')->nullable(); // for the 24-hour window
            $table->timestamps();
        });

        Schema::create('passports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Personal fields are encrypted at rest (see App\Models\Passport casts).
            $table->text('surname')->nullable();
            $table->text('given_names')->nullable();
            $table->text('number')->nullable();
            $table->text('date_of_birth')->nullable();
            $table->string('number_last3', 3)->nullable(); // for masked display without decrypting
            $table->string('nationality', 3)->nullable();
            $table->string('issuing_country', 3)->nullable();
            $table->string('sex', 1)->nullable();
            $table->date('expiry')->nullable();
            $table->json('confidence')->nullable();
            $table->boolean('mrz_valid')->default(false);
            $table->string('image_path')->nullable();      // encrypted file on the local disk
            $table->timestamp('confirmed_at')->nullable();  // client said the details are right
            $table->timestamp('consent_at')->nullable();    // client agreed to keep it for next time
            $table->timestamp('delete_after')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique(); // SF-2417
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->string('source', 16)->default('whatsapp'); // whatsapp | manual
            $table->string('origin', 3)->nullable();
            $table->string('destination', 3)->nullable();
            $table->date('depart_on')->nullable();
            $table->date('return_on')->nullable();
            $table->unsignedTinyInteger('travellers')->nullable();
            $table->string('cabin_class', 20)->default('economy');
            $table->unsignedBigInteger('fare_amount')->nullable();   // NGN, cheapest fare at quote time
            $table->unsignedBigInteger('quote_amount')->nullable();  // NGN, what the client pays
            $table->unsignedBigInteger('paid_amount')->default(0);   // NGN, confirmed so far
            $table->unsignedBigInteger('ticketed_fare')->nullable(); // NGN, what we paid Duffel
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->string('pnr', 12)->nullable();
            $table->string('provider_order_id')->nullable();
            $table->string('ticket_path')->nullable();
            $table->timestamp('ticketed_at')->nullable();
            $table->boolean('bot_paused')->default(false);  // a person has taken over the chat
            $table->string('flag')->nullable();             // short note shown on desk cards
            $table->string('flag_tone', 8)->nullable();     // info | warn | ok | mute
            $table->json('state')->nullable();              // conversation scratchpad
            $table->timestamps();
        });

        Schema::create('booking_passport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passport_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();
            $table->unique(['booking_id', 'position']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider_offer_id');
            $table->string('batch', 40)->index();      // one search = one batch
            $table->string('airline');
            $table->string('airline_code', 3)->nullable();
            $table->date('depart_on');
            $table->string('summary');                 // "via Cairo · 11h 40m"
            $table->unsignedTinyInteger('stops')->default(0);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('baggage')->nullable();
            $table->unsignedBigInteger('amount');      // NGN
            $table->decimal('original_amount', 12, 2);
            $table->string('original_currency', 3);
            $table->json('segments')->nullable();
            $table->json('passenger_ids')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('selected')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 24)->unique();  // PAY-88213 / LINK-3319 / REF-...
            $table->string('kind', 12)->default('charge'); // charge | refund
            $table->string('purpose', 16)->default('fare'); // fare | difference | refund
            $table->string('provider', 40);
            $table->string('provider_ref')->nullable();
            $table->string('method', 16)->nullable();  // card | transfer | ussd
            $table->unsignedBigInteger('amount');       // NGN
            $table->string('status', 16)->index();      // open | confirmed | failed | expired | due | sent
            $table->string('token', 64)->unique();       // public pay-link token
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3);              // in | out
            $table->string('type', 16);                  // text | image | button | list | cta | document | template
            $table->text('body')->nullable();
            $table->json('payload')->nullable();         // buttons, list rows, media ids, reply ids
            $table->string('wa_id')->nullable()->unique();
            $table->string('status', 16)->nullable();    // sent | delivered | read | failed
            $table->string('sent_by')->nullable();       // bot | operator name
            $table->timestamps();
        });

        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->string('detail')->nullable();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('stage');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('kind', 16)->default('session'); // session | template
            $table->string('meta_name')->nullable();         // approved template name at Meta
            $table->string('meta_status', 16)->nullable();   // approved | in_review | rejected
            $table->text('body_en');
            $table->text('body_ha');
            $table->json('buttons_en')->nullable();
            $table->json('buttons_ha')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'message_templates', 'booking_events', 'messages', 'payments', 'offers', 'booking_passport', 'bookings', 'passports', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
