<?php
require_once 'stripe_helpers.php';

function find_customer_by_email($email) {
    $customers = fetch_all_pages(\Stripe\Customer::class, [
        'email' => $email,
        'limit' => 1
    ]);
    return !empty($customers) ? $customers[0] : null;
}

function find_existing_subscription($customer_id, $items) {
    // Get all active subscriptions for the customer
    $subscriptions = fetch_all_pages(\Stripe\Subscription::class, [
        'customer' => $customer_id,
        'status' => 'active',
        'expand' => ['data.items.data.price']
    ]);

    foreach ($subscriptions as $subscription) {
        // Check if subscription has same number of items
        if (count($subscription->items->data) !== count($items)) {
            continue;
        }

        // Check if all items match
        $matches = true;
        foreach ($items as $new_item) {
            $found_match = false;
            foreach ($subscription->items->data as $existing_item) {
                // Ensure we have full price objects
                if (is_string($existing_item->price)) {
                    $existing_item->price = \Stripe\Price::retrieve([
                        'id' => $existing_item->price,
                        'expand' => ['product']
                    ]);
                }
                if (is_string($new_item['price'])) {
                    $new_item['price'] = \Stripe\Price::retrieve([
                        'id' => $new_item['price'],
                        'expand' => ['product']
                    ]);
                }

                $price_matches =
                    $existing_item->price->unit_amount === $new_item['price']->unit_amount &&
                    $existing_item->price->currency === $new_item['price']->currency;

                // Ensure we have full product objects for comparison
                if (is_string($existing_item->price->product)) {
                    $existing_item->price->product = \Stripe\Product::retrieve($existing_item->price->product);
                }
                if (is_string($new_item['price']->product)) {
                    $new_item['price']->product = \Stripe\Product::retrieve($new_item['price']->product);
                }

                $price_matches = $price_matches &&
                    $existing_item->price->product->name === $new_item['price']->product->name;

                // Check recurring parameters if both prices are recurring
                $both_recurring = isset($existing_item->price->recurring) && isset($new_item['price']->recurring);
                $both_non_recurring = !isset($existing_item->price->recurring) && !isset($new_item['price']->recurring);

                if ($both_recurring) {
                    $price_matches = $price_matches &&
                        $existing_item->price->recurring->interval === $new_item['price']->recurring->interval &&
                        $existing_item->price->recurring->interval_count === $new_item['price']->recurring->interval_count;
                } elseif (!$both_non_recurring) {
                    // One is recurring and one isn't - not a match
                    $price_matches = false;
                }

                if ($price_matches) {
                    $found_match = true;
                    break;
                }
            }
            if (!$found_match) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return $subscription;
        }
    }

    return null;
}

function cancel_source_subscription($source_api_key, $source_subscription, $target_subscription_id) {
    \Stripe\Stripe::setApiKey($source_api_key);

    $updated_metadata = array_merge(
        convert_metadata_to_array($source_subscription->metadata),
        [
            'migrated_to' => $target_subscription_id,
            'migrated_at' => date('Y-m-d H:i:s'),
        ]
    );

    $updated_source_subscription = \Stripe\Subscription::update($source_subscription->id, [
        'cancel_at_period_end' => true,
        'metadata' => convert_metadata_to_array($updated_metadata)
    ]);

    echo "\nSource subscription #{$source_subscription->id} set to cancel at period end and added migration metadata\n";

    return $updated_source_subscription;
}

function copy_single_subscription($source_api_key, $target_api_key, $source_subscription_id) {
    if (empty($source_subscription_id)) {
        throw new Exception("Error: Subscription ID is required");
    }

    // Set source API key and fetch source subscription
    \Stripe\Stripe::setApiKey($source_api_key);
    $source_subscription = \Stripe\Subscription::retrieve([
        'id' => $source_subscription_id,
        'expand' => ['customer', 'items.data.price.product']
    ]);

    // Skip if subscription is scheduled to be cancelled
    if ($source_subscription->cancel_at || $source_subscription->cancel_at_period_end || !empty($source_subscription->pause_collection) || $source_subscription->status !== 'active') {
        echo "Skipping subscription {$source_subscription_id} as it is either not active or scheduled to be cancelled\n";
        return;
    }

    // Switch to target API key
    \Stripe\Stripe::setApiKey($target_api_key);

    // Find customer in target account
    $target_customer = find_customer_by_email($source_subscription->customer->email);
    if (!$target_customer) {
        throw new Exception("Error: Customer with email '{$source_subscription->customer->email}' not found in target account");
    }

    // Fetch all products in target account
    $target_products = fetch_all_pages(\Stripe\Product::class);

    $items_with_prices = [];
    foreach ($source_subscription->items->data as $item) {
        $source_product = $item->price->product;
        $matching_product = find_matching_product($target_products, $source_product);

        if (!$matching_product) {
            throw new Exception("Error: Product '{$source_product->name}' not found in target account");
        }

        // Find matching price
        $target_prices = fetch_all_pages(\Stripe\Price::class, ['product' => $matching_product->id]);
        $matching_price = find_matching_price($target_prices, $item->price);
        $matching_price->product = $matching_product;

        if (!$matching_price) {
            throw new Exception("Error: Matching price not found for product '{$source_product->name}'");
        }

        $items_with_prices[] = [
            'price' => $matching_price,  // Store full price object for comparison
            'quantity' => $item->quantity,
        ];

        $items[] = [
            'price' => $matching_price->id,  // Store just the ID for creation
            'quantity' => $item->quantity,
        ];
    }

    // Check for existing subscription
    $existing_subscription = find_existing_subscription($target_customer->id, $items);
    if ($existing_subscription) {
        echo "Found matching subscription in target account with ID: " . $existing_subscription->id . "\n";

        cancel_source_subscription(
            $source_api_key,
            $source_subscription,
            $existing_subscription->id
        );
        return;
    }

    // Create subscription in target account
    try {
        $subscription_data = [
            'customer' => $target_customer->id,
            'items' => $items,
            'metadata' => convert_metadata_to_array($source_subscription->metadata),
            'billing_cycle_anchor' => $source_subscription->current_period_end,
            'automatic_tax' => ['enabled' => false],  // Disable automatic tax calculation
            'collection_method' => 'charge_automatically',
            'proration_behavior' => 'none',
        ];

        // Copy additional subscription attributes
        $attributes_to_copy = [
            'description'
        ];

        foreach ($attributes_to_copy as $attr) {
            if (isset($source_subscription->$attr)) {
                $subscription_data[$attr] = $source_subscription->$attr;
            }
        }

        $target_subscription = \Stripe\Subscription::create($subscription_data);

        cancel_source_subscription(
            $source_api_key,
            $source_subscription,
            $target_subscription->id
        );

        return $target_subscription;

    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new Exception("Failed to create/update subscription: " . $e->getMessage());
    }
}

function copy_all_subscriptions($source_api_key, $target_api_key) {
    // Set source API key and fetch all active subscriptions
    \Stripe\Stripe::setApiKey($source_api_key);
    $source_subscriptions = fetch_all_pages(\Stripe\Subscription::class, [
        'status' => 'active',
        'expand' => ['data.items.data.price']
    ]);

    echo "Found " . count($source_subscriptions) . " active subscriptions in source account\n\n";

    foreach ($source_subscriptions as $subscription) {
        try {
            $target_subscription = copy_single_subscription($source_api_key, $target_api_key, $subscription->id);
            echo "=== Processed Subscription: " . $subscription->id . ", Target ID: " . $target_subscription->id . " ===\n";
        } catch (Exception $e) {
            echo "Error processing subscription {$subscription->id}: " . $e->getMessage() . "\n";
            exit(1); // Exit immediately if any subscription fails
        }
    }

    echo "\nSubscription copying completed successfully!\n";
}

// Main execution
try {
    [$source_api_key, $target_api_key] = load_stripe_environment();

    // Parse command line arguments
    $subscription_ids = null;
    foreach ($argv as $arg) {
        if (strpos($arg, '--subscriptions=') === 0) {
            $subscription_ids = substr($arg, strlen('--subscriptions='));
            break;
        }
    }

    if (!empty($subscription_ids)) {
        $ids = array_map('trim', explode(',', $subscription_ids));
        echo "Processing " . count($ids) . " subscription(s)...\n\n";

        foreach ($ids as $subscription_id) {
            try {
                $target_subscription = copy_single_subscription($source_api_key, $target_api_key, $subscription_id);
                echo "=== Processed Subscription: " . $subscription_id . ", Target ID: " . $target_subscription->id . " ===\n";
            } catch (Exception $e) {
                echo "Error processing subscription {$subscription_id}: " . $e->getMessage() . "\n";
                exit(1); // Exit immediately if any subscription fails
            }
        }
        echo "\nAll specified subscriptions processed successfully!\n";
    } else {
        echo "No subscription IDs provided. Processing all active subscriptions...\n\n";
        copy_all_subscriptions($source_api_key, $target_api_key);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
