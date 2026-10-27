-- 008_aunqa.sql — AUN-QA program assessment (migrated slice from legacy prompti2_sciutk).
-- Three tables: programs (ref), assessments (per program×year, overall_score), criterion scores.
-- Feeds KPI 7-4-074 "Percentage of programmes assessed against the AUN-QA criteria with a score of 3 or higher".

CREATE TABLE IF NOT EXISTS aunqa_programs (
    id         CHAR(36)     NOT NULL,
    code       VARCHAR(20)  NOT NULL,
    name_th    VARCHAR(300) NOT NULL,
    name_en    VARCHAR(300) NOT NULL DEFAULT '',
    degree     VARCHAR(40)  NOT NULL DEFAULT '',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aunqa_prog_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aunqa_assessments (
    id            CHAR(36)     NOT NULL,
    tenant_id     CHAR(36)     NOT NULL DEFAULT '00000000-0000-4000-a000-000000000001',
    program_id    CHAR(36)     NOT NULL,
    academic_year SMALLINT     NOT NULL,
    assessor_name VARCHAR(255) NOT NULL DEFAULT '',
    status        ENUM('draft','submitted','approved') NOT NULL DEFAULT 'draft',
    overall_score DECIMAL(4,2) NULL,
    assessment_date DATE       NULL,
    notes         TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aunqa_prog_year (program_id, academic_year),
    KEY idx_aunqa_year (academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aunqa_criterion_scores (
    id            CHAR(36)    NOT NULL,
    assessment_id CHAR(36)    NOT NULL,
    criterion_code VARCHAR(10) NOT NULL,
    criterion_name_th VARCHAR(300) NOT NULL DEFAULT '',
    score         DECIMAL(4,2) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aunqa_score (assessment_id, criterion_code),
    KEY idx_aunqa_score_assess (assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
