<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aeo_prompts', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->text('prompt');
            $t->string('language')->nullable();
            $t->string('buying_journey_phase')->nullable();
            $t->json('ai_assistants')->nullable();
            $t->string('business_unit_id')->nullable();
            $t->json('visibility')->nullable();      // aggregated metrics from GET /prompts/{id}
            $t->timestamp('hs_created_at')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
        });

        Schema::create('aeo_prompt_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('prompt_id')->index();
            $t->string('state')->nullable();
            $t->string('ai_model')->nullable()->index(); // engine: ChatGPT / Gemini / Perplexity ...
            $t->timestamp('completed_at')->nullable()->index();
            $t->unsignedInteger('total_citations')->default(0);
            $t->unsignedInteger('owned_mentions')->default(0);
            $t->unsignedInteger('competitor_mentions')->default(0);
            $t->longText('response_text')->nullable();
            $t->json('search_queries')->nullable();
            $t->boolean('details_fetched')->default(false);
            $t->timestamp('hs_created_at')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
        });

        Schema::create('aeo_citations', function (Blueprint $t) {
            $t->id();
            $t->uuid('run_id')->index();
            $t->text('url');
            $t->string('domain')->index();
            $t->text('title')->nullable();
            $t->timestamps();
        });

        Schema::create('aeo_recommendations', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->json('prompt_ids')->nullable();
            $t->string('answer_engine')->nullable();
            $t->string('recommendation_type')->nullable();
            $t->string('action_category')->nullable();
            $t->string('action_channel')->nullable();
            $t->string('content_type')->nullable();
            $t->string('content_topic')->nullable();
            $t->string('domain')->nullable();
            $t->text('url')->nullable();
            $t->string('priority')->nullable();
            $t->string('status')->nullable();
            $t->text('recommendation_summary')->nullable();
            $t->text('justification')->nullable();
            $t->text('associated_content_url')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('hs_created_at')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aeo_recommendations');
        Schema::dropIfExists('aeo_citations');
        Schema::dropIfExists('aeo_prompt_runs');
        Schema::dropIfExists('aeo_prompts');
    }
};
