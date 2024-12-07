# Stripe Account Migration Tools

A collection of PHP scripts to help migrate data between Stripe accounts. Currently supports copying products, prices, and subscriptions from one Stripe account to another.

## Features

- Copy products with their prices from source to target Stripe account
- Copy active subscriptions while maintaining billing cycles
- Match products and prices between accounts
- Automatically cancel source subscriptions after successful migration
- Support for both single and bulk migrations
- Detailed error handling and logging

## Prerequisites

- PHP 7.4 or higher
- [Composer](https://getcomposer.org/)
- Source Stripe Account API Key
- Target Stripe Account API Key
- Customers must be copied to target account first (see Migration Steps below)

## Migration Steps

1. First, copy your customers from source to target account using Stripe's Customers Copy feature.

2. Then follow the installation steps below to copy products and subscriptions.

## Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/stripe-copy.git
cd stripe-copy
```

2. Install dependencies:
```bash
composer install
```

3. Create a `.env` file in the root directory:
```env
SOURCE_STRIPE_KEY=sk_live_your_source_key
TARGET_STRIPE_KEY=sk_live_your_target_key
```

## Usage

### Copy Products

Copy all products and their prices:
```bash
php copy_products.php
```

Copy a specific product:
```bash
php copy_products.php --product=prod_xyz
```

### Copy Subscriptions

Copy all active subscriptions:
```bash
php copy_subscriptions.php
```

Copy specific subscriptions (comma-separated):
```bash
php copy_subscriptions.php --subscriptions=sub_xyz,sub_abc
```

## Important Notes

1. The scripts will:
   - Match products by name
   - Match prices by amount, currency, and recurrence
   - Set source subscriptions to cancel at period end
   - Create new subscriptions with the same billing cycle

2. Prerequisites for subscription copying:
   - Customers MUST be migrated first using Stripe's Data Import feature
   - Products must exist in the target account (use copy_products.php first)
   - Payment methods should be copied along with customers

3. Error Handling:
   - The process will halt on any error
   - Detailed error messages are displayed
   - No partial migrations are left in an inconsistent state

## Security

- Never commit your `.env` file
- Use test mode keys first to verify the migration
- Review all migrations in the Stripe dashboard

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## License

[MIT](https://choosealicense.com/licenses/mit/)
