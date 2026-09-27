-- ====================================================================
-- PHASE 2 MIGRATION: 001_phase2_backend_hardening.sql
-- Database: android_studio_db
-- Target: PostgreSQL
-- Safety: Additive only. No destructive drops or truncations.
-- ====================================================================

-- 1. Authentication Sessions Table
CREATE TABLE IF NOT EXISTS student_tokens (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE DEFAULT (CURRENT_TIMESTAMP + INTERVAL '30 days')
);

CREATE INDEX IF NOT EXISTS idx_student_tokens_token ON student_tokens(token);
CREATE INDEX IF NOT EXISTS idx_student_tokens_student_id ON student_tokens(student_id);

-- 2. Master Scholarship Programs Table (For Admin Management)
CREATE TABLE IF NOT EXISTS scholarships (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    academic_year VARCHAR(50) NOT NULL,
    deadline VARCHAR(50),
    stipend_amount VARCHAR(50) DEFAULT '₱30,000.00',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_scholarships_code ON scholarships(code);

-- 3. Additive columns for Student Documents (Admin Review Metadata)
ALTER TABLE student_documents 
    ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(100),
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP WITH TIME ZONE,
    ADD COLUMN IF NOT EXISTS admin_notes TEXT;

-- 4. Additive columns for Student Requests (Staff Handling Metadata)
ALTER TABLE student_requests 
    ADD COLUMN IF NOT EXISTS assigned_to VARCHAR(100),
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

-- 5. Additive columns for Student Concerns (Staff Response Metadata)
ALTER TABLE student_concerns 
    ADD COLUMN IF NOT EXISTS responded_by VARCHAR(100),
    ADD COLUMN IF NOT EXISTS responded_at TIMESTAMP WITH TIME ZONE;

-- 6. Additive columns for Students (Role / Access Control)
ALTER TABLE students 
    ADD COLUMN IF NOT EXISTS role VARCHAR(30) DEFAULT 'student';

-- 7. Seed Master Scholarship Record (If not already present)
INSERT INTO scholarships (code, title, description, academic_year, deadline, stipend_amount, is_active)
VALUES (
    'CHED-CMSP-2025',
    'CHED Academic Excellence (CMSP)',
    'Commission on Higher Education Merit Scholarship Program for qualifying collegiate scholars.',
    'AY 2024–2025 • Mid-Year Renewal Cycle',
    'August 25, 2026',
    '₱30,000.00',
    TRUE
)
ON CONFLICT (code) DO NOTHING;
