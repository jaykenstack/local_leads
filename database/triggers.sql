-- ============================================================================
-- Database Triggers for Automated Calculations
-- ============================================================================

DELIMITER $$

-- Update provider average rating after review insert/update/delete
CREATE TRIGGER after_review_insert
AFTER INSERT ON provider_reviews
FOR EACH ROW
BEGIN
    UPDATE provider_profiles 
    SET total_reviews = (
        SELECT COUNT(*) FROM provider_reviews 
        WHERE provider_id = NEW.provider_id AND status = 'published'
    ),
    average_rating = (
        SELECT COALESCE(AVG(rating_overall), 0) 
        FROM provider_reviews 
        WHERE provider_id = NEW.provider_id AND status = 'published'
    )
    WHERE id = NEW.provider_id;
END$$

CREATE TRIGGER after_review_update
AFTER UPDATE ON provider_reviews
FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status OR NEW.rating_overall != OLD.rating_overall THEN
        UPDATE provider_profiles 
        SET total_reviews = (
            SELECT COUNT(*) FROM provider_reviews 
            WHERE provider_id = NEW.provider_id AND status = 'published'
        ),
        average_rating = (
            SELECT COALESCE(AVG(rating_overall), 0) 
            FROM provider_reviews 
            WHERE provider_id = NEW.provider_id AND status = 'published'
        )
        WHERE id = NEW.provider_id;
    END IF;
END$$

CREATE TRIGGER after_review_delete
AFTER DELETE ON provider_reviews
FOR EACH ROW
BEGIN
    UPDATE provider_profiles 
    SET total_reviews = (
        SELECT COUNT(*) FROM provider_reviews 
        WHERE provider_id = OLD.provider_id AND status = 'published'
    ),
    average_rating = (
        SELECT COALESCE(AVG(rating_overall), 0) 
        FROM provider_reviews 
        WHERE provider_id = OLD.provider_id AND status = 'published'
    )
    WHERE id = OLD.provider_id;
END$$

-- Update conversation unread counts
CREATE TRIGGER after_message_insert
AFTER INSERT ON messages
FOR EACH ROW
BEGIN
    UPDATE conversations 
    SET last_message_id = NEW.id,
        updated_at = NOW(),
        customer_unread_count = CASE 
            WHEN NEW.receiver_id = (SELECT user_id FROM customer_profiles WHERE id = conversations.customer_id)
            THEN customer_unread_count + 1
            ELSE customer_unread_count
        END,
        provider_unread_count = CASE 
            WHEN NEW.receiver_id = (SELECT user_id FROM provider_profiles WHERE id = conversations.provider_id)
            THEN provider_unread_count + 1
            ELSE provider_unread_count
        END
    WHERE id = NEW.conversation_id;
END$$

-- Update message read status
CREATE TRIGGER after_message_read
AFTER UPDATE ON messages
FOR EACH ROW
BEGIN
    IF NEW.is_read = 1 AND OLD.is_read = 0 THEN
        UPDATE conversations 
        SET customer_unread_count = CASE 
                WHEN NEW.receiver_id = (SELECT user_id FROM customer_profiles WHERE id = conversations.customer_id)
                THEN GREATEST(customer_unread_count - 1, 0)
                ELSE customer_unread_count
            END,
            provider_unread_count = CASE 
                WHEN NEW.receiver_id = (SELECT user_id FROM provider_profiles WHERE id = conversations.provider_id)
                THEN GREATEST(provider_unread_count - 1, 0)
                ELSE provider_unread_count
            END
        WHERE id = NEW.conversation_id;
    END IF;
END$$

-- Update provider response rate
CREATE TRIGGER after_review_response
AFTER UPDATE ON provider_reviews
FOR EACH ROW
BEGIN
    IF NEW.response_from_provider IS NOT NULL AND OLD.response_from_provider IS NULL THEN
        UPDATE provider_profiles 
        SET response_rate = (
            SELECT (COUNT(CASE WHEN response_from_provider IS NOT NULL THEN 1 END) * 100.0 / COUNT(*))
            FROM provider_reviews 
            WHERE provider_id = NEW.provider_id
        ),
        average_response_time = (
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, response_date))
            FROM provider_reviews 
            WHERE provider_id = NEW.provider_id 
            AND response_from_provider IS NOT NULL
        )
        WHERE id = NEW.provider_id;
    END IF;
END$$

-- Update provider lead credits after lead delivery
CREATE TRIGGER after_lead_delivery
AFTER INSERT ON lead_delivery
FOR EACH ROW
BEGIN
    IF NEW.credit_used = 1 THEN
        UPDATE provider_subscriptions 
        SET lead_credits_used = lead_credits_used + 1,
            lead_credits_remaining = lead_credits_remaining - 1,
            total_leads_received = total_leads_received + 1,
            updated_at = NOW()
        WHERE provider_id = NEW.provider_id 
        AND status IN ('active', 'trialing')
        ORDER BY created_at DESC
        LIMIT 1;
    END IF;
END$$

-- Update request status when bid accepted
CREATE TRIGGER after_bid_accepted
AFTER UPDATE ON bids
FOR EACH ROW
BEGIN
    IF NEW.status = 'accepted' AND OLD.status != 'accepted' THEN
        -- Update request
        UPDATE service_requests 
        SET provider_id = NEW.provider_id,
            status = 'assigned',
            assigned_at = NOW(),
            updated_at = NOW()
        WHERE id = NEW.request_id;
        
        -- Reject other bids
        UPDATE bids 
        SET status = 'rejected'
        WHERE request_id = NEW.request_id 
        AND id != NEW.id;
    END IF;
END$$

-- Update daily stats
CREATE TRIGGER after_service_fee_insert
AFTER INSERT ON service_fees
FOR EACH ROW
BEGIN
    INSERT INTO daily_stats (stat_date, total_revenue, platform_fees, provider_payouts)
    VALUES (CURDATE(), NEW.total_amount, NEW.platform_fee, NEW.subtotal)
    ON DUPLICATE KEY UPDATE
        total_revenue = total_revenue + NEW.total_amount,
        platform_fees = platform_fees + NEW.platform_fee,
        provider_payouts = provider_payouts + NEW.subtotal;
END$$

DELIMITER ;