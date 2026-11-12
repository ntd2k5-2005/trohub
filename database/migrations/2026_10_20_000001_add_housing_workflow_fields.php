<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->string('public_address')->nullable();
            $t->json('amenity_ids')->nullable();
            $t->json('custom_amenities')->nullable();
        });
        Schema::table('rooms', function (Blueprint $t) {
            $t->decimal('length', 8, 2)->nullable();
            $t->decimal('width', 8, 2)->nullable();
            $t->string('floor', 30)->nullable();
            $t->string('room_type', 40)->nullable();
            $t->date('available_from')->nullable();
            $t->json('amenity_ids')->nullable();
            $t->json('custom_amenities')->nullable();
        });
        Schema::table('roommate_needs', function (Blueprint $t) {
            $t->string('mode', 30)->default('search_together');
            $t->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('budget_basis', 20)->default('per_person');
            $t->unsignedSmallInteger('people_needed')->default(1);
            $t->unsignedSmallInteger('duration_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('roommate_needs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('room_id');
            $t->dropColumn(['mode', 'budget_basis', 'people_needed', 'duration_months']);
        });
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn(['length', 'width', 'floor', 'room_type', 'available_from', 'amenity_ids', 'custom_amenities']));
        Schema::table('properties', fn (Blueprint $t) => $t->dropColumn(['public_address', 'amenity_ids', 'custom_amenities']));
    }
};
