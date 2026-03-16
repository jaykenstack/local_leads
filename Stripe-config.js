/**
 * Stripe Payment Configuration
 * Complete setup for Stripe Connect, subscriptions, and payment processing
 */

const stripe = require('stripe')(process.env.STRIPE_SECRET_KEY);

module.exports = {
    // ============================================================================
    // STRIPE CONNECT PLATFORM SETTINGS
    // ============================================================================
    platform: {
        name: 'UrgentServices',
        url: process.env.APP_URL || 'https://urgentservices.com',
        logo: `${process.env.APP_URL}/public/assets/images/logo.png`,
        icon: `${process.env.APP_URL}/public/assets/images/favicon.ico`,
        
        // Business details for Connect onboarding
        business_profile: {
            mcc: '7399', // Business services
            url: process.env.APP_URL,
            product_description: 'Marketplace connecting customers with urgent service providers'
        },

        // Platform fees configuration
        fees: {
            // Customer service fee (paid by customer)
            customer_service_fee: {
                type: 'percentage',
                rate: 0.10, // 10% of transaction total
                minimum: 100, // $1.00 minimum fee
                maximum: 5000, // $50.00 maximum fee
                description: 'Service fee'
            },

            // Provider commission (deducted from provider earnings)
            provider_commission: {
                basic_plan: 0.15, // 15% for basic plan
                pro_plan: 0.12,    // 12% for pro plan
                enterprise_plan: 0.10, // 10% for enterprise
                premium_plan: 0.08, // 8% for premium
                
                // Commission by service type
                by_service: {
                    plumbing: 0.15,
                    electrical: 0.15,
                    hvac: 0.12,
                    locksmith: 0.20,
                    pest_control: 0.25
                }
            },

            // Payout fees
            payout: {
                bank_account: 0.25, // $0.25 per payout
                instant: 0.01, // 1% for instant payouts
                minimum_payout: 1000, // $10.00 minimum
                payout_schedule: 'weekly' // weekly, daily, manual
            }
        },

        // Stripe Connect settings
        connect: {
            // OAuth settings for Connect onboarding
            oauth: {
                client_id: process.env.STRIPE_CONNECT_CLIENT_ID,
                redirect_uri: `${process.env.APP_URL}/api/payments/stripe-connect/callback`,
                scope: 'read_write',
                response_type: 'code'
            },

            // Capabilities required for providers
            capabilities: {
                card_payments: { requested: true },
                transfers: { requested: true },
                tax_reporting_us_1099_k: { requested: true }
            },

            // Business types accepted
            business_types: ['individual', 'company', 'non_profit'],

            // Country support
            supported_countries: ['US', 'CA', 'GB']
        }
    },

    // ============================================================================
    // SUBSCRIPTION PLANS
    // ============================================================================
    subscription_plans: {
        // Basic Plan - $29/month
        basic: {
            id: 'price_basic_monthly',
            name: 'Basic',
            amount: 2900, // $29.00 in cents
            currency: 'usd',
            interval: 'month',
            product: {
                name: 'Basic Plan',
                description: '5 leads per month, basic profile'
            },
            metadata: {
                plan_slug: 'basic',
                leads_per_month: 5,
                featured_listing: false,
                priority_support: false,
                analytics: false
            }
        },

        // Professional Plan - $79/month
        professional: {
            id: 'price_professional_monthly',
            name: 'Professional',
            amount: 7900,
            currency: 'usd',
            interval: 'month',
            product: {
                name: 'Professional Plan',
                description: '20 leads per month, featured listing'
            },
            metadata: {
                plan_slug: 'professional',
                leads_per_month: 20,
                featured_listing: true,
                priority_support: true,
                analytics: true
            }
        },

        // Enterprise Plan - $199/month
        enterprise: {
            id: 'price_enterprise_monthly',
            name: 'Enterprise',
            amount: 19900,
            currency: 'usd',
            interval: 'month',
            product: {
                name: 'Enterprise Plan',
                description: '100 leads per month, API access'
            },
            metadata: {
                plan_slug: 'enterprise',
                leads_per_month: 100,
                featured_listing: true,
                priority_support: true,
                analytics: true,
                api_access: true
            }
        },

        // Premium Plan - $499/month
        premium: {
            id: 'price_premium_monthly',
            name: 'Premium Partner',
            amount: 49900,
            currency: 'usd',
            interval: 'month',
            product: {
                name: 'Premium Plan',
                description: 'Unlimited leads, maximum exposure'
            },
            metadata: {
                plan_slug: 'premium',
                leads_per_month: -1, // Unlimited
                featured_listing: true,
                priority_support: true,
                analytics: true,
                api_access: true,
                premium_badge: true
            }
        },

        // Yearly plans (discounted)
        yearly: {
            basic: {
                id: 'price_basic_yearly',
                amount: 29000, // $290 (save 17%)
                interval: 'year'
            },
            professional: {
                id: 'price_professional_yearly',
                amount: 79000, // $790 (save 17%)
                interval: 'year'
            },
            enterprise: {
                id: 'price_enterprise_yearly',
                amount: 199000, // $1,990 (save 17%)
                interval: 'year'
            },
            premium: {
                id: 'price_premium_yearly',
                amount: 499000, // $4,990 (save 17%)
                interval: 'year'
            }
        }
    },

    // ============================================================================
    // CREDIT PACKAGES
    // ============================================================================
    credit_packages: [
        {
            id: 'credits_5',
            name: '5 Credits',
            credits: 5,
            amount: 2500, // $25.00
            currency: 'usd',
            metadata: {
                package: 'starter',
                savings: 0
            }
        },
        {
            id: 'credits_10',
            name: '10 Credits',
            credits: 10,
            amount: 4500, // $45.00 (save 10%)
            currency: 'usd',
            metadata: {
                package: 'popular',
                savings: 10
            }
        },
        {
            id: 'credits_25',
            name: '25 Credits',
            credits: 25,
            amount: 10000, // $100.00 (save 20%)
            currency: 'usd',
            metadata: {
                package: 'value',
                savings: 20
            }
        },
        {
            id: 'credits_50',
            name: '50 Credits',
            credits: 50,
            amount: 17500, // $175.00 (save 30%)
            currency: 'usd',
            metadata: {
                package: 'pro',
                savings: 30
            }
        },
        {
            id: 'credits_100',
            name: '100 Credits',
            credits: 100,
            amount: 30000, // $300.00 (save 40%)
            currency: 'usd',
            metadata: {
                package: 'business',
                savings: 40
            }
        }
    ],

    // ============================================================================
    // PAYMENT METHODS
    // ============================================================================
    payment_methods: {
        card: {
            enabled: true,
            brands: ['visa', 'mastercard', 'amex', 'discover'],
            capture_method: 'automatic'
        },
        bank_transfer: {
            enabled: true,
            countries: ['US'],
            account_types: ['checking', 'savings']
        },
        digital_wallets: {
            apple_pay: true,
            google_pay: true
        }
    },

    // ============================================================================
    // WEBHOOK CONFIGURATION
    // ============================================================================
    webhooks: {
        // Production webhook secret
        production_secret: process.env.STRIPE_WEBHOOK_SECRET,
        
        // Development webhook secret
        development_secret: process.env.STRIPE_WEBHOOK_DEV_SECRET,

        // Events to listen for
        events: [
            'account.updated',
            'charge.dispute.created',
            'charge.refunded',
            'charge.succeeded',
            'checkout.session.completed',
            'customer.created',
            'customer.deleted',
            'customer.updated',
            'customer.subscription.created',
            'customer.subscription.deleted',
            'customer.subscription.trial_will_end',
            'customer.subscription.updated',
            'invoice.created',
            'invoice.finalized',
            'invoice.paid',
            'invoice.payment_failed',
            'invoice.payment_succeeded',
            'invoice.updated',
            'payment_intent.canceled',
            'payment_intent.created',
            'payment_intent.payment_failed',
            'payment_intent.succeeded',
            'payout.created',
            'payout.failed',
            'payout.paid',
            'payout.updated',
            'product.created',
            'product.updated',
            'subscription_schedule.canceled',
            'subscription_schedule.created',
            'subscription_schedule.released',
            'subscription_schedule.updated'
        ],

        // Event handlers
        handlers: {
            // Subscription events
            'customer.subscription.created': 'handleSubscriptionCreated',
            'customer.subscription.updated': 'handleSubscriptionUpdated',
            'customer.subscription.deleted': 'handleSubscriptionCanceled',
            'customer.subscription.trial_will_end': 'handleTrialEnding',

            // Invoice events
            'invoice.paid': 'handleInvoicePaid',
            'invoice.payment_failed': 'handlePaymentFailed',
            'invoice.payment_succeeded': 'handlePaymentSucceeded',

            // Payment intent events
            'payment_intent.succeeded': 'handlePaymentSuccess',
            'payment_intent.payment_failed': 'handlePaymentFailure',

            // Payout events
            'payout.paid': 'handlePayoutComplete',
            'payout.failed': 'handlePayoutFailed',

            // Dispute events
            'charge.dispute.created': 'handleDisputeCreated',
            'charge.refunded': 'handleRefund'
        }
    },

    // ============================================================================
    // TAX CONFIGURATION
    // ============================================================================
    tax: {
        // Tax rates by location
        rates: {
            US: {
                default: 0.00, // No tax - handled by provider
                CA: 0.0725,    // California
                NY: 0.04,      // New York
                TX: 0.0625,    // Texas
                FL: 0.06,      // Florida
                IL: 0.0625,    // Illinois
                PA: 0.06,      // Pennsylvania
                OH: 0.0575,    // Ohio
                GA: 0.04,      // Georgia
                NC: 0.0475,    // North Carolina
                MI: 0.06,      // Michigan
                NJ: 0.06625,   // New Jersey
                VA: 0.043,     // Virginia
                WA: 0.065,     // Washington
                AZ: 0.056,     // Arizona
                MA: 0.0625,    // Massachusetts
                TN: 0.07,      // Tennessee
                IN: 0.07,      // Indiana
                MO: 0.04225,   // Missouri
                MD: 0.06,      // Maryland
                WI: 0.05,      // Wisconsin
                CO: 0.029,     // Colorado
                MN: 0.06875,   // Minnesota
                SC: 0.06,      // South Carolina
                AL: 0.04,      // Alabama
                LA: 0.0445,    // Louisiana
                KY: 0.06,      // Kentucky
                OR: 0.00,      // Oregon
                OK: 0.045,     // Oklahoma
                CT: 0.0635,    // Connecticut
                IA: 0.06,      // Iowa
                MS: 0.07,      // Mississippi
                AR: 0.065,     // Arkansas
                KS: 0.065,     // Kansas
                UT: 0.0485,    // Utah
                NV: 0.0685,    // Nevada
                NM: 0.05125,   // New Mexico
                WV: 0.06,      // West Virginia
                NE: 0.055,     // Nebraska
                ID: 0.06,      // Idaho
                HI: 0.04,      // Hawaii
                ME: 0.055,     // Maine
                NH: 0.00,      // New Hampshire
                RI: 0.07,      // Rhode Island
                MT: 0.00,      // Montana
                DE: 0.00,      // Delaware
                SD: 0.045,     // South Dakota
                ND: 0.05,      // North Dakota
                AK: 0.00,      // Alaska
                VT: 0.06,      // Vermont
                WY: 0.04       // Wyoming
            },
            CA: {
                default: 0.05,
                provinces: {
                    AB: 0.05,
                    BC: 0.05,
                    MB: 0.05,
                    NB: 0.15,
                    NL: 0.15,
                    NS: 0.15,
                    NT: 0.05,
                    NU: 0.05,
                    ON: 0.13,
                    PE: 0.15,
                    QC: 0.14975,
                    SK: 0.05,
                    YT: 0.05
                }
            },
            GB: {
                default: 0.20 // UK VAT
            }
        },

        // Tax exemption types
        exemptions: {
            nonprofit: 'Non-profit organization',
            government: 'Government entity',
            resale: 'Resale certificate',
            other: 'Other exemption'
        },

        // Tax reporting
        reporting: {
            threshold: 600, // $600 threshold for 1099-K
            form_1099k: true,
            form_1099nec: true
        }
    },

    // ============================================================================
    // DISPUTE & REFUND CONFIGURATION
    // ============================================================================
    disputes: {
        // Dispute reasons
        reasons: [
            'fraudulent',
            'duplicate',
            'product_not_received',
            'product_unacceptable',
            'subscription_canceled',
            'credit_not_processed',
            'general'
        ],

        // Refund policies
        refund_policy: {
            timeframe_days: 7, // 7 days to request refund
            restocking_fee: 0, // No restocking fee
            partial_refunds_allowed: true,
            full_refunds_allowed: true
        },

        // Dispute response timeframe
        response_days: 7 // 7 days to respond to dispute
    },

    // ============================================================================
    // CHECKOUT CONFIGURATION
    // ============================================================================
    checkout: {
        // Custom checkout page settings
        custom_page: {
            enabled: true,
            branding: {
                logo: '/public/assets/images/logo.png',
                colors: {
                    primary: '#3b82f6',
                    background: '#ffffff'
                }
            }
        },

        // Checkout options
        options: {
            allow_promotion_codes: true,
            collect_shipping_address: false,
            collect_billing_address: true,
            collect_phone_number: true,
            collect_tax_id: true,
            success_url: `${process.env.APP_URL}/payment/success?session_id={CHECKOUT_SESSION_ID}`,
            cancel_url: `${process.env.APP_URL}/payment/cancel`
        },

        // Customer portal
        customer_portal: {
            enabled: true,
            features: {
                invoice_history: true,
                payment_method_update: true,
                subscription_cancel: true,
                subscription_update: true
            }
        }
    },

    // ============================================================================
    // SECURITY SETTINGS
    // ============================================================================
    security: {
        // 3D Secure settings
        three_d_secure: {
            enabled: true,
            rule: 'recommended', // recommended, optional, required
            amount_threshold: 5000 // Apply 3DS for transactions over $50
        },

        // Radar fraud rules
        radar: {
            enabled: true,
            risk_score_threshold: 75, // Block transactions with risk score > 75
            block_high_risk_countries: true,
            block_anonymous_ip: true,
            block_card_testing: true
        },

        // PCI compliance
        pci_compliance: {
            saq_type: 'SAQ-A',
            level: 'Level 4',
            validated: true
        }
    },

    // ============================================================================
    // DEVELOPMENT MODE
    // ============================================================================
    development: {
        // Test mode settings
        test_mode: process.env.NODE_ENV !== 'production',
        
        // Test card numbers
        test_cards: {
            success: '4242424242424242',
            decline: '4000000000000002',
            insufficient_funds: '4000000000009995',
            lost_card: '4000000000009987',
            stolen_card: '4000000000009979',
            charge_decline: '4100000000000019',
            three_d_secure: '4000002500003155',
            three_d_secure_2: '4000002760003184'
        },

        // Test webhook URL
        webhook_url: 'https://webhook.site/your-webhook-url'
    }
};

// ============================================================================
// STRIPE UTILITY FUNCTIONS
// ============================================================================

/**
 * Create a Stripe Connect account for provider
 */
async function createConnectAccount(providerData) {
    try {
        const account = await stripe.accounts.create({
            type: 'express',
            country: providerData.country || 'US',
            email: providerData.email,
            capabilities: {
                card_payments: { requested: true },
                transfers: { requested: true }
            },
            business_type: providerData.business_type || 'individual',
            business_profile: {
                name: providerData.business_name,
                url: providerData.website,
                product_description: providerData.description
            },
            metadata: {
                provider_id: providerData.id,
                user_id: providerData.user_id
            }
        });

        return account;
    } catch (error) {
        console.error('Error creating Connect account:', error);
        throw error;
    }
}

/**
 * Create a subscription
 */
async function createSubscription(customerId, priceId, paymentMethodId) {
    try {
        // Attach payment method to customer
        await stripe.paymentMethods.attach(paymentMethodId, {
            customer: customerId
        });

        // Set as default payment method
        await stripe.customers.update(customerId, {
            invoice_settings: {
                default_payment_method: paymentMethodId
            }
        });

        // Create subscription
        const subscription = await stripe.subscriptions.create({
            customer: customerId,
            items: [{ price: priceId }],
            expand: ['latest_invoice.payment_intent'],
            payment_behavior: 'default_incomplete',
            payment_settings: {
                payment_method_types: ['card'],
                save_default_payment_method: 'on_subscription'
            }
        });

        return subscription;
    } catch (error) {
        console.error('Error creating subscription:', error);
        throw error;
    }
}

/**
 * Create a payment intent for one-time payment
 */
async function createPaymentIntent(amount, customerId, metadata = {}) {
    try {
        const paymentIntent = await stripe.paymentIntents.create({
            amount: amount,
            currency: 'usd',
            customer: customerId,
            metadata: metadata,
            setup_future_usage: 'off_session'
        });

        return paymentIntent;
    } catch (error) {
        console.error('Error creating payment intent:', error);
        throw error;
    }
}

/**
 * Create a payout to provider
 */
async function createPayout(amount, destination, metadata = {}) {
    try {
        const payout = await stripe.payouts.create({
            amount: amount,
            currency: 'usd',
            destination: destination,
            metadata: metadata
        });

        return payout;
    } catch (error) {
        console.error('Error creating payout:', error);
        throw error;
    }
}

/**
 * Handle refund
 */
async function processRefund(paymentIntentId, amount = null) {
    try {
        const refundParams = {
            payment_intent: paymentIntentId
        };

        if (amount) {
            refundParams.amount = amount;
        }

        const refund = await stripe.refunds.create(refundParams);
        return refund;
    } catch (error) {
        console.error('Error processing refund:', error);
        throw error;
    }
}

/**
 * Generate Connect onboarding link
 */
async function getConnectOnboardingLink(accountId, refreshUrl, returnUrl) {
    try {
        const accountLink = await stripe.accountLinks.create({
            account: accountId,
            refresh_url: refreshUrl,
            return_url: returnUrl,
            type: 'account_onboarding'
        });

        return accountLink.url;
    } catch (error) {
        console.error('Error generating onboarding link:', error);
        throw error;
    }
}

/**
 * Calculate platform fee for transaction
 */
function calculatePlatformFee(amount, providerPlan = 'basic', serviceType = null) {
    let commissionRate = module.exports.platform.fees.provider_commission[`${providerPlan}_plan`] || 0.15;
    
    // Override with service-specific rate if provided
    if (serviceType && module.exports.platform.fees.provider_commission.by_service[serviceType]) {
        commissionRate = module.exports.platform.fees.provider_commission.by_service[serviceType];
    }
    
    const fee = Math.round(amount * commissionRate);
    const minimum = module.exports.platform.fees.customer_service_fee.minimum;
    const maximum = module.exports.platform.fees.customer_service_fee.maximum;
    
    return Math.min(Math.max(fee, minimum), maximum);
}

/**
 * Format amount for Stripe (convert dollars to cents)
 */
function toStripeAmount(dollars) {
    return Math.round(dollars * 100);
}

/**
 * Format amount from Stripe (cents to dollars)
 */
function fromStripeAmount(cents) {
    return cents / 100;
}

module.exports = {
    stripe,
    createConnectAccount,
    createSubscription,
    createPaymentIntent,
    createPayout,
    processRefund,
    getConnectOnboardingLink,
    calculatePlatformFee,
    toStripeAmount,
    fromStripeAmount,
    ...module.exports
};