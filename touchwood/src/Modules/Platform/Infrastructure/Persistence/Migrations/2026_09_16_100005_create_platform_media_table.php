<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Platform spec §5.4. Only keys are stored: the bytes live in object storage (handoff §5.5).
| Originals stay on the private disk; public variants are served by the CDN, and their keys are
| derived, never stored.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform.media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('visibility', 16);
            $table->string('disk', 32);
            $table->string('object_key', 255);
            $table->string('original_filename', 255);
            $table->string('mime', 100);
            $table->bigInteger('bytes');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->char('checksum', 64);
            $table->string('alt_ar', 255)->nullable();
            $table->string('alt_en', 255)->nullable();
            $table->string('variants_status', 16)->nullable();
            $table->timestampTz('variants_queued_at')->nullable();
            $table->timestampTz('variants_generated_at')->nullable();
            $table->char('uploaded_by', 26)->nullable();
            $table->timestampsTz();

            $table->unique(['disk', 'object_key']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE platform.media
                ADD CONSTRAINT media_visibility CHECK (visibility IN ('PUBLIC', 'PRIVATE')),
                ADD CONSTRAINT media_variants_status CHECK (variants_status IS NULL OR variants_status IN ('PENDING', 'READY', 'FAILED')),
                ADD CONSTRAINT media_variants_queued CHECK ((variants_status IS NULL) = (variants_queued_at IS NULL)),
                ADD CONSTRAINT media_bytes_positive CHECK (bytes > 0),
                ADD CONSTRAINT media_dimensions CHECK ((width IS NULL) = (height IS NULL) AND (width IS NULL OR (width > 0 AND height > 0))),
                ADD CONSTRAINT media_checksum_format CHECK (checksum ~ '^[0-9a-f]{64}$')
            SQL);

        // The dedupe rule: identical public images are stored once. Private files never are.
        DB::statement("CREATE UNIQUE INDEX media_public_checksum_unique ON platform.media (checksum) WHERE visibility = 'PUBLIC'");
        // Keyset pagination for the media library screen.
        DB::statement('CREATE INDEX media_created_idx ON platform.media (created_at DESC, id DESC)');
        // Finds failed generation, and PENDING images whose job was lost, oldest first.
        DB::statement("CREATE INDEX media_variants_pending_idx ON platform.media (variants_status, variants_queued_at) WHERE variants_status <> 'READY'");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.media');
    }
};
