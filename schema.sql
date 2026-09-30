-- ====================================================================
-- PostgreSQL Database Schema: android_studio_db
-- Southwestern University PHINMA - Student Life Mobile Platform
-- Target: Windows XAMPP + PostgreSQL (Port 5432)
-- ====================================================================

-- Create Database (Run if not already created)
-- CREATE DATABASE android_studio_db WITH ENCODING 'UTF8';

-- 1. Students Table
CREATE TABLE IF NOT EXISTS students (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    course VARCHAR(100) DEFAULT 'BS Computer Science',
    year_level VARCHAR(50) DEFAULT '3rd Year',
    academic_status VARCHAR(50) DEFAULT 'Active',
    requirements_progress INT DEFAULT 67,
    verified_docs_count INT DEFAULT 4,
    total_docs_count INT DEFAULT 6,
    pending_actions_count INT DEFAULT 2,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_students_student_id ON students(student_id);
CREATE INDEX IF NOT EXISTS idx_students_email ON students(email);

-- 2. Student Documents Table (matches upload_document.php)
CREATE TABLE IF NOT EXISTS student_documents (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    file_size_bytes INT NOT NULL,
    status VARCHAR(30) DEFAULT 'Pending Review',
    reviewed_by VARCHAR(100),
    reviewed_at TIMESTAMP WITH TIME ZONE,
    admin_notes TEXT,
    uploaded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_documents_student_id ON student_documents(student_id);

-- 3. Student Requests & Document Pickups Table
CREATE TABLE IF NOT EXISTS student_requests (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    department VARCHAR(100) NOT NULL,
    reference_number VARCHAR(50) UNIQUE NOT NULL,
    status VARCHAR(50) DEFAULT 'Processing',
    status_message TEXT,
    category VARCHAR(50) DEFAULT 'Document',
    assigned_to VARCHAR(100),
    updated_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_requests_student_id ON student_requests(student_id);
CREATE INDEX IF NOT EXISTS idx_requests_ref_num ON student_requests(reference_number);

-- 4. Scholarship Applications Table
CREATE TABLE IF NOT EXISTS scholarship_applications (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    program_name VARCHAR(150) NOT NULL,
    academic_year VARCHAR(50) NOT NULL,
    application_type VARCHAR(50) NOT NULL,
    status VARCHAR(50) DEFAULT 'Under Review',
    tracking_ref VARCHAR(50) NOT NULL,
    reviewed_by VARCHAR(100),
    reviewed_at TIMESTAMP WITH TIME ZONE,
    admin_notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_scholarship_apps_student_id ON scholarship_applications(student_id);

-- 5. Scholarship Requirements Checklist Table
CREATE TABLE IF NOT EXISTS scholarship_requirements (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(255),
    status VARCHAR(50) DEFAULT 'UPLOAD', -- 'VERIFIED', 'REVIEWING', 'UPLOAD'
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_scholarship_reqs_student_id ON scholarship_requirements(student_id);

-- 6. Student Notifications Table
CREATE TABLE IF NOT EXISTS student_notifications (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'General',
    status_tag VARCHAR(50) DEFAULT 'Notice',
    is_unread BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notifications_student_id ON student_notifications(student_id);

-- 7. Student Concerns & Chatbot Inquiry History Table
CREATE TABLE IF NOT EXISTS student_concerns (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    ticket_ref VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'General Inquiry',
    response TEXT,
    status VARCHAR(30) DEFAULT 'Open',
    responded_by VARCHAR(100),
    responded_at TIMESTAMP WITH TIME ZONE,
    internal_notes TEXT,
    assigned_to VARCHAR(100),
    escalation_reason TEXT,
    is_ai_paused BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_concerns_student_id ON student_concerns(student_id);

-- 7b. Student Concern Multi-Turn Messages Table
CREATE TABLE IF NOT EXISTS student_concern_messages (
    id SERIAL PRIMARY KEY,
    concern_id INT NOT NULL REFERENCES student_concerns(id) ON DELETE CASCADE,
    ticket_ref VARCHAR(50) NOT NULL,
    sender_type VARCHAR(20) NOT NULL, -- 'STUDENT', 'AI', 'STAFF', 'SYSTEM'
    sender_id VARCHAR(50),
    sender_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_concern_msgs_ticket ON student_concern_messages(ticket_ref);

-- 8. Student Authentication Sessions Table (Tokens)
CREATE TABLE IF NOT EXISTS student_tokens (
    id SERIAL PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE DEFAULT (CURRENT_TIMESTAMP + INTERVAL '30 days')
);

CREATE INDEX IF NOT EXISTS idx_student_tokens_token ON student_tokens(token);
CREATE INDEX IF NOT EXISTS idx_student_tokens_student_id ON student_tokens(student_id);

-- 9. Master Scholarship Programs Table (For Admin Management)
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

-- 9b. Dynamic Service Types Catalog Table
CREATE TABLE IF NOT EXISTS service_types (
    id SERIAL PRIMARY KEY,
    service_code VARCHAR(50) UNIQUE NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    description TEXT,
    department VARCHAR(100) DEFAULT 'University Registrar',
    category VARCHAR(50) DEFAULT 'Document',
    processing_days VARCHAR(50) DEFAULT '2-3 Business Days',
    fee_amount VARCHAR(50) DEFAULT 'Free / Digital',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 10. Administrator Users Table
CREATE TABLE IF NOT EXISTS admin_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    department VARCHAR(100) DEFAULT 'Student Life Office',
    role VARCHAR(50) DEFAULT 'SUPER_ADMIN',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 11. Administrator Authentication Tokens Table
CREATE TABLE IF NOT EXISTS admin_tokens (
    id SERIAL PRIMARY KEY,
    admin_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE DEFAULT (CURRENT_TIMESTAMP + INTERVAL '7 days')
);

-- ====================================================================
-- SEED INITIAL DEMO DATA (Pass: student123)
-- ====================================================================

-- 1. Demo Student: Maria Santos (Pass: student123 => bcrypt hash)
INSERT INTO students (student_id, first_name, middle_name, last_name, email, password, course, year_level, academic_status, requirements_progress, verified_docs_count, total_docs_count, pending_actions_count)
VALUES (
    '2024-08912',
    'Maria',
    'Clara',
    'Santos',
    'student@swu.edu.ph',
    '$2y$10$K9mYTxhYP8dT2KHMOo9HA.NIWZpGnmkamuhQhlWIZ3dxKz9QPDWsW', -- valid bcrypt hash for 'student123'
    'BS Computer Science',
    '3rd Year',
    'Active',
    67,
    4,
    6,
    2
)
ON CONFLICT (student_id) DO NOTHING;

-- Also support secondary ID referenced in UI: 2023-00456
INSERT INTO students (student_id, first_name, middle_name, last_name, email, password, course, year_level, academic_status, requirements_progress, verified_docs_count, total_docs_count, pending_actions_count)
VALUES (
    '2023-00456',
    'Maria',
    'Clara',
    'Santos',
    'maria.santos@swu.edu.ph',
    '$2y$10$wTf3rDvy0V3fQc0k9fM7UeYl4Y3G6gB8kR5H2uJ1sK0qO8xXm1wTu',
    'BS Computer Science',
    '3rd Year',
    'Active',
    67,
    4,
    6,
    2
)
ON CONFLICT (student_id) DO NOTHING;

-- 2. Demo Scholarship Requirements for 2024-08912
INSERT INTO scholarship_requirements (student_id, title, description, status) VALUES
('2024-08912', 'Certificate of Grades (COG)', '2nd Sem • 1.35 GWA verified', 'VERIFIED'),
('2024-08912', 'Certificate of Good Moral', 'Issued by Office of Student Affairs', 'VERIFIED'),
('2024-08912', 'Enrollment Assessment Form', 'Validated 21 Academic Units', 'VERIFIED'),
('2024-08912', 'Income Tax Return / Affidavit', 'Submitted July 18 • Bursar queue', 'REVIEWING'),
('2024-08912', 'Barangay Indigency Certificate', 'Submitted July 19 • Office validation', 'REVIEWING'),
('2024-08912', 'Scholarship Agreement', 'Missing student signature', 'UPLOAD')
ON CONFLICT DO NOTHING;

-- Also for 2023-00456
INSERT INTO scholarship_requirements (student_id, title, description, status) VALUES
('2023-00456', 'Certificate of Grades (COG)', '2nd Sem • 1.35 GWA verified', 'VERIFIED'),
('2023-00456', 'Certificate of Good Moral', 'Issued by Office of Student Affairs', 'VERIFIED'),
('2023-00456', 'Enrollment Assessment Form', 'Validated 21 Academic Units', 'VERIFIED'),
('2023-00456', 'Income Tax Return / Affidavit', 'Submitted July 18 • Bursar queue', 'REVIEWING'),
('2023-00456', 'Barangay Indigency Certificate', 'Submitted July 19 • Office validation', 'REVIEWING'),
('2023-00456', 'Scholarship Agreement', 'Missing student signature', 'UPLOAD')
ON CONFLICT DO NOTHING;

-- 3. Demo Requests
INSERT INTO student_requests (student_id, title, department, reference_number, status, status_message, category, created_at) VALUES
('2024-08912', 'Good Moral Certificate', 'Dean of Student Affairs', 'SL-2026-000121', 'Processing', 'Ready for pick-up in 2 days', 'Document', NOW() - INTERVAL '2 days'),
('2024-08912', 'Scholarship — Grade Slip', 'Academic Registrar', 'SL-2026-000115', 'Under Review', 'In Bursar evaluation queue', 'Scholarship', NOW() - INTERVAL '4 days'),
('2024-08912', 'Scholarship — Enrollment Form', 'Bursar & Grants Office', 'SL-2026-000098', 'Approved', 'Validated by Accounting', 'Scholarship', NOW() - INTERVAL '7 days'),
('2024-08912', 'Lost ID Replacement', 'Campus Security & Records', 'SL-2026-000042', 'Rejected', 'Affidavit of loss expired, please re-submit', 'ID Card', NOW() - INTERVAL '15 days')
ON CONFLICT (reference_number) DO NOTHING;

-- 4. Demo Notifications
INSERT INTO student_notifications (student_id, title, body, category, status_tag, is_unread, created_at) VALUES
('2024-08912', 'Scholarship Requirement Approved', 'Your Grade Slip (2nd Sem) has been approved by the Student Life Office.', 'Scholarship', 'Verified', TRUE, NOW() - INTERVAL '2 hours'),
('2024-08912', 'Document Request Update', 'Your Good Moral Certificate request (SL-2026-000121) is now being processed and ready for release.', 'Documents', 'Processing', TRUE, NOW() - INTERVAL '1 day'),
('2024-08912', 'Missing Scholarship Requirement', 'Please submit your Grade Slip (1st Sem, AY 2025–2026) before August 20, 2026 to maintain active status.', 'Scholarship', 'Action required', TRUE, NOW() - INTERVAL '2 days'),
('2024-08912', 'Inquiry Response Received', 'The Student Life Office has responded to your inquiry regarding scholarship renewal requirements.', 'Inquiry', 'Helpdesk', FALSE, NOW() - INTERVAL '3 days')
ON CONFLICT DO NOTHING;

-- 5. Seed Initial Default Admin Account (Username: admin / Password: admin123)
INSERT INTO admin_users (username, email, password, full_name, department, role)
VALUES (
    'admin',
    'admin@swu.phinma.edu.ph',
    '$2y$10$wTf3rDvy0V3fQc0k9fM7UeYl4Y3G6gB8kR5H2uJ1sK0qO8xXm1wTu', -- bcrypt hash for admin123
    'Administrator',
    'Student Life Office',
    'SUPER_ADMIN'
)
ON CONFLICT (username) DO NOTHING;
