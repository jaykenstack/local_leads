-- ============================================================================
-- Subscription Plans and Provider Subscriptions
-- ============================================================================

-- Subscription plans for providers
CREATE TABLE subscription_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    
    -- Pricing (in cents)
    price_monthly INT NOT NULL,
    price_yearly INT,
    
    -- Features
    leads_per_month INT DEFAULT 0, -- -1 for unlimited
    lead_credit_price INT, -- price per additional lead in cents
    featured_listing BOOLEAN DEFAULT FALSE,
    priority_support BOOLEAN DEFAULT FALSE,
    analytics_access BOOLEAN DEFAULT FALSE,
    api_access BOOLEAN DEFAULT FALSE,
    max_service_areas INT DEFAULT 1,
    max_team_members INT DEFAULT 1,
    commission_rate DECIMAL(5,2) DEFAULT 15.00,
    
    -- Badge
    badge_type ENUM('none', 'bronze', 'silver', 'gold', 'platinum') DEFAULT 'none',
    
    -- Marketing
    popular BOOLEAN DEFAULT FALSE,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_active (is_active),
    INDEX idx_price (price_monthly)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default subscription plans
INSERT INTO subscription_plans 
(name, slug, description, price_monthly, leads_per_month, lead_credit_price, 
 featured_listing, priority_support, analytics_access, api_access, 
 max_service_areas, max_team_members, commission_rate, badge_type, popular, sort_order) VALUES
('Basic', 'basic', 'Perfect for getting started', 2900, 5, 500, 
 FALSE, FALSE, FALSE, FALSE, 1, 1, 15.00, 'bronze', FALSE, 1),

('Professional', 'professional', 'Most popular for growing businesses', 7900, 20, 450, 
 TRUE, TRUE, TRUE, FALSE, 3, 3, 12.00, 'silver', TRUE, 2),

('Enterprise', 'enterprise', 'For established businesses', 19900, 100, 400, 
 TRUE, TRUE, TRUE, TRUE, 10, 10, 10.00, 'gold', FALSE, 3),

('Premium Partner', 'premium', 'Maximum exposure and benefits', 49900, -1, 350, 
 TRUE, TRUE, TRUE, TRUE, -1, -1, 8.00, 'platinum', FALSE, 4);

-- Provider subscriptions
CREATE TABLE provider_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    plan_id INT NOT NULL,
    
    -- Status
    status ENUM('active', 'past_due', 'canceled', 'trialing', 'incomplete') DEFAULT 'trialing',
    billing_cycle ENUM('monthly', 'yearly') DEFAULT 'monthly',
    
    -- Period
    current_period_start DATETIME NOT NULL,
    current_period_end DATETIME NOT NULL,
    cancel_at_period_end BOOLEAN DEFAULT FALSE,
    cancelled_at DATETIME,
    cancellation_reason VARCHAR(255),
    cancellation_feedback TEXT,
    
    -- Credits
    lead_credits_remaining INT DEFAULT 0,
    lead_credits_used INT DEFAULT 0,
    total_leads_received INT DEFAULT 0,
    
    -- Stripe
    stripe_subscription_id VARCHAR(100),
    stripe_customer_id VARCHAR(100),
    payment_method_id INT,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
    INDEX idx_provider (provider_id),
    INDEX idx_status (status),
    INDEX idx_stripe (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credit packages for additional purchases
CREATE TABLE credit_packages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    credits INT NOT NULL,
    price INT NOT NULL, -- in cents
    savings_percentage INT DEFAULT 0,
    popular BOOLEAN DEFAULT FALSE,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert credit packages
INSERT INTO credit_packages (credits, price, savings_percentage, popular, description, sort_order) VALUES
(5, 2500, 0, FALSE, '5 credits - $25.00', 1),
(10, 4500, 10, FALSE, '10 credits - $45.00 (save 10%)', 2),
(25, 10000, 20, TRUE, '25 credits - $100.00 (save 20%)', 3),
(50, 17500, 30, FALSE, '50 credits - $175.00 (save 30%)', 4),
(100, 30000, 40, FALSE, '100 credits - $300.00 (save 40%)', 5);

-- Credit purchases
CREATE TABLE credit_purchases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    package_id INT,
    amount INT NOT NULL, -- number of credits
    price_per_credit INT NOT NULL, -- in cents
    total_amount INT NOT NULL, -- in cents
    stripe_payment_intent_id VARCHAR(100),
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES credit_packages(id) ON DELETE SET NULL,
    INDEX idx_provider (provider_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lead delivery tracking
CREATE TABLE lead_delivery (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lead_id INT NOT NULL,
    provider_id INT NOT NULL,
    
    -- Delivery
    delivery_method ENUM('email', 'sms', 'push', 'dashboard') DEFAULT 'email',
    delivered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    viewed_at DATETIME,
    clicked_at DATETIME,
    
    -- Credits
    credit_used BOOLEAN DEFAULT TRUE,
    cost INT, -- in cents
    
    -- Status
    status ENUM('delivered', 'viewed', 'clicked', 'converted', 'expired') DEFAULT 'delivered',
    converted BOOLEAN DEFAULT FALSE,
    converted_at DATETIME,
    
    FOREIGN KEY (lead_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    INDEX idx_provider (provider_id),
    INDEX idx_lead (lead_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription cancellations tracking
CREATE TABLE subscription_cancellations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT NOT NULL,
    provider_id INT NOT NULL,
    reason VARCHAR(255),
    feedback TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (subscription_id) REFERENCES provider_subscriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    INDEX idx_subscription (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;