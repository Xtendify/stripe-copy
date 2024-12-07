<?php
require_once 'stripe_helpers.php';

// Function to fetch prices for a product
function fetch_prices_for_product($product_id) {
    return fetch_all_pages(\Stripe\Price::class, ['product' => $product_id]);
}

// Function to check if metadata is empty
function is_metadata_empty($metadata) {
    return empty($metadata) || count((array)$metadata) === 0;
}

// Function to copy a single product
function copy_single_product($source_api_key, $target_api_key, $product_id) {
    if (empty($product_id)) {
        echo "Error: Product ID is required\n";
        return;
    }

    try {
        // Set source API key and fetch source product
        \Stripe\Stripe::setApiKey($source_api_key);
        $source_product = \Stripe\Product::retrieve($product_id);
        $source_prices = fetch_prices_for_product($product_id);

        echo "Source Product Details:\n";
        echo "Name: " . $source_product->name . "\n";
        echo "ID: " . $source_product->id . "\n";
        echo "Description: " . ($source_product->description ?? 'N/A') . "\n";
        echo "Number of prices: " . count($source_prices) . "\n";
        echo "Metadata: " . json_encode($source_product->metadata, JSON_PRETTY_PRINT) . "\n\n";

        // Switch to target API key
        \Stripe\Stripe::setApiKey($target_api_key);

        // Fetch all target products
        $target_products = fetch_all_pages(\Stripe\Product::class);

        // Check if product exists
        $matching_product = find_matching_product($target_products, $source_product);
        $target_product = $matching_product;

        if (!$matching_product) {
            // Create new product
            $target_product = \Stripe\Product::create([
                'name' => $source_product->name,
                'description' => $source_product->description,
                'metadata' => convert_metadata_to_array($source_product->metadata),
                'images' => $source_product->images,
                'active' => $source_product->active,
            ]);
            echo "Created new product: " . $target_product->name . " (ID: " . $target_product->id . ")\n";
        } else {
            echo "Product already exists: " . $matching_product->name . " (ID: " . $matching_product->id . ")\n";

            // Check and update metadata if empty
            if (is_metadata_empty($matching_product->metadata) && !is_metadata_empty($source_product->metadata)) {
                $target_product = \Stripe\Product::update($matching_product->id, [
                    'metadata' => convert_metadata_to_array($source_product->metadata)
                ]);
                echo "Updated product metadata\n";
            }
        }

        // Fetch all existing prices for the target product
        $target_product_prices = fetch_prices_for_product($target_product->id);

        // Process prices for this product
        echo "\nProcessing prices:\n";
        foreach ($source_prices as $source_price) {
            echo "\nChecking Price:\n";
            echo "Amount: " . ($source_price->unit_amount/100) . " " . $source_price->currency . "\n";
            echo "Metadata: " . json_encode($source_price->metadata, JSON_PRETTY_PRINT) . "\n";
            if (isset($source_price->recurring)) {
                echo "Type: Recurring - " . $source_price->recurring->interval .
                     " (every " . $source_price->recurring->interval_count . " " .
                     $source_price->recurring->interval . ")\n";
            } else {
                echo "Type: One-time payment\n";
            }

            $matching_price = find_matching_price($target_product_prices, $source_price);

            if (!$matching_price) {
                // Create new price
                $price_data = [
                    'product' => $target_product->id,
                    'unit_amount' => $source_price->unit_amount,
                    'currency' => $source_price->currency,
                    'active' => $source_price->active,
                    'metadata' => convert_metadata_to_array($source_price->metadata),
                ];

                if (isset($source_price->recurring)) {
                    $price_data['recurring'] = [
                        'interval' => $source_price->recurring->interval,
                        'interval_count' => $source_price->recurring->interval_count,
                    ];
                }

                $new_price = \Stripe\Price::create($price_data);
                echo "Created new price: " . $new_price->id . "\n";
            } else {
                echo "Price already exists: " . $matching_price->id . "\n";
                echo "Existing price details:\n";
                echo "- Amount: " . ($matching_price->unit_amount/100) . " " . $matching_price->currency . "\n";
                echo "- Metadata: " . json_encode($matching_price->metadata, JSON_PRETTY_PRINT) . "\n";

                // Check and update price metadata if empty
                if (is_metadata_empty($matching_price->metadata) && !is_metadata_empty($source_price->metadata)) {
                    $updated_price = \Stripe\Price::update($matching_price->id, [
                        'metadata' => convert_metadata_to_array($source_price->metadata)
                    ]);
                    echo "- Updated price metadata\n";
                }

                if (isset($matching_price->recurring)) {
                    echo "- Type: Recurring - " . $matching_price->recurring->interval .
                         " (every " . $matching_price->recurring->interval_count . " " .
                         $matching_price->recurring->interval . ")\n";
                } else {
                    echo "- Type: One-time payment\n";
                }
            }
        }

        echo "\nProcess completed successfully!\n";

    } catch (\Stripe\Exception\ApiErrorException $e) {
        echo "Stripe API Error: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Function to copy all products
function copy_all_products($source_api_key, $target_api_key) {
    try {
        // Set source API key and fetch all source products
        \Stripe\Stripe::setApiKey($source_api_key);
        $source_products = fetch_all_pages(\Stripe\Product::class);

        echo "Found " . count($source_products) . " products in source account\n\n";

        foreach ($source_products as $source_product) {
            echo "\n=== Processing Product: " . $source_product->name . " ===\n";
            copy_single_product($source_api_key, $target_api_key, $source_product->id);
            echo "=== Completed Product: " . $source_product->name . " ===\n";
        }

        echo "\nAll products processed successfully!\n";

    } catch (\Stripe\Exception\ApiErrorException $e) {
        echo "Stripe API Error: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Main execution
try {
    [$source_api_key, $target_api_key] = load_stripe_environment();

    // Parse command line arguments
    $product_id = null;
    foreach ($argv as $arg) {
        if (strpos($arg, '--product=') === 0) {
            $product_id = substr($arg, strlen('--product='));
            break;
        }
    }

    if (!empty($product_id)) {
        echo "Processing single product with ID: " . $product_id . "\n\n";
        copy_single_product($source_api_key, $target_api_key, $product_id);
    } else {
        echo "No product ID provided. Processing all products...\n\n";
        copy_all_products($source_api_key, $target_api_key);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
