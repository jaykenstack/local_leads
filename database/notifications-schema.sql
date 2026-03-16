-- ============================================================================
-- Notifications System Tables
-- ============================================================================

-- Notifications
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    
    -- Notification content
    type VARCHAR(50) NOT NULL, -- 'message', 'bid', 'payment', 'review', etc.
    title VARCHAR(255),
    message TEXT NOT NULL,
    data JSON, -- additional data for deep linking
    
    -- Status
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME,
    is_archived BOOLEAN DEFAULT FALSE,
    
    -- Priority
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Push notification subscriptions (for web push)
CREATE TABLE push_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_endpoint (endpoint(255)),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email notification logs
CREATE TABLE email_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    email_to VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template VARCHAR(100),
    status ENUM('sent', 'failed', 'opened', 'clicked') DEFAULT 'sent',
    opens INT DEFAULT 0,
    clicks INT DEFAULT 0,
    error_message TEXT,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS notification logs
CREATE TABLE sms_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    phone_to VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('sent', 'failed', 'delivered') DEFAULT 'sent',
    error_message TEXT,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;