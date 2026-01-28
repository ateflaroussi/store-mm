-- Add index for workflow state queries
CREATE INDEX IF NOT EXISTS idx_store_mm_workflow_state 
ON wp_postmeta (meta_key(255), meta_value(255)) 
WHERE meta_key = '_store_mm_workflow_state';

-- Create table for workflow history (optional enhancement)
CREATE TABLE IF NOT EXISTS wp_store_mm_workflow_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    from_state VARCHAR(50) NOT NULL,
    to_state VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (product_id) REFERENCES wp_posts(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;