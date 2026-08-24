<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P24 Task 3: the `Story` fixture's own table — one column per filter shape
// `StoryResource` declares, plus the `deleted_at` a real `TrashedFilter`
// needs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            // The plain SelectFilter's column.
            $table->string('status')->default('draft');
            // The ->multiple() SelectFilter's column: whereIn over one column,
            // which is all a multiple select filter does.
            $table->string('tags')->nullable();
            // The TernaryFilter's column.
            $table->boolean('featured')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
