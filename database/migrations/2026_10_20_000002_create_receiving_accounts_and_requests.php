<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('bank_bin', 6);
            $t->string('bank_name');
            $t->text('account_no');
            $t->string('account_fingerprint', 64);
            $t->string('account_holder_name', 100);
            $t->string('label', 100);
            $t->string('status', 20)->default('active');
            $t->string('verification_status', 30)->default('not_checked');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'account_fingerprint']);
        });
        Schema::create('receiving_account_defaults', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('scope_key', 50);
            $t->foreignId('receiving_account_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['workspace_id', 'scope_key']);
        });
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->constrained()->restrictOnDelete();
            $t->foreignId('rental_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('payer_id')->constrained('users');
            $t->foreignId('receiving_account_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('obligation_key', 100)->unique();
            $t->unsignedBigInteger('amount');
            $t->string('transfer_reference', 18)->unique();
            $t->text('payee_snapshot');
            $t->string('status', 30)->default('awaiting_payment');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->string('bank_transaction_reference', 100)->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('payment_transfer_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('reference', 100);
            $t->text('note')->nullable();
            $t->string('status', 20)->default('pending');
            $t->text('review_reason')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->string('evidence_path')->nullable();
            $t->timestamps();
            $t->unique(['payment_request_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transfer_reports');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('receiving_account_defaults');
        Schema::dropIfExists('receiving_accounts');
    }
};
