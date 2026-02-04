# ShopKing eCommerce Platform - AI Agent Guidelines

## Project Overview
ShopKing is a Laravel 12 + Vue 3 multi-tenant eCommerce platform with POS, inventory management, and multi-language support. It's a commercial product (version 2.9) with extensive payment gateway integrations.

## Architecture Patterns

### Backend: Service-Layer Pattern
All business logic lives in dedicated service classes (`app/Services/*.php`), not controllers. Controllers are thin wrappers that delegate to services.

**Example:** When implementing order functionality:
```php
// OrderController just validates and delegates
public function store(OrderRequest $request) {
    return $this->orderService->store($request);
}
```
Services handle: validation, events, DB transactions, external API calls. See [OrderService.php](app/Services/OrderService.php) for the pattern.

### Frontend: Vue 3 SPA with Vuex Modules
The frontend is a full Vue 3 SPA (`resources/js/`). Each major feature has a dedicated Vuex module ([store/modules/](resources/js/store/modules/)). Router is modularized by feature in [router/modules/](resources/js/router/modules/).

**Key files:**
- [resources/js/app.js](resources/js/app.js): Bootstrap, axios interceptors, global components
- API calls go through `resources/js/services/*.js` (e.g., `orderService.js`)
- State management: Vuex with `vuex-persistedstate` for auth/cart persistence

### Enums as Interfaces (Not PHP Enums)
This codebase uses **interfaces** for enums, not PHP 8.1+ enums:
```php
// app/Enums/Activity.php
interface Activity {
    const ENABLE  = 5;
    const DISABLE = 10;
}
```
Access via `Activity::ENABLE`. Add new constants to existing interfaces, don't convert to backed enums.

### Payment Gateway Abstraction
All payment gateways extend `PaymentAbstract` ([app/Services/PaymentAbstract.php](app/Services/PaymentAbstract.php)) and implement:
- `status()`, `payment($order, $request)`, `success()`, `fail()`, `cancel()`
- Located in [app/Http/PaymentGateways/Gateways/](app/Http/PaymentGateways/Gateways/)
- 24+ gateways supported: Stripe, PayPal, Razorpay, Bkash, etc.
- Gateway configs stored in DB (`payment_gateways`, `gateway_options` tables)

**To add a new gateway:** Extend `PaymentAbstract`, add slug to gateway seeder, add route in `app/Http/PaymentGateways/Routes/`.

### SMS Gateway Abstraction
Similar pattern to payments. All SMS gateways extend `SmsAbstract` (e.g., [Twilio.php](app/Http/SmsGateways/Gateways/Twilio.php)).

## Critical Developer Workflows

### Development Server
**Never run `php artisan serve` alone.** Use the orchestrated dev command:
```bash
composer dev
```
This runs concurrently: Laravel server, queue worker, Pail logs, Vite dev server. See [composer.json scripts](composer.json#L76).

### Build & Deploy
```bash
composer setup  # Fresh install: composer install + migrations + npm build
npm run build   # Production Vite build
php artisan config:cache && php artisan route:cache  # Production optimization
```

### Queue System
Queue workers are **required** for: order notifications (email/SMS/push), report generation. Use `php artisan queue:listen` (included in `composer dev`).

### Migrations
Database has 80+ migrations. Order matters due to foreign keys. Never run individual migrations; use:
```bash
php artisan migrate:fresh --seed  # Development only
php artisan migrate --force       # Production
```

## Project-Specific Conventions

### API Structure
- Admin routes: `/api/admin/*` (API key + Bearer token required)
- Frontend routes: `/api/frontend/*` (API key + optional Bearer token)
- Auth routes: `/api/auth/*` (signup, login, OTP)
- API key sent via `x-api-key` header (see [app.js](resources/js/app.js#L42))

### Multi-Language Support
- Vue I18n for frontend ([i18n.js](resources/js/i18n.js))
- Laravel translation files in `lang/{ar,bn,en}/`
- Language code sent via `x-localization` header (extracted from Vuex state)

### Media Handling
Uses Spatie Media Library. All uploads go through:
```php
$model->addMedia($request->file)->toMediaCollection('collection-name');
```
Media stored in `storage/media-library/`. Public symlink required: `php artisan storage:link`.

### Soft Deletes & Permission System
- Users table has soft deletes (see migration `2024_03_11_000000_add_softdelete_to_users_table.php`)
- Uses Spatie Laravel Permission for roles/permissions
- Check permissions in services, not controllers

### Order Flow
1. Frontend cart → checkout → payment gateway selection
2. Payment processing via `PaymentManagerService`
3. On success: Order created, stock reduced, notifications queued (mail/SMS/push)
4. Events: `SendOrderMail`, `SendOrderSms`, `SendOrderPush` (see [OrderService.php](app/Services/OrderService.php))

## Integration Points

### Firebase (Push Notifications)
- Config: `public/firebase-messaging-sw.js`
- Firebase initialized in [app.js](resources/js/app.js)
- Push notifications sent via `FirebaseService`

### Third-Party APIs
- **SMS:** Twilio, Vonage (Nexmo)
- **Payment:** 24+ gateways (see [composer.json](composer.json#L13-L45))
- **Storage:** S3 support via `league/flysystem-aws-s3-v3`
- **Excel:** Maatwebsite Excel for imports/exports

### Installer System
Custom installer for multi-tenant setup (`app/Http/Controllers/Installer/`, `routes/web.php` installer routes). Checks server requirements, permissions, generates `.env`, runs migrations.

## Common Pitfalls

1. **Don't bypass services:** Always use service classes, never raw Eloquent in controllers
2. **Queue dependency:** Order notifications won't send without queue worker running
3. **API key:** All API requests need `x-api-key` header (defined in ENV)
4. **Enum syntax:** Use `Activity::ENABLE`, not `Activity::ENABLE->value`
5. **Vite manifest:** Run `npm run build` before production, or assets 404
6. **Settings facade:** Use `Settings::group('site')->get('key')` from `dipokhalder/settings` package
7. **License validation:** Product has license checking (see [config/product.php](config/product.php))

## Testing
PHPUnit configured ([phpunit.xml](phpunit.xml)). Run via:
```bash
composer test
```
Test files in `tests/Feature/` and `tests/Unit/`. No existing tests; write feature tests for critical flows (order, payment).

## Key Directories
- `app/Services/`: Business logic layer (100+ service classes)
- `app/Enums/`: Interface-based enums
- `app/Http/PaymentGateways/`: Payment gateway implementations
- `resources/js/`: Vue 3 SPA (components, router, store, services)
- `database/migrations/`: 80+ migrations (ordered chronologically)
- `lang/`: Multi-language support (ar, bn, en)
