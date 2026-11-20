<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| flags-2026-11.md S3.1, S3.7: a policy - Terms and Conditions, Privacy, Returns and the rest - is its own Content
| document, Draft or Published at once as a page is, of one kind from a fixed list, serving a LIST of stores (one text
| for KSA and Egypt, another for the UAE) rather than one store. Its words per language (title, address, body, SEO),
| its share image per language (content.md §1.14, owner 2026-11-20: as pages) and its body's images, each with a
| RESTRICT key to platform.media (CONVENTIONS "Stores": never inside JSON), its stores.
|
| `text_version` is the number of the published text (S3.2, F14): 0 until first published, raised by each publish that
| changes the text; `version` is the edit lock (content.md §1.1 rule 12).
|
| An address is unique per store and language (owner, 2026-11-20: a KSA copy split off a KSA+Egypt policy and the
| Egypt original both keep `terms`), the same in every store the policy serves: no unique index here, since a row names
| no store; each store's addresses are claimed in content.slug_history, asked first in code (`SlugTaken`), with its
| unique (store_id, kind, locale, slug) as the last line. "A store has at most one published policy of each kind
| (Other excepted)" spans two tables and is refused in code only, under the policies' lock (`PolicyRules`).
|
| CHECKs the Schema builder cannot write, in standard SQL (CONVENTIONS, "Database"), each refused first by the code;
| except the body's shape (`jsonb_typeof`), which has no standard form and copies pt_body (page_translations): the
| owner approved it on 2026-11-20 (CONVENTIONS: "something with no standard SQL form at all needs the owner's OK").
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.policies', function (Blueprint $table) {
            $table->ulid('id');
            $table->string('kind', 20);
            $table->string('status', 16)->default('DRAFT');
            $table->timestampTz('published_at')->nullable();
            $table->ulid('share_media_ar')->nullable();
            $table->ulid('share_media_en')->nullable();
            $table->integer('text_version')->default(0);
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'policies_pkey');
            $table->foreign('share_media_ar', 'policies_share_ar')->references('id')->on('platform.media')->restrictOnDelete();
            $table->foreign('share_media_en', 'policies_share_en')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index(['status', 'kind'], 'policies_status_kind_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.policies
                ADD CONSTRAINT policies_kind CHECK (kind IN ('TERMS', 'PRIVACY', 'RETURNS', 'SHIPPING', 'WARRANTY', 'PAYMENT_METHODS', 'COMPLAINTS', 'COMPANY_TERMS', 'OTHER')),
                ADD CONSTRAINT policies_status CHECK (status IN ('DRAFT', 'PUBLISHED')),
                ADD CONSTRAINT policies_published CHECK ((status = 'PUBLISHED') = (published_at IS NOT NULL)),
                ADD CONSTRAINT policies_text_version CHECK (text_version >= 0 AND (status <> 'PUBLISHED' OR text_version >= 1)),
                ADD CONSTRAINT policies_version CHECK (version >= 1)
            SQL);

        Schema::create('content.policy_translations', function (Blueprint $table) {
            $table->ulid('policy_id');
            $table->string('locale', 2);
            $table->string('title', 150);
            $table->string('slug', 200);
            $table->jsonb('body');
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();

            $table->primary(['policy_id', 'locale'], 'policy_translations_pkey');
            $table->foreign('policy_id', 'polt_policy')->references('id')->on('content.policies')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.policy_translations
                ADD CONSTRAINT polt_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT polt_title CHECK (char_length(TRIM(title)) >= 1),
                ADD CONSTRAINT polt_slug CHECK (char_length(slug) BETWEEN 1 AND 200 AND POSITION('/' IN slug) = 0),
                ADD CONSTRAINT polt_body CHECK (COALESCE(jsonb_typeof(body -> 'blocks') = 'array', false))
            SQL);

        Schema::create('content.policy_stores', function (Blueprint $table) {
            $table->ulid('policy_id');
            $table->ulid('store_id');

            $table->primary(['policy_id', 'store_id'], 'policy_stores_pkey');
            $table->foreign('policy_id', 'pols_policy')->references('id')->on('content.policies')->cascadeOnDelete();
            $table->foreign('store_id', 'pols_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('store_id', 'pols_store_idx');
        });

        Schema::create('content.policy_images', function (Blueprint $table) {
            $table->ulid('policy_id');
            $table->string('locale', 2);
            $table->integer('ref');
            $table->ulid('media_id');
            $table->text('alt');

            $table->primary(['policy_id', 'locale', 'ref'], 'policy_images_pkey');
            $table->foreign('policy_id', 'poli_policy')->references('id')->on('content.policies')->cascadeOnDelete();
            $table->foreign('media_id', 'poli_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'poli_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.policy_images
                ADD CONSTRAINT poli_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT poli_ref CHECK (ref >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.policy_images');
        Schema::dropIfExists('content.policy_stores');
        Schema::dropIfExists('content.policy_translations');
        Schema::dropIfExists('content.policies');
    }
};
