<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('container_id')->nullable(); // Docker container ID
            $table->string('container_name')->nullable(); // Docker container name
            $table->integer('port')->nullable(); // Assigned port
            $table->string('preview_url')->nullable(); // Full preview URL
            $table->enum('status', ['creating', 'active', 'stopped', 'error'])->default('creating');
            $table->json('metadata')->nullable(); // Project settings, components, etc.
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index('container_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
