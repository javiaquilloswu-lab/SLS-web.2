-- ====================================================================
-- MIGRATION: 002_admin_management_foundation.sql
-- Database: android_studio_db (PostgreSQL)
-- Target: SWU PHINMA Student Life Administrative Management System
-- Safety: Additive only. No DROP, TRUNCATE, or destructive alters.
-- ====================================================================

-- 1. Administrative Users Table (Locally managed staff & administrator accounts)
CREATE TABLE IF NOT EXISTS admin_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL, -- bcrypt hashed
    full_name VARCHAR(150) NOT NULL,
    department VARCHAR(100) DEFAULT 'Student Life Support Office',
    role VARCHAR(50) DEFAULT 'Staff', -- 'Staff', 'Director', 'SuperAdmin'
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_users_email ON admin_users(email);
CREATE INDEX IF NOT EXISTS idx_admin_users_username ON admin_users(username);

-- 2. Administrative Session Tokens (Separate from Student Tokens)
CREATE TABLE IF NOT EXISTS admin_tokens (
    id SERIAL PRIMARY KEY,
    admin_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE DEFAULT (CURRENT_TIMESTAMP + INTERVAL '7 days')
);

CREATE INDEX IF NOT EXISTS idx_admin_tokens_token ON admin_tokens(token);
CREATE INDEX IF NOT EXISTS idx_admin_tokens_admin_id ON admin_tokens(admin_id);

-- 3. Normalized Conversation Transcript History Table
-- (Supports real multi-turn conversation transcripts: STUDENT, AI, STAFF, SYSTEM)
CREATE TABLE IF NOT EXISTS student_concern_messages (
    id SERIAL PRIMARY KEY,
    concern_id INT NOT NULL REFERENCES student_concerns(id) ON DELETE CASCADE,
    ticket_ref VARCHAR(50) NOT NULL,
    sender_type VARCHAR(20) NOT NULL CHECK (sender_type IN ('STUDENT', 'AI', 'STAFF', 'SYSTEM')),
    sender_id VARCHAR(100), -- student_id, admin username, or AI engine identifier
    sender_name VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_concern_messages_ticket ON student_concern_messages(ticket_ref);
CREATE INDEX IF NOT EXISTS idx_concern_messages_concern ON student_concern_messages(concern_id);

-- 4. Additive Columns to student_concerns for Staff Workflow
ALTER TABLE student_concerns 
    ADD COLUMN IF NOT EXISTS internal_notes TEXT,
    ADD COLUMN IF NOT EXISTS assigned_to VARCHAR(100),
    ADD COLUMN IF NOT EXISTS escalation_reason TEXT,
    ADD COLUMN IF NOT EXISTS is_ai_paused BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

-- 5. Additive Columns to scholarship_requirements (For Admin Review Audit)
ALTER TABLE scholarship_requirements
    ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(100),
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP WITH TIME ZONE,
    ADD COLUMN IF NOT EXISTS admin_notes TEXT;

-- 6. Additive Columns to scholarship_applications (For Bursar Review Audit)
ALTER TABLE scholarship_applications
    ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(100),
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP WITH TIME ZONE,
    ADD COLUMN IF NOT EXISTS admin_notes TEXT;

-- 7. DEVELOPMENT ONLY SEED
-- Creates initial local admin account if no admin user exists.
-- NOTICE: For development/testing only. Production deployments must reset this password.
INSERT INTO admin_users (username, email, password, full_name, department, role, is_active)
VALUES (
    'admin',
    'admin@swu.phinma.edu.ph',
    '$2y$10$w5p6ZkJ8J3Y8m0E9G5xK1uVw3p9Q8x2Y0Z1A2B3C4D5E6F7G8H9I0', -- Development password hash; must be reset for production
    'Student Life Administrator',
    'Office of Student Affairs',
    'SuperAdmin',
    TRUE
)
ON CONFLICT (username) DO NOTHING;
