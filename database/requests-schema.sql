-- ============================================================================
-- Service Requests and Bids Tables
-- ============================================================================

-- Service requests (leads)
CREATE TABLE service_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    provider_id INT,
    
    -- Service details
    category_id INT NOT NULL,
    sub_service_id INT,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    
    -- Location
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50),
    zip_code VARCHAR(20) NOT NULL,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    
    -- Timing
    urgency ENUM('emergency', 'today', 'week', 'flexible') DEFAULT 'flexible',
    preferred_date DATE,
    preferred_time TIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    -- Budget
    budget_min DECIMAL(10,2),
    budget_max DECIMAL(10,2),
    
    -- Status tracking
    status ENUM('open', 'assigned', 'in_progress', 'completed', 'cancelled', 'expired') DEFAULT 'open',
    payment_status ENUM('pending', 'paid', 'refunded') DEFAULT 'pending',
    assigned_at DATETIME,
    completed_at DATETIME,
    cancelled_at DATETIME,
    cancelled_reason TEXT,
    
    -- Metadata
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES service_categories(id),
    FOREIGN KEY (sub_service_id) REFERENCES sub_services(id),
    
    INDEX idx_customer (customer_id),
    INDEX idx_provider (provider_id),
    INDEX idx_status (status),
    INDEX idx_urgency (urgency),
    INDEX idx_zip (zip_code),
    INDEX idx_created (created_at),
    INDEX idx_location (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bids/Quotes from providers
CREATE TABLE bids (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    provider_id INT NOT NULL,
    
    -- Bid details
    amount DECIMAL(10,2) NOT NULL,
    estimated_hours DECIMAL(5,2),
    message TEXT,
    
    -- Timing
    estimated_start_date DATE,
    estimated_completion_date DATE,
    
    -- Status
    status ENUM('pending', 'accepted', 'rejected', 'expired', 'withdrawn') DEFAULT 'pending',
    viewed_at DATETIME,
    accepted_at DATETIME,
    rejected_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_provider_request (request_id, provider_id),
    INDEX idx_request (request_id),
    INDEX idx_provider (provider_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Request attachments (photos, documents)
CREATE TABLE request_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    file_type VARCHAR(50),
    file_name VARCHAR(255),
    file_size INT,
    uploaded_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Request timeline events
CREATE TABLE request_timeline (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    description TEXT,
    user_id INT,
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_request (request_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;