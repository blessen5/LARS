-- SQL Table Structure for Systems Management Feature
-- This table is automatically created when you first add a system through the admin dashboard
-- You can also run this SQL manually in your database

CREATE TABLE IF NOT EXISTS systems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    system_id VARCHAR(100) NOT NULL UNIQUE,
    system_name VARCHAR(150) NOT NULL,
    login_count INT DEFAULT 0,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Example data (optional):
-- INSERT INTO systems (system_id, system_name) VALUES 
-- ('SYS001', 'Computer Lab 1'),
-- ('SYS002', 'Computer Lab 2'),
-- ('SYS003', 'Programming Lab');

