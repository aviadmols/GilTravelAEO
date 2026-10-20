<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flat MySQL views for Looker Studio. Connect Looker to these, not the raw tables.
 * Skipped on other databases: the dashboard runs on SQLite and does not use them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        // Brand visibility = share of completed runs where the brand was mentioned
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_aeo_visibility_weekly AS
            SELECT
                DATE(DATE_SUB(r.completed_at, INTERVAL WEEKDAY(r.completed_at) DAY)) AS week_start,
                r.ai_model                                   AS engine,
                COUNT(*)                                      AS runs,
                SUM(r.owned_mentions > 0)                     AS runs_with_brand,
                SUM(r.owned_mentions > 0) / COUNT(*)          AS brand_visibility,
                SUM(r.competitor_mentions)                    AS competitor_mentions,
                SUM(r.total_citations)                        AS citations
            FROM aeo_prompt_runs r
            WHERE r.completed_at IS NOT NULL
            GROUP BY week_start, engine
        SQL);

        // One row per prompt: lifetime + last-28-days visibility
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_aeo_prompt_performance AS
            SELECT
                p.id                                  AS prompt_id,
                p.prompt,
                p.buying_journey_phase,
                p.language,
                COUNT(r.id)                           AS runs,
                AVG(r.owned_mentions > 0)             AS visibility_all_time,
                AVG(CASE WHEN r.completed_at >= NOW() - INTERVAL 28 DAY
                         THEN r.owned_mentions > 0 END) AS visibility_28d,
                MAX(r.completed_at)                   AS last_run_at
            FROM aeo_prompts p
            LEFT JOIN aeo_prompt_runs r ON r.prompt_id = p.id AND r.completed_at IS NOT NULL
            GROUP BY p.id, p.prompt, p.buying_journey_phase, p.language
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_aeo_citation_domains AS
            SELECT
                c.domain,
                r.ai_model                                   AS engine,
                DATE(DATE_SUB(r.completed_at, INTERVAL WEEKDAY(r.completed_at) DAY)) AS week_start,
                COUNT(*)                                     AS citations
            FROM aeo_citations c
            JOIN aeo_prompt_runs r ON r.id = c.run_id
            GROUP BY c.domain, engine, week_start
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_aeo_recommendations AS
            SELECT id, recommendation_summary, recommendation_type, action_category, action_channel,
                   content_type, content_topic, priority, status, answer_engine, domain, url,
                   DATE(hs_created_at) AS created_date
            FROM aeo_recommendations
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        foreach (['v_aeo_recommendations', 'v_aeo_citation_domains', 'v_aeo_prompt_performance', 'v_aeo_visibility_weekly'] as $v) {
            DB::statement("DROP VIEW IF EXISTS {$v}");
        }
    }
};
