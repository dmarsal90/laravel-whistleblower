<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblower_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tracking_code_hash', 64)->unique();
            $table->string('status', 20)->index();
            $table->string('category')->nullable();
            // Personal and report content: encrypted by the model casts.
            $table->text('subject')->nullable();
            $table->longText('description')->nullable();
            $table->boolean('anonymous');
            $table->text('reporter_name')->nullable();
            $table->text('reporter_email')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('acknowledgement_due_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolution_due_at');
            $table->timestamp('extended_at')->nullable();
            $table->timestamp('investigation_started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whistleblower_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('report_id')->constrained('whistleblower_reports')->cascadeOnDelete();
            $table->string('author', 10);
            $table->string('user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at');
        });

        // Append-only: never store personal data or report content here.
        Schema::create('whistleblower_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('report_id')->constrained('whistleblower_reports')->cascadeOnDelete();
            $table->string('actor_type', 10);
            $table->string('actor_id')->nullable();
            $table->string('action', 40);
            $table->json('context')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblower_audit_logs');
        Schema::dropIfExists('whistleblower_messages');
        Schema::dropIfExists('whistleblower_reports');
    }
};
