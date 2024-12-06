<?php
require 'vendor/autoload.php';

$source_api_key = 'sk_test_...'; // Source Account API Key
$target_api_key = 'sk_test_...'; // Target Account API Key

// Set the Stripe API keys for both source and target accounts
\Stripe\Stripe::setApiKey($source_api_key);

// Function to copy customers
function copy_customers($source_api_key, $target_api_key) {
    \Stripe\Stripe::setApiKey($source_api_key);
    $customers = \Stripe\Customer::all();
    
    \Stripe\Stripe::setApiKey($target_api_key);
    
    foreach ($customers->data as $customer) {
        $new_customer = \Stripe\Customer::create([
            'email' => $customer->email,
            'name' => $customer->name,
            'description' => $customer->description,
            'address' => $customer->address,
        ]);

        echo "Customer copied: " . $new_customer->id . "\n";
    }
}

// Function to copy products
function copy_products($source_api_key, $target_api_key) {
    \Stripe\Stripe::setApiKey($source_api_key);
    $products = \Stripe\Product::all();
    
    \Stripe\Stripe::setApiKey($target_api_key);
    
    foreach ($products->data as $product) {
        $new_product = \Stripe\Product::create([
            'name' => $product->name,
            'description' => $product->description,
            'type' => $product->type,
            'metadata' => $product->metadata,
            'images' => $product->images,
        ]);

        echo "Product copied: " . $new_product->id . "\n";
    }
}

// Function to copy only active subscriptions (including metadata)
function copy_subscriptions($source_api_key, $target_api_key) {
    // Set API key for the source account
    \Stripe\Stripe::setApiKey($source_api_key);
    
    // List active subscriptions from the source account
    $subscriptions = \Stripe\Subscription::all([
        'status' => 'active', // Only retrieve active subscriptions
        'limit' => 100, // Set a limit for pagination (you can adjust this)
    ]);
    
    // Set API key for the target account
    \Stripe\Stripe::setApiKey($target_api_key);
    
    foreach ($subscriptions->data as $subscription) {
        // Check if the subscription is active (although we filter in the API call)
        if ($subscription->status === 'active') {
            // Find the customer from the source account
            $customer = \Stripe\Customer::retrieve($subscription->customer);

            // Copy items (products, quantities, etc.) from the source subscription
            $subscription_items = [];
            foreach ($subscription->items->data as $item) {
                $subscription_items[] = [
                    'price' => $item->price->id, // Use the price ID from the original subscription item
                    'quantity' => $item->quantity
                ];
            }

            // Create the subscription in the target account with the same details
            $new_subscription = \Stripe\Subscription::create([
                'customer' => $customer->id, // Use the same customer
                'items' => $subscription_items, // Same subscription items
                'default_payment_method' => $subscription->default_payment_method, // Same payment method
                'trial_period_days' => $subscription->trial_period_days, // Same trial period (if any)
                'metadata' => $subscription->metadata, // Copy metadata
            ]);

            echo "Active subscription copied: " . $new_subscription->id . "\n";
        }
    }
}

// Main execution
copy_customers($source_api_key, $target_api_key);
copy_products($source_api_key, $target_api_key);
copy_subscriptions($source_api_key, $target_api_key);