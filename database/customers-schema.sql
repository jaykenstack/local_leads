-- ============================================================================
-- Customer Profiles and Management Tables
-- ============================================================================

-- Customer profiles (extends users table)
CREATE TABLE customer_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    
    -- Default information
    default_address TEXT,
    default_city VARCHAR(100),
    default_state VARCHAR(50),
    default_zip VARCHAR(20),
    default_latitude DECIMAL(10,8),
    default_longitude DECIMAL(11,8),
    
    -- Preferences
    preferred_contact ENUM('email', 'phone', 'sms', 'push') DEFAULT 'email',
    notification_preferences JSON,
    
    -- Stripe for payments
    stripe_customer_id VARCHAR(100),
    
    -- Statistics
    total_requests INT DEFAULT 0,
    total_spent DECIMAL(10,2) DEFAULT 0.00,
    average_rating_given DECIMAL(3,2) DEFAULT 0.00,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_stripe (stripe_customer_id),
    INDEX idx_zip (default_zip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer addresses (multiple addresses per customer)
CREATE TABLE customer_addresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    address_name VARCHAR(100), -- e.g., "Home", "Office"
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50),
    zip_code VARCHAR(20) NOT NULL,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    is_default BOOLEAN DEFAULT FALSE,
    instructions TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id),
    INDEX idx_zip (zip_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved providers (favorites)
CREATE TABLE saved_providers (
    customer_id INT NOT NULL,
    provider_id INT NOT NULL,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (customer_id, provider_id),
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;