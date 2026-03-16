-- ============================================================================
-- Provider Profiles and Management Tables
-- ============================================================================

-- Provider profiles (extends users table)
CREATE TABLE provider_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    
    -- Business information
    business_name VARCHAR(255) NOT NULL,
    business_description TEXT,
    years_in_business INT DEFAULT 0,
    license_number VARCHAR(100),
    insurance_provider VARCHAR(255),
    insurance_policy_number VARCHAR(100),
    insurance_expiry DATE,
    
    -- Verification
    verified BOOLEAN DEFAULT FALSE,
    verification_date DATETIME,
    background_check BOOLEAN DEFAULT FALSE,
    background_check_date DATETIME,
    
    -- Stripe Connect for payments
    stripe_connect_id VARCHAR(100),
    stripe_customer_id VARCHAR(100),
    commission_rate DECIMAL(5,2) DEFAULT 15.00, -- percentage
    
    -- Service settings
    service_radius INT DEFAULT 25, -- miles
    max_jobs_per_day INT,
    instant_booking BOOLEAN DEFAULT FALSE,
    emergency_available BOOLEAN DEFAULT FALSE,
    
    -- Statistics
    total_reviews INT DEFAULT 0,
    average_rating DECIMAL(3,2) DEFAULT 0.00,
    total_jobs_completed INT DEFAULT 0,
    response_rate DECIMAL(5,2) DEFAULT 0.00,
    average_response_time INT, -- in minutes
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_verified (verified),
    INDEX idx_business_name (business_name),
    INDEX idx_stripe (stripe_connect_id),
    INDEX idx_rating (average_rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider services (which services they offer)
CREATE TABLE provider_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    category_id INT NOT NULL,
    sub_service_id INT,
    
    -- Pricing
    hourly_rate DECIMAL(10,2),
    flat_rate DECIMAL(10,2),
    minimum_fee DECIMAL(10,2),
    travel_fee DECIMAL(10,2) DEFAULT 0.00,
    
    -- Availability
    available_24_7 BOOLEAN DEFAULT FALSE,
    emergency_service BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES service_categories(id),
    FOREIGN KEY (sub_service_id) REFERENCES sub_services(id),
    UNIQUE KEY unique_provider_service (provider_id, sub_service_id),
    INDEX idx_provider (provider_id),
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider availability schedule
CREATE TABLE provider_availability (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    day_of_week TINYINT NOT NULL, -- 0=Sunday, 1=Monday, ..., 6=Saturday
    start_time TIME,
    end_time TIME,
    is_available BOOLEAN DEFAULT TRUE,
    emergency_hours BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_day (provider_id, day_of_week),
    INDEX idx_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider service areas (locations they serve)
CREATE TABLE provider_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50),
    zip_code VARCHAR(20),
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    is_primary BOOLEAN DEFAULT FALSE,
    service_radius INT, -- override global radius
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    INDEX idx_provider (provider_id),
    INDEX idx_zip (zip_code),
    INDEX idx_coordinates (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider documents (licenses, insurance, etc.)
CREATE TABLE provider_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    document_type ENUM('license', 'insurance', 'certification', 'background_check', 'other') NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    expiry_date DATE,
    is_verified BOOLEAN DEFAULT FALSE,
    verified_by INT,
    verified_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_provider (provider_id),
    INDEX idx_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider reviews and ratings
CREATE TABLE provider_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    customer_id INT NOT NULL,
    request_id INT UNIQUE,
    
    -- Ratings (1-5)
    rating_overall DECIMAL(2,1) NOT NULL,
    rating_quality DECIMAL(2,1),
    rating_punctuality DECIMAL(2,1),
    rating_professionalism DECIMAL(2,1),
    rating_value DECIMAL(2,1),
    rating_communication DECIMAL(2,1),
    
    -- Review content
    title VARCHAR(255),
    comment TEXT,
    pros TEXT,
    cons TEXT,
    
    -- Response
    response_from_provider TEXT,
    response_date DATETIME,
    
    -- Metadata
    is_anonymous BOOLEAN DEFAULT FALSE,
    is_verified_purchase BOOLEAN DEFAULT TRUE,
    helpful_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE SET NULL,
    INDEX idx_provider (provider_id),
    INDEX idx_rating (rating_overall)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider team members (for multi-person businesses)
CREATE TABLE provider_team (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    user_id INT UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    role VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;