-- Store MM Database Upgrade for Version 1.0.7
-- Adds support for workflow locking and internal notes

-- Add index for better performance
CREATE INDEX IF NOT EXISTS idx_store_mm_workflow_state 
ON wp_postmeta (meta_key(191), meta_value(191)) 
WHERE meta_key = '_store_mm_workflow_state';

CREATE INDEX IF NOT EXISTS idx_store_mm_designer_id 
ON wp_postmeta (meta_key(191), meta_value(191)) 
WHERE meta_key = '_store_mm_designer_id';

-- Create workflow log table for better history tracking
CREATE TABLE IF NOT EXISTS wp_store_mm_workflow_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    from_state VARCHAR(50) NOT NULL,
    to_state VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    notes TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_state_transition (from_state, to_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing logs from postmeta to new table (optional)
INSERT INTO wp_store_mm_workflow_log (product_id, user_id, from_state, to_state, action, notes, created_at)
SELECT 
    pm.post_id,
    pm2.meta_value,
    'draft',
    pm.meta_value,
    'initial_submission',
    '',
    pm3.meta_value
FROM wp_postmeta pm
LEFT JOIN wp_postmeta pm2 ON pm.post_id = pm2.post_id AND pm2.meta_key = '_store_mm_designer_id'
LEFT JOIN wp_postmeta pm3 ON pm.post_id = pm3.post_id AND pm3.meta_key = '_store_mm_submission_date'
WHERE pm.meta_key = '_store_mm_workflow_state'
AND pm.meta_value = 'submitted'
ON DUPLICATE KEY UPDATE id=id;