<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('scope')->default('workspace');
            $t->json('permissions');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('category');
            $t->boolean('delegable')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name');
            $t->string('plan')->default('trial');
            $t->string('status')->default('trial');
            $t->timestamp('expires_at');
            $t->timestamp('retention_until')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->string('provenance')->default('trial');
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('access_group_id')->nullable()->constrained();
            $t->string('status')->default('pending');
            $t->json('property_ids')->nullable();
            $t->timestamp('valid_until')->nullable();
            $t->unique(['workspace_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('direct_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('workspace_id')->nullable()->constrained();
            $t->foreignId('property_id')->nullable();
            $t->string('permission');
            $t->foreignId('grantor_id')->constrained('users');
            $t->timestamp('valid_from');
            $t->timestamp('valid_until')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('email');
            $t->foreignId('access_group_id')->nullable()->constrained();
            $t->string('token_hash')->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->json('property_ids')->nullable();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('name');
            $t->string('address');
            $t->string('district');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained();
            $t->string('name');
            $t->decimal('area', 8, 2);
            $t->unsignedBigInteger('rent');
            $t->unsignedBigInteger('deposit')->default(0);
            $t->unsignedInteger('capacity')->default(2);
            $t->string('status')->default('vacant');
            $t->text('amenities')->nullable();
            $t->text('rules')->nullable();
            $t->unique(['property_id', 'name']);
            $t->timestamps();
        });
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained();
            $t->string('title');
            $t->text('description');
            $t->string('status')->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->boolean('moderated')->default(false);
            $t->timestamps();
        });
        Schema::create('favorites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('listing_id')->constrained();
            $t->unique(['user_id', 'listing_id']);
            $t->timestamps();
        });
        Schema::create('connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('listing_id')->constrained();
            $t->string('status')->default('new');
            $t->text('message')->nullable();
            $t->timestamp('viewing_at')->nullable();
            $t->unique(['user_id', 'listing_id']);
            $t->timestamps();
        });
        Schema::create('roommate_needs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('district');
            $t->unsignedBigInteger('budget');
            $t->date('move_in');
            $t->text('lifestyle');
            $t->string('status')->default('open');
            $t->date('expires_at');
            $t->timestamps();
        });
        Schema::create('roommate_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('roommate_need_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('status')->default('pending');
            $t->unique(['roommate_need_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('rentals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained();
            $t->foreignId('tenant_id')->constrained('users');
            $t->foreignId('connection_id')->nullable()->constrained();
            $t->date('starts_at');
            $t->date('ends_at');
            $t->unsignedBigInteger('rent');
            $t->unsignedBigInteger('deposit')->default(0);
            $t->text('terms');
            $t->string('status')->default('draft');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->date('move_out_at')->nullable();
            $t->boolean('handover_complete')->default(false);
            $t->timestamps();
        });
        Schema::create('contract_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_id')->constrained();
            $t->unsignedInteger('version');
            $t->json('terms');
            $t->foreignId('actor_id')->constrained('users');
            $t->unique(['rental_id', 'version']);
            $t->timestamps();
        });
        Schema::create('occupants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('relationship')->default('resident');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->unique(['rental_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_id')->unique()->constrained();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('deduction')->default(0);
            $t->text('reason')->nullable();
            $t->timestamp('received_at');
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('service_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained();
            $t->string('name');
            $t->string('kind');
            $t->unsignedBigInteger('rate');
            $t->date('effective_from');
            $t->unique(['property_id', 'name', 'effective_from']);
            $t->timestamps();
        });
        Schema::create('meter_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained();
            $t->foreignId('service_rate_id')->constrained();
            $t->string('period', 7);
            $t->decimal('previous', 12, 2);
            $t->decimal('current', 12, 2);
            $t->decimal('usage', 12, 2);
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->unique(['room_id', 'service_rate_id', 'period']);
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_id')->constrained();
            $t->string('period', 7);
            $t->date('due_at');
            $t->string('status')->default('draft');
            $t->bigInteger('total');
            $t->timestamp('issued_at')->nullable();
            $t->unique(['rental_id', 'period']);
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->string('description');
            $t->decimal('quantity', 12, 2)->default(1);
            $t->bigInteger('unit_price');
            $t->bigInteger('amount');
            $t->boolean('adjustment')->default(false);
            $t->foreignId('actor_id')->constrained('users');
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedBigInteger('amount');
            $t->string('method');
            $t->string('reference')->unique();
            $t->timestamp('paid_at');
            $t->timestamps();
        });
        Schema::create('maintenance_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained();
            $t->foreignId('rental_id')->nullable()->constrained();
            $t->foreignId('reporter_id')->constrained('users');
            $t->string('title');
            $t->text('description');
            $t->string('status')->default('new');
            $t->timestamps();
        });
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporter_id')->constrained('users');
            $t->string('target_type');
            $t->unsignedBigInteger('target_id');
            $t->text('reason');
            $t->string('status')->default('open');
            $t->text('resolution')->nullable();
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_id')->unique()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedTinyInteger('rating');
            $t->text('comment');
            $t->timestamps();
        });
        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('title');
            $t->text('body');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('name');
            $t->string('serial')->unique();
            $t->string('token_hash');
            $t->string('risk')->default('unknown');
            $t->string('health')->default('unknown');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('last_event_at')->nullable();
            $t->unsignedInteger('config_version')->default(1);
            $t->timestamps();
        });
        Schema::create('device_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->timestamp('effective_from');
            $t->timestamp('effective_to')->nullable();
            $t->index(['device_id', 'effective_from']);
            $t->timestamps();
        });
        Schema::create('device_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained();
            $t->unsignedInteger('version');
            $t->json('settings');
            $t->timestamp('effective_from');
            $t->unique(['device_id', 'version']);
            $t->timestamps();
        });
        Schema::create('safety_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->string('event_id');
            $t->timestamp('event_time');
            $t->timestamp('ingest_time');
            $t->unsignedInteger('config_version');
            $t->string('risk');
            $t->string('health');
            $t->text('reason');
            $t->string('status')->default('open');
            $t->json('payload');
            $t->unique(['device_id', 'event_id']);
            $t->index(['room_id', 'event_time']);
            $t->timestamps();
        });
        Schema::create('audit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->foreignId('workspace_id')->nullable()->constrained();
            $t->string('action');
            $t->string('resource_type');
            $t->unsignedBigInteger('resource_id');
            $t->json('changes');
            $t->index(['workspace_id', 'created_at']);
            $t->timestamps();
        });
        Schema::create('domain_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->foreignId('workspace_id')->nullable()->constrained();
            $t->string('source');
            $t->string('event_type');
            $t->unsignedInteger('schema_version')->default(1);
            $t->timestamp('event_time');
            $t->timestamp('ingest_time');
            $t->string('provenance');
            $t->boolean('late')->default(false);
            $t->json('payload');
            $t->timestamp('projected_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('quarantined_events', function (Blueprint $t) {
            $t->id();
            $t->string('reason');
            $t->json('payload');
            $t->timestamps();
        });
        Schema::create('subscription_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('plan');
            $t->string('status')->default('pending');
            $t->string('reference')->unique();
            $t->foreignId('confirmed_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('status')->default('active');
            $t->foreignId('access_group_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('access_group_id');
            $t->dropColumn('status');
        });
        Schema::dropIfExists('subscription_orders');
        Schema::dropIfExists('quarantined_events');
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('safety_events');
        Schema::dropIfExists('device_configs');
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('maintenance_requests');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('service_rates');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('occupants');
        Schema::dropIfExists('contract_versions');
        Schema::dropIfExists('rentals');
        Schema::dropIfExists('roommate_requests');
        Schema::dropIfExists('roommate_needs');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('direct_grants');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('access_groups');
    }
};
