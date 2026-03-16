-- ============================================================================
-- Seed Data for Development
-- ============================================================================

-- Insert test users (passwords are 'password123' hashed)
INSERT INTO users (email, password_hash, user_type, first_name, last_name, phone, email_verified, is_active) VALUES
('customer1@test.com', '$2y$10$YourHashedPasswordHere', 'customer', 'John', 'Doe', '+1234567890', TRUE, TRUE),
('customer2@test.com', '$2y$10$YourHashedPasswordHere', 'customer', 'Jane', 'Smith', '+1234567891', TRUE, TRUE),
('provider1@test.com', '$2y$10$YourHashedPasswordHere', 'provider', 'Mike', 'Johnson', '+1234567892', TRUE, TRUE),
('provider2@test.com', '$2y$10$YourHashedPasswordHere', 'provider', 'Sarah', 'Williams', '+1234567893', TRUE, TRUE),
('admin@test.com', '$2y$10$YourHashedPasswordHere', 'admin', 'Admin', 'User', '+1234567899', TRUE, TRUE);

-- Insert customer profiles
INSERT INTO customer_profiles (user_id, default_address, default_city, default_state, default_zip) VALUES
(1, '123 Main St', 'New York', 'NY', '10001'),
(2, '456 Oak Ave', 'Los Angeles', 'CA', '90001');

-- Insert provider profiles
INSERT INTO provider_profiles (user_id, business_name, business_description, years_in_business, verified, service_radius) VALUES
(3, 'Mike''s Plumbing', 'Professional plumbing services for 10+ years', 10, TRUE, 30),
(4, 'Sarah''s Electrical', 'Licensed electricians serving LA area', 5, TRUE, 25);

-- Insert provider services
INSERT INTO provider_services (provider_id, category_id, sub_service_id, hourly_rate, flat_rate, available_24_7) VALUES
(1, 1, 1, 85.00, 150.00, TRUE),
(1, 1, 2, 75.00, 120.00, TRUE),
(2, 2, 6, 90.00, 150.00, TRUE),
(2, 2, 7, 80.00, 130.00, TRUE);

-- Insert provider availability
INSERT INTO provider_availability (provider_id, day_of_week, start_time, end_time, is_available) VALUES
(1, 1, '08:00:00', '18:00:00', TRUE),
(1, 2, '08:00:00', '18:00:00', TRUE),
(1, 3, '08:00:00', '18:00:00', TRUE),
(1, 4, '08:00:00', '18:00:00', TRUE),
(1, 5, '08:00:00', '18:00:00', TRUE),
(1, 6, '09:00:00', '15:00:00', TRUE),
(2, 1, '09:00:00', '17:00:00', TRUE),
(2, 2, '09:00:00', '17:00:00', TRUE),
(2, 3, '09:00:00', '17:00:00', TRUE),
(2, 4, '09:00:00', '17:00:00', TRUE),
(2, 5, '09:00:00', '17:00:00', TRUE);

-- Insert provider locations
INSERT INTO provider_locations (provider_id, city, state, zip_code, is_primary) VALUES
(1, 'New York', 'NY', '10001', TRUE),
(1, 'Brooklyn', 'NY', '11201', FALSE),
(2, 'Los Angeles', 'CA', '90001', TRUE),
(2, 'Burbank', 'CA', '91501', FALSE);

-- Insert service requests
INSERT INTO service_requests (customer_id, category_id, sub_service_id, title, description, address, city, state, zip_code, urgency, status) VALUES
(1, 1, 1, 'Burst pipe in basement', 'Water pipe burst in basement, need immediate help', '123 Main St', 'New York', 'NY', '10001', 'emergency', 'open'),
(1, 2, 6, 'Power outage in kitchen', 'Kitchen outlets not working, circuit breaker keeps tripping', '123 Main St', 'New York', 'NY', '10001', 'emergency', 'open'),
(2, 3, 11, 'AC not cooling', 'Air conditioner blowing warm air', '456 Oak Ave', 'Los Angeles', 'CA', '90001', 'today', 'open');

-- Insert bids
INSERT INTO bids (request_id, provider_id, amount, estimated_hours, message, status) VALUES
(1, 1, 150.00, 2, 'Can be there in 30 minutes', 'pending'),
(2, 2, 130.00, 1.5, 'Available now, will diagnose the issue', 'pending');

-- Insert sample reviews
INSERT INTO provider_reviews (provider_id, customer_id, request_id, rating_overall, rating_quality, rating_punctuality, rating_professionalism, rating_value, rating_communication, comment, status) VALUES
(1, 1, 1, 5.0, 5.0, 5.0, 5.0, 5.0, 5.0, 'Mike did an excellent job fixing my pipe. Fast and professional!', 'published'),
(2, 2, 2, 4.5, 5.0, 4.0, 5.0, 4.0, 5.0, 'Sarah was very knowledgeable and fixed my electrical issue quickly.', 'published');

-- Insert subscriptions for providers
INSERT INTO provider_subscriptions (provider_id, plan_id, status, billing_cycle, current_period_start, current_period_end, lead_credits_remaining) VALUES
(1, 2, 'active', 'monthly', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 15),
(2, 2, 'active', 'monthly', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), 18);

-- Insert sample conversation
INSERT INTO conversations (customer_id, provider_id, request_id) VALUES
(1, 1, 1);

INSERT INTO messages (conversation_id, sender_id, receiver_id, content, type) VALUES
(1, 1, 3, 'Hi, I have a burst pipe emergency', 'text'),
(1, 3, 1, 'I can be there in 30 minutes', 'text'),
(1, 1, 3, 'Great, thank you!', 'text');