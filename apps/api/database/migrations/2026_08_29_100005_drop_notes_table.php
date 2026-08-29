<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the starter's "notes" demo resource, now that Content Studio is the
 * real CRUD surface.
 *
 * A forward migration rather than deleting the original: the create migration
 * has already run on deployed environments, and editing it would leave the
 * table behind there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notes');
    }

    public function down(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }
};
