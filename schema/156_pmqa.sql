-- 156_pmqa.sql — register PMQA-2562 as a framework profile (config-only registration path).
--
-- Rationale (see D:/PhD-NU/.Journals/Rung/TQM-Journal-Emerald/pmqa_analysis/06_pmqa_extension_ooad_design.md
-- and 05_pmqa_vs_edpex_comparison.md): PMQA-2562 uses the SAME ADLI (cat 1-6) / LeTCI (cat 7)
-- scoring paradigm as EdPEx (app/scoring.php's scoringScheme() keys purely off categoryN, not a
-- framework flag; scoringBands()/scoringChoices()/scoringDimCapOk()/scoringValidate() key off the
-- generic scheme code 'ADLI'/'LeTCI', not 'EdPEx'). Unlike AUN-QA (app/aunqa.php + 008_aunqa.sql,
-- a genuinely standalone module with its own 1-5 flat scoring shape and its own 3 tables), PMQA-2562
-- needs NO new scoring tables and NO new PHP scoring code — only:
--   (a) config/pmqa.json (category/item/points structure, mirrors config/edpex.json's shape), and
--   (b) a framework-profile registration row, added here, using the existing qh_framework_profiles
--       table (schema/124_faculty_quality_data_hub.sql) which already carries a framework_code ENUM
--       ('EDPEX','AUN_QA','TQF') for exactly this purpose — this migration only ADDS 'PMQA' to that
--       enum and inserts one profile row; it does not touch EDPEX/AUN_QA/TQF rows or any scoring.php
--       logic. This is additive-only, safe to apply without affecting the live EdPEx/AUN-QA/TQF data.

ALTER TABLE qh_framework_profiles
    MODIFY COLUMN framework_code ENUM('EDPEX','AUN_QA','TQF','PMQA') NOT NULL;

INSERT IGNORE INTO qh_framework_profiles
    (id, tenant_id, framework_code, edition, title_th, status, effective_from, source_url, created_at, updated_at)
VALUES (
    '00000000-0000-4000-a000-000000000156',
    '00000000-0000-4000-a000-000000000001',
    'PMQA',
    '2562',
    'Public Sector Management Quality Award criteria, B.E. 2562 / 2019 (PMQA-2562)',
    'active',
    CURDATE(),
    'config/pmqa.json',
    NOW(),
    NOW()
);
