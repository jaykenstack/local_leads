-- ============================================================================
-- Locations and Geographic Data Tables
-- ============================================================================

-- Cities and regions
CREATE TABLE cities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    state VARCHAR(50),
    state_code VARCHAR(2),
    country VARCHAR(100) DEFAULT 'United States',
    country_code VARCHAR(2) DEFAULT 'US',
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    timezone VARCHAR(50),
    population INT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_state (state),
    INDEX idx_coordinates (latitude, longitude),
    INDEX idx_active (is_active),
    FULLTEXT INDEX ft_city (name, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zip codes
CREATE TABLE zip_codes (
    zip VARCHAR(10) PRIMARY KEY,
    city_id INT NOT NULL,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    timezone VARCHAR(50),
    daylight_savings BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    INDEX idx_city (city_id),
    INDEX idx_coordinates (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service areas (mapping providers to cities/zip codes)
CREATE TABLE service_areas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    city_id INT,
    zip_code VARCHAR(10),
    radius INT, -- service radius in miles
    is_primary BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    FOREIGN KEY (zip_code) REFERENCES zip_codes(zip) ON DELETE CASCADE,
    
    INDEX idx_provider (provider_id),
    INDEX idx_city (city_id),
    INDEX idx_zip (zip_code),
    CHECK (city_id IS NOT NULL OR zip_code IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;