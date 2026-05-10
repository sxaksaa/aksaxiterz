# Aksa Xiterz Storefront

Aksa Xiterz is a digital-license storefront built with Laravel. This repository is shared publicly as a portfolio showcase for internship and recruitment review, not as an open-source starter kit or reusable commercial project.

## Portfolio Notice

Copyright (c) 2026 Aksa Xiterz. All rights reserved.

This source code is not licensed for public use, copying, modification, redistribution, resale, or production deployment. The repository is public only to demonstrate project scope, engineering decisions, and implementation experience.

## Project Overview

The application supports a complete digital product purchase flow: customers browse license packages, sign in, choose a payment method, track order status, and receive a delivered license after payment verification. It also includes an admin area for managing license stock and reviewing customer orders.

The project was built to solve a real storefront need: selling duration-based digital licenses while keeping checkout, stock delivery, customer history, and support pages in one Laravel application.

## Key Features

- Digital product catalog with categories, package pricing, search, and stock visibility.
- Google-based customer login.
- Checkout flow for local QR payment and direct USDT payment.
- Order history with pending, paid, cancelled, pay-again, cancel, and verification states.
- Automatic license delivery after payment confirmation.
- FIFO stock fulfillment so older imported license keys are delivered first.
- Admin dashboard for license stock, order review, manual payment handling, and user overview.
- Public pages for downloads, guides, terms, privacy policy, refund policy, and support contact.
- Shared UI feedback patterns for product selection, checkout actions, and payment states.
- Security-focused middleware for browser headers, private order/license pages, and production URL handling.

## My Role

I designed and implemented the full Laravel application, including:

- Database structure for products, packages, orders, license stock, and delivered licenses.
- Storefront pages and responsive Blade UI.
- Payment and order status flow.
- License fulfillment logic.
- Admin stock and order management.
- Public legal/support content pages.
- Production readiness improvements for deployment.
- Frontend interaction behavior with Vite, Tailwind CSS, and JavaScript helpers.

## Tech Stack

- Laravel 12
- PHP 8.2+
- Blade
- Tailwind CSS
- Vite
- JavaScript
- Laravel Socialite
- Laravel migrations, seeders, middleware, and Artisan commands

## Architecture Highlights

The codebase separates the main business logic into dedicated services:

- `PaymentService` handles checkout creation and payment inspection.
- `DirectCryptoOrderVerifier` handles direct USDT verification behavior.
- `OrderFulfillmentService` handles paid-order fulfillment and license delivery.
- Admin controllers handle operational workflows for stock and orders.
- Blade partials keep storefront, order, and payment UI reusable.

This keeps the core purchase flow easier to maintain: checkout creates an order, verification confirms payment, and fulfillment delivers the correct license stock.

## User Flow

1. Customer opens the storefront and selects a product package.
2. Customer logs in with Google before checkout.
3. Customer chooses a payment method.
4. The system creates an order and shows the payment instructions.
5. Payment verification updates the order status.
6. A license key is delivered to the customer account after payment is confirmed.
7. Customer can view their license and previous orders from the account pages.

## Admin Flow

Admins can access a protected management area to:

- Import license stock in bulk.
- Monitor available and sold license keys.
- Filter orders by status and payment method.
- Review payment details.
- Mark eligible orders as paid.
- Resync license delivery when needed.
- Monitor low-stock package conditions.

## What This Project Demonstrates

- Building a complete Laravel storefront from database to frontend.
- Handling real checkout states instead of only static product pages.
- Designing safer order and license delivery logic.
- Separating customer-facing pages from admin operations.
- Working with third-party authentication and payment-style integrations.
- Preparing a Laravel app for production deployment.
- Writing maintainable service classes for business-critical flows.

## Repository Status

This repository is intended for portfolio review. Setup instructions, deployment values, provider credentials, and operational runbooks are intentionally omitted from this public README.

For review purposes, please evaluate the project structure, Laravel implementation, UI code, service classes, migrations, and overall product flow.
