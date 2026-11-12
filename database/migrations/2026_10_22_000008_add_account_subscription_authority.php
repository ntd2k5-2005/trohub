<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_versions', function(Blueprint $t){
            $t->id();$t->string('plan');$t->string('fingerprint',64)->unique();$t->json('snapshot');$t->timestamps();
        });
        Schema::create('account_subscriptions', function(Blueprint $t){
            $t->id();$t->foreignId('owner_id')->unique()->constrained('users')->restrictOnDelete();
            $t->foreignId('plan_version_id')->constrained()->restrictOnDelete();$t->string('status');
            $t->timestamp('trial_started_at')->nullable();$t->timestamp('expires_at');$t->unsignedInteger('epoch')->default(1);
            $t->unsignedInteger('version')->default(1);$t->unsignedBigInteger('current_cycle_id')->nullable();
            $t->timestamp('authority_enabled_at')->nullable();$t->timestamps();
        });
        Schema::create('subscription_quotes', function(Blueprint $t){
            $t->id();$t->foreignId('account_subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('plan_version_id')->constrained()->restrictOnDelete();$t->unsignedBigInteger('amount');
            $t->string('currency',3);$t->string('reference',100)->unique();$t->unsignedInteger('expected_version');
            $t->timestamp('expires_at');$t->string('status')->default('pending');$t->timestamps();
        });
        Schema::create('subscription_cycles', function(Blueprint $t){
            $t->id();$t->foreignId('account_subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('subscription_quote_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('verified_amount');$t->string('bank_reference',100)->unique();
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();$t->text('verification_note');
            $t->timestamp('starts_at');$t->timestamp('ends_at');$t->timestamp('verified_at');
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('reversed_at')->nullable();
            $t->text('reversal_evidence')->nullable();$t->timestamps();
        });
        Schema::table('account_subscriptions',fn(Blueprint $t)=>$t->foreign('current_cycle_id')->references('id')->on('subscription_cycles')->restrictOnDelete());
        Schema::create('account_restrictions',function(Blueprint $t){
            $t->id();$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->string('cause',100);
            $t->string('active_key')->nullable()->unique();$t->text('reason');$t->foreignId('created_by')->constrained('users');
            $t->foreignId('released_by')->nullable()->constrained('users');$t->timestamp('released_at')->nullable();$t->timestamps();
        });
    }
    public function down(): void {throw new RuntimeException('Không xóa lịch sử kỳ gói, đối soát, báo giá hoặc hạn chế tài khoản.');}
};
