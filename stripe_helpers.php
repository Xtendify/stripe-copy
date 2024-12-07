<?php
require 'vendor/autoload.php';

// Load environment variables from .env file
function load_stripe_environment() {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $source_api_key = $_ENV['SOURCE_STRIPE_KEY'];
    $target_api_key = $_ENV['TARGET_STRIPE_KEY'];

    if (empty($source_api_key) || empty($target_api_key)) {
        throw new Exception("Error: API keys not found in .env file\n" .
            "Please make sure SOURCE_STRIPE_KEY and TARGET_STRIPE_KEY are set in your .env file");
    }

    return [$source_api_key, $target_api_key];
}

// Function to handle pagination for Stripe API calls
function fetch_all_pages($stripe_class, $params = []) {
    $results = [];
    $params = array_merge(['limit' => 100], $params);

    do {
        $page = $stripe_class::all($params);
        if (!empty($page->data)) {
            $results = array_merge($results, $page->data);
            $params['starting_after'] = end($page->data)->id;
        }
    } while ($page && $page->has_more);

    return $results;
}

// Function to convert metadata to array properly
function convert_metadata_to_array($metadata) {
    if (empty($metadata)) {
        return [];
    }
    $metadata_array = json_decode(json_encode($metadata), true);
    return array_map('strval', $metadata_array);
}

// Function to find matching product in target account
function find_matching_product($target_products, $source_product) {
    foreach ($target_products as $target_product) {
        if ($target_product->name === $source_product->name) {
            return $target_product;
        }
    }
    return null;
}

// Function to find matching price in target account
function find_matching_price($target_prices, $source_price) {
    foreach ($target_prices as $target_price) {
        $both_recurring = isset($source_price->recurring) && isset($target_price->recurring);
        $both_non_recurring = !isset($source_price->recurring) && !isset($target_price->recurring);

        $basic_match = $target_price->unit_amount === $source_price->unit_amount &&
                      $target_price->currency === $source_price->currency;

        if ($both_recurring && $basic_match) {
            if ($target_price->recurring->interval === $source_price->recurring->interval &&
                $target_price->recurring->interval_count === $source_price->recurring->interval_count) {
                return $target_price;
            }
        }
        elseif ($both_non_recurring && $basic_match) {
            return $target_price;
        }
    }
    return null;
}
