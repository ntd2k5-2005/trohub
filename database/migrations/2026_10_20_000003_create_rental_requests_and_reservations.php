<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['workspaces', 'properties', 'rooms'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('rental_policy')->nullable());
        }
        Schema::table('receiving_account_defaults', fn (Blueprint $t) => $t->foreignId('room_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('rentals', function (Blueprint $t) {
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('residency_started_at')->nullable();
        });
        Schema::create('rental_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->foreignId('listing_id')->constrained();
            $t->foreignId('workspace_id')->constrained();
            $t->foreignId('rental_id')->nullable()->constrained();
            $t->string('active_key', 80)->nullable()->unique();
            $t->string('status', 30)->default('submitted');
            $t->unsignedInteger('version')->default(1);
            $t->json('terms_snapshot');
            $t->text('message')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestamp('terms_accepted_at');
            $t->timestamps();
        });
        Schema::create('room_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained();
            $t->foreignId('active_room_id')->nullable()->unique()->constrained('rooms');
            $t->foreignId('rental_request_id')->unique()->constrained();
            $t->foreignId('rental_id')->unique()->constrained();
            $t->string('status', 30)->default('holding');
            $t->timestamp('hold_until');
            $t->timestamp('exception_until')->nullable();
            $t->unsignedSmallInteger('extensions')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->text('reason')->nullable();
            $t->json('policy_snapshot');
            $t->timestamps();
        });
        Schema::create('payment_request_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_request_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->string('reference', 100)->unique();
            $t->foreignId('verified_by')->constrained('users');
            $t->text('reason')->nullable();
            $t->boolean('exception')->default(false);
            $t->timestamp('verified_at');
            $t->timestamps();
        });
        Schema::create('deposit_refund_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_id')->constrained();
            $t->unsignedBigInteger('amount_received');
            $t->unsignedBigInteger('deduction')->default(0);
            $t->text('reason');
            $t->string('status', 30)->default('proposed');
            $t->timestamp('payer_accepted_at')->nullable();
            $t->text('payer_note')->nullable();
            $t->timestamp('refunded_at')->nullable();
            $t->string('refund_reference', 100)->nullable()->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_refund_decisions');
        Schema::dropIfExists('payment_request_transactions');
        Schema::dropIfExists('room_reservations');
        Schema::dropIfExists('rental_requests');
        Schema::table('rentals', fn (Blueprint $t) => $t->dropColumn(['signed_at', 'residency_started_at']));
        Schema::table('receiving_account_defaults', fn (Blueprint $t) => $t->dropConstrainedForeignId('room_id'));
        foreach (['workspaces', 'properties', 'rooms'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('rental_policy'));
        }
    }
};
