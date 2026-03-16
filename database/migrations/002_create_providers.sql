-- Migration 002: Create provider profiles
-- Date: 2024-01-02

-- Provider profiles
CREATE TABLE IF NOT EXISTS provider_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    business_name VARCHAR(255) NOT NULL,
    business_description TEXT,
    years_in_business INT DEFAULT 0,
    license_number VARCHAR(100),
    insurance_provider VARCHAR(255),
    insurance_policy_number VARCHAR(100),
    insurance_expiry DATE,
    verified BOOLEAN DEFAULT FALSE,
    verification_date DATETIME,
    background_check BOOLEAN DEFAULT FALSE,
    background_check_date DATETIME,
    stripe_connect_id VARCHAR(100),
    stripe_customer_id VARCHAR(100),
    commission_rate DECIMAL(5,2) DEFAULT 15.00,
    service_radius INT DEFAULT 25,
    max_jobs_per_day INT,
    instant_booking BOOLEAN DEFAULT FALSE,
    emergency_available BOOLEAN DEFAULT FALSE,
    total_reviews INT DEFAULT 0,
    average_rating DECIMAL(3,2) DEFAULT 0.00,
    total_jobs_completed INT DEFAULT 0,
    response_rate DECIMAL(5,2) DEFAULT 0.00,
    average_response_time INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_verified (verified),
    INDEX idx_business_name (business_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider services
CREATE TABLE IF NOT EXISTS provider_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    category_id INT NOT NULL,
    sub_service_id INT,
    hourly_rate DECIMAL(10,2),
    flat_rate DECIMAL(10,2),
    minimum_fee DECIMAL(10,2),
    travel_fee DECIMAL(10,2) DEFAULT 0.00,
    available_24_7 BOOLEAN DEFAULT FALSE,
    emergency_service BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES service_categories(id),
    FOREIGN KEY (sub_service_id) REFERENCES sub_services(id),
    UNIQUE KEY unique_provider_service (provider_id, sub_service_id),
    INDEX idx_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;