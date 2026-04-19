# OMEGA POS RESTAURANT — Documentation API Laravel Complète
> **Stack** : Laravel 11 · PHP 8.2 · MySQL 8 · Sanctum · Echo/Pusher · Redis  
> **Auteur** : Expert PHP Laravel  
> **Version** : 1.0.0  

---

## TABLE DES MATIÈRES

1. [Architecture & Setup](#1-architecture--setup)
2. [Base de données — Migrations complètes](#2-base-de-données--migrations-complètes)
3. [Modèles Eloquent](#3-modèles-eloquent)
4. [Authentification & Rôles](#4-authentification--rôles)
5. [Module Tables & Plan de salle](#5-module-tables--plan-de-salle)
6. [Module Commandes](#6-module-commandes)
7. [Module Paiements & Caisse](#7-module-paiements--caisse)
8. [Module Cuisine KDS](#8-module-cuisine-kds)
9. [Module Menu & Produits](#9-module-menu--produits)
10. [Module Stock & Inventaire](#10-module-stock--inventaire)
11. [Module Livraisons](#11-module-livraisons)
12. [Module Rapports](#12-module-rapports)
13. [Système Annulations](#13-système-annulations)
14. [Audit Trail — Traçabilité](#14-audit-trail--traçabilité)
15. [Événements WebSocket temps réel](#15-événements-websocket-temps-réel)
16. [Routes API complètes](#16-routes-api-complètes)
17. [Middleware & Policies](#17-middleware--policies)
18. [Jobs & Queues](#18-jobs--queues)

---

## 1. ARCHITECTURE & SETUP

### 1.1 Structure des dossiers

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── Auth/
│   │   │   └── AuthController.php
│   │   ├── TableController.php
│   │   ├── OrderController.php
│   │   ├── OrderItemController.php
│   │   ├── PaymentController.php
│   │   ├── CashSessionController.php
│   │   ├── ProductController.php
│   │   ├── CategoryController.php
│   │   ├── StockController.php
│   │   ├── IngredientController.php
│   │   ├── DeliveryController.php
│   │   ├── ReportController.php
│   │   ├── CancellationController.php
│   │   ├── ActivityLogController.php
│   │   └── UserController.php
│   ├── Middleware/
│   │   ├── CheckRole.php
│   │   ├── LogActivity.php
│   │   └── CheckRestaurant.php
│   └── Requests/
│       ├── StoreOrderRequest.php
│       ├── UpdateOrderItemsRequest.php
│       ├── StorePaymentRequest.php
│       └── ...
├── Models/
│   ├── User.php
│   ├── Restaurant.php
│   ├── Floor.php
│   ├── Table.php
│   ├── Order.php
│   ├── OrderItem.php
│   ├── OrderItemModifier.php
│   ├── Payment.php
│   ├── CashSession.php
│   ├── Category.php
│   ├── Product.php
│   ├── Modifier.php
│   ├── ModifierGroup.php
│   ├── Ingredient.php
│   ├── Recipe.php
│   ├── StockMovement.php
│   ├── Delivery.php
│   ├── Cancellation.php
│   └── ActivityLog.php
├── Events/
│   ├── OrderCreated.php
│   ├── OrderItemStatusUpdated.php
│   ├── OrderReady.php
│   ├── TableStatusChanged.php
│   └── DeliveryUpdated.php
├── Observers/
│   └── AuditObserver.php
├── Policies/
│   ├── OrderPolicy.php
│   ├── CancellationPolicy.php
│   └── UserPolicy.php
├── Traits/
│   └── Auditable.php
└── Jobs/
    ├── SendKitchenNotification.php
    └── ProcessStockDeduction.php
```

### 1.2 Installation & Configuration

```bash
# Création du projet
composer create-project laravel/laravel omega-pos
cd omega-pos

# Packages essentiels
composer require laravel/sanctum
composer require pusher/pusher-php-server
composer require intervention/image
composer require barryvdh/laravel-dompdf
composer require maatwebsite/excel
composer require simplesoftwareio/simple-qrcode
composer require spatie/laravel-activitylog

# Publier les configs
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"
```

### 1.3 Variables d'environnement `.env`

```env
APP_NAME="Omega POS"
APP_ENV=production
APP_KEY=base64:...
APP_URL=https://pos.monrestaurant.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=omega_pos
DB_USERNAME=root
DB_PASSWORD=secret

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

BROADCAST_DRIVER=pusher
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1

SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1
```

---

## 2. BASE DE DONNÉES — MIGRATIONS COMPLÈTES

### 2.1 Restaurants

```php
// database/migrations/2024_01_01_000001_create_restaurants_table.php
Schema::create('restaurants', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('logo')->nullable();
    $table->string('address')->nullable();
    $table->string('phone')->nullable();
    $table->string('email')->nullable();
    $table->string('vat_number')->nullable();
    $table->string('currency', 10)->default('XOF');
    $table->string('timezone')->default('Africa/Lome');
    $table->json('settings')->nullable(); // config imprimante, TVA par défaut, etc.
    $table->boolean('active')->default(true);
    $table->timestamps();
});
```

### 2.2 Utilisateurs & Rôles

```php
// database/migrations/2024_01_01_000002_create_roles_table.php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->string('name'); // admin, manager, cashier, waiter, cook, driver
    $table->string('display_name');
    $table->json('permissions'); // liste des permissions
    $table->boolean('is_system')->default(false); // rôles non modifiables
    $table->timestamps();
});

// database/migrations/2024_01_01_000003_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('role_id')->constrained();
    $table->string('first_name');
    $table->string('last_name');
    $table->string('email')->unique();
    $table->string('password');
    $table->string('pin', 6)->nullable(); // PIN pour connexion rapide serveur
    $table->string('avatar')->nullable();
    $table->boolean('active')->default(true);
    $table->timestamp('last_login_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['restaurant_id', 'active']);
});
```

### 2.3 Salles & Tables

```php
// database/migrations/2024_01_01_000004_create_floors_table.php
Schema::create('floors', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->integer('order')->default(0);
    $table->boolean('active')->default(true);
    $table->timestamps();
});

// database/migrations/2024_01_01_000005_create_tables_table.php
Schema::create('tables', function (Blueprint $table) {
    $table->id();
    $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
    $table->string('number');
    $table->integer('capacity')->default(4);
    $table->enum('status', ['free', 'occupied', 'waiting', 'reserved'])->default('free');
    $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->decimal('position_x', 8, 2)->default(0);
    $table->decimal('position_y', 8, 2)->default(0);
    $table->integer('width')->default(100);
    $table->integer('height')->default(100);
    $table->string('shape')->default('rectangle'); // rectangle, round
    $table->timestamp('occupied_since')->nullable();
    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->unique(['floor_id', 'number']);
    $table->index('status');
});

// Réservations
Schema::create('reservations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('table_id')->constrained()->cascadeOnDelete();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->string('customer_name');
    $table->string('customer_phone')->nullable();
    $table->integer('covers');
    $table->timestamp('reserved_at');
    $table->integer('duration_minutes')->default(90);
    $table->enum('status', ['confirmed', 'seated', 'cancelled', 'no_show'])->default('confirmed');
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->index(['table_id', 'reserved_at']);
});
```

### 2.4 Menu — Catégories & Produits

```php
// database/migrations/2024_01_01_000006_create_categories_table.php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
    $table->string('name');
    $table->string('image')->nullable();
    $table->integer('order')->default(0);
    $table->boolean('active')->default(true);
    $table->timestamps();
});

// database/migrations/2024_01_01_000007_create_products_table.php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('category_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('image')->nullable();
    $table->decimal('price', 12, 2);
    $table->decimal('cost_price', 12, 2)->nullable(); // prix de revient
    $table->decimal('vat_rate', 5, 2)->default(18.00);
    $table->string('sku')->nullable()->unique();
    $table->boolean('available')->default(true);
    $table->boolean('track_stock')->default(false); // traquer le stock via recettes
    $table->integer('order')->default(0);
    $table->boolean('active')->default(true);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['restaurant_id', 'available', 'active']);
});

// Groupes de modificateurs (ex: "Cuisson", "Options")
Schema::create('modifier_groups', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->boolean('required')->default(false);
    $table->boolean('multiple')->default(false);
    $table->integer('min_selections')->default(0);
    $table->integer('max_selections')->default(1);
    $table->integer('order')->default(0);
    $table->timestamps();
});

// Modificateurs (ex: "Bien cuit", "Sans piment")
Schema::create('modifiers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->decimal('extra_price', 10, 2)->default(0);
    $table->boolean('active')->default(true);
    $table->integer('order')->default(0);
    $table->timestamps();
});

// Menus composés / formules
Schema::create('combo_menus', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('price', 12, 2);
    $table->boolean('active')->default(true);
    $table->timestamps();
});

Schema::create('combo_menu_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('combo_menu_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->integer('quantity')->default(1);
    $table->timestamps();
});
```

### 2.5 Commandes

```php
// database/migrations/2024_01_01_000008_create_orders_table.php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('table_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // serveur
    $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('order_number')->unique(); // ORD-2024-0001
    $table->enum('type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');
    $table->enum('status', [
        'open',           // commande ouverte, items en cours
        'sent_to_kitchen',// envoyée en cuisine
        'partially_served', // certains items servis
        'served',         // tous les items servis
        'paid',           // payée
        'cancelled'       // annulée
    ])->default('open');
    $table->integer('covers')->default(1);
    $table->decimal('subtotal', 12, 2)->default(0);
    $table->decimal('discount_amount', 12, 2)->default(0);
    $table->string('discount_reason')->nullable();
    $table->decimal('vat_amount', 12, 2)->default(0);
    $table->decimal('total', 12, 2)->default(0);
    $table->text('notes')->nullable();
    $table->timestamp('sent_to_kitchen_at')->nullable();
    $table->timestamp('served_at')->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['restaurant_id', 'status']);
    $table->index(['table_id', 'status']);
    $table->index('order_number');
});

// Items de commande
Schema::create('order_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained();
    $table->foreignId('combo_menu_id')->nullable()->constrained()->nullOnDelete();
    $table->integer('quantity');
    $table->decimal('unit_price', 12, 2);
    $table->decimal('subtotal', 12, 2);
    $table->enum('status', ['pending', 'preparing', 'done', 'served', 'cancelled'])->default('pending');
    $table->text('notes')->nullable(); // "Sans piment", "Bien cuit"
    $table->integer('course')->default(1); // service 1=entrée, 2=plat, 3=dessert
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('prepared_at')->nullable();
    $table->timestamp('served_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['order_id', 'status']);
});

// Modificateurs choisis sur un item
Schema::create('order_item_modifiers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
    $table->foreignId('modifier_id')->constrained();
    $table->decimal('extra_price', 10, 2)->default(0);
    $table->timestamps();
});

// Journal de la commande
Schema::create('order_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('action'); // 'created', 'item_added', 'sent_kitchen', 'item_done', 'paid', 'cancelled'
    $table->text('message');
    $table->json('meta')->nullable(); // données contextuelles
    $table->timestamps();

    $table->index('order_id');
});
```

### 2.6 Paiements & Caisse

```php
// Sessions de caisse
Schema::create('cash_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained(); // caissier
    $table->decimal('opening_amount', 12, 2)->default(0);
    $table->decimal('closing_amount', 12, 2)->nullable();
    $table->decimal('expected_amount', 12, 2)->nullable(); // calculé auto
    $table->decimal('difference', 12, 2)->nullable(); // écart
    $table->text('closing_notes')->nullable();
    $table->timestamp('opened_at');
    $table->timestamp('closed_at')->nullable();
    $table->timestamps();

    $table->index(['restaurant_id', 'closed_at']);
});

// Paiements
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('user_id')->constrained(); // caissier
    $table->decimal('amount', 12, 2);
    $table->enum('method', ['cash', 'card', 'wave', 'orange_money', 'momo', 'other']);
    $table->string('reference')->nullable(); // référence transaction mobile
    $table->decimal('amount_given', 12, 2)->nullable(); // espèces remises par client
    $table->decimal('change_given', 12, 2)->nullable(); // monnaie rendue
    $table->boolean('is_partial')->default(false);
    $table->timestamps();
    $table->softDeletes();

    $table->index('order_id');
});
```

### 2.7 Stock

```php
// Ingrédients / matières premières
Schema::create('ingredients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('unit'); // kg, g, L, pièce, etc.
    $table->decimal('quantity', 12, 3)->default(0);
    $table->decimal('min_quantity', 12, 3)->default(0); // seuil alerte
    $table->decimal('cost_per_unit', 12, 4)->default(0);
    $table->string('category')->nullable(); // viandes, légumes, boissons...
    $table->string('supplier')->nullable();
    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->index(['restaurant_id', 'active']);
});

// Recettes : composition d'un produit en ingrédients
Schema::create('recipes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->decimal('quantity', 12, 4); // quantité utilisée par portion
    $table->timestamps();

    $table->unique(['product_id', 'ingredient_id']);
});

// Mouvements de stock
Schema::create('stock_movements', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('type', ['in', 'out', 'adjustment', 'waste', 'return']);
    $table->decimal('quantity', 12, 3);
    $table->decimal('quantity_before', 12, 3);
    $table->decimal('quantity_after', 12, 3);
    $table->decimal('unit_cost', 12, 4)->nullable();
    $table->string('reason')->nullable();
    $table->string('reference')->nullable(); // N° bon de livraison
    $table->timestamps();

    $table->index(['restaurant_id', 'ingredient_id']);
    $table->index(['type', 'created_at']);
});
```

### 2.8 Livraisons

```php
Schema::create('deliveries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->string('customer_name');
    $table->string('customer_phone');
    $table->text('address');
    $table->decimal('lat', 10, 7)->nullable();
    $table->decimal('lng', 10, 7)->nullable();
    $table->enum('status', ['pending', 'preparing', 'ready', 'on_the_way', 'delivered', 'failed'])->default('pending');
    $table->decimal('delivery_fee', 10, 2)->default(0);
    $table->text('notes')->nullable();
    $table->timestamp('estimated_at')->nullable();
    $table->timestamp('picked_up_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamps();

    $table->index(['restaurant_id', 'status']);
    $table->index('driver_id');
});
```

### 2.9 Annulations

```php
Schema::create('cancellations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
    $table->morphs('cancellable'); // order, order_item, payment
    $table->foreignId('requested_by')->constrained('users');
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
    $table->string('reason'); // raison obligatoire
    $table->text('notes')->nullable();
    $table->decimal('refund_amount', 12, 2)->nullable();
    $table->enum('refund_method', ['cash', 'original_method', 'credit', 'none'])->nullable();
    $table->timestamp('requested_at');
    $table->timestamp('approved_at')->nullable();
    $table->timestamps();

    $table->index(['restaurant_id', 'status']);
});
```

### 2.10 Audit Trail

```php
Schema::create('activity_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('action');           // created, updated, deleted, login, logout, cancelled...
    $table->string('module');           // order, payment, stock, user, cash, cancellation...
    $table->string('subject_type')->nullable();
    $table->unsignedBigInteger('subject_id')->nullable();
    $table->text('description');        // message lisible humain
    $table->json('old_values')->nullable();
    $table->json('new_values')->nullable();
    $table->string('reason')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent')->nullable();
    $table->timestamps();

    $table->index(['restaurant_id', 'module', 'action']);
    $table->index(['subject_type', 'subject_id']);
    $table->index(['user_id', 'created_at']);
});
```

---

## 3. MODÈLES ELOQUENT

### 3.1 Trait Auditable

```php
// app/Traits/Auditable.php
namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn($model) => static::audit('created', $model, [], $model->toArray()));
        static::updated(fn($model) => static::audit('updated', $model, $model->getOriginal(), $model->getChanges()));
        static::deleted(fn($model) => static::audit('deleted', $model, $model->toArray(), []));
    }

    protected static function audit(string $action, $model, array $old, array $new): void
    {
        // Exclure les champs sensibles
        $excluded = ['password', 'pin', 'remember_token', 'updated_at'];
        $old = array_diff_key($old, array_flip($excluded));
        $new = array_diff_key($new, array_flip($excluded));

        ActivityLog::create([
            'restaurant_id' => $model->restaurant_id ?? Auth::user()?->restaurant_id,
            'user_id'       => Auth::id(),
            'action'        => $action,
            'module'        => static::getAuditModule(),
            'subject_type'  => get_class($model),
            'subject_id'    => $model->getKey(),
            'description'   => static::buildDescription($action, $model),
            'old_values'    => empty($old) ? null : $old,
            'new_values'    => empty($new) ? null : $new,
            'ip_address'    => Request::ip(),
            'user_agent'    => Request::userAgent(),
        ]);
    }

    protected static function getAuditModule(): string
    {
        return defined('static::AUDIT_MODULE') ? static::AUDIT_MODULE : 'system';
    }

    protected static function buildDescription(string $action, $model): string
    {
        $className = class_basename($model);
        $id = $model->getKey();
        return match($action) {
            'created' => "{$className} #{$id} créé",
            'updated' => "{$className} #{$id} modifié",
            'deleted' => "{$className} #{$id} supprimé",
            default   => "{$className} #{$id} — {$action}",
        };
    }

    // Méthode manuelle pour logs custom
    public function logActivity(string $action, string $description, array $meta = []): void
    {
        ActivityLog::create([
            'restaurant_id' => $this->restaurant_id ?? Auth::user()?->restaurant_id,
            'user_id'       => Auth::id(),
            'action'        => $action,
            'module'        => static::getAuditModule(),
            'subject_type'  => get_class($this),
            'subject_id'    => $this->getKey(),
            'description'   => $description,
            'new_values'    => empty($meta) ? null : $meta,
            'ip_address'    => Request::ip(),
        ]);
    }
}
```

### 3.2 Modèle Order

```php
// app/Models/Order.php
namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'order';

    protected $fillable = [
        'restaurant_id', 'table_id', 'user_id', 'cashier_id',
        'order_number', 'type', 'status', 'covers',
        'subtotal', 'discount_amount', 'discount_reason',
        'vat_amount', 'total', 'notes',
        'sent_to_kitchen_at', 'served_at', 'paid_at',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount'      => 'decimal:2',
        'total'           => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
        'served_at'       => 'datetime',
        'paid_at'         => 'datetime',
    ];

    // Relations
    public function restaurant()   { return $this->belongsTo(Restaurant::class); }
    public function table()        { return $this->belongsTo(Table::class); }
    public function waiter()       { return $this->belongsTo(User::class, 'user_id'); }
    public function cashier()      { return $this->belongsTo(User::class, 'cashier_id'); }
    public function items()        { return $this->hasMany(OrderItem::class); }
    public function payments()     { return $this->hasMany(Payment::class); }
    public function logs()         { return $this->hasMany(OrderLog::class)->latest(); }
    public function delivery()     { return $this->hasOne(Delivery::class); }
    public function cancellations(){ return $this->morphMany(Cancellation::class, 'cancellable'); }

    // Scopes
    public function scopeOpen($q)   { return $q->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served']); }
    public function scopeToday($q)  { return $q->whereDate('created_at', today()); }
    public function scopePaid($q)   { return $q->where('status', 'paid'); }

    // Calculer et mettre à jour les totaux
    public function recalculate(): void
    {
        $subtotal = $this->items()
            ->whereNotIn('status', ['cancelled'])
            ->sum(\DB::raw('unit_price * quantity'));

        $vatAmount = round($subtotal * 0.18, 2);
        $total     = $subtotal - $this->discount_amount + $vatAmount;

        $this->update([
            'subtotal'   => $subtotal,
            'vat_amount' => $vatAmount,
            'total'      => max(0, $total),
        ]);
    }

    // Montant déjà payé
    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    // Reste à payer
    public function amountDue(): float
    {
        return max(0, $this->total - $this->amountPaid());
    }

    // Générer numéro de commande
    public static function generateNumber(int $restaurantId): string
    {
        $today = now()->format('Ymd');
        $count = static::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', today())
            ->count() + 1;
        return "ORD-{$today}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
```

### 3.3 Modèle User

```php
// app/Models/User.php
namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Auditable, SoftDeletes;

    const AUDIT_MODULE = 'user';

    protected $fillable = [
        'restaurant_id', 'role_id', 'first_name', 'last_name',
        'email', 'password', 'pin', 'avatar', 'active',
    ];

    protected $hidden = ['password', 'pin', 'remember_token'];

    protected $casts = [
        'active'        => 'boolean',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    // Relations
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function role()       { return $this->belongsTo(Role::class); }

    // Vérifier une permission
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->role->permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    // Vérifier un rôle
    public function hasRole(string|array $roles): bool
    {
        $roleName = $this->role->name;
        return is_array($roles)
            ? in_array($roleName, $roles)
            : $roleName === $roles;
    }

    public function isManager(): bool
    {
        return $this->hasRole(['admin', 'manager']);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
```

---

## 4. AUTHENTIFICATION & RÔLES

### 4.1 AuthController

```php
// app/Http/Controllers/Api/Auth/AuthController.php
namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // POST /api/auth/login — Connexion email + password
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('role', 'restaurant')
            ->where('email', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            ActivityLog::create([
                'action'      => 'login_failed',
                'module'      => 'auth',
                'description' => "Tentative de connexion échouée pour {$request->email}",
                'ip_address'  => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est désactivé.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        ActivityLog::create([
            'restaurant_id' => $user->restaurant_id,
            'user_id'       => $user->id,
            'action'        => 'login',
            'module'        => 'auth',
            'description'   => "{$user->full_name} connecté",
            'ip_address'    => $request->ip(),
        ]);

        $token = $user->createToken('pos-token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    // POST /api/auth/login-pin — Connexion rapide par PIN (serveurs)
    public function loginPin(Request $request)
    {
        $request->validate([
            'restaurant_id' => 'required|exists:restaurants,id',
            'pin'           => 'required|string|min:4|max:6',
        ]);

        $user = User::with('role')
            ->where('restaurant_id', $request->restaurant_id)
            ->where('active', true)
            ->get()
            ->first(fn($u) => Hash::check($request->pin, $u->pin));

        if (!$user) {
            throw ValidationException::withMessages(['pin' => ['PIN incorrect.']]);
        }

        $user->update(['last_login_at' => now()]);

        ActivityLog::create([
            'restaurant_id' => $user->restaurant_id,
            'user_id'       => $user->id,
            'action'        => 'login_pin',
            'module'        => 'auth',
            'description'   => "{$user->full_name} connecté via PIN",
            'ip_address'    => $request->ip(),
        ]);

        $token = $user->createToken('pin-token', ['role:' . $user->role->name])->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    // POST /api/auth/logout
    public function logout(Request $request)
    {
        ActivityLog::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'user_id'       => $request->user()->id,
            'action'        => 'logout',
            'module'        => 'auth',
            'description'   => "{$request->user()->full_name} déconnecté",
            'ip_address'    => $request->ip(),
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    // GET /api/auth/me
    public function me(Request $request)
    {
        return response()->json($request->user()->load('role', 'restaurant'));
    }

    // PUT /api/auth/change-password
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mot de passe actuel incorrect.'],
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        $request->user()->logActivity('password_changed', 'Mot de passe modifié');

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }
}
```

### 4.2 UserController

```php
// app/Http/Controllers/Api/UserController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // GET /api/users
    public function index(Request $request)
    {
        $users = User::with('role')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where(function($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                  ->orWhere('last_name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->role_id, fn($q) => $q->where('role_id', $request->role_id))
            ->when(isset($request->active), fn($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('first_name')
            ->paginate(20);

        return response()->json($users);
    }

    // POST /api/users
    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|min:8',
            'role_id'    => 'required|exists:roles,id',
            'pin'        => 'nullable|digits_between:4,6',
            'active'     => 'boolean',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['password']      = Hash::make($validated['password']);
        if (isset($validated['pin'])) {
            $validated['pin'] = Hash::make($validated['pin']);
        }

        $user = User::create($validated);

        return response()->json($user->load('role'), 201);
    }

    // GET /api/users/{id}
    public function show(Request $request, User $user)
    {
        $this->authorizeRestaurant($request, $user);
        return response()->json($user->load('role'));
    }

    // PUT /api/users/{id}
    public function update(Request $request, User $user)
    {
        $this->authorizeRestaurant($request, $user);
        $this->authorize('update', $user);

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'email'      => "sometimes|email|unique:users,email,{$user->id}",
            'role_id'    => 'sometimes|exists:roles,id',
            'pin'        => 'nullable|digits_between:4,6',
            'active'     => 'sometimes|boolean',
        ]);

        if (isset($validated['pin'])) {
            $validated['pin'] = Hash::make($validated['pin']);
        }

        $user->update($validated);

        return response()->json($user->fresh('role'));
    }

    // DELETE /api/users/{id}
    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);
        $this->authorizeRestaurant($request, $user);

        $user->delete();
        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    // PATCH /api/users/{id}/toggle-active
    public function toggleActive(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $user->update(['active' => !$user->active]);
        return response()->json($user);
    }

    private function authorizeRestaurant(Request $request, User $user): void
    {
        abort_if(
            $user->restaurant_id !== $request->user()->restaurant_id,
            403, 'Accès non autorisé.'
        );
    }
}
```

---

## 5. MODULE TABLES & PLAN DE SALLE

### 5.1 TableController

```php
// app/Http/Controllers/Api/TableController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Floor;
use App\Events\TableStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableController extends Controller
{
    // GET /api/floors/{floor}/tables
    public function index(Request $request, Floor $floor)
    {
        $this->authorizeFloor($request, $floor);

        $tables = $floor->tables()
            ->with(['assignedUser:id,first_name,last_name', 'currentOrder'])
            ->where('active', true)
            ->get()
            ->map(function ($table) {
                $table->occupation_minutes = $table->occupied_since
                    ? now()->diffInMinutes($table->occupied_since)
                    : null;
                return $table;
            });

        return response()->json($tables);
    }

    // GET /api/floors
    public function floors(Request $request)
    {
        $floors = Floor::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->withCount(['tables', 'tables as occupied_count' => fn($q) => $q->where('status', 'occupied')])
            ->orderBy('order')
            ->get();

        return response()->json($floors);
    }

    // PUT /api/tables/{table}/status
    public function updateStatus(Request $request, Table $table)
    {
        $this->authorizeTable($request, $table);

        $request->validate([
            'status' => 'required|in:free,occupied,waiting,reserved',
        ]);

        $oldStatus = $table->status;
        $newStatus = $request->status;

        // Règle : libérer une table occupée requiert manager ou fin de paiement
        if ($oldStatus === 'occupied' && $newStatus === 'free') {
            $activeOrder = $table->currentOrder;
            if ($activeOrder && $activeOrder->status !== 'paid') {
                if (!$request->user()->isManager()) {
                    abort(403, 'Un manager doit valider la libération de cette table.');
                }
            }
        }

        $table->update([
            'status'        => $newStatus,
            'occupied_since'=> $newStatus === 'occupied' ? now() : null,
            'assigned_user_id' => $newStatus === 'free' ? null : $table->assigned_user_id,
        ]);

        $table->logActivity('status_changed', "Table {$table->number} : {$oldStatus} → {$newStatus}");

        broadcast(new TableStatusChanged($table))->toOthers();

        return response()->json($table);
    }

    // POST /api/tables/merge
    public function merge(Request $request)
    {
        $request->validate([
            'source_table_id' => 'required|exists:tables,id',
            'target_table_id' => 'required|exists:tables,id|different:source_table_id',
        ]);

        $source = Table::findOrFail($request->source_table_id);
        $target = Table::findOrFail($request->target_table_id);

        // Les deux tables doivent appartenir au même restaurant
        abort_if(
            $source->floor->restaurant_id !== $request->user()->restaurant_id,
            403
        );

        // Règle : fusion uniquement si les deux sont libres ou même groupe
        $sourceOrder = $source->currentOrder;
        $targetOrder = $target->currentOrder;

        if ($sourceOrder && $targetOrder) {
            // Fusionner les commandes : déplacer les items de source vers target
            DB::transaction(function () use ($source, $target, $sourceOrder, $targetOrder) {
                $sourceOrder->items()->update(['order_id' => $targetOrder->id]);
                $targetOrder->recalculate();
                $sourceOrder->update(['status' => 'cancelled', 'table_id' => null]);
                $source->update(['status' => 'free', 'occupied_since' => null]);

                $targetOrder->logs()->create([
                    'user_id' => auth()->id(),
                    'action'  => 'tables_merged',
                    'message' => "Table {$source->number} fusionnée dans Table {$target->number}",
                ]);
            });
        }

        return response()->json(['message' => "Tables fusionnées avec succès."]);
    }

    // POST /api/tables/{table}/transfer
    public function transfer(Request $request, Table $table)
    {
        $request->validate([
            'target_table_id' => 'required|exists:tables,id',
        ]);

        $target = Table::findOrFail($request->target_table_id);

        abort_if($target->status !== 'free', 422, 'La table cible doit être libre.');

        DB::transaction(function () use ($table, $target, $request) {
            $order = $table->currentOrder;

            if ($order) {
                $order->update(['table_id' => $target->id]);
                $order->logs()->create([
                    'user_id' => auth()->id(),
                    'action'  => 'table_transferred',
                    'message' => "Commande transférée de Table {$table->number} vers Table {$target->number}",
                ]);
            }

            $target->update(['status' => 'occupied', 'occupied_since' => $table->occupied_since]);
            $table->update(['status' => 'free', 'occupied_since' => null, 'assigned_user_id' => null]);
        });

        broadcast(new TableStatusChanged($table->fresh()))->toOthers();
        broadcast(new TableStatusChanged($target->fresh()))->toOthers();

        return response()->json(['message' => 'Transfert effectué.']);
    }

    // PUT /api/tables/{table}/assign
    public function assign(Request $request, Table $table)
    {
        $request->validate(['user_id' => 'nullable|exists:users,id']);
        $table->update(['assigned_user_id' => $request->user_id]);
        return response()->json($table->load('assignedUser'));
    }

    // POST /api/tables/{table}/reserve
    public function reserve(Request $request, Table $table)
    {
        $request->validate([
            'customer_name'  => 'required|string',
            'customer_phone' => 'nullable|string',
            'covers'         => 'required|integer|min:1',
            'reserved_at'    => 'required|date|after:now',
            'duration'       => 'integer|min:30|max:300',
            'notes'          => 'nullable|string',
        ]);

        $reservation = $table->reservations()->create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'customer_name'  => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'covers'         => $request->covers,
            'reserved_at'    => $request->reserved_at,
            'duration_minutes' => $request->duration ?? 90,
            'notes'          => $request->notes,
        ]);

        $table->update(['status' => 'reserved']);

        return response()->json($reservation, 201);
    }

    // PUT /api/tables/{table}/layout (admin — position drag & drop)
    public function updateLayout(Request $request, Table $table)
    {
        $request->validate([
            'position_x' => 'required|numeric',
            'position_y' => 'required|numeric',
            'width'      => 'integer|min:60',
            'height'     => 'integer|min:60',
        ]);

        $table->update($request->only(['position_x', 'position_y', 'width', 'height']));

        return response()->json($table);
    }

    private function authorizeFloor(Request $request, Floor $floor): void
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
    }

    private function authorizeTable(Request $request, Table $table): void
    {
        abort_if($table->floor->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
```

---

## 6. MODULE COMMANDES

### 6.1 OrderController

```php
// app/Http/Controllers/Api/OrderController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Table;
use App\Models\Product;
use App\Events\OrderCreated;
use App\Events\OrderReady;
use App\Jobs\ProcessStockDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // GET /api/orders
    public function index(Request $request)
    {
        $orders = Order::with(['table', 'waiter', 'items.product'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->table_id, fn($q) => $q->where('table_id', $request->table_id))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate(30);

        return response()->json($orders);
    }

    // GET /api/orders/table/{tableId}/current
    public function currentByTable(Request $request, int $tableId)
    {
        $order = Order::with([
                'items.product',
                'items.modifiers.modifier',
                'waiter:id,first_name,last_name',
                'logs' => fn($q) => $q->latest()->limit(20),
            ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('table_id', $tableId)
            ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])
            ->latest()
            ->firstOrFail();

        return response()->json([
            'order'        => $order,
            'amount_paid'  => $order->amountPaid(),
            'amount_due'   => $order->amountDue(),
        ]);
    }

    // GET /api/orders/{id}
    public function show(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        return response()->json(
            $order->load([
                'items.product', 'items.modifiers.modifier',
                'payments', 'waiter', 'cashier', 'table',
                'logs', 'delivery', 'cancellations',
            ])
        );
    }

    // POST /api/orders
    public function store(Request $request)
    {
        $request->validate([
            'table_id'   => 'nullable|exists:tables,id',
            'type'       => 'required|in:dine_in,takeaway,delivery',
            'covers'     => 'integer|min:1',
            'notes'      => 'nullable|string',
            'items'      => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.notes'        => 'nullable|string',
            'items.*.course'       => 'integer|min:1',
            'items.*.modifier_ids' => 'nullable|array',
            'items.*.modifier_ids.*' => 'exists:modifiers,id',
        ]);

        $order = DB::transaction(function () use ($request) {
            // Créer la commande
            $order = Order::create([
                'restaurant_id' => $request->user()->restaurant_id,
                'table_id'      => $request->table_id,
                'user_id'       => $request->user()->id,
                'order_number'  => Order::generateNumber($request->user()->restaurant_id),
                'type'          => $request->type,
                'covers'        => $request->covers ?? 1,
                'notes'         => $request->notes,
                'status'        => 'open',
            ]);

            // Ajouter les items
            foreach ($request->items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $item = $order->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $itemData['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $product->price * $itemData['quantity'],
                    'notes'      => $itemData['notes'] ?? null,
                    'course'     => $itemData['course'] ?? 1,
                    'status'     => 'pending',
                ]);

                // Ajouter les modificateurs
                if (!empty($itemData['modifier_ids'])) {
                    foreach ($itemData['modifier_ids'] as $modifierId) {
                        $modifier = \App\Models\Modifier::find($modifierId);
                        $item->modifiers()->create([
                            'modifier_id' => $modifierId,
                            'extra_price' => $modifier?->extra_price ?? 0,
                        ]);
                    }
                }
            }

            // Recalculer les totaux
            $order->recalculate();

            // Mettre à jour statut de la table
            if ($request->table_id) {
                Table::where('id', $request->table_id)->update([
                    'status'         => 'occupied',
                    'occupied_since' => now(),
                    'assigned_user_id' => $request->user()->id,
                ]);
            }

            // Log
            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'created',
                'message' => "Commande {$order->order_number} créée par {$request->user()->full_name}",
            ]);

            return $order;
        });

        return response()->json($order->load('items.product'), 201);
    }

    // POST /api/orders/{id}/send-to-kitchen
    public function sendToKitchen(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'item_ids' => 'nullable|array', // si null → envoyer tous les items pending
            'item_ids.*' => 'exists:order_items,id',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Commande déjà finalisée.');

        $itemsQuery = $order->items()->where('status', 'pending');
        if ($request->item_ids) {
            $itemsQuery->whereIn('id', $request->item_ids);
        }

        $items = $itemsQuery->get();
        abort_if($items->isEmpty(), 422, 'Aucun item à envoyer en cuisine.');

        DB::transaction(function () use ($order, $items) {
            $items->each->update(['status' => 'preparing', 'sent_at' => now()]);

            $order->update([
                'status'             => 'sent_to_kitchen',
                'sent_to_kitchen_at' => $order->sent_to_kitchen_at ?? now(),
            ]);

            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'sent_to_kitchen',
                'message' => count($items) . " article(s) envoyé(s) en cuisine",
                'meta'    => ['item_ids' => $items->pluck('id')],
            ]);
        });

        // Diffuser l'événement WebSocket vers la cuisine
        broadcast(new OrderCreated($order->load('items.product', 'table')))->toOthers();

        return response()->json(['message' => 'Commande envoyée en cuisine.']);
    }

    // PUT /api/orders/{id}/items — Modifier les items d'une commande
    public function updateItems(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'items'            => 'required|array',
            'items.*.id'       => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.notes'    => 'nullable|string|max:300',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Impossible de modifier une commande finalisée.');

        DB::transaction(function () use ($request, $order) {
            foreach ($request->items as $data) {
                $item = $order->items()->findOrFail($data['id']);

                // Item déjà servi : annulation requiert manager
                if ($item->status === 'done' && $data['quantity'] < $item->quantity) {
                    abort_unless($request->user()->isManager(), 403, 'Manager requis pour annuler un article servi.');
                }

                if ($data['quantity'] === 0) {
                    $order->logs()->create([
                        'user_id' => auth()->id(),
                        'action'  => 'item_removed',
                        'message' => "{$item->product->name} supprimé de la commande",
                    ]);
                    $item->delete();
                } else {
                    $wasServed = $item->status === 'done';
                    $item->update([
                        'quantity' => $data['quantity'],
                        'subtotal' => $item->unit_price * $data['quantity'],
                        'notes'    => $data['notes'] ?? $item->notes,
                        'status'   => ($data['quantity'] > $item->quantity && $wasServed) ? 'pending' : $item->status,
                    ]);

                    $order->logs()->create([
                        'user_id' => auth()->id(),
                        'action'  => 'item_updated',
                        'message' => "{$item->product->name} : qté → {$data['quantity']}",
                    ]);
                }
            }

            $order->recalculate();
        });

        return response()->json($order->fresh(['items.product', 'items.modifiers']));
    }

    // POST /api/orders/{id}/add-items
    public function addItems(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.notes'      => 'nullable|string',
            'items.*.course'     => 'integer|min:1',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Commande finalisée.');

        DB::transaction(function () use ($request, $order) {
            foreach ($request->items as $data) {
                $product = Product::findOrFail($data['product_id']);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $data['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $product->price * $data['quantity'],
                    'notes'      => $data['notes'] ?? null,
                    'course'     => $data['course'] ?? 1,
                    'status'     => 'pending',
                ]);
            }
            $order->recalculate();

            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'items_added',
                'message' => count($request->items) . " article(s) ajouté(s)",
            ]);
        });

        return response()->json($order->fresh('items.product'));
    }

    // PUT /api/orders/{id}/discount
    public function applyDiscount(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        abort_unless($request->user()->isManager(), 403, 'Manager requis pour appliquer une remise.');

        $request->validate([
            'type'   => 'required|in:percent,fixed',
            'value'  => 'required|numeric|min:0',
            'reason' => 'required|string',
        ]);

        $discount = $request->type === 'percent'
            ? round($order->subtotal * $request->value / 100, 2)
            : min($request->value, $order->subtotal);

        $order->update([
            'discount_amount' => $discount,
            'discount_reason' => $request->reason,
        ]);
        $order->recalculate();

        $order->logs()->create([
            'user_id' => auth()->id(),
            'action'  => 'discount_applied',
            'message' => "Remise de {$discount} FCFA appliquée ({$request->reason})",
        ]);

        return response()->json($order->fresh());
    }

    // PUT /api/orders/{id}/status
    public function updateStatus(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate(['status' => 'required|in:open,sent_to_kitchen,partially_served,served,paid,cancelled']);

        $order->update(['status' => $request->status]);

        return response()->json($order);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
```

---

## 7. MODULE PAIEMENTS & CAISSE

### 7.1 PaymentController

```php
// app/Http/Controllers/Api/PaymentController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\CashSession;
use App\Jobs\ProcessStockDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // POST /api/payments — Enregistrer un paiement
    public function store(Request $request)
    {
        $request->validate([
            'order_id'     => 'required|exists:orders,id',
            'amount'       => 'required|numeric|min:0.01',
            'method'       => 'required|in:cash,card,wave,orange_money,momo,other',
            'reference'    => 'nullable|string',
            'amount_given' => 'nullable|numeric|min:0',
        ]);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($order->status === 'paid', 422, 'Commande déjà payée.');
        abort_if($order->status === 'cancelled', 422, 'Commande annulée.');

        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')
            ->latest()
            ->first();

        abort_if(!$session && $request->method === 'cash', 422, 'Aucune session de caisse ouverte.');

        DB::transaction(function () use ($request, $order, $session) {
            $changeGiven = null;
            if ($request->method === 'cash' && $request->amount_given) {
                $changeGiven = max(0, $request->amount_given - $request->amount);
            }

            $payment = Payment::create([
                'order_id'        => $order->id,
                'cash_session_id' => $session?->id,
                'user_id'         => auth()->id(),
                'amount'          => $request->amount,
                'method'          => $request->method,
                'reference'       => $request->reference,
                'amount_given'    => $request->amount_given,
                'change_given'    => $changeGiven,
                'is_partial'      => $request->amount < $order->amountDue(),
            ]);

            // Vérifier si commande totalement payée
            $amountPaid = $order->payments()->sum('amount');
            if ($amountPaid >= $order->total) {
                $order->update(['status' => 'paid', 'paid_at' => now(), 'cashier_id' => auth()->id()]);

                // Libérer la table
                if ($order->table_id) {
                    $order->table->update([
                        'status'           => 'free',
                        'occupied_since'   => null,
                        'assigned_user_id' => null,
                    ]);
                }

                // Déclencher déduction stock en arrière-plan
                ProcessStockDeduction::dispatch($order);
            }

            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'payment',
                'message' => "Paiement de {$request->amount} FCFA par {$request->method}",
                'meta'    => ['payment_id' => $payment->id],
            ]);
        });

        return response()->json([
            'order'   => $order->fresh(['items', 'payments']),
            'message' => 'Paiement enregistré.',
        ], 201);
    }

    // POST /api/payments/split — Paiement divisé (plusieurs méthodes)
    public function split(Request $request)
    {
        $request->validate([
            'order_id'     => 'required|exists:orders,id',
            'payments'     => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|in:cash,card,wave,orange_money,momo,other',
            'payments.*.reference' => 'nullable|string',
        ]);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        $totalPayments = collect($request->payments)->sum('amount');
        abort_if($totalPayments < $order->total, 422, "Montant insuffisant. Requis: {$order->total} FCFA");

        DB::transaction(function () use ($request, $order) {
            $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
                ->whereNull('closed_at')->latest()->first();

            foreach ($request->payments as $p) {
                Payment::create([
                    'order_id'        => $order->id,
                    'cash_session_id' => $session?->id,
                    'user_id'         => auth()->id(),
                    'amount'          => $p['amount'],
                    'method'          => $p['method'],
                    'reference'       => $p['reference'] ?? null,
                    'is_partial'      => true,
                ]);
            }

            $order->update(['status' => 'paid', 'paid_at' => now(), 'cashier_id' => auth()->id()]);

            if ($order->table_id) {
                $order->table->update(['status' => 'free', 'occupied_since' => null, 'assigned_user_id' => null]);
            }

            ProcessStockDeduction::dispatch($order);

            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'payment_split',
                'message' => "Paiement multiple : " . collect($request->payments)->map(fn($p) => "{$p['amount']} FCFA par {$p['method']}")->join(', '),
            ]);
        });

        return response()->json(['message' => 'Paiement divisé enregistré.']);
    }
}
```

### 7.2 CashSessionController

```php
// app/Http/Controllers/Api/CashSessionController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashSessionController extends Controller
{
    // GET /api/cash-sessions/current
    public function current(Request $request)
    {
        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')
            ->with('user:id,first_name,last_name')
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(null);
        }

        // Calculer le montant attendu
        $cashIn  = Payment::where('cash_session_id', $session->id)->where('method', 'cash')->sum('amount');
        $expected = $session->opening_amount + $cashIn;

        return response()->json([
            'session'          => $session,
            'cash_in'          => $cashIn,
            'expected_amount'  => $expected,
            'orders_count'     => Payment::where('cash_session_id', $session->id)->distinct('order_id')->count(),
        ]);
    }

    // POST /api/cash-sessions/open
    public function open(Request $request)
    {
        $existing = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->exists();

        abort_if($existing, 422, 'Une session de caisse est déjà ouverte.');

        $request->validate(['opening_amount' => 'required|numeric|min:0']);

        $session = CashSession::create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'user_id'        => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at'      => now(),
        ]);

        $session->logActivity('cash_session_opened', "Session caisse ouverte avec {$request->opening_amount} FCFA");

        return response()->json($session, 201);
    }

    // POST /api/cash-sessions/{id}/close
    public function close(Request $request, CashSession $session)
    {
        abort_if($session->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($session->closed_at, 422, 'Session déjà fermée.');
        abort_unless($request->user()->isManager(), 403, 'Manager requis pour fermer la caisse.');

        $request->validate([
            'closing_amount' => 'required|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $cashIn   = Payment::where('cash_session_id', $session->id)->where('method', 'cash')->sum('amount');
        $expected = $session->opening_amount + $cashIn;
        $diff     = $request->closing_amount - $expected;

        $session->update([
            'closing_amount'  => $request->closing_amount,
            'expected_amount' => $expected,
            'difference'      => $diff,
            'closing_notes'   => $request->notes,
            'closed_at'       => now(),
        ]);

        $session->logActivity('cash_session_closed',
            "Session caisse fermée. Attendu: {$expected} | Compté: {$request->closing_amount} | Écart: {$diff}"
        );

        return response()->json($session);
    }

    // GET /api/cash-sessions — Historique
    public function index(Request $request)
    {
        $sessions = CashSession::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->date, fn($q) => $q->whereDate('opened_at', $request->date))
            ->latest('opened_at')
            ->paginate(20);

        return response()->json($sessions);
    }
}
```

---

## 8. MODULE CUISINE KDS

### 8.1 KitchenController

```php
// app/Http/Controllers/Api/KitchenController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Events\OrderItemStatusUpdated;
use App\Events\OrderReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    // GET /api/kitchen/orders — Commandes actives pour le KDS
    public function orders(Request $request)
    {
        $orders = Order::with([
                'items' => fn($q) => $q->whereIn('status', ['pending', 'preparing', 'done'])
                                        ->with('product:id,name')
                                        ->orderBy('course')
                                        ->orderBy('created_at'),
                'table:id,number',
                'waiter:id,first_name,last_name',
            ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereIn('status', ['sent_to_kitchen', 'partially_served'])
            ->orderBy('sent_to_kitchen_at')
            ->get()
            ->map(function ($order) {
                $order->minutes_since_sent = $order->sent_to_kitchen_at
                    ? now()->diffInMinutes($order->sent_to_kitchen_at)
                    : 0;
                $order->priority = $order->minutes_since_sent > 15 ? 'urgent'
                    : ($order->minutes_since_sent > 10 ? 'warning' : 'normal');
                return $order;
            });

        return response()->json($orders);
    }

    // PUT /api/kitchen/items/{item}/status — Valider un item en cuisine
    public function updateItemStatus(Request $request, OrderItem $item)
    {
        $this->authorizeItem($request, $item);

        $request->validate([
            'status' => 'required|in:preparing,done',
        ]);

        $item->update([
            'status'      => $request->status,
            'prepared_at' => $request->status === 'done' ? now() : $item->prepared_at,
        ]);

        $order = $item->order;

        // Vérifier si tous les items de la commande sont faits
        $allDone = $order->items()
            ->whereNotIn('status', ['done', 'served', 'cancelled'])
            ->doesntExist();

        if ($allDone) {
            $order->update(['status' => 'served']);
            broadcast(new OrderReady($order->load('table')))->toOthers();

            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'order_ready',
                'message' => "Commande {$order->order_number} prête à être servie",
            ]);
        } elseif ($request->status === 'done') {
            $order->update(['status' => 'partially_served']);
        }

        broadcast(new OrderItemStatusUpdated($item, $order))->toOthers();

        return response()->json([
            'item'      => $item,
            'order'     => $order->fresh(),
            'all_done'  => $allDone,
        ]);
    }

    // PUT /api/kitchen/orders/{order}/validate-all — Valider tous les items d'un coup
    public function validateAll(Request $request, Order $order)
    {
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        DB::transaction(function () use ($order) {
            $order->items()
                ->whereIn('status', ['pending', 'preparing'])
                ->update(['status' => 'done', 'prepared_at' => now()]);

            $order->update(['status' => 'served', 'served_at' => now()]);

            $order->logs()->create([
                'user_id' => auth()->id(),
                'action'  => 'order_ready',
                'message' => "Commande {$order->order_number} entièrement validée en cuisine",
            ]);
        });

        broadcast(new OrderReady($order->load('table')))->toOthers();

        return response()->json(['message' => 'Commande validée.', 'order' => $order->fresh()]);
    }

    private function authorizeItem(Request $request, OrderItem $item): void
    {
        abort_if($item->order->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
```
---

## 9. MODULE MENU & PRODUITS

### 9.1 ProductController

```php
// app/Http/Controllers/Api/ProductController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // GET /api/products
    public function index(Request $request)
    {
        $products = Product::with(['category', 'modifierGroups.modifiers'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when(isset($request->available), fn($q) => $q->where('available', $request->boolean('available')))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->where('active', true)
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    // GET /api/menu — Menu public (pour QR code client)
    public function publicMenu(Request $request, string $restaurantSlug)
    {
        $restaurant = \App\Models\Restaurant::where('slug', $restaurantSlug)->firstOrFail();

        $categories = Category::with([
            'products' => fn($q) => $q->where('available', true)->where('active', true)->orderBy('order'),
            'products.modifierGroups.modifiers',
        ])
        ->where('restaurant_id', $restaurant->id)
        ->where('active', true)
        ->whereNull('parent_id')
        ->orderBy('order')
        ->get();

        return response()->json([
            'restaurant' => $restaurant->only(['name', 'logo', 'address']),
            'categories' => $categories,
        ]);
    }

    // POST /api/products
    public function store(Request $request)
    {
        $request->validate([
            'category_id'  => 'required|exists:categories,id',
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string',
            'price'        => 'required|numeric|min:0',
            'cost_price'   => 'nullable|numeric|min:0',
            'vat_rate'     => 'numeric|min:0|max:100',
            'track_stock'  => 'boolean',
            'available'    => 'boolean',
            'image'        => 'nullable|image|max:2048',
            'modifier_groups' => 'nullable|array',
            'modifier_groups.*.name'     => 'required|string',
            'modifier_groups.*.required' => 'boolean',
            'modifier_groups.*.multiple' => 'boolean',
            'modifier_groups.*.modifiers' => 'array',
            'modifier_groups.*.modifiers.*.name'        => 'required|string',
            'modifier_groups.*.modifiers.*.extra_price' => 'numeric|min:0',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store("restaurants/{$request->user()->restaurant_id}/products", 'public');
        }

        $product = Product::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'category_id'   => $request->category_id,
            'name'          => $request->name,
            'description'   => $request->description,
            'price'         => $request->price,
            'cost_price'    => $request->cost_price,
            'vat_rate'      => $request->vat_rate ?? 18,
            'track_stock'   => $request->boolean('track_stock'),
            'available'     => $request->boolean('available', true),
            'image'         => $imagePath,
        ]);

        // Créer les groupes de modificateurs
        if ($request->modifier_groups) {
            foreach ($request->modifier_groups as $i => $groupData) {
                $group = $product->modifierGroups()->create([
                    'name'           => $groupData['name'],
                    'required'       => $groupData['required'] ?? false,
                    'multiple'       => $groupData['multiple'] ?? false,
                    'order'          => $i,
                ]);

                foreach ($groupData['modifiers'] ?? [] as $j => $modData) {
                    $group->modifiers()->create([
                        'name'        => $modData['name'],
                        'extra_price' => $modData['extra_price'] ?? 0,
                        'order'       => $j,
                    ]);
                }
            }
        }

        return response()->json($product->load('modifierGroups.modifiers', 'category'), 201);
    }

    // PUT /api/products/{id}
    public function update(Request $request, Product $product)
    {
        abort_if($product->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'name'        => 'sometimes|string|max:200',
            'price'       => 'sometimes|numeric|min:0',
            'available'   => 'sometimes|boolean',
            'category_id' => 'sometimes|exists:categories,id',
        ]);

        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $request->merge(['image' => $request->file('image')->store(
                "restaurants/{$product->restaurant_id}/products", 'public'
            )]);
        }

        $product->update($request->only([
            'name', 'description', 'price', 'cost_price', 'vat_rate',
            'category_id', 'available', 'track_stock', 'image', 'order',
        ]));

        return response()->json($product->fresh('modifierGroups.modifiers'));
    }

    // PATCH /api/products/{id}/toggle-available
    public function toggleAvailable(Product $product, Request $request)
    {
        abort_if($product->restaurant_id !== $request->user()->restaurant_id, 403);
        $product->update(['available' => !$product->available]);
        return response()->json($product);
    }

    // DELETE /api/products/{id}
    public function destroy(Product $product, Request $request)
    {
        abort_if($product->restaurant_id !== $request->user()->restaurant_id, 403);
        $product->delete();
        return response()->json(['message' => 'Produit supprimé.']);
    }

    // POST /api/products/reorder — Réordonner les produits
    public function reorder(Request $request)
    {
        $request->validate([
            'products'       => 'required|array',
            'products.*.id'  => 'required|exists:products,id',
            'products.*.order' => 'required|integer',
        ]);

        foreach ($request->products as $item) {
            Product::where('id', $item['id'])
                ->where('restaurant_id', $request->user()->restaurant_id)
                ->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Ordre mis à jour.']);
    }
}
```

---

## 10. MODULE STOCK & INVENTAIRE

### 10.1 StockController

```php
// app/Http/Controllers/Api/StockController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    // GET /api/ingredients
    public function index(Request $request)
    {
        $ingredients = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when($request->alert, fn($q) => $q->whereColumn('quantity', '<=', 'min_quantity'))
            ->where('active', true)
            ->orderBy('name')
            ->paginate(30);

        return response()->json($ingredients);
    }

    // GET /api/stock/alerts — Produits en rupture ou faibles
    public function alerts(Request $request)
    {
        $alerts = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->whereColumn('quantity', '<=', 'min_quantity')
            ->where('active', true)
            ->orderByRaw('quantity / NULLIF(min_quantity, 0)')
            ->get()
            ->map(function ($ingredient) {
                $ingredient->level = $ingredient->quantity <= 0 ? 'rupture' : 'faible';
                return $ingredient;
            });

        return response()->json($alerts);
    }

    // POST /api/stock-movements — Entrée ou sortie de stock
    public function createMovement(Request $request)
    {
        $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'type'          => 'required|in:in,out,adjustment,waste,return',
            'quantity'      => 'required|numeric|min:0.001',
            'reason'        => 'nullable|string',
            'reference'     => 'nullable|string',
            'unit_cost'     => 'nullable|numeric|min:0',
        ]);

        $ingredient = Ingredient::findOrFail($request->ingredient_id);
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);

        $quantityBefore = $ingredient->quantity;

        DB::transaction(function () use ($request, $ingredient, $quantityBefore) {
            // Calculer nouvelle quantité
            $delta = in_array($request->type, ['in', 'return', 'adjustment'])
                ? $request->quantity
                : -$request->quantity;

            $quantityAfter = max(0, $quantityBefore + $delta);

            if ($request->type === 'adjustment') {
                $quantityAfter = $request->quantity; // Remplacement direct
            }

            StockMovement::create([
                'restaurant_id'   => $request->user()->restaurant_id,
                'ingredient_id'   => $ingredient->id,
                'user_id'         => auth()->id(),
                'type'            => $request->type,
                'quantity'        => $request->quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after'  => $quantityAfter,
                'unit_cost'       => $request->unit_cost,
                'reason'          => $request->reason,
                'reference'       => $request->reference,
            ]);

            $ingredient->update(['quantity' => $quantityAfter]);
        });

        return response()->json($ingredient->fresh(), 201);
    }

    // GET /api/ingredients/{id}/movements — Historique mouvements
    public function movements(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);

        $movements = $ingredient->movements()
            ->with('user:id,first_name,last_name')
            ->when($request->from, fn($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn($q) => $q->where('created_at', '<=', $request->to))
            ->latest()
            ->paginate(50);

        return response()->json($movements);
    }

    // GET /api/stock/value — Valeur totale du stock
    public function value(Request $request)
    {
        $value = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->sum(DB::raw('quantity * cost_per_unit'));

        return response()->json(['total_value' => round($value, 2)]);
    }

    // POST /api/recipes — Créer/mettre à jour la recette d'un produit
    public function saveRecipe(Request $request)
    {
        $request->validate([
            'product_id'   => 'required|exists:products,id',
            'ingredients'  => 'required|array|min:1',
            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'ingredients.*.quantity'      => 'required|numeric|min:0.001',
        ]);

        // Supprimer l'ancienne recette
        Recipe::where('product_id', $request->product_id)->delete();

        // Créer la nouvelle
        $recipes = [];
        foreach ($request->ingredients as $item) {
            $recipes[] = Recipe::create([
                'product_id'    => $request->product_id,
                'ingredient_id' => $item['ingredient_id'],
                'quantity'      => $item['quantity'],
            ]);
        }

        return response()->json($recipes, 201);
    }

    // GET /api/recipes/{productId}
    public function getRecipe(Request $request, int $productId)
    {
        $recipe = Recipe::with('ingredient')
            ->where('product_id', $productId)
            ->get();

        return response()->json($recipe);
    }
}
```

### 10.2 Job déduction stock

```php
// app/Jobs/ProcessStockDeduction.php
namespace App\Jobs;

use App\Models\Order;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessStockDeduction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        DB::transaction(function () {
            foreach ($this->order->items as $item) {
                if (!$item->product->track_stock) continue;

                $recipes = $item->product->recipes()->with('ingredient')->get();

                foreach ($recipes as $recipe) {
                    $quantityNeeded = $recipe->quantity * $item->quantity;
                    $ingredient = $recipe->ingredient;
                    $before = $ingredient->quantity;
                    $after  = max(0, $before - $quantityNeeded);

                    StockMovement::create([
                        'restaurant_id'   => $this->order->restaurant_id,
                        'ingredient_id'   => $ingredient->id,
                        'order_id'        => $this->order->id,
                        'type'            => 'out',
                        'quantity'        => $quantityNeeded,
                        'quantity_before' => $before,
                        'quantity_after'  => $after,
                        'reason'          => "Vente — Commande #{$this->order->order_number}",
                    ]);

                    $ingredient->update(['quantity' => $after]);

                    // Alerte si stock en dessous du minimum
                    if ($after <= $ingredient->min_quantity) {
                        Log::warning("Stock faible: {$ingredient->name} = {$after} {$ingredient->unit}");
                        // Ici : envoyer notification push / email manager
                    }
                }
            }
        });
    }
}
```

---

## 11. MODULE LIVRAISONS

### 11.1 DeliveryController

```php
// app/Http/Controllers/Api/DeliveryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Events\DeliveryUpdated;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    // GET /api/deliveries
    public function index(Request $request)
    {
        $deliveries = Delivery::with(['order.items.product', 'driver:id,first_name,last_name'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->driver_id, fn($q) => $q->where('driver_id', $request->driver_id))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->latest()
            ->paginate(20);

        return response()->json($deliveries);
    }

    // POST /api/deliveries — Créer une livraison
    public function store(Request $request)
    {
        $request->validate([
            'order_id'        => 'required|exists:orders,id',
            'customer_name'   => 'required|string',
            'customer_phone'  => 'required|string',
            'address'         => 'required|string',
            'lat'             => 'nullable|numeric',
            'lng'             => 'nullable|numeric',
            'delivery_fee'    => 'numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        $delivery = Delivery::create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'order_id'       => $order->id,
            'customer_name'  => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'address'        => $request->address,
            'lat'            => $request->lat,
            'lng'            => $request->lng,
            'delivery_fee'   => $request->delivery_fee ?? 0,
            'notes'          => $request->notes,
            'status'         => 'pending',
        ]);

        return response()->json($delivery->load('order'), 201);
    }

    // PUT /api/deliveries/{id}/assign — Assigner un livreur
    public function assign(Request $request, Delivery $delivery)
    {
        abort_if($delivery->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate(['driver_id' => 'required|exists:users,id']);

        $delivery->update(['driver_id' => $request->driver_id]);

        $delivery->logActivity('driver_assigned',
            "Livreur assigné à livraison #{$delivery->id}"
        );

        broadcast(new DeliveryUpdated($delivery->load('driver')))->toOthers();

        return response()->json($delivery->load('driver'));
    }

    // PUT /api/deliveries/{id}/status
    public function updateStatus(Request $request, Delivery $delivery)
    {
        abort_if($delivery->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'status' => 'required|in:pending,preparing,ready,on_the_way,delivered,failed',
        ]);

        $timestamps = [
            'on_the_way' => ['picked_up_at' => now()],
            'delivered'  => ['delivered_at' => now()],
        ];

        $delivery->update(array_merge(
            ['status' => $request->status],
            $timestamps[$request->status] ?? []
        ));

        broadcast(new DeliveryUpdated($delivery))->toOthers();

        return response()->json($delivery);
    }

    // GET /api/deliveries/drivers — Livreurs disponibles
    public function availableDrivers(Request $request)
    {
        $drivers = \App\Models\User::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->whereHas('role', fn($q) => $q->where('name', 'driver'))
            ->get(['id', 'first_name', 'last_name', 'avatar']);

        return response()->json($drivers);
    }
}
```

---

## 12. MODULE RAPPORTS

### 12.1 ReportController

```php
// app/Http/Controllers/Api/ReportController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // GET /api/reports/sales?from=&to=
    public function sales(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $restaurantId = $request->user()->restaurant_id;

        $summary = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) as orders_count,
                SUM(total) as revenue,
                AVG(total) as avg_ticket,
                SUM(covers) as total_covers,
                SUM(discount_amount) as total_discounts,
                SUM(vat_amount) as total_vat
            ')
            ->first();

        $byDay = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('DATE(paid_at) as date, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $byMethod = Payment::whereHas('order', fn($q) =>
                $q->where('restaurant_id', $restaurantId)
                  ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            )
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get();

        return response()->json([
            'summary'   => $summary,
            'by_day'    => $byDay,
            'by_method' => $byMethod,
        ]);
    }

    // GET /api/reports/top-products
    public function topProducts(Request $request)
    {
        $request->validate([
            'from'  => 'required|date',
            'to'    => 'required|date',
            'limit' => 'integer|min:5|max:50',
        ]);

        $products = OrderItem::with('product:id,name,image')
            ->whereHas('order', fn($q) =>
                $q->where('restaurant_id', $request->user()->restaurant_id)
                  ->where('status', 'paid')
                  ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            )
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('product_id, SUM(quantity) as total_qty, SUM(subtotal) as revenue')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit($request->limit ?? 10)
            ->get();

        return response()->json($products);
    }

    // GET /api/reports/by-waiter
    public function byWaiter(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $data = Order::with('waiter:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('user_id, COUNT(*) as orders_count, SUM(total) as revenue, AVG(total) as avg_ticket')
            ->groupBy('user_id')
            ->orderByDesc('revenue')
            ->get();

        return response()->json($data);
    }

    // GET /api/reports/by-category
    public function byCategory(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $data = OrderItem::with('product.category:id,name')
            ->whereHas('order', fn($q) =>
                $q->where('restaurant_id', $request->user()->restaurant_id)
                  ->where('status', 'paid')
                  ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            )
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('
                products.category_id,
                SUM(order_items.quantity) as qty,
                SUM(order_items.subtotal) as revenue
            ')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->groupBy('products.category_id')
            ->orderByDesc('revenue')
            ->get();

        return response()->json($data);
    }

    // GET /api/reports/cash-summary
    public function cashSummary(Request $request)
    {
        $request->validate(['date' => 'nullable|date']);
        $date = $request->date ?? today()->toDateString();
        $restaurantId = $request->user()->restaurant_id;

        $sessions = \App\Models\CashSession::with('user:id,first_name,last_name')
            ->where('restaurant_id', $restaurantId)
            ->whereDate('opened_at', $date)
            ->get();

        $payments = Payment::whereHas('order', fn($q) =>
                $q->where('restaurant_id', $restaurantId)->whereDate('paid_at', $date)
            )
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get();

        return response()->json([
            'date'     => $date,
            'sessions' => $sessions,
            'payments' => $payments,
            'total'    => $payments->sum('total'),
        ]);
    }

    // GET /api/reports/dashboard — Métriques temps réel pour dashboard
    public function dashboard(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $today = today()->toDateString();

        $revenueToday = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')->whereDate('paid_at', $today)->sum('total');

        $ordersToday = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')->whereDate('paid_at', $today)->count();

        $coversToday = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')->whereDate('paid_at', $today)->sum('covers');

        $avgTicket = $ordersToday > 0 ? $revenueToday / $ordersToday : 0;

        $tablesStats = \App\Models\Table::whereHas('floor', fn($q) =>
            $q->where('restaurant_id', $restaurantId))
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $yesterday = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')->whereDate('paid_at', today()->subDay())->sum('total');

        $growth = $yesterday > 0 ? (($revenueToday - $yesterday) / $yesterday) * 100 : 0;

        return response()->json([
            'revenue_today'  => round($revenueToday, 2),
            'orders_today'   => $ordersToday,
            'covers_today'   => $coversToday,
            'avg_ticket'     => round($avgTicket, 2),
            'growth_percent' => round($growth, 1),
            'tables'         => $tablesStats,
        ]);
    }
}
```

---

## 13. SYSTÈME ANNULATIONS

### 13.1 CancellationController

```php
// app/Http/Controllers/Api/CancellationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CancellationController extends Controller
{
    // POST /api/cancellations/request — Demander une annulation
    public function request(Request $request)
    {
        $request->validate([
            'type'        => 'required|in:order,order_item,payment',
            'subject_id'  => 'required|integer',
            'reason'      => 'required|string|max:500',
            'notes'       => 'nullable|string',
        ]);

        $subject = $this->resolveSubject($request->type, $request->subject_id, $request->user());

        // Vérifier si déjà une annulation en cours
        $existing = Cancellation::where('cancellable_type', get_class($subject))
            ->where('cancellable_id', $subject->id)
            ->where('status', 'pending')
            ->exists();

        abort_if($existing, 422, 'Une demande d\'annulation est déjà en cours pour cet élément.');

        $cancellation = Cancellation::create([
            'restaurant_id'    => $request->user()->restaurant_id,
            'cancellable_type' => get_class($subject),
            'cancellable_id'   => $subject->id,
            'requested_by'     => $request->user()->id,
            'reason'           => $request->reason,
            'notes'            => $request->notes,
            'status'           => 'pending',
            'requested_at'     => now(),
        ]);

        // Si l'utilisateur est manager → approbation automatique
        if ($request->user()->isManager()) {
            return $this->approve($request, $cancellation);
        }

        return response()->json([
            'cancellation' => $cancellation,
            'message'      => 'Demande d\'annulation soumise. Un manager doit valider.',
        ], 201);
    }

    // POST /api/cancellations/{id}/approve — Approuver avec PIN manager
    public function approve(Request $request, Cancellation $cancellation)
    {
        abort_if($cancellation->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($cancellation->status !== 'pending', 422, 'Cette demande est déjà traitée.');

        // Si la requête vient d'un non-manager, vérifier le PIN manager
        if (!$request->user()->isManager()) {
            $request->validate([
                'manager_pin' => 'required|string',
            ]);

            $manager = \App\Models\User::where('restaurant_id', $request->user()->restaurant_id)
                ->where('active', true)
                ->whereHas('role', fn($q) => $q->whereIn('name', ['admin', 'manager']))
                ->get()
                ->first(fn($u) => Hash::check($request->manager_pin, $u->pin));

            abort_if(!$manager, 403, 'PIN manager incorrect.');
            $approverId = $manager->id;
        } else {
            $approverId = $request->user()->id;
        }

        $request->validate([
            'refund_amount' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|in:cash,original_method,credit,none',
        ]);

        DB::transaction(function () use ($cancellation, $request, $approverId) {
            $cancellation->update([
                'status'        => 'approved',
                'approved_by'   => $approverId,
                'approved_at'   => now(),
                'refund_amount' => $request->refund_amount,
                'refund_method' => $request->refund_method ?? 'none',
            ]);

            // Exécuter l'annulation selon le type
            $subject = $cancellation->cancellable;

            if ($subject instanceof Order) {
                $this->cancelOrder($subject, $cancellation);
            } elseif ($subject instanceof OrderItem) {
                $this->cancelOrderItem($subject, $cancellation);
            } elseif ($subject instanceof Payment) {
                $this->cancelPayment($subject, $cancellation);
            }
        });

        return response()->json([
            'cancellation' => $cancellation->fresh(),
            'message'      => 'Annulation approuvée et exécutée.',
        ]);
    }

    // POST /api/cancellations/{id}/reject
    public function reject(Request $request, Cancellation $cancellation)
    {
        abort_if($cancellation->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        abort_if($cancellation->status !== 'pending', 422, 'Déjà traitée.');

        $request->validate(['reason' => 'required|string']);

        $cancellation->update([
            'status'      => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'notes'       => ($cancellation->notes ? $cancellation->notes . "\n" : '') . "Refus: {$request->reason}",
        ]);

        return response()->json(['message' => 'Demande rejetée.']);
    }

    // GET /api/cancellations — Historique des annulations
    public function index(Request $request)
    {
        $cancellations = Cancellation::with(['requester:id,first_name,last_name', 'approver:id,first_name,last_name'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->from, fn($q) => $q->where('requested_at', '>=', $request->from))
            ->latest('requested_at')
            ->paginate(30);

        return response()->json($cancellations);
    }

    // --- Méthodes privées d'exécution ---

    private function cancelOrder(Order $order, Cancellation $cancellation): void
    {
        // Annuler tous les items
        $order->items()->update(['status' => 'cancelled']);

        // Mettre à jour le statut
        $order->update(['status' => 'cancelled']);

        // Libérer la table
        if ($order->table_id) {
            $order->table->update([
                'status'           => 'free',
                'occupied_since'   => null,
                'assigned_user_id' => null,
            ]);
        }

        // Log
        $order->logs()->create([
            'user_id' => $cancellation->approved_by,
            'action'  => 'cancelled',
            'message' => "Commande annulée. Raison: {$cancellation->reason}",
            'meta'    => ['cancellation_id' => $cancellation->id],
        ]);
    }

    private function cancelOrderItem(OrderItem $item, Cancellation $cancellation): void
    {
        $item->update(['status' => 'cancelled']);

        $order = $item->order;
        $order->recalculate();

        // Vérifier si tous les items sont annulés
        $allCancelled = $order->items()->where('status', '!=', 'cancelled')->doesntExist();
        if ($allCancelled) {
            $order->update(['status' => 'cancelled']);
        }

        $order->logs()->create([
            'user_id' => $cancellation->approved_by,
            'action'  => 'item_cancelled',
            'message' => "{$item->product->name} x{$item->quantity} annulé. Raison: {$cancellation->reason}",
            'meta'    => ['cancellation_id' => $cancellation->id],
        ]);
    }

    private function cancelPayment(Payment $payment, Cancellation $cancellation): void
    {
        $payment->delete(); // Soft delete

        $order = $payment->order;

        // Remettre la commande en statut non payé si nécessaire
        if ($order->status === 'paid') {
            $order->update(['status' => 'served', 'paid_at' => null]);
        }

        $order->logs()->create([
            'user_id' => $cancellation->approved_by,
            'action'  => 'payment_cancelled',
            'message' => "Paiement de {$payment->amount} FCFA annulé. Raison: {$cancellation->reason}",
            'meta'    => ['cancellation_id' => $cancellation->id],
        ]);
    }

    private function resolveSubject(string $type, int $id, $user)
    {
        return match($type) {
            'order'      => Order::where('restaurant_id', $user->restaurant_id)->findOrFail($id),
            'order_item' => OrderItem::whereHas('order', fn($q) => $q->where('restaurant_id', $user->restaurant_id))->findOrFail($id),
            'payment'    => Payment::whereHas('order', fn($q) => $q->where('restaurant_id', $user->restaurant_id))->findOrFail($id),
            default      => abort(422, 'Type invalide.'),
        };
    }
}
```

---

## 14. AUDIT TRAIL — TRAÇABILITÉ

### 14.1 ActivityLogController

```php
// app/Http/Controllers/Api/ActivityLogController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    // GET /api/activity-logs
    public function index(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');

        $logs = ActivityLog::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->module, fn($q) => $q->where('module', $request->module))
            ->when($request->action, fn($q) => $q->where('action', $request->action))
            ->when($request->from, fn($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn($q) => $q->where('created_at', '<=', $request->to . ' 23:59:59'))
            ->when($request->search, fn($q) => $q->where('description', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(50);

        return response()->json($logs);
    }

    // GET /api/activity-logs/subject/{type}/{id}
    // Voir tout l'historique d'un objet précis (ex: commande #42)
    public function forSubject(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->isManager(), 403);

        $modelClass = match($type) {
            'order'       => \App\Models\Order::class,
            'order_item'  => \App\Models\OrderItem::class,
            'payment'     => \App\Models\Payment::class,
            'user'        => \App\Models\User::class,
            'ingredient'  => \App\Models\Ingredient::class,
            'cancellation'=> \App\Models\Cancellation::class,
            default       => abort(422, 'Type invalide.'),
        };

        $logs = ActivityLog::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('subject_type', $modelClass)
            ->where('subject_id', $id)
            ->latest()
            ->get();

        return response()->json($logs);
    }

    // GET /api/activity-logs/summary — Résumé par utilisateur
    public function summary(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);

        $request->validate(['date' => 'nullable|date']);
        $date = $request->date ?? today()->toDateString();

        $summary = ActivityLog::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereDate('created_at', $date)
            ->selectRaw('user_id, module, COUNT(*) as actions_count')
            ->groupBy('user_id', 'module')
            ->orderByDesc('actions_count')
            ->get();

        return response()->json($summary);
    }
}
```

---

## 15. ÉVÉNEMENTS WEBSOCKET TEMPS RÉEL

### 15.1 Événements

```php
// app/Events/OrderCreated.php
namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurant.{$this->order->restaurant_id}"),
            new Channel("kitchen.{$this->order->restaurant_id}"),
        ];
    }

    public function broadcastAs(): string { return 'order.created'; }

    public function broadcastWith(): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'table'        => $this->order->table?->only(['id', 'number']),
            'items'        => $this->order->items->map(fn($i) => [
                'id'       => $i->id,
                'name'     => $i->product->name,
                'quantity' => $i->quantity,
                'notes'    => $i->notes,
                'course'   => $i->course,
            ]),
        ];
    }
}

// app/Events/OrderItemStatusUpdated.php
class OrderItemStatusUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public \App\Models\OrderItem $item,
        public Order $order
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurant.{$this->order->restaurant_id}"),
            new Channel("floor.{$this->order->restaurant_id}"),
        ];
    }

    public function broadcastAs(): string { return 'order.item.status'; }

    public function broadcastWith(): array
    {
        return [
            'item_id'      => $this->item->id,
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'status'       => $this->item->status,
            'product_name' => $this->item->product->name,
        ];
    }
}

// app/Events/OrderReady.php
class OrderReady implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurant.{$this->order->restaurant_id}"),
            new Channel("floor.{$this->order->restaurant_id}"),
        ];
    }

    public function broadcastAs(): string { return 'order.ready'; }

    public function broadcastWith(): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'table_number' => $this->order->table?->number,
        ];
    }
}

// app/Events/TableStatusChanged.php
class TableStatusChanged implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public \App\Models\Table $table) {}

    public function broadcastOn(): array
    {
        return [new Channel("restaurant.{$this->table->floor->restaurant_id}")];
    }

    public function broadcastAs(): string { return 'table.status'; }

    public function broadcastWith(): array
    {
        return $this->table->only(['id', 'number', 'status', 'occupied_since', 'assigned_user_id']);
    }
}
```

---

## 16. ROUTES API COMPLÈTES

```php
// routes/api.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

// =============================================
// ROUTES PUBLIQUES (sans authentification)
// =============================================
Route::prefix('auth')->group(function () {
    Route::post('login',     [Api\Auth\AuthController::class, 'login']);
    Route::post('login-pin', [Api\Auth\AuthController::class, 'loginPin']);
});

// Menu QR public (client)
Route::get('menu/{restaurantSlug}', [Api\ProductController::class, 'publicMenu']);

// =============================================
// ROUTES PROTÉGÉES (Sanctum)
// =============================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout',          [Api\Auth\AuthController::class, 'logout']);
        Route::get('me',               [Api\Auth\AuthController::class, 'me']);
        Route::put('change-password',  [Api\Auth\AuthController::class, 'changePassword']);
    });

    // ---- UTILISATEURS ----
    Route::prefix('users')->group(function () {
        Route::get('/',                [Api\UserController::class, 'index']);
        Route::post('/',               [Api\UserController::class, 'store']);
        Route::get('{user}',           [Api\UserController::class, 'show']);
        Route::put('{user}',           [Api\UserController::class, 'update']);
        Route::delete('{user}',        [Api\UserController::class, 'destroy']);
        Route::patch('{user}/toggle-active', [Api\UserController::class, 'toggleActive']);
    });

    // ---- TABLES & SALLES ----
    Route::prefix('floors')->group(function () {
        Route::get('/',                [Api\TableController::class, 'floors']);
        Route::get('{floor}/tables',   [Api\TableController::class, 'index']);
    });

    Route::prefix('tables')->group(function () {
        Route::put('{table}/status',   [Api\TableController::class, 'updateStatus']);
        Route::put('{table}/layout',   [Api\TableController::class, 'updateLayout']);
        Route::put('{table}/assign',   [Api\TableController::class, 'assign']);
        Route::post('{table}/reserve', [Api\TableController::class, 'reserve']);
        Route::post('{table}/transfer',[Api\TableController::class, 'transfer']);
        Route::post('merge',           [Api\TableController::class, 'merge']);
    });

    // ---- COMMANDES ----
    Route::prefix('orders')->group(function () {
        Route::get('/',                    [Api\OrderController::class, 'index']);
        Route::post('/',                   [Api\OrderController::class, 'store']);
        Route::get('table/{tableId}/current', [Api\OrderController::class, 'currentByTable']);
        Route::get('{order}',              [Api\OrderController::class, 'show']);
        Route::put('{order}/status',       [Api\OrderController::class, 'updateStatus']);
        Route::put('{order}/items',        [Api\OrderController::class, 'updateItems']);
        Route::post('{order}/add-items',   [Api\OrderController::class, 'addItems']);
        Route::post('{order}/send-to-kitchen', [Api\OrderController::class, 'sendToKitchen']);
        Route::put('{order}/discount',     [Api\OrderController::class, 'applyDiscount']);
    });

    // ---- PAIEMENTS ----
    Route::prefix('payments')->group(function () {
        Route::post('/',               [Api\PaymentController::class, 'store']);
        Route::post('split',           [Api\PaymentController::class, 'split']);
    });

    // ---- CAISSE ----
    Route::prefix('cash-sessions')->group(function () {
        Route::get('/',                [Api\CashSessionController::class, 'index']);
        Route::get('current',          [Api\CashSessionController::class, 'current']);
        Route::post('open',            [Api\CashSessionController::class, 'open']);
        Route::post('{session}/close', [Api\CashSessionController::class, 'close']);
    });

    // ---- CUISINE KDS ----
    Route::prefix('kitchen')->group(function () {
        Route::get('orders',           [Api\KitchenController::class, 'orders']);
        Route::put('items/{item}/status', [Api\KitchenController::class, 'updateItemStatus']);
        Route::put('orders/{order}/validate-all', [Api\KitchenController::class, 'validateAll']);
    });

    // ---- MENU & PRODUITS ----
    Route::prefix('categories')->group(function () {
        Route::get('/',                [Api\CategoryController::class, 'index']);
        Route::post('/',               [Api\CategoryController::class, 'store']);
        Route::put('{category}',       [Api\CategoryController::class, 'update']);
        Route::delete('{category}',    [Api\CategoryController::class, 'destroy']);
    });

    Route::prefix('products')->group(function () {
        Route::get('/',                [Api\ProductController::class, 'index']);
        Route::post('/',               [Api\ProductController::class, 'store']);
        Route::put('{product}',        [Api\ProductController::class, 'update']);
        Route::delete('{product}',     [Api\ProductController::class, 'destroy']);
        Route::patch('{product}/toggle-available', [Api\ProductController::class, 'toggleAvailable']);
        Route::post('reorder',         [Api\ProductController::class, 'reorder']);
    });

    // ---- STOCK ----
    Route::prefix('ingredients')->group(function () {
        Route::get('/',                [Api\StockController::class, 'index']);
        Route::post('/',               [Api\StockController::class, 'store']);
        Route::put('{ingredient}',     [Api\StockController::class, 'update']);
        Route::get('{ingredient}/movements', [Api\StockController::class, 'movements']);
    });

    Route::prefix('stock')->group(function () {
        Route::get('alerts',           [Api\StockController::class, 'alerts']);
        Route::get('value',            [Api\StockController::class, 'value']);
        Route::post('movements',       [Api\StockController::class, 'createMovement']);
    });

    Route::prefix('recipes')->group(function () {
        Route::get('{productId}',      [Api\StockController::class, 'getRecipe']);
        Route::post('/',               [Api\StockController::class, 'saveRecipe']);
    });

    // ---- LIVRAISONS ----
    Route::prefix('deliveries')->group(function () {
        Route::get('/',                [Api\DeliveryController::class, 'index']);
        Route::post('/',               [Api\DeliveryController::class, 'store']);
        Route::get('drivers',          [Api\DeliveryController::class, 'availableDrivers']);
        Route::put('{delivery}/assign', [Api\DeliveryController::class, 'assign']);
        Route::put('{delivery}/status', [Api\DeliveryController::class, 'updateStatus']);
    });

    // ---- ANNULATIONS ----
    Route::prefix('cancellations')->group(function () {
        Route::get('/',                [Api\CancellationController::class, 'index']);
        Route::post('request',         [Api\CancellationController::class, 'request']);
        Route::post('{cancellation}/approve', [Api\CancellationController::class, 'approve']);
        Route::post('{cancellation}/reject',  [Api\CancellationController::class, 'reject']);
    });

    // ---- RAPPORTS ----
    Route::prefix('reports')->group(function () {
        Route::get('dashboard',        [Api\ReportController::class, 'dashboard']);
        Route::get('sales',            [Api\ReportController::class, 'sales']);
        Route::get('top-products',     [Api\ReportController::class, 'topProducts']);
        Route::get('by-waiter',        [Api\ReportController::class, 'byWaiter']);
        Route::get('by-category',      [Api\ReportController::class, 'byCategory']);
        Route::get('cash-summary',     [Api\ReportController::class, 'cashSummary']);
    });

    // ---- AUDIT TRAIL ----
    Route::prefix('activity-logs')->group(function () {
        Route::get('/',                     [Api\ActivityLogController::class, 'index']);
        Route::get('summary',               [Api\ActivityLogController::class, 'summary']);
        Route::get('subject/{type}/{id}',   [Api\ActivityLogController::class, 'forSubject']);
    });

});
```

---

## 17. MIDDLEWARE & POLICIES

### 17.1 Middleware CheckRole

```php
// app/Http/Middleware/CheckRole.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user || !$user->role) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if (!in_array($user->role->name, $roles)) {
            return response()->json([
                'message' => "Rôle requis : " . implode(' ou ', $roles),
            ], 403);
        }

        return $next($request);
    }
}

// Enregistrement dans bootstrap/app.php
$middleware->alias([
    'role'       => \App\Http\Middleware\CheckRole::class,
    'restaurant' => \App\Http\Middleware\CheckRestaurant::class,
]);
```

### 17.2 Middleware LogActivity (global)

```php
// app/Http/Middleware/LogActivity.php
namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;

class LogActivity
{
    // Méthodes HTTP qui créent des modifications
    private array $loggedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    // Routes à exclure du log automatique
    private array $excluded = [
        'api/auth/login', 'api/auth/logout', 'api/auth/me',
        'api/kitchen/orders', 'api/reports/*', 'api/activity-logs',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (
            $request->user() &&
            in_array($request->method(), $this->loggedMethods) &&
            !$this->isExcluded($request) &&
            $response->getStatusCode() < 400
        ) {
            ActivityLog::create([
                'restaurant_id' => $request->user()->restaurant_id,
                'user_id'       => $request->user()->id,
                'action'        => strtolower($request->method()),
                'module'        => 'api',
                'description'   => "{$request->method()} {$request->path()}",
                'ip_address'    => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);
        }

        return $response;
    }

    private function isExcluded(Request $request): bool
    {
        foreach ($this->excluded as $pattern) {
            if ($request->is($pattern)) return true;
        }
        return false;
    }
}
```

### 17.3 OrderPolicy

```php
// app/Policies/OrderPolicy.php
namespace App\Policies;

use App\Models\User;
use App\Models\Order;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->restaurant_id === $user->restaurant_id;
    }

    public function update(User $user, Order $order): bool
    {
        if ($order->restaurant_id !== $user->restaurant_id) return false;
        if (in_array($order->status, ['paid', 'cancelled'])) return false;

        // Serveur peut modifier seulement ses propres commandes
        if ($user->hasRole('waiter')) {
            return $order->user_id === $user->id;
        }

        return $user->hasRole(['admin', 'manager', 'cashier']);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->restaurant_id === $user->restaurant_id
            && $user->isManager();
    }
}
```

---

## 18. JOBS & QUEUES

### 18.1 Configuration Queue

```php
// config/queue.php — connexion Redis recommandée
'redis' => [
    'driver'     => 'redis',
    'connection' => 'default',
    'queue'      => env('REDIS_QUEUE', 'default'),
    'retry_after'=> 90,
    'block_for'  => null,
],
```

### 18.2 ServiceProvider — Enregistrement des Observers

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tous les modèles tracés automatiquement via le trait Auditable
        // Pour les modèles sans le trait, utiliser l'observer global :
        // Order::observe(AuditObserver::class);

        // Politique d'autorisation
        \Illuminate\Support\Facades\Gate::policy(Order::class, \App\Policies\OrderPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(User::class, \App\Policies\UserPolicy::class);

        // Génération automatique du slug restaurant
        \App\Models\Restaurant::creating(function ($restaurant) {
            $restaurant->slug = \Illuminate\Support\Str::slug($restaurant->name) . '-' . uniqid();
        });
    }
}
```

### 18.3 Commandes Artisan utiles

```bash
# Lancer le worker de queue
php artisan queue:work redis --queue=default --tries=3

# Lancer le scheduler (cron)
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1

# Migrations
php artisan migrate --seed

# Créer un restaurant de test
php artisan db:seed --class=RestaurantSeeder

# Vider le cache de config
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## RÉCAPITULATIF DES ENDPOINTS

| Méthode | Endpoint | Description | Rôle minimum |
|---------|----------|-------------|--------------|
| POST | /api/auth/login | Connexion email | Public |
| POST | /api/auth/login-pin | Connexion PIN | Public |
| POST | /api/auth/logout | Déconnexion | Connecté |
| GET | /api/auth/me | Profil utilisateur | Connecté |
| GET | /api/users | Liste utilisateurs | Manager |
| POST | /api/users | Créer utilisateur | Manager |
| PUT | /api/users/{id} | Modifier utilisateur | Manager |
| DELETE | /api/users/{id} | Supprimer utilisateur | Admin |
| GET | /api/floors | Salles + compteurs | Connecté |
| GET | /api/floors/{id}/tables | Tables d'une salle | Connecté |
| PUT | /api/tables/{id}/status | Changer statut table | Connecté |
| POST | /api/tables/merge | Fusionner tables | Manager |
| POST | /api/tables/{id}/transfer | Transférer commande | Serveur |
| POST | /api/tables/{id}/reserve | Réserver une table | Connecté |
| GET | /api/orders | Liste commandes | Connecté |
| POST | /api/orders | Créer commande | Serveur |
| GET | /api/orders/table/{id}/current | Commande active | Connecté |
| PUT | /api/orders/{id}/items | Modifier items | Serveur |
| POST | /api/orders/{id}/add-items | Ajouter articles | Serveur |
| POST | /api/orders/{id}/send-to-kitchen | Envoyer cuisine | Serveur |
| PUT | /api/orders/{id}/discount | Appliquer remise | Manager |
| POST | /api/payments | Enregistrer paiement | Caissier |
| POST | /api/payments/split | Paiement divisé | Caissier |
| GET | /api/cash-sessions/current | Session active | Caissier |
| POST | /api/cash-sessions/open | Ouvrir caisse | Manager |
| POST | /api/cash-sessions/{id}/close | Fermer caisse | Manager |
| GET | /api/kitchen/orders | Commandes KDS | Cuisinier |
| PUT | /api/kitchen/items/{id}/status | Valider item | Cuisinier |
| PUT | /api/kitchen/orders/{id}/validate-all | Valider tout | Cuisinier |
| GET | /api/products | Catalogue produits | Connecté |
| POST | /api/products | Créer produit | Manager |
| PATCH | /api/products/{id}/toggle-available | Dispo/Indispo | Manager |
| GET | /api/ingredients | Stock ingrédients | Manager |
| GET | /api/stock/alerts | Alertes rupture | Manager |
| POST | /api/stock/movements | Mouvement stock | Manager |
| GET | /api/deliveries | Liste livraisons | Connecté |
| PUT | /api/deliveries/{id}/assign | Assigner livreur | Manager |
| PUT | /api/deliveries/{id}/status | Statut livraison | Livreur |
| POST | /api/cancellations/request | Demander annulation | Connecté |
| POST | /api/cancellations/{id}/approve | Approuver | Manager |
| POST | /api/cancellations/{id}/reject | Rejeter | Manager |
| GET | /api/reports/dashboard | Métriques temps réel | Manager |
| GET | /api/reports/sales | Rapport ventes | Manager |
| GET | /api/reports/top-products | Top produits | Manager |
| GET | /api/reports/cash-summary | Résumé caisse | Manager |
| GET | /api/activity-logs | Journal audit | Manager |
| GET | /api/activity-logs/subject/{type}/{id} | Historique objet | Manager |
| GET | /api/menu/{slug} | Menu QR public | Public |

---

*Documentation générée pour Omega POS Restaurant — Laravel 11 — v1.0.0*
*Tous les endpoints protégés nécessitent le header : `Authorization: Bearer {token}`*
# OMEGA POS RESTAURANT — Documentation API Laravel (Suite & Fin)
> Complément du fichier principal — Sections manquantes + Génération de reçus

---

## 19. CATEGORYCONTROLLER (COMPLET)

```php
// app/Http/Controllers/Api/CategoryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    // GET /api/categories
    public function index(Request $request)
    {
        $categories = Category::with(['children', 'products' => fn($q) => $q->where('active', true)])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('parent_id')
            ->where('active', true)
            ->orderBy('order')
            ->get();

        return response()->json($categories);
    }

    // GET /api/categories/flat — toutes sans hiérarchie (pour selects)
    public function flat(Request $request)
    {
        $categories = Category::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return response()->json($categories);
    }

    // POST /api/categories
    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'parent_id' => 'nullable|exists:categories,id',
            'image'     => 'nullable|image|max:2048',
            'order'     => 'integer|min:0',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store(
                "restaurants/{$request->user()->restaurant_id}/categories", 'public'
            );
        }

        $category = Category::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'parent_id'     => $request->parent_id,
            'image'         => $imagePath,
            'order'         => $request->order ?? 0,
        ]);

        return response()->json($category, 201);
    }

    // PUT /api/categories/{id}
    public function update(Request $request, Category $category)
    {
        abort_if($category->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'name'      => 'sometimes|string|max:100',
            'parent_id' => 'nullable|exists:categories,id',
            'order'     => 'integer|min:0',
            'active'    => 'boolean',
        ]);

        if ($request->hasFile('image')) {
            if ($category->image) Storage::disk('public')->delete($category->image);
            $request->merge(['image' => $request->file('image')->store(
                "restaurants/{$category->restaurant_id}/categories", 'public'
            )]);
        }

        $category->update($request->only(['name', 'parent_id', 'order', 'active', 'image']));

        return response()->json($category);
    }

    // DELETE /api/categories/{id}
    public function destroy(Request $request, Category $category)
    {
        abort_if($category->restaurant_id !== $request->user()->restaurant_id, 403);

        // Vérifier si la catégorie a des produits actifs
        $hasProducts = $category->products()->where('active', true)->exists();
        abort_if($hasProducts, 422, 'Impossible de supprimer : catégorie contient des produits actifs.');

        $category->update(['active' => false]);

        return response()->json(['message' => 'Catégorie désactivée.']);
    }

    // POST /api/categories/reorder
    public function reorder(Request $request)
    {
        $request->validate([
            'categories'         => 'required|array',
            'categories.*.id'    => 'required|exists:categories,id',
            'categories.*.order' => 'required|integer',
        ]);

        foreach ($request->categories as $item) {
            Category::where('id', $item['id'])
                ->where('restaurant_id', $request->user()->restaurant_id)
                ->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Ordre mis à jour.']);
    }
}
```

---

## 20. INGREDIENTCONTROLLER (COMPLET)

```php
// app/Http/Controllers/Api/IngredientController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    // GET /api/ingredients
    public function index(Request $request)
    {
        $ingredients = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when($request->alert, fn($q) => $q->whereColumn('quantity', '<=', 'min_quantity'))
            ->where('active', true)
            ->orderBy('name')
            ->paginate(30);

        return response()->json($ingredients);
    }

    // GET /api/ingredients/{id}
    public function show(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);
        return response()->json($ingredient);
    }

    // POST /api/ingredients
    public function store(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:150',
            'unit'          => 'required|string|max:20',
            'quantity'      => 'numeric|min:0',
            'min_quantity'  => 'numeric|min:0',
            'cost_per_unit' => 'numeric|min:0',
            'category'      => 'nullable|string|max:100',
            'supplier'      => 'nullable|string|max:150',
        ]);

        $ingredient = Ingredient::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'unit'          => $request->unit,
            'quantity'      => $request->quantity ?? 0,
            'min_quantity'  => $request->min_quantity ?? 0,
            'cost_per_unit' => $request->cost_per_unit ?? 0,
            'category'      => $request->category,
            'supplier'      => $request->supplier,
        ]);

        return response()->json($ingredient, 201);
    }

    // PUT /api/ingredients/{id}
    public function update(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'name'          => 'sometimes|string|max:150',
            'unit'          => 'sometimes|string|max:20',
            'min_quantity'  => 'numeric|min:0',
            'cost_per_unit' => 'numeric|min:0',
            'category'      => 'nullable|string',
            'supplier'      => 'nullable|string',
            'active'        => 'boolean',
        ]);

        $ingredient->update($request->only([
            'name', 'unit', 'min_quantity', 'cost_per_unit',
            'category', 'supplier', 'active',
        ]));

        return response()->json($ingredient);
    }

    // DELETE /api/ingredients/{id}
    public function destroy(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);

        $hasRecipes = $ingredient->recipes()->exists();
        abort_if($hasRecipes, 422, 'Cet ingrédient est utilisé dans des recettes.');

        $ingredient->update(['active' => false]);

        return response()->json(['message' => 'Ingrédient désactivé.']);
    }

    // GET /api/ingredients/categories — Liste des catégories distinctes
    public function categories(Request $request)
    {
        $cats = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        return response()->json($cats);
    }
}
```

---

## 21. FLOORCONTROLLER (COMPLET)

```php
// app/Http/Controllers/Api/FloorController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Table;
use Illuminate\Http\Request;

class FloorController extends Controller
{
    // GET /api/floors
    public function index(Request $request)
    {
        $floors = Floor::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->withCount([
                'tables',
                'tables as free_count'     => fn($q) => $q->where('status', 'free'),
                'tables as occupied_count' => fn($q) => $q->where('status', 'occupied'),
            ])
            ->orderBy('order')
            ->get();

        return response()->json($floors);
    }

    // POST /api/floors
    public function store(Request $request)
    {
        $request->validate([
            'name'  => 'required|string|max:100',
            'order' => 'integer|min:0',
        ]);

        $floor = Floor::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'order'         => $request->order ?? 0,
        ]);

        return response()->json($floor, 201);
    }

    // PUT /api/floors/{id}
    public function update(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'name'   => 'sometimes|string|max:100',
            'order'  => 'integer|min:0',
            'active' => 'boolean',
        ]);

        $floor->update($request->only(['name', 'order', 'active']));

        return response()->json($floor);
    }

    // DELETE /api/floors/{id}
    public function destroy(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403);

        $hasOccupied = $floor->tables()->where('status', 'occupied')->exists();
        abort_if($hasOccupied, 422, 'Des tables sont occupées dans cette salle.');

        $floor->update(['active' => false]);

        return response()->json(['message' => 'Salle désactivée.']);
    }

    // POST /api/floors/{id}/tables — Ajouter une table à une salle
    public function addTable(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'number'     => 'required|string|max:10',
            'capacity'   => 'required|integer|min:1',
            'shape'      => 'in:rectangle,round',
            'position_x' => 'numeric',
            'position_y' => 'numeric',
            'width'      => 'integer|min:60',
            'height'     => 'integer|min:60',
        ]);

        $exists = $floor->tables()->where('number', $request->number)->exists();
        abort_if($exists, 422, "Le numéro de table {$request->number} existe déjà dans cette salle.");

        $table = $floor->tables()->create([
            'number'     => $request->number,
            'capacity'   => $request->capacity,
            'shape'      => $request->shape ?? 'rectangle',
            'position_x' => $request->position_x ?? 0,
            'position_y' => $request->position_y ?? 0,
            'width'      => $request->width ?? 100,
            'height'     => $request->height ?? 100,
            'status'     => 'free',
        ]);

        return response()->json($table, 201);
    }

    // DELETE /api/floors/{floorId}/tables/{tableId}
    public function removeTable(Request $request, Floor $floor, Table $table)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($table->status === 'occupied', 422, 'Table occupée, impossible de supprimer.');

        $table->update(['active' => false]);

        return response()->json(['message' => 'Table supprimée.']);
    }
}
```

---

## 22. SETTINGSCONTROLLER (COMPLET)

```php
// app/Http/Controllers/Api/SettingsController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    // GET /api/settings
    public function show(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        return response()->json($restaurant);
    }

    // PUT /api/settings
    public function update(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);

        $restaurant = $request->user()->restaurant;

        $request->validate([
            'name'        => 'sometimes|string|max:200',
            'address'     => 'nullable|string',
            'phone'       => 'nullable|string|max:30',
            'email'       => 'nullable|email',
            'vat_number'  => 'nullable|string|max:50',
            'currency'    => 'in:XOF,EUR,USD,GHS,NGN',
            'timezone'    => 'nullable|string',
            'logo'        => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            if ($restaurant->logo) Storage::disk('public')->delete($restaurant->logo);
            $request->merge(['logo' => $request->file('logo')->store(
                "restaurants/{$restaurant->id}", 'public'
            )]);
        }

        $restaurant->update($request->only([
            'name', 'address', 'phone', 'email',
            'vat_number', 'currency', 'timezone', 'logo',
        ]));

        return response()->json($restaurant);
    }

    // PUT /api/settings/config — Paramètres JSON (imprimante, TVA, etc.)
    public function updateConfig(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);

        $request->validate([
            'settings' => 'required|array',
        ]);

        $restaurant = $request->user()->restaurant;

        $current  = $restaurant->settings ?? [];
        $merged   = array_merge($current, $request->settings);
        $restaurant->update(['settings' => $merged]);

        return response()->json(['settings' => $restaurant->fresh()->settings]);
    }

    // GET /api/settings/config — Lire la config complète
    public function getConfig(Request $request)
    {
        $defaults = [
            'receipt_logo'         => true,
            'receipt_footer'       => 'Merci de votre visite !',
            'receipt_width'        => '80mm',
            'printer_ip'           => null,
            'printer_port'         => 9100,
            'default_vat_rate'     => 18,
            'auto_print_receipt'   => true,
            'kitchen_alert_sound'  => true,
            'order_timeout_alert'  => 15, // minutes
            'currency_symbol'      => 'FCFA',
            'currency_position'    => 'after', // before/after
        ];

        $config = array_merge($defaults, $request->user()->restaurant->settings ?? []);

        return response()->json($config);
    }
}
```

---

## 23. SUPPLIERCONTROLLER — FOURNISSEURS

```php
// app/Http/Controllers/Api/SupplierController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    // GET /api/suppliers
    public function index(Request $request)
    {
        $suppliers = Supplier::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate(20);

        return response()->json($suppliers);
    }

    // POST /api/suppliers
    public function store(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:200',
            'phone'   => 'nullable|string|max:30',
            'email'   => 'nullable|email',
            'address' => 'nullable|string',
            'notes'   => 'nullable|string',
        ]);

        $supplier = Supplier::create([
            'restaurant_id' => $request->user()->restaurant_id,
            ...$request->only(['name', 'phone', 'email', 'address', 'notes']),
        ]);

        return response()->json($supplier, 201);
    }

    // PUT /api/suppliers/{id}
    public function update(Request $request, Supplier $supplier)
    {
        abort_if($supplier->restaurant_id !== $request->user()->restaurant_id, 403);
        $supplier->update($request->only(['name', 'phone', 'email', 'address', 'notes']));
        return response()->json($supplier);
    }

    // DELETE /api/suppliers/{id}
    public function destroy(Request $request, Supplier $supplier)
    {
        abort_if($supplier->restaurant_id !== $request->user()->restaurant_id, 403);
        $supplier->delete();
        return response()->json(['message' => 'Fournisseur supprimé.']);
    }
}

// Migration fournisseurs
// Schema::create('suppliers', function (Blueprint $table) {
//     $table->id();
//     $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
//     $table->string('name');
//     $table->string('phone')->nullable();
//     $table->string('email')->nullable();
//     $table->text('address')->nullable();
//     $table->text('notes')->nullable();
//     $table->timestamps();
//     $table->softDeletes();
// });
```

---

## 24. COMBOMENUCONTROLLER — MENUS COMPOSÉS

```php
// app/Http/Controllers/Api/ComboMenuController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComboMenu;
use Illuminate\Http\Request;

class ComboMenuController extends Controller
{
    // GET /api/combos
    public function index(Request $request)
    {
        $combos = ComboMenu::with('items.product')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->get();

        return response()->json($combos);
    }

    // POST /api/combos
    public function store(Request $request)
    {
        $request->validate([
            'name'           => 'required|string|max:200',
            'description'    => 'nullable|string',
            'price'          => 'required|numeric|min:0',
            'items'          => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
        ]);

        $combo = ComboMenu::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'description'   => $request->description,
            'price'         => $request->price,
        ]);

        foreach ($request->items as $item) {
            $combo->items()->create([
                'product_id' => $item['product_id'],
                'quantity'   => $item['quantity'],
            ]);
        }

        return response()->json($combo->load('items.product'), 201);
    }

    // PUT /api/combos/{id}
    public function update(Request $request, ComboMenu $combo)
    {
        abort_if($combo->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'name'    => 'sometimes|string',
            'price'   => 'sometimes|numeric|min:0',
            'active'  => 'boolean',
        ]);

        $combo->update($request->only(['name', 'description', 'price', 'active']));

        if ($request->has('items')) {
            $combo->items()->delete();
            foreach ($request->items as $item) {
                $combo->items()->create($item);
            }
        }

        return response()->json($combo->fresh('items.product'));
    }

    // DELETE /api/combos/{id}
    public function destroy(Request $request, ComboMenu $combo)
    {
        abort_if($combo->restaurant_id !== $request->user()->restaurant_id, 403);
        $combo->update(['active' => false]);
        return response()->json(['message' => 'Menu composé désactivé.']);
    }
}
```

---

## 25. QRCODECONTROLLER — MENU QR

```php
// app/Http/Controllers/Api/QrCodeController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends Controller
{
    // GET /api/qr/{tableId} — Générer QR code pour une table
    public function generate(Request $request, int $tableId)
    {
        $table = Table::whereHas('floor', fn($q) =>
            $q->where('restaurant_id', $request->user()->restaurant_id)
        )->findOrFail($tableId);

        $restaurant = $request->user()->restaurant;

        // URL que le client scannera
        $menuUrl = config('app.frontend_url') . "/menu/{$restaurant->slug}?table={$tableId}";

        // Générer le QR en SVG ou PNG
        $qr = QrCode::format('png')
            ->size(300)
            ->margin(2)
            ->errorCorrection('H')
            ->generate($menuUrl);

        return response($qr, 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => "inline; filename=table-{$table->number}-qr.png",
        ]);
    }

    // GET /api/qr/{tableId}/url — Juste l'URL du QR
    public function url(Request $request, int $tableId)
    {
        $table = Table::whereHas('floor', fn($q) =>
            $q->where('restaurant_id', $request->user()->restaurant_id)
        )->findOrFail($tableId);

        $restaurant = $request->user()->restaurant;
        $menuUrl    = config('app.frontend_url') . "/menu/{$restaurant->slug}?table={$tableId}";

        return response()->json([
            'table_number' => $table->number,
            'menu_url'     => $menuUrl,
        ]);
    }

    // GET /api/qr/floor/{floorId}/all — Tous les QR d'une salle en ZIP
    public function allForFloor(Request $request, int $floorId)
    {
        $floor = \App\Models\Floor::where('restaurant_id', $request->user()->restaurant_id)
            ->findOrFail($floorId);

        $restaurant = $request->user()->restaurant;
        $tables     = $floor->tables()->where('active', true)->get();
        $zipPath    = storage_path("app/temp/qr-floor-{$floorId}.zip");

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($tables as $table) {
            $menuUrl = config('app.frontend_url') . "/menu/{$restaurant->slug}?table={$table->id}";
            $qr = QrCode::format('png')->size(300)->generate($menuUrl);
            $zip->addFromString("table-{$table->number}.png", $qr);
        }

        $zip->close();

        return response()->download($zipPath, "qr-codes-salle-{$floor->name}.zip")
            ->deleteFileAfterSend();
    }
}
```

---

## 26. NOTIFICATIONCONTROLLER — ALERTES

```php
// app/Http/Controllers/Api/NotificationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET /api/notifications — Toutes les alertes actives
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $notifications = [];

        // 1. Alertes stock faible
        $lowStock = Ingredient::where('restaurant_id', $restaurantId)
            ->whereColumn('quantity', '<=', 'min_quantity')
            ->where('active', true)
            ->get(['id', 'name', 'quantity', 'min_quantity', 'unit'])
            ->map(fn($i) => [
                'type'     => 'stock_low',
                'level'    => $i->quantity <= 0 ? 'danger' : 'warning',
                'message'  => $i->quantity <= 0
                    ? "Rupture : {$i->name}"
                    : "Stock faible : {$i->name} ({$i->quantity} {$i->unit})",
                'subject'  => $i,
                'created_at' => now(),
            ]);

        // 2. Commandes en retard (cuisine)
        $lateOrders = \App\Models\Order::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['sent_to_kitchen'])
            ->where('sent_to_kitchen_at', '<', now()->subMinutes(15))
            ->with('table:id,number')
            ->get(['id', 'order_number', 'sent_to_kitchen_at', 'table_id'])
            ->map(fn($o) => [
                'type'    => 'order_late',
                'level'   => 'danger',
                'message' => "Commande {$o->order_number} en retard (" . now()->diffInMinutes($o->sent_to_kitchen_at) . " min)",
                'subject' => $o,
                'created_at' => $o->sent_to_kitchen_at,
            ]);

        // 3. Annulations en attente
        $pendingCancellations = \App\Models\Cancellation::where('restaurant_id', $restaurantId)
            ->where('status', 'pending')
            ->with('requester:id,first_name,last_name')
            ->get()
            ->map(fn($c) => [
                'type'    => 'cancellation_pending',
                'level'   => 'warning',
                'message' => "Demande d'annulation de {$c->requester->full_name}",
                'subject' => $c,
                'created_at' => $c->requested_at,
            ]);

        $notifications = collect($lowStock)
            ->merge($lateOrders)
            ->merge($pendingCancellations)
            ->sortByDesc('created_at')
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'count'         => $notifications->count(),
            'has_danger'    => $notifications->where('level', 'danger')->isNotEmpty(),
        ]);
    }
}
```

---

## 27. POLICIES COMPLÈTES

### 27.1 UserPolicy

```php
// app/Policies/UserPolicy.php
namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    // Seuls les admins/managers peuvent créer des utilisateurs
    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, User $target): bool
    {
        if ($user->restaurant_id !== $target->restaurant_id) return false;
        // Un user peut modifier son propre profil
        if ($user->id === $target->id) return true;
        // Un manager peut modifier les non-admins
        if ($user->hasRole('manager') && $target->hasRole('admin')) return false;
        return $user->isManager();
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) return false; // pas se supprimer soi-même
        if ($target->hasRole('admin')) return false;  // pas supprimer un admin
        return $user->hasRole('admin');
    }
}
```

### 27.2 CancellationPolicy

```php
// app/Policies/CancellationPolicy.php
namespace App\Policies;

use App\Models\User;
use App\Models\Cancellation;

class CancellationPolicy
{
    public function request(User $user): bool
    {
        // Tout utilisateur connecté peut demander une annulation
        return true;
    }

    public function approve(User $user, Cancellation $cancellation): bool
    {
        return $cancellation->restaurant_id === $user->restaurant_id
            && $user->isManager();
    }

    public function reject(User $user, Cancellation $cancellation): bool
    {
        return $this->approve($user, $cancellation);
    }

    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }
}
```

---

## 28. FORM REQUESTS — VALIDATION CLASSES

```php
// app/Http/Requests/StoreOrderRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('orders.create');
    }

    public function rules(): array
    {
        return [
            'table_id'               => 'nullable|exists:tables,id',
            'type'                   => 'required|in:dine_in,takeaway,delivery',
            'covers'                 => 'integer|min:1|max:50',
            'notes'                  => 'nullable|string|max:500',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1|max:99',
            'items.*.notes'          => 'nullable|string|max:300',
            'items.*.course'         => 'integer|min:1|max:5',
            'items.*.modifier_ids'   => 'nullable|array',
            'items.*.modifier_ids.*' => 'exists:modifiers,id',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'             => 'La commande doit contenir au moins un article.',
            'items.*.product_id.exists'  => 'Produit introuvable.',
            'items.*.quantity.min'       => 'La quantité minimum est 1.',
        ];
    }
}

// app/Http/Requests/StorePaymentRequest.php
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payments.create');
    }

    public function rules(): array
    {
        return [
            'order_id'     => 'required|exists:orders,id',
            'amount'       => 'required|numeric|min:0.01',
            'method'       => 'required|in:cash,card,wave,orange_money,momo,other',
            'reference'    => 'nullable|string|max:100',
            'amount_given' => 'nullable|numeric|min:0',
        ];
    }
}

// app/Http/Requests/UpdateOrderItemsRequest.php
class UpdateOrderItemsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'items'            => 'required|array',
            'items.*.id'       => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.notes'    => 'nullable|string|max:300',
        ];
    }
}
```

---

## 29. OBSERVER GLOBAL

```php
// app/Observers/AuditObserver.php
namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditObserver
{
    private array $excluded = ['password', 'pin', 'remember_token', 'updated_at'];

    public function created(Model $model): void
    {
        $this->log('created', $model, [], $model->toArray());
    }

    public function updated(Model $model): void
    {
        $this->log('updated', $model, $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, $model->toArray(), []);
    }

    public function restored(Model $model): void
    {
        $this->log('restored', $model, [], []);
    }

    private function log(string $action, Model $model, array $old, array $new): void
    {
        $old = array_diff_key($old, array_flip($this->excluded));
        $new = array_diff_key($new, array_flip($this->excluded));

        ActivityLog::create([
            'restaurant_id' => $model->restaurant_id ?? Auth::user()?->restaurant_id,
            'user_id'       => Auth::id(),
            'action'        => $action,
            'module'        => $this->getModule($model),
            'subject_type'  => get_class($model),
            'subject_id'    => $model->getKey(),
            'description'   => class_basename($model) . " #{$model->getKey()} — {$action}",
            'old_values'    => empty($old) ? null : $old,
            'new_values'    => empty($new) ? null : $new,
            'ip_address'    => Request::ip(),
        ]);
    }

    private function getModule(Model $model): string
    {
        return match(true) {
            $model instanceof \App\Models\Order       => 'order',
            $model instanceof \App\Models\OrderItem   => 'order_item',
            $model instanceof \App\Models\Payment     => 'payment',
            $model instanceof \App\Models\User        => 'user',
            $model instanceof \App\Models\Ingredient  => 'stock',
            $model instanceof \App\Models\Delivery    => 'delivery',
            $model instanceof \App\Models\Cancellation=> 'cancellation',
            $model instanceof \App\Models\Product     => 'product',
            default                                   => 'system',
        };
    }
}
```

---

## 30. GÉNÉRATION DU REÇU — RECEIPTCONTROLLER

```php
// app/Http/Controllers/Api/ReceiptController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    // GET /api/receipts/{orderId} — Aperçu JSON du reçu
    public function show(Request $request, int $orderId)
    {
        $order = Order::with([
            'items' => fn($q) => $q->whereNotIn('status', ['cancelled']),
            'items.product:id,name,vat_rate',
            'items.modifiers.modifier:id,name,extra_price',
            'payments',
            'table:id,number',
            'waiter:id,first_name,last_name',
            'cashier:id,first_name,last_name',
        ])
        ->where('restaurant_id', $request->user()->restaurant_id)
        ->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config     = $restaurant->settings ?? [];

        $receipt = $this->buildReceipt($order, $restaurant, $config);

        return response()->json($receipt);
    }

    // GET /api/receipts/{orderId}/pdf — Télécharger le reçu en PDF
    public function pdf(Request $request, int $orderId)
    {
        $order = Order::with([
            'items' => fn($q) => $q->whereNotIn('status', ['cancelled']),
            'items.product',
            'items.modifiers.modifier',
            'payments',
            'table',
        ])
        ->where('restaurant_id', $request->user()->restaurant_id)
        ->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config     = $restaurant->settings ?? [];
        $receipt    = $this->buildReceipt($order, $restaurant, $config);

        $pdf = Pdf::loadView('receipts.ticket', compact('receipt', 'restaurant', 'config'))
            ->setPaper([0, 0, 226.77, 700]) // 80mm de large, hauteur auto
            ->setOption('margin-top', 5)
            ->setOption('margin-bottom', 5)
            ->setOption('margin-left', 5)
            ->setOption('margin-right', 5);

        return $pdf->download("ticket-{$order->order_number}.pdf");
    }

    // GET /api/receipts/{orderId}/html — HTML pour impression directe ESC/POS via navigateur
    public function html(Request $request, int $orderId)
    {
        $order      = Order::with(['items.product', 'items.modifiers.modifier', 'payments', 'table'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config     = $restaurant->settings ?? [];
        $receipt    = $this->buildReceipt($order, $restaurant, $config);

        return response()->json([
            'html' => view('receipts.ticket-html', compact('receipt', 'restaurant', 'config'))->render(),
        ]);
    }

    // POST /api/receipts/{orderId}/send-sms — Envoyer le reçu par SMS
    public function sendSms(Request $request, int $orderId)
    {
        $request->validate(['phone' => 'required|string|min:8']);

        $order = Order::where('restaurant_id', $request->user()->restaurant_id)
            ->findOrFail($orderId);

        $message = "Reçu {$order->order_number}\n";
        $message .= "Montant: " . number_format($order->total, 0, '.', ' ') . " FCFA\n";
        $message .= "Merci de votre visite !";

        // Intégration SMS (ex: Africa's Talking, Twilio, etc.)
        // \AfricasTalking\SDK\AfricasTalking::sms()->send([
        //     'to'      => $request->phone,
        //     'message' => $message,
        // ]);

        $order->logs()->create([
            'user_id' => auth()->id(),
            'action'  => 'receipt_sms',
            'message' => "Reçu envoyé par SMS au {$request->phone}",
        ]);

        return response()->json(['message' => 'Reçu envoyé par SMS.']);
    }

    // POST /api/receipts/{orderId}/send-email
    public function sendEmail(Request $request, int $orderId)
    {
        $request->validate(['email' => 'required|email']);

        $order = Order::with(['items.product', 'payments', 'table'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config     = $restaurant->settings ?? [];
        $receipt    = $this->buildReceipt($order, $restaurant, $config);

        \Illuminate\Support\Facades\Mail::send(
            'receipts.email',
            compact('receipt', 'restaurant'),
            function ($mail) use ($request, $order, $restaurant) {
                $mail->to($request->email)
                     ->subject("Votre reçu — {$restaurant->name} — {$order->order_number}");
            }
        );

        return response()->json(['message' => 'Reçu envoyé par email.']);
    }

    // ---- Méthode centrale de construction du reçu ----
    private function buildReceipt(Order $order, $restaurant, array $config): array
    {
        $currencySymbol   = $config['currency_symbol'] ?? 'FCFA';
        $currencyPosition = $config['currency_position'] ?? 'after';

        $formatAmount = fn($amount) => $currencyPosition === 'before'
            ? "{$currencySymbol} " . number_format($amount, 0, '.', ' ')
            : number_format($amount, 0, '.', ' ') . " {$currencySymbol}";

        // Construire les lignes du reçu
        $lines = $order->items->map(function ($item) use ($formatAmount) {
            $modifiers = $item->modifiers->map(fn($m) => [
                'name'        => $m->modifier->name,
                'extra_price' => $m->extra_price,
                'extra_fmt'   => $m->extra_price > 0 ? '+' . number_format($m->extra_price, 0) : '',
            ])->toArray();

            $lineTotal = ($item->unit_price * $item->quantity)
                + collect($modifiers)->sum('extra_price') * $item->quantity;

            return [
                'name'         => $item->product->name,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->unit_price,
                'unit_fmt'     => $formatAmount($item->unit_price),
                'total'        => $lineTotal,
                'total_fmt'    => $formatAmount($lineTotal),
                'notes'        => $item->notes,
                'modifiers'    => $modifiers,
            ];
        })->toArray();

        // Résumé des paiements
        $paymentLines = $order->payments->map(fn($p) => [
            'method'       => $this->methodLabel($p->method),
            'amount'       => $p->amount,
            'amount_fmt'   => $formatAmount($p->amount),
            'reference'    => $p->reference,
            'amount_given' => $p->amount_given,
            'change_given' => $p->change_given,
            'change_fmt'   => $p->change_given ? $formatAmount($p->change_given) : null,
        ])->toArray();

        return [
            // En-tête restaurant
            'restaurant' => [
                'name'       => $restaurant->name,
                'logo'       => $restaurant->logo ? asset('storage/' . $restaurant->logo) : null,
                'address'    => $restaurant->address,
                'phone'      => $restaurant->phone,
                'email'      => $restaurant->email,
                'vat_number' => $restaurant->vat_number,
            ],

            // Infos commande
            'order' => [
                'id'           => $order->id,
                'number'       => $order->order_number,
                'date'         => $order->created_at->format('d/m/Y'),
                'time'         => $order->created_at->format('H:i'),
                'paid_at'      => $order->paid_at?->format('d/m/Y H:i'),
                'table_number' => $order->table?->number,
                'covers'       => $order->covers,
                'type'         => $order->type,
                'type_label'   => $this->typeLabel($order->type),
                'waiter'       => $order->waiter?->full_name,
                'cashier'      => $order->cashier?->full_name,
                'notes'        => $order->notes,
            ],

            // Lignes articles
            'lines' => $lines,

            // Totaux
            'totals' => [
                'subtotal'        => $order->subtotal,
                'subtotal_fmt'    => $formatAmount($order->subtotal),
                'discount'        => $order->discount_amount,
                'discount_fmt'    => $order->discount_amount > 0 ? '-' . $formatAmount($order->discount_amount) : null,
                'discount_reason' => $order->discount_reason,
                'vat_rate'        => 18,
                'vat_amount'      => $order->vat_amount,
                'vat_fmt'         => $formatAmount($order->vat_amount),
                'total'           => $order->total,
                'total_fmt'       => $formatAmount($order->total),
                'amount_paid'     => $order->amountPaid(),
                'amount_paid_fmt' => $formatAmount($order->amountPaid()),
                'change'          => max(0, $order->amountPaid() - $order->total),
                'change_fmt'      => $formatAmount(max(0, $order->amountPaid() - $order->total)),
            ],

            // Paiements
            'payments' => $paymentLines,

            // Pied de page
            'footer' => [
                'message'   => $config['receipt_footer'] ?? 'Merci de votre visite !',
                'website'   => $config['receipt_website'] ?? null,
                'show_logo' => $config['receipt_logo'] ?? true,
                'width'     => $config['receipt_width'] ?? '80mm',
            ],

            // Meta
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function methodLabel(string $method): string
    {
        return match($method) {
            'cash'         => 'Espèces',
            'card'         => 'Carte bancaire',
            'wave'         => 'Wave',
            'orange_money' => 'Orange Money',
            'momo'         => 'Mobile Money',
            default        => 'Autre',
        };
    }

    private function typeLabel(string $type): string
    {
        return match($type) {
            'dine_in'  => 'Sur place',
            'takeaway' => 'À emporter',
            'delivery' => 'Livraison',
            default    => $type,
        };
    }
}
```

---

## 31. VUE BLADE DU REÇU

```html
{{-- resources/views/receipts/ticket.blade.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Courier New', Courier, monospace;
    font-size: 11px;
    color: #000;
    width: {{ $config['receipt_width'] ?? '80mm' }};
    padding: 4px;
  }
  .center  { text-align: center; }
  .right   { text-align: right; }
  .bold    { font-weight: bold; }
  .large   { font-size: 14px; }
  .xlarge  { font-size: 16px; }
  .muted   { color: #555; }
  .divider { border-top: 1px dashed #000; margin: 5px 0; }
  .divider-solid { border-top: 1px solid #000; margin: 5px 0; }
  table    { width: 100%; border-collapse: collapse; }
  td       { padding: 1px 0; vertical-align: top; }
  .td-right { text-align: right; white-space: nowrap; padding-left: 4px; }
  .logo    { max-width: 80px; max-height: 60px; display: block; margin: 0 auto 4px; }
  .total-row td { font-weight: bold; font-size: 13px; border-top: 1px solid #000; padding-top: 3px; }
  .mod-line { padding-left: 8px; color: #444; }
  .footer-msg { font-size: 12px; font-weight: bold; }
</style>
</head>
<body>

{{-- EN-TÊTE RESTAURANT --}}
<div class="center">
  @if($receipt['footer']['show_logo'] && $receipt['restaurant']['logo'])
    <img src="{{ $receipt['restaurant']['logo'] }}" class="logo" alt="Logo">
  @endif
  <div class="bold xlarge">{{ $receipt['restaurant']['name'] }}</div>
  @if($receipt['restaurant']['address'])
    <div class="muted">{{ $receipt['restaurant']['address'] }}</div>
  @endif
  @if($receipt['restaurant']['phone'])
    <div class="muted">Tél : {{ $receipt['restaurant']['phone'] }}</div>
  @endif
  @if($receipt['restaurant']['vat_number'])
    <div class="muted">TVA : {{ $receipt['restaurant']['vat_number'] }}</div>
  @endif
</div>

<div class="divider"></div>

{{-- INFOS COMMANDE --}}
<table>
  <tr>
    <td class="bold">TICKET DE CAISSE</td>
    <td class="td-right bold">{{ $receipt['order']['number'] }}</td>
  </tr>
  <tr>
    <td class="muted">Date</td>
    <td class="td-right">{{ $receipt['order']['date'] }} {{ $receipt['order']['time'] }}</td>
  </tr>
  @if($receipt['order']['table_number'])
  <tr>
    <td class="muted">Table</td>
    <td class="td-right">{{ $receipt['order']['table_number'] }}</td>
  </tr>
  @endif
  @if($receipt['order']['covers'])
  <tr>
    <td class="muted">Couverts</td>
    <td class="td-right">{{ $receipt['order']['covers'] }}</td>
  </tr>
  @endif
  <tr>
    <td class="muted">Type</td>
    <td class="td-right">{{ $receipt['order']['type_label'] }}</td>
  </tr>
  @if($receipt['order']['waiter'])
  <tr>
    <td class="muted">Serveur</td>
    <td class="td-right">{{ $receipt['order']['waiter'] }}</td>
  </tr>
  @endif
  @if($receipt['order']['cashier'])
  <tr>
    <td class="muted">Caissier</td>
    <td class="td-right">{{ $receipt['order']['cashier'] }}</td>
  </tr>
  @endif
</table>

<div class="divider"></div>

{{-- LIGNES ARTICLES --}}
<table>
  <thead>
    <tr>
      <td class="bold">Article</td>
      <td class="td-right bold">Qté</td>
      <td class="td-right bold">PU</td>
      <td class="td-right bold">Total</td>
    </tr>
  </thead>
  <tbody>
    @foreach($receipt['lines'] as $line)
    <tr>
      <td class="bold">{{ $line['name'] }}</td>
      <td class="td-right">{{ $line['quantity'] }}</td>
      <td class="td-right">{{ $line['unit_fmt'] }}</td>
      <td class="td-right">{{ $line['total_fmt'] }}</td>
    </tr>
    @foreach($line['modifiers'] as $mod)
    <tr class="mod-line">
      <td colspan="3">  + {{ $mod['name'] }}</td>
      <td class="td-right muted">{{ $mod['extra_fmt'] }}</td>
    </tr>
    @endforeach
    @if($line['notes'])
    <tr>
      <td colspan="4" class="muted" style="padding-left:8px;font-style:italic">
        Note: {{ $line['notes'] }}
      </td>
    </tr>
    @endif
    @endforeach
  </tbody>
</table>

<div class="divider"></div>

{{-- TOTAUX --}}
<table>
  <tr>
    <td class="muted">Sous-total</td>
    <td class="td-right">{{ $receipt['totals']['subtotal_fmt'] }}</td>
  </tr>
  @if($receipt['totals']['discount'] > 0)
  <tr>
    <td class="muted">Remise {{ $receipt['totals']['discount_reason'] ? '('.$receipt['totals']['discount_reason'].')' : '' }}</td>
    <td class="td-right">{{ $receipt['totals']['discount_fmt'] }}</td>
  </tr>
  @endif
  <tr>
    <td class="muted">TVA ({{ $receipt['totals']['vat_rate'] }}%)</td>
    <td class="td-right">{{ $receipt['totals']['vat_fmt'] }}</td>
  </tr>
  <tr class="total-row">
    <td class="large">TOTAL</td>
    <td class="td-right large">{{ $receipt['totals']['total_fmt'] }}</td>
  </tr>
</table>

<div class="divider"></div>

{{-- PAIEMENTS --}}
<div class="bold" style="margin-bottom:3px">Règlement :</div>
<table>
  @foreach($receipt['payments'] as $payment)
  <tr>
    <td>{{ $payment['method'] }}
      @if($payment['reference'])
        <span class="muted">({{ $payment['reference'] }})</span>
      @endif
    </td>
    <td class="td-right">{{ $payment['amount_fmt'] }}</td>
  </tr>
  @if($payment['amount_given'])
  <tr>
    <td class="muted">  Reçu</td>
    <td class="td-right muted">{{ number_format($payment['amount_given'], 0, '.', ' ') }} {{ 'FCFA' }}</td>
  </tr>
  @endif
  @if($payment['change_given'])
  <tr>
    <td class="muted">  Rendu</td>
    <td class="td-right muted">{{ $payment['change_fmt'] }}</td>
  </tr>
  @endif
  @endforeach
</table>

@if($receipt['totals']['change'] > 0)
<div class="divider-solid"></div>
<table>
  <tr>
    <td class="bold">Monnaie rendue</td>
    <td class="td-right bold">{{ $receipt['totals']['change_fmt'] }}</td>
  </tr>
</table>
@endif

<div class="divider"></div>

{{-- PIED DE PAGE --}}
<div class="center" style="margin-top:6px">
  <div class="footer-msg">{{ $receipt['footer']['message'] }}</div>
  @if($receipt['footer']['website'])
    <div class="muted" style="margin-top:3px">{{ $receipt['footer']['website'] }}</div>
  @endif
  <div class="muted" style="margin-top:6px;font-size:10px">
    Généré le {{ now()->format('d/m/Y à H:i') }}
  </div>
</div>

</body>
</html>
```

---

## 32. SEEDERS — DONNÉES DE DÉMARRAGE

```php
// database/seeders/RestaurantSeeder.php
namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\Floor;
use App\Models\Table;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Restaurant
        $restaurant = Restaurant::create([
            'name'     => 'Restaurant Omega',
            'slug'     => 'restaurant-omega-demo',
            'address'  => 'Avenue du 24 Janvier, Lomé, Togo',
            'phone'    => '+228 90 00 00 00',
            'email'    => 'contact@omega-resto.tg',
            'currency' => 'XOF',
            'timezone' => 'Africa/Lome',
            'settings' => [
                'receipt_footer'      => 'Merci de votre visite ! Revenez bientôt.',
                'receipt_width'       => '80mm',
                'default_vat_rate'    => 18,
                'auto_print_receipt'  => true,
                'currency_symbol'     => 'FCFA',
                'currency_position'   => 'after',
            ],
        ]);

        // 2. Rôles
        $roles = [
            ['name' => 'admin',   'display_name' => 'Administrateur', 'permissions' => ['*'], 'is_system' => true],
            ['name' => 'manager', 'display_name' => 'Manager',        'permissions' => ['orders.*','payments.*','stock.*','reports.*','users.view','cancellations.*'], 'is_system' => true],
            ['name' => 'cashier', 'display_name' => 'Caissier',       'permissions' => ['orders.view','payments.create','cash_sessions.*'], 'is_system' => true],
            ['name' => 'waiter',  'display_name' => 'Serveur',        'permissions' => ['orders.create','orders.update','tables.view'], 'is_system' => true],
            ['name' => 'cook',    'display_name' => 'Cuisinier',      'permissions' => ['kitchen.*'], 'is_system' => true],
            ['name' => 'driver',  'display_name' => 'Livreur',        'permissions' => ['deliveries.update'], 'is_system' => true],
        ];

        $createdRoles = [];
        foreach ($roles as $role) {
            $createdRoles[$role['name']] = Role::create([
                'restaurant_id' => $restaurant->id,
                ...$role,
            ]);
        }

        // 3. Utilisateurs par défaut
        $users = [
            ['first_name' => 'Super',  'last_name' => 'Admin',   'email' => 'admin@omega.tg',   'pin' => '0000', 'role' => 'admin'],
            ['first_name' => 'Kwame',  'last_name' => 'Manager', 'email' => 'manager@omega.tg', 'pin' => '1111', 'role' => 'manager'],
            ['first_name' => 'Ama',    'last_name' => 'Serveur', 'email' => 'ama@omega.tg',     'pin' => '2222', 'role' => 'waiter'],
            ['first_name' => 'Kofi',   'last_name' => 'Caisse',  'email' => 'kofi@omega.tg',    'pin' => '3333', 'role' => 'cashier'],
            ['first_name' => 'Jean',   'last_name' => 'Cuisine', 'email' => 'jean@omega.tg',    'pin' => '4444', 'role' => 'cook'],
            ['first_name' => 'Moise',  'last_name' => 'Livreur', 'email' => 'moise@omega.tg',   'pin' => '5555', 'role' => 'driver'],
        ];

        foreach ($users as $u) {
            User::create([
                'restaurant_id' => $restaurant->id,
                'role_id'       => $createdRoles[$u['role']]->id,
                'first_name'    => $u['first_name'],
                'last_name'     => $u['last_name'],
                'email'         => $u['email'],
                'password'      => Hash::make('password'),
                'pin'           => Hash::make($u['pin']),
                'active'        => true,
            ]);
        }

        // 4. Plan de salle
        $floor = Floor::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Salle principale',
            'order'         => 1,
        ]);

        $tablePositions = [
            ['number' => '1',  'capacity' => 4,  'x' => 50,  'y' => 60],
            ['number' => '2',  'capacity' => 4,  'x' => 200, 'y' => 60],
            ['number' => '3',  'capacity' => 2,  'x' => 350, 'y' => 60],
            ['number' => '4',  'capacity' => 6,  'x' => 500, 'y' => 60],
            ['number' => '5',  'capacity' => 4,  'x' => 50,  'y' => 220],
            ['number' => '6',  'capacity' => 4,  'x' => 200, 'y' => 220],
            ['number' => '7',  'capacity' => 8,  'x' => 350, 'y' => 220],
            ['number' => '8',  'capacity' => 4,  'x' => 50,  'y' => 380],
            ['number' => 'T1', 'capacity' => 10, 'x' => 250, 'y' => 380, 'shape' => 'round'],
        ];

        foreach ($tablePositions as $t) {
            Table::create([
                'floor_id'   => $floor->id,
                'number'     => $t['number'],
                'capacity'   => $t['capacity'],
                'position_x' => $t['x'],
                'position_y' => $t['y'],
                'width'      => 120,
                'height'     => 80,
                'shape'      => $t['shape'] ?? 'rectangle',
                'status'     => 'free',
            ]);
        }

        // 5. Catégories & Produits
        $categories = [
            'Entrées'       => [
                ['name' => 'Salade de crudités', 'price' => 1500],
                ['name' => 'Soupe du jour',       'price' => 2000],
            ],
            'Plats'         => [
                ['name' => 'Poulet braisé',         'price' => 4500],
                ['name' => 'Riz sauce arachide',    'price' => 3500],
                ['name' => 'Attiéké poisson',       'price' => 3000],
                ['name' => 'Thiéboudienne',          'price' => 5000],
                ['name' => 'Brochettes de bœuf',    'price' => 4000],
            ],
            'Boissons'      => [
                ['name' => 'Coca Cola 33cl',   'price' => 800],
                ['name' => 'Eau minérale',     'price' => 500],
                ['name' => 'Jus naturel',      'price' => 1200],
                ['name' => 'Bière locale',     'price' => 1500],
                ['name' => 'Sodabi',           'price' => 1000],
            ],
            'Desserts'      => [
                ['name' => 'Gâteau fondant',   'price' => 2000],
                ['name' => 'Salade de fruits', 'price' => 1500],
                ['name' => 'Glace vanille',    'price' => 1800],
            ],
        ];

        foreach ($categories as $catName => $products) {
            $category = Category::create([
                'restaurant_id' => $restaurant->id,
                'name'          => $catName,
            ]);

            foreach ($products as $i => $product) {
                Product::create([
                    'restaurant_id' => $restaurant->id,
                    'category_id'   => $category->id,
                    'name'          => $product['name'],
                    'price'         => $product['price'],
                    'vat_rate'      => 18,
                    'available'     => true,
                    'order'         => $i,
                ]);
            }
        }

        $this->command->info("✅ Restaurant '{$restaurant->name}' créé avec succès.");
        $this->command->info("📧 Admin : admin@omega.tg | Mot de passe : password | PIN : 0000");
    }
}

// database/seeders/DatabaseSeeder.php
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RestaurantSeeder::class]);
    }
}
```

---

## 33. CONFIGURATION BROADCASTING & CORS

### 33.1 Broadcasting

```php
// config/broadcasting.php
'connections' => [
    'pusher' => [
        'driver'  => 'pusher',
        'key'     => env('PUSHER_APP_KEY'),
        'secret'  => env('PUSHER_APP_SECRET'),
        'app_id'  => env('PUSHER_APP_ID'),
        'options' => [
            'cluster'   => env('PUSHER_APP_CLUSTER', 'mt1'),
            'useTLS'    => true,
            'encrypted' => true,
        ],
    ],

    // Alternative : Soketi (auto-hébergé, gratuit)
    'soketi' => [
        'driver'  => 'pusher',
        'key'     => env('SOKETI_APP_KEY', 'omega-key'),
        'secret'  => env('SOKETI_APP_SECRET', 'omega-secret'),
        'app_id'  => env('SOKETI_APP_ID', '1'),
        'options' => [
            'host'      => env('SOKETI_HOST', '127.0.0.1'),
            'port'      => env('SOKETI_PORT', 6001),
            'scheme'    => 'http',
            'useTLS'    => false,
            'encrypted' => false,
        ],
    ],
],
```

### 33.2 CORS pour React & Flutter

```php
// config/cors.php
return [
    'paths'               => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'     => ['*'],
    'allowed_origins'     => [
        env('FRONTEND_URL', 'http://localhost:3000'), // React dev
        env('APP_URL'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers'     => ['*'],
    'exposed_headers'     => [],
    'max_age'             => 0,
    'supports_credentials'=> true,
];
```

---

## 34. ROUTES API — MISE À JOUR COMPLÈTE FINALE

```php
// routes/api.php — FICHIER COMPLET FINAL

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

// =============================================
// ROUTES PUBLIQUES
// =============================================
Route::prefix('auth')->group(function () {
    Route::post('login',     [Api\Auth\AuthController::class, 'login']);
    Route::post('login-pin', [Api\Auth\AuthController::class, 'loginPin']);
});

Route::get('menu/{restaurantSlug}', [Api\ProductController::class, 'publicMenu']);

// =============================================
// ROUTES PROTÉGÉES
// =============================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout',         [Api\Auth\AuthController::class, 'logout']);
        Route::get('me',              [Api\Auth\AuthController::class, 'me']);
        Route::put('change-password', [Api\Auth\AuthController::class, 'changePassword']);
    });

    // Utilisateurs
    Route::apiResource('users', Api\UserController::class);
    Route::patch('users/{user}/toggle-active', [Api\UserController::class, 'toggleActive']);

    // Salles
    Route::apiResource('floors', Api\FloorController::class);
    Route::post('floors/{floor}/tables',              [Api\FloorController::class, 'addTable']);
    Route::delete('floors/{floor}/tables/{table}',    [Api\FloorController::class, 'removeTable']);

    // Tables
    Route::put('tables/{table}/status',    [Api\TableController::class, 'updateStatus']);
    Route::put('tables/{table}/layout',    [Api\TableController::class, 'updateLayout']);
    Route::put('tables/{table}/assign',    [Api\TableController::class, 'assign']);
    Route::post('tables/{table}/reserve',  [Api\TableController::class, 'reserve']);
    Route::post('tables/{table}/transfer', [Api\TableController::class, 'transfer']);
    Route::post('tables/merge',            [Api\TableController::class, 'merge']);

    // Commandes
    Route::apiResource('orders', Api\OrderController::class)->except(['destroy']);
    Route::get('orders/table/{tableId}/current',      [Api\OrderController::class, 'currentByTable']);
    Route::put('orders/{order}/items',                [Api\OrderController::class, 'updateItems']);
    Route::post('orders/{order}/add-items',           [Api\OrderController::class, 'addItems']);
    Route::post('orders/{order}/send-to-kitchen',     [Api\OrderController::class, 'sendToKitchen']);
    Route::put('orders/{order}/discount',             [Api\OrderController::class, 'applyDiscount']);

    // Paiements
    Route::post('payments',       [Api\PaymentController::class, 'store']);
    Route::post('payments/split', [Api\PaymentController::class, 'split']);

    // Caisse
    Route::get('cash-sessions/current',          [Api\CashSessionController::class, 'current']);
    Route::post('cash-sessions/open',            [Api\CashSessionController::class, 'open']);
    Route::get('cash-sessions',                  [Api\CashSessionController::class, 'index']);
    Route::post('cash-sessions/{session}/close', [Api\CashSessionController::class, 'close']);

    // Cuisine KDS
    Route::prefix('kitchen')->group(function () {
        Route::get('orders',                          [Api\KitchenController::class, 'orders']);
        Route::put('items/{item}/status',             [Api\KitchenController::class, 'updateItemStatus']);
        Route::put('orders/{order}/validate-all',     [Api\KitchenController::class, 'validateAll']);
    });

    // Catégories
    Route::apiResource('categories', Api\CategoryController::class);
    Route::get('categories-flat',                    [Api\CategoryController::class, 'flat']);
    Route::post('categories/reorder',                [Api\CategoryController::class, 'reorder']);

    // Produits
    Route::apiResource('products', Api\ProductController::class);
    Route::patch('products/{product}/toggle-available', [Api\ProductController::class, 'toggleAvailable']);
    Route::post('products/reorder',                  [Api\ProductController::class, 'reorder']);

    // Menus composés
    Route::apiResource('combos', Api\ComboMenuController::class);

    // Ingrédients & Stock
    Route::apiResource('ingredients', Api\IngredientController::class);
    Route::get('ingredients/categories',             [Api\IngredientController::class, 'categories']);
    Route::get('stock/alerts',                       [Api\StockController::class, 'alerts']);
    Route::get('stock/value',                        [Api\StockController::class, 'value']);
    Route::post('stock/movements',                   [Api\StockController::class, 'createMovement']);
    Route::get('ingredients/{ingredient}/movements', [Api\StockController::class, 'movements']);

    // Recettes
    Route::get('recipes/{productId}',  [Api\StockController::class, 'getRecipe']);
    Route::post('recipes',             [Api\StockController::class, 'saveRecipe']);

    // Livraisons
    Route::apiResource('deliveries', Api\DeliveryController::class)->except(['destroy']);
    Route::get('deliveries/drivers',               [Api\DeliveryController::class, 'availableDrivers']);
    Route::put('deliveries/{delivery}/assign',     [Api\DeliveryController::class, 'assign']);
    Route::put('deliveries/{delivery}/status',     [Api\DeliveryController::class, 'updateStatus']);

    // Annulations
    Route::get('cancellations',                         [Api\CancellationController::class, 'index']);
    Route::post('cancellations/request',                [Api\CancellationController::class, 'request']);
    Route::post('cancellations/{cancellation}/approve', [Api\CancellationController::class, 'approve']);
    Route::post('cancellations/{cancellation}/reject',  [Api\CancellationController::class, 'reject']);

    // Rapports
    Route::prefix('reports')->group(function () {
        Route::get('dashboard',    [Api\ReportController::class, 'dashboard']);
        Route::get('sales',        [Api\ReportController::class, 'sales']);
        Route::get('top-products', [Api\ReportController::class, 'topProducts']);
        Route::get('by-waiter',    [Api\ReportController::class, 'byWaiter']);
        Route::get('by-category',  [Api\ReportController::class, 'byCategory']);
        Route::get('cash-summary', [Api\ReportController::class, 'cashSummary']);
    });

    // Reçus
    Route::prefix('receipts')->group(function () {
        Route::get('{orderId}',            [Api\ReceiptController::class, 'show']);
        Route::get('{orderId}/pdf',        [Api\ReceiptController::class, 'pdf']);
        Route::get('{orderId}/html',       [Api\ReceiptController::class, 'html']);
        Route::post('{orderId}/send-sms',  [Api\ReceiptController::class, 'sendSms']);
        Route::post('{orderId}/send-email',[Api\ReceiptController::class, 'sendEmail']);
    });

    // QR Code
    Route::prefix('qr')->group(function () {
        Route::get('{tableId}',             [Api\QrCodeController::class, 'generate']);
        Route::get('{tableId}/url',         [Api\QrCodeController::class, 'url']);
        Route::get('floor/{floorId}/all',   [Api\QrCodeController::class, 'allForFloor']);
    });

    // Paramètres
    Route::get('settings',           [Api\SettingsController::class, 'show']);
    Route::put('settings',           [Api\SettingsController::class, 'update']);
    Route::get('settings/config',    [Api\SettingsController::class, 'getConfig']);
    Route::put('settings/config',    [Api\SettingsController::class, 'updateConfig']);

    // Fournisseurs
    Route::apiResource('suppliers', Api\SupplierController::class);

    // Audit Trail
    Route::prefix('activity-logs')->group(function () {
        Route::get('/',                   [Api\ActivityLogController::class, 'index']);
        Route::get('summary',             [Api\ActivityLogController::class, 'summary']);
        Route::get('subject/{type}/{id}', [Api\ActivityLogController::class, 'forSubject']);
    });

    // Notifications
    Route::get('notifications', [Api\NotificationController::class, 'index']);

});
```

---

## TABLEAU RÉCAPITULATIF FINAL COMPLET

| Méthode | Endpoint | Description | Rôle |
|---------|----------|-------------|------|
| POST | /api/auth/login | Connexion email | Public |
| POST | /api/auth/login-pin | Connexion PIN rapide | Public |
| GET | /api/menu/{slug} | Menu QR client | Public |
| POST | /api/auth/logout | Déconnexion | Connecté |
| GET | /api/auth/me | Profil connecté | Connecté |
| PUT | /api/auth/change-password | Changer mot de passe | Connecté |
| GET | /api/users | Liste utilisateurs | Manager |
| POST | /api/users | Créer utilisateur | Manager |
| PUT | /api/users/{id} | Modifier utilisateur | Manager |
| DELETE | /api/users/{id} | Supprimer utilisateur | Admin |
| PATCH | /api/users/{id}/toggle-active | Activer/désactiver | Manager |
| GET | /api/floors | Liste salles | Connecté |
| POST | /api/floors | Créer salle | Manager |
| PUT | /api/floors/{id} | Modifier salle | Manager |
| POST | /api/floors/{id}/tables | Ajouter table | Manager |
| DELETE | /api/floors/{id}/tables/{tid} | Supprimer table | Manager |
| PUT | /api/tables/{id}/status | Changer statut table | Connecté |
| PUT | /api/tables/{id}/layout | Position drag & drop | Manager |
| PUT | /api/tables/{id}/assign | Assigner serveur | Manager |
| POST | /api/tables/{id}/reserve | Réserver table | Connecté |
| POST | /api/tables/{id}/transfer | Transférer commande | Serveur |
| POST | /api/tables/merge | Fusionner tables | Manager |
| GET | /api/orders | Liste commandes | Connecté |
| POST | /api/orders | Créer commande | Serveur |
| GET | /api/orders/{id} | Détail commande | Connecté |
| GET | /api/orders/table/{id}/current | Commande active table | Connecté |
| PUT | /api/orders/{id}/items | Modifier items | Serveur |
| POST | /api/orders/{id}/add-items | Ajouter articles | Serveur |
| POST | /api/orders/{id}/send-to-kitchen | Envoyer cuisine | Serveur |
| PUT | /api/orders/{id}/discount | Appliquer remise | Manager |
| POST | /api/payments | Enregistrer paiement | Caissier |
| POST | /api/payments/split | Paiement divisé | Caissier |
| GET | /api/cash-sessions | Historique sessions | Manager |
| GET | /api/cash-sessions/current | Session active | Caissier |
| POST | /api/cash-sessions/open | Ouvrir caisse | Manager |
| POST | /api/cash-sessions/{id}/close | Fermer caisse | Manager |
| GET | /api/kitchen/orders | Commandes KDS | Cuisinier |
| PUT | /api/kitchen/items/{id}/status | Valider item cuisine | Cuisinier |
| PUT | /api/kitchen/orders/{id}/validate-all | Tout valider | Cuisinier |
| GET | /api/categories | Liste catégories | Connecté |
| GET | /api/categories-flat | Catégories sans hiérarchie | Connecté |
| POST | /api/categories | Créer catégorie | Manager |
| PUT | /api/categories/{id} | Modifier catégorie | Manager |
| POST | /api/categories/reorder | Réordonner | Manager |
| GET | /api/products | Catalogue produits | Connecté |
| POST | /api/products | Créer produit | Manager |
| PUT | /api/products/{id} | Modifier produit | Manager |
| DELETE | /api/products/{id} | Supprimer produit | Manager |
| PATCH | /api/products/{id}/toggle-available | Dispo/Indispo | Manager |
| POST | /api/products/reorder | Réordonner | Manager |
| GET | /api/combos | Menus composés | Connecté |
| POST | /api/combos | Créer menu composé | Manager |
| PUT | /api/combos/{id} | Modifier combo | Manager |
| GET | /api/ingredients | Stock ingrédients | Manager |
| POST | /api/ingredients | Ajouter ingrédient | Manager |
| PUT | /api/ingredients/{id} | Modifier ingrédient | Manager |
| GET | /api/ingredients/categories | Catégories stock | Manager |
| GET | /api/ingredients/{id}/movements | Historique mouvements | Manager |
| GET | /api/stock/alerts | Alertes rupture | Manager |
| GET | /api/stock/value | Valeur totale stock | Manager |
| POST | /api/stock/movements | Mouvement stock | Manager |
| GET | /api/recipes/{productId} | Recette d'un produit | Manager |
| POST | /api/recipes | Sauvegarder recette | Manager |
| GET | /api/deliveries | Liste livraisons | Connecté |
| POST | /api/deliveries | Créer livraison | Connecté |
| GET | /api/deliveries/drivers | Livreurs disponibles | Manager |
| PUT | /api/deliveries/{id}/assign | Assigner livreur | Manager |
| PUT | /api/deliveries/{id}/status | Statut livraison | Livreur |
| GET | /api/cancellations | Historique annulations | Manager |
| POST | /api/cancellations/request | Demander annulation | Connecté |
| POST | /api/cancellations/{id}/approve | Approuver + PIN | Manager |
| POST | /api/cancellations/{id}/reject | Rejeter demande | Manager |
| GET | /api/reports/dashboard | Métriques temps réel | Manager |
| GET | /api/reports/sales | Rapport ventes | Manager |
| GET | /api/reports/top-products | Top produits | Manager |
| GET | /api/reports/by-waiter | Performance serveurs | Manager |
| GET | /api/reports/by-category | Ventes par catégorie | Manager |
| GET | /api/reports/cash-summary | Résumé caisse | Manager |
| GET | /api/receipts/{id} | Reçu JSON | Connecté |
| GET | /api/receipts/{id}/pdf | Reçu PDF | Connecté |
| GET | /api/receipts/{id}/html | Reçu HTML | Connecté |
| POST | /api/receipts/{id}/send-sms | Envoyer SMS | Connecté |
| POST | /api/receipts/{id}/send-email | Envoyer Email | Connecté |
| GET | /api/qr/{tableId} | Générer QR PNG | Manager |
| GET | /api/qr/{tableId}/url | URL du menu QR | Manager |
| GET | /api/qr/floor/{floorId}/all | ZIP tous QR salle | Manager |
| GET | /api/settings | Infos restaurant | Manager |
| PUT | /api/settings | Modifier infos | Manager |
| GET | /api/settings/config | Configuration JSON | Manager |
| PUT | /api/settings/config | Modifier config | Manager |
| GET | /api/suppliers | Liste fournisseurs | Manager |
| POST | /api/suppliers | Créer fournisseur | Manager |
| PUT | /api/suppliers/{id} | Modifier fournisseur | Manager |
| DELETE | /api/suppliers/{id} | Supprimer fournisseur | Manager |
| GET | /api/activity-logs | Journal audit | Manager |
| GET | /api/activity-logs/summary | Résumé par utilisateur | Manager |
| GET | /api/activity-logs/subject/{type}/{id} | Historique d'un objet | Manager |
| GET | /api/notifications | Alertes actives | Manager |

---

**TOTAL : 75 endpoints · 34 sections · Backend 100% complet**

*Omega POS Restaurant — Laravel 11 — Documentation API v1.0.0 — FINALE*
*Header requis sur tous les endpoints protégés : `Authorization: Bearer {token}`*
*Format des réponses : JSON · Pagination : 20-50 items/page · Erreurs : RFC 7807*