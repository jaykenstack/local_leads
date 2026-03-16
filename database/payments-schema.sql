-- ============================================================================
-- Payment Processing Tables
-- ============================================================================

-- Customer payment methods
CREATE TABLE customer_payment_methods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    
    -- Stripe details
    stripe_payment_method_id VARCHAR(100) UNIQUE NOT NULL,
    stripe_customer_id VARCHAR(100),
    
    -- Card details (partial for display)
    card_brand VARCHAR(50),
    card_last4 VARCHAR(4),
    card_exp_month INT,
    card_exp_year INT,
    
    -- Billing address
    billing_address TEXT,
    billing_city VARCHAR(100),
    billing_state VARCHAR(50),
    billing_zip VARCHAR(20),
    billing_country VARCHAR(2) DEFAULT 'US',
    
    -- Settings
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id),
    INDEX idx_stripe (stripe_payment_method_id),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service fees (payments for services)
CREATE TABLE service_fees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    provider_id INT NOT NULL,
    
    -- Fee breakdown (in cents)
    subtotal INT NOT NULL,
    platform_fee INT NOT NULL,
    tax_amount INT DEFAULT 0,
    total_amount INT NOT NULL,
    
    -- Payment processing
    stripe_payment_intent_id VARCHAR(100),
    stripe_charge_id VARCHAR(100),
    payment_method_id INT,
    status ENUM('pending', 'succeeded', 'failed', 'refunded', 'disputed') DEFAULT 'pending',
    paid_at DATETIME,
    refunded_at DATETIME,
    refund_reason TEXT,
    
    -- Metadata
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES customer_payment_methods(id) ON DELETE SET NULL,
    
    INDEX idx_request (request_id),
    INDEX idx_customer (customer_id),
    INDEX idx_provider (provider_id),
    INDEX idx_status (status),
    INDEX idx_stripe (stripe_payment_intent_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider payouts
CREATE TABLE provider_payouts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    
    -- Payout details (in cents)
    amount INT NOT NULL,
    fee INT DEFAULT 0,
    net_amount INT NOT NULL,
    
    -- Stripe
    stripe_payout_id VARCHAR(100),
    stripe_transfer_id VARCHAR(100),
    
    -- Status
    status ENUM('pending', 'processing', 'completed', 'failed', 'canceled') DEFAULT 'pending',
    payout_method ENUM('bank_account', 'card', 'paypal') DEFAULT 'bank_account',
    
    -- Period
    period_start DATETIME,
    period_end DATETIME,
    completed_at DATETIME,
    failure_reason TEXT,
    
    -- Bank details (partial)
    bank_last4 VARCHAR(4),
    bank_name VARCHAR(255),
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    INDEX idx_provider (provider_id),
    INDEX idx_status (status),
    INDEX idx_stripe (stripe_payout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction history (unified view)
CREATE TABLE transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    user_type ENUM('customer', 'provider', 'admin') NOT NULL,
    
    -- Transaction details
    transaction_type ENUM('payment', 'payout', 'refund', 'credit_purchase', 'fee') NOT NULL,
    amount INT NOT NULL, -- in cents
    fee INT DEFAULT 0,
    net_amount INT,
    currency VARCHAR(3) DEFAULT 'USD',
    
    -- Status
    status ENUM('pending', 'succeeded', 'failed', 'refunded') DEFAULT 'pending',
    
    -- References
    stripe_transaction_id VARCHAR(100),
    reference_id INT, -- ID in source table
    reference_type VARCHAR(50), -- table name
    
    -- Description
    description TEXT,
    metadata JSON,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_type (transaction_type),
    INDEX idx_status (status),
    INDEX idx_stripe (stripe_transaction_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refunds
CREATE TABLE refunds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id INT NOT NULL,
    request_id INT,
    customer_id INT NOT NULL,
    provider_id INT,
    
    -- Refund details (in cents)
    amount INT NOT NULL,
    reason VARCHAR(255),
    status ENUM('pending', 'succeeded', 'failed') DEFAULT 'pending',
    
    -- Stripe
    stripe_refund_id VARCHAR(100),
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME,
    
    FOREIGN KEY (payment_id) REFERENCES service_fees(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE SET NULL,
    
    INDEX idx_payment (payment_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;