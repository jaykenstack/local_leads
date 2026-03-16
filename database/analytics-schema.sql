-- ============================================================================
-- Analytics and Reporting Tables
-- ============================================================================

-- Page views tracking
CREATE TABLE page_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    session_id VARCHAR(100),
    page_url VARCHAR(500) NOT NULL,
    page_title VARCHAR(255),
    referrer_url VARCHAR(500),
    user_agent TEXT,
    ip_address VARCHAR(45),
    viewport_width INT,
    viewport_height INT,
    time_on_page INT, -- seconds
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_session (session_id),
    INDEX idx_page (page_url(255)),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User events tracking
CREATE TABLE user_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    session_id VARCHAR(100),
    event_type VARCHAR(50) NOT NULL, -- 'click', 'scroll', 'form_submit', etc.
    event_category VARCHAR(50),
    event_action VARCHAR(100),
    event_label VARCHAR(255),
    event_value INT,
    page_url VARCHAR(500),
    element_id VARCHAR(255),
    element_class VARCHAR(255),
    element_text VARCHAR(255),
    x_position INT,
    y_position INT,
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_session (session_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performance metrics
CREATE TABLE performance_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    page_url VARCHAR(500),
    session_id VARCHAR(100),
    user_id INT,
    load_time INT, -- milliseconds
    dom_interactive INT,
    dom_complete INT,
    first_paint INT,
    first_contentful_paint INT,
    time_to_interactive INT,
    dns_time INT,
    tcp_time INT,
    ttfb INT, -- time to first byte
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_session (session_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Error tracking
CREATE TABLE error_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    session_id VARCHAR(100),
    error_type VARCHAR(50), -- 'javascript', 'api', 'database', etc.
    error_message TEXT,
    error_stack TEXT,
    page_url VARCHAR(500),
    line_number INT,
    column_number INT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_session (session_id),
    INDEX idx_type (error_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversion tracking
CREATE TABLE conversions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    session_id VARCHAR(100),
    conversion_type VARCHAR(50) NOT NULL, -- 'lead', 'booking', 'payment', 'signup'
    conversion_value INT, -- in cents
    source VARCHAR(50), -- 'organic', 'direct', 'referral', 'social', 'email'
    medium VARCHAR(50), -- 'search', 'social', 'cpc', 'email'
    campaign VARCHAR(100),
    content VARCHAR(255),
    term VARCHAR(255),
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_session (session_id),
    INDEX idx_type (conversion_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily aggregates for reporting
CREATE TABLE daily_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    stat_date DATE NOT NULL,
    
    -- User stats
    new_users INT DEFAULT 0,
    new_customers INT DEFAULT 0,
    new_providers INT DEFAULT 0,
    active_users INT DEFAULT 0,
    
    -- Request stats
    total_requests INT DEFAULT 0,
    emergency_requests INT DEFAULT 0,
    completed_requests INT DEFAULT 0,
    
    -- Revenue stats
    total_revenue INT DEFAULT 0, -- in cents
    platform_fees INT DEFAULT 0,
    provider_payouts INT DEFAULT 0,
    
    -- Engagement stats
    page_views INT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    avg_session_duration INT DEFAULT 0, -- seconds
    
    -- Conversion stats
    lead_conversions INT DEFAULT 0,
    booking_conversions INT DEFAULT 0,
    payment_conversions INT DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_date (stat_date),
    INDEX idx_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;