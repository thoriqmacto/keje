<?php

/*
|--------------------------------------------------------------------------
| Example resource — safe to delete
|--------------------------------------------------------------------------
| Part of the "notes" demo feature that proves the CRUD pattern in this
| starter. To remove the demo entirely, delete:
|   - this migration
|   - app/Models/Note.php
|   - app/Http/Controllers/Api/V1/NoteController.php
|   - app/Http/Requests/Api/V1/StoreNoteRequest.php
|   - database/factories/NoteFactory.php
|   - tests/Feature/Notes/
|   - the /notes routes in routes/api.php
|   - apps/web/app/(app)/notes/
|   - the /notes entry in apps/web/middleware.ts PROTECTED_PREFIXES
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
