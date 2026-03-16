-- ============================================================================
-- Reviews and Ratings Tables
-- ============================================================================

-- Reviews (already defined in providers-schema, but extended here)
ALTER TABLE provider_reviews ADD COLUMN (
    -- Additional fields for detailed review system
    response_helpful_count INT DEFAULT 0,
    response_unhelpful_count INT DEFAULT 0,
    edited_at DATETIME,
    edited_count INT DEFAULT 0,
    ip_address VARCHAR(45),
    
    -- Moderation
    status ENUM('pending', 'published', 'hidden', 'flagged') DEFAULT 'pending',
    moderated_by INT,
    moderated_at DATETIME,
    moderation_note TEXT,
    
    FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Review photos
CREATE TABLE review_photos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    review_id INT NOT NULL,
    photo_url VARCHAR(500) NOT NULL,
    thumbnail_url VARCHAR(500),
    caption VARCHAR(255),
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (review_id) REFERENCES provider_reviews(id) ON DELETE CASCADE,
    INDEX idx_review (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review helpfulness votes
CREATE TABLE review_votes (
    review_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('helpful', 'unhelpful') NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (review_id, user_id),
    FOREIGN KEY (review_id) REFERENCES provider_reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_review (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review reports (for moderation)
CREATE TABLE review_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    review_id INT NOT NULL,
    reporter_id INT NOT NULL,
    reason ENUM('spam', 'inappropriate', 'fake', 'conflict', 'other') NOT NULL,
    description TEXT,
    status ENUM('pending', 'resolved', 'dismissed') DEFAULT 'pending',
    resolved_by INT,
    resolved_at DATETIME,
    resolution_note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (review_id) REFERENCES provider_reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_review (review_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review reminders (to encourage customers to leave reviews)
CREATE TABLE review_reminders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    customer_id INT NOT NULL,
    provider_id INT NOT NULL,
    sent_at DATETIME,
    opened_at DATETIME,
    clicked_at DATETIME,
    reviewed_at DATETIME,
    status ENUM('pending', 'sent', 'opened', 'clicked', 'reviewed') DEFAULT 'pending',
    reminder_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    
    INDEX idx_customer (customer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;