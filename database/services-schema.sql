-- ============================================================================
-- Services and Categories Tables
-- ============================================================================

-- Service categories (Plumbing, Electrical, HVAC, Locksmith, Pest Control)
CREATE TABLE service_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(255),
    color VARCHAR(20),
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_slug (slug),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default service categories
INSERT INTO service_categories (name, slug, description, icon, color, sort_order) VALUES
('Plumbing', 'plumbing', 'Emergency plumbing, repairs, and installations', 'plumbing-icon.svg', '#3b82f6', 1),
('Electrical', 'electrical', 'Electrical repairs, wiring, and panel upgrades', 'electrical-icon.svg', '#f59e0b', 2),
('HVAC', 'hvac', 'Heating, ventilation, and air conditioning services', 'hvac-icon.svg', '#10b981', 3),
('Locksmith', 'locksmith', 'Lock installation, repair, and emergency lockout', 'locksmith-icon.svg', '#8b5cf6', 4),
('Pest Control', 'pest-control', 'Pest removal, prevention, and extermination', 'pest-icon.svg', '#ef4444', 5);

-- Sub-services (specific offerings)
CREATE TABLE sub_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT,
    is_emergency BOOLEAN DEFAULT FALSE,
    base_price DECIMAL(10,2),
    estimated_duration INT, -- in minutes
    icon VARCHAR(255),
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_category_slug (category_id, slug),
    INDEX idx_category (category_id),
    INDEX idx_emergency (is_emergency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample sub-services for each category
INSERT INTO sub_services (category_id, name, slug, description, is_emergency, base_price, estimated_duration) VALUES
-- Plumbing (category_id = 1)
(1, 'Emergency Plumbing', 'emergency-plumbing', '24/7 emergency plumbing services', TRUE, 150.00, 60),
(1, 'Pipe Repair', 'pipe-repair', 'Fix leaking or burst pipes', TRUE, 120.00, 45),
(1, 'Drain Cleaning', 'drain-cleaning', 'Unclog drains and sewer lines', FALSE, 100.00, 30),
(1, 'Water Heater Repair', 'water-heater-repair', 'Repair or replace water heaters', FALSE, 180.00, 90),
(1, 'Fixture Installation', 'fixture-installation', 'Install sinks, faucets, toilets', FALSE, 90.00, 60),

-- Electrical (category_id = 2)
(2, 'Emergency Electrical', 'emergency-electrical', '24/7 electrical emergencies', TRUE, 150.00, 60),
(2, 'Wiring Repair', 'wiring-repair', 'Fix faulty wiring', TRUE, 130.00, 60),
(2, 'Panel Upgrade', 'panel-upgrade', 'Electrical panel replacement', FALSE, 500.00, 240),
(2, 'Outlet Installation', 'outlet-installation', 'Install new outlets', FALSE, 80.00, 30),
(2, 'Lighting Installation', 'lighting-installation', 'Install light fixtures', FALSE, 70.00, 45),

-- HVAC (category_id = 3)
(3, 'AC Repair', 'ac-repair', 'Air conditioning repair', TRUE, 120.00, 60),
(3, 'Heating Repair', 'heating-repair', 'Furnace and heater repair', TRUE, 120.00, 60),
(3, 'AC Installation', 'ac-installation', 'Install new AC units', FALSE, 800.00, 240),
(3, 'Maintenance', 'hvac-maintenance', 'Regular HVAC maintenance', FALSE, 90.00, 60),
(3, 'Duct Cleaning', 'duct-cleaning', 'Clean air ducts', FALSE, 200.00, 120),

-- Locksmith (category_id = 4)
(4, 'Emergency Lockout', 'emergency-lockout', 'Locked out of home or car', TRUE, 80.00, 30),
(4, 'Lock Repair', 'lock-repair', 'Fix broken locks', FALSE, 60.00, 30),
(4, 'Key Duplication', 'key-duplication', 'Copy keys', FALSE, 10.00, 5),
(4, 'Safe Opening', 'safe-opening', 'Open locked safes', TRUE, 150.00, 60),
(4, 'Security System', 'security-system', 'Install security systems', FALSE, 200.00, 120),

-- Pest Control (category_id = 5)
(5, 'Emergency Pest Control', 'emergency-pest', 'Urgent pest removal', TRUE, 150.00, 60),
(5, 'Termite Treatment', 'termite-treatment', 'Termite inspection and treatment', FALSE, 300.00, 120),
(5, 'Rodent Control', 'rodent-control', 'Remove rats and mice', FALSE, 180.00, 90),
(5, 'Bed Bug Treatment', 'bed-bug-treatment', 'Bed bug extermination', FALSE, 250.00, 120),
(5, 'Monthly Plan', 'monthly-plan', 'Regular pest control service', FALSE, 50.00, 30);

-- Service requirements and prerequisites
CREATE TABLE service_requirements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_id INT NOT NULL,
    requirement_type ENUM('license', 'insurance', 'certification', 'tool', 'other') NOT NULL,
    description TEXT NOT NULL,
    is_mandatory BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (service_id) REFERENCES sub_services(id) ON DELETE CASCADE,
    INDEX idx_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service FAQs
CREATE TABLE service_faqs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (service_id) REFERENCES sub_services(id) ON DELETE CASCADE,
    INDEX idx_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;