-- SQL script to ensure the issues table exists with all required columns
-- Run this if you're having problems with the Fixed button functionality

-- Create issues table if it doesn't exist
CREATE TABLE IF NOT EXISTS `issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `system_number` varchar(50) DEFAULT NULL,
  `description` text NOT NULL,
  `status` enum('pending','fixed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fixed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `issues_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add fixed_at column if it doesn't exist (for older databases)
ALTER TABLE `issues` 
ADD COLUMN IF NOT EXISTS `fixed_at` timestamp NULL DEFAULT NULL;

-- Ensure status column has correct enum values
ALTER TABLE `issues` 
MODIFY COLUMN `status` enum('pending','fixed') DEFAULT 'pending';
