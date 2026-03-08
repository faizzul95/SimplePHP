# SimplePHP

A lightweight PHP 8.0+ framework with modern features for rapid web application development, combining procedural simplicity with Laravel-inspired architecture.

## Features

- **Organized File Structure** - Clean separation of controllers, helpers, and core components
- **Database Query Builder** - Fluent, expressive database interactions with Laravel-style eager loading — no models required
- **Modern HTTP Router** - Fast indexed routing with middleware pipeline, named routes, route groups, multi-parameter routes, `where()` constraints, middleware groups, and 405 Method Not Allowed detection
- **API Versioning** - Support for versioned (`/api/v1/users`) and non-versioned (`/api/users`) API routes via route group prefixing
- **Blade-like Template Engine** - Template compilation with caching, includes `@forelse`, `@method`, `@error`, `@checked`, `@class`, and more
- **Artisan-like Console** - CLI command system with argument parsing, scheduling (cron expressions), colored output, progress bars, and scaffolding generators
- **Laravel-like Auth** - Configurable session keys, `login()`, `attempt()`, OAuth/Socialite support, dual session + API token authentication
- **RBAC Permissions** - Role-based access control with abilities, checked for both session and token auth
- **Collection Class** - Fluent array wrapper with 60+ methods (map, filter, where, pluck, groupBy, reduce, etc.) inspired by Laravel Collection
- **Cache System** - Unified cache API with file and array drivers, remember pattern, counters, and batch operations
- **Job Queue** - Database-backed job queue with background workers, retries, failed job management, and delayed dispatch
- **Configurable Security Headers** - CSP and Permissions-Policy built from config, not hardcoded — easily add CDN domains
- **Schema Builder** - Fluent, database-agnostic API for creating tables, indexes, procedures, functions, triggers, and views
- **Migration System** - Versioned database schema changes with `up()`/`down()`, deploy.json tracking (no migration table needed), seeders, and `timestamps()`/`softDeletes()` shortcuts
- **Backup System** - Spatie-like backup component for database and file backups with cron scheduling and automatic cleanup
- **Request Handling** - Modern request/response utilities with FormRequest validation
- **API Component** - Token-based API authentication with rate limiting, CORS, and SQL injection protection
- **Middleware Traits** - Composable security traits (XSS protection, rate limiting, permissions) usable in custom middleware
- **Environment Configuration** - Multi-environment support
- **Helper Functions** - Extensive collection of utility functions including `collect()`, `cache()`, `dispatch()`

## Installation

1. Clone or download the project:
```bash
git clone https://github.com/faizzul95/simplephp.git
cd simplephp
```
2. Edit `app/config/*.php` with your database and mail settings

3. Run database migrations:
```bash
php myth migrate
php myth db:seed
```

## Configuration

### Environment Setup

Edit `app/config/database.php` to configure your application:

```php
<?php
global $config;

// Environment: development, staging, production
$config['environment'] = 'development';

// Database configuration (support multi connection)
$config['db'] = [
    'default' => [
        'development' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'your_database',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ]
    ],
    'slave' => [
        'development' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'your_database',
            'port' => '3306',
            'charset' => 'utf8mb4',
        ]
    ]
];

// Mail configuration
$config['mail'] = [
    'driver' => 'smtp',
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'your-email@gmail.com',
    'password' => 'your-app-password',
    'encryption' => 'TLS',
    'from_email' => 'your-email@gmail.com',
    'from_name' => 'Your App Name',
    'debug' => TRUE,
];
```

## Usage

### Routing

SimplePHP uses a clean URL router. Define routes in `app/routes/web.php` (web) or `app/routes/api.php` (API):

```php
// app/routes/web.php
use App\Http\Controllers\UserController;

$router->get('/users', [UserController::class, 'index'])->name('users.index');
$router->post('/users/save', [UserController::class, 'store'])->name('users.store');
$router->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$router->delete('/users/{id}', [UserController::class, 'destroy'])->name('users.delete');

// Multi-parameter routes
$router->get('/users/{id}/posts/{postId}', [PostController::class, 'show']);

// Route parameter constraints
$router->get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '[0-9]+');               // Regex constraint
    // ->whereNumber('id')                   // Shortcut for [0-9]+
    // ->whereAlpha('slug')                  // Shortcut for [a-zA-Z]+
    // ->whereAlphaNumeric('code')           // Shortcut for [a-zA-Z0-9]+

// Route groups with shared middleware and prefix
$router->group(['prefix' => 'admin', 'middleware' => ['auth.web']], function ($router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->resource('/roles', RoleController::class);
});
```

### API Versioning

```php
// app/routes/api.php — Versioned API
$router->group(['prefix' => '/api/v1', 'middleware' => ['auth.api']], function ($router) {
    $router->get('/users', [UserApiController::class, 'index']);
    $router->resource('/posts', PostApiController::class);
});

// Non-versioned API (internal use)
$router->group(['prefix' => '/api', 'middleware' => ['auth.api']], function ($router) {
    $router->get('/health', [SystemController::class, 'health']);
});
```

### Form Submissions

Forms submit to named routes — no hidden `action` fields needed:

```html
<form method="POST" action="{{ route('users.store') }}">
    @csrf
    <input type="text" name="name" required>
    <input type="email" name="email" required>
    <button type="submit">Submit</button>
</form>
```

### API Calls

```javascript
// Use named routes via the route() helper or direct URLs
const res = await callApi('get', "/api/v1/users");
const user = await callApi('get', `/api/v1/users/${id}`);
const saved = await callApi('post', "/api/v1/users", {
    name: 'John Doe',
    email: 'john@example.com'
});
```

### Controller Structure (Class-Based)

Controllers are classes with methods mapped to routes. Use **FormRequest** for automatic validation:

```php
<?php
// app/http/controllers/UserController.php

namespace App\Http\Controllers;

use Core\Http\Controller;
use App\Http\Requests\SaveUserRequest;
use Core\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        $users = db()->table('users')->whereNull('deleted_at')->safeOutput()->paginate();
        return view('directory/users', ['users' => $users]);
    }

    public function store(SaveUserRequest $request)
    {
        $data = $request->validated();

        $result = db()->table('users')->insertOrUpdate(
            ['id' => $request->input('id')],
            $data
        );

        if (isError($result['code'])) {
            return ['code' => 422, 'message' => 'Failed to save user'];
        }

        return ['code' => 200, 'message' => 'User saved'];
    }

    public function show(string $id)
    {
        $data = db()->table('users')->where('id', (int) $id)->safeOutput()->fetch();

        if (!$data) {
            return ['code' => 404, 'message' => 'User not found'];
        }

        return ['code' => 200, 'data' => $data];
    }

    public function destroy(string $id)
    {
        $result = db()->table('users')->where('id', (int) $id)->softDelete();

        if (isError($result['code'])) {
            return ['code' => 422, 'message' => 'Failed to delete data'];
        }

        return ['code' => 200, 'message' => 'Data deleted'];
    }
}
```

### FormRequest Validation

```php
<?php
// app/http/requests/SaveUserRequest.php

namespace App\Http\Requests;

use Core\Http\FormRequest;

class SaveUserRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'name'  => 'required|string|min_length:3|max_length:255',
            'email' => 'required|email|max_length:255',
        ];

        if ($this->isCreate()) {
            $rules['password'] = 'required|min_length:8';
        }

        return $rules;
    }

    public function authorize(): bool
    {
        return true;
    }
}
```
```

## Database Usage

SimplePHP provides an elegant query builder for database operations with Laravel-style Query Scopes and Macros:

### Basic Queries

### Query Scopes and Macros

SimplePHP provides Laravel-style query scopes and macros to help you reuse common query patterns:

#### Built-in Query Scopes

Here are the built-in scopes available in SimplePHP:

1. **Soft Delete Scopes**:
```php
// Include soft-deleted records
$users = db()->table('users')->withTrashed()->get();

// Get only soft-deleted records
$users = db()->table('users')->onlyTrashed()->get();
```

2. **Date-based Scopes**:
```php
// Order by created_at DESC
$users = db()->table('users')->latest()->get();

// Get records from last 7 days
$users = db()->table('users')->recent(7)->get();
```

You can combine multiple scopes for complex queries:

```php
// Example: Get recently deleted users from the last 30 days
$recentDeletedUsers = db()->table('users')
    ->onlyTrashed()
    ->recent(30)
    ->latest()
    ->get();
```

### Registering Query Scopes

SimplePHP offers multiple ways to register and configure query scopes. Here are the different approaches:

#### 1. Global Scope Registration

In your `app.php`, scopes are loaded automatically when initializing the database connection:

```php
if (!empty($conn_db)) {
    loadScopeMacroDBFunctions(
        $conn_db,
        [], // Individual scope files
        ['ScopeMacroQuery'], // Folder containing scope definitions
        '../controllers/', 
        false
    );
}
```

#### 2. Direct Scope Registration

You can register scopes directly using the `scopes/scopes` method:

```php
// Register single scope, structure : scope($callback, $functionName)
db()->scope(function() { return $this->where('status', 1); }, 'active');

// Register multiple scopes
db()->scopes([
    'active' => function() {
        return $this->where('status', 1);
    },
    'featured' => function() {
        return $this->where('is_featured', 1);
    }
]);
```

#### 3. File-based Scope Registration

Create your scopes in `controllers/ScopeMacroQuery/Scope.php`:

```php
// Scope.php
function scopeQuery($db)
{
    $listOfScope = [
        'withTrashed' => function ($table = null) {
            return $this->whereNull(empty($table) ? 'deleted_at' : "{$table}.deleted_at");
        },
        'active' => function() {
            return $this->where('status', 1);
        }
    ];

    // Register scopes
    if (!empty($listOfScope)) {
        $db->scopes($listOfScope);
    }
}
```

#### 4. Dynamic Scope Registration

You can register scopes dynamically based on conditions:

```php
// Register scopes based on configuration
$config = [
    'enable_soft_deletes' => true,
    'enable_status_scopes' => true
];

$scopes = [];

if ($config['enable_soft_deletes']) {
    $scopes['withTrashed'] = function() {
        return $this->whereNull('deleted_at');
    };
}

if ($config['enable_status_scopes']) {
    $scopes['active'] = function() {
        return $this->where('status', 1);
    };
}

db()->scopes($scopes);
```

#### 5. Module-based Scope Registration

For larger applications, you can organize scopes by modules:

```php
// scopes/UserScopes.php
$userScopes = [
    'active' => function() {
        return $this->where('status', 1);
    },
    'verified' => function() {
        return $this->whereNotNull('email_verified_at');
    }
];

// scopes/OrderScopes.php
$orderScopes = [
    'paid' => function() {
        return $this->where('payment_status', 'paid');
    },
    'pending' => function() {
        return $this->where('status', 'pending');
    }
];

// Register module scopes
db()->scopes(array_merge($userScopes, $orderScopes));
```

#### Best Practices for Scope Registration

1. **Namespace Your Scopes**: Use clear, descriptive names that indicate the scope's purpose
```php
$scopes = [
    'userActive' => function() { ... },
    'userVerified' => function() { ... },
    'orderPaid' => function() { ... }
];
```

2. **Group Related Scopes**: Keep related scopes together
```php
$scopes = [
    // Status scopes
    'active' => function() { ... },
    'inactive' => function() { ... },
    
    // Date scopes
    'recent' => function($days = 7) { ... },
    'thisMonth' => function() { ... },
    
    // Role scopes
    'isAdmin' => function() { ... },
    'isUser' => function() { ... }
];
```

3. **Document Your Scopes**: Add comments to explain complex scopes
```php
$scopes = [
    // Checks if user has completed all required profile fields
    'profileComplete' => function() {
        return $query->whereNotNull('email')
        ->whereNotNull('phone')
        ->whereNotNull('address');
    }
];
```

#### 2. Scope Definition Structure

Create your scopes in `controllers/ScopeMacroQuery/Scope.php`:

```php
function scopeQuery($db)
{
    try {
        // Define all available scopes
        $listOfScope = [
            // Soft Delete Scopes
            'withTrashed' => function ($table = null) {
                return $this->whereNull(empty($table) ? 'deleted_at' : "{$table}.deleted_at");
            },
            'onlyTrashed' => function ($table = null) {
                return $this->whereNotNull(empty($table) ? 'deleted_at' : "{$table}.deleted_at");
            },
            
            // Date-based Scopes
            'latest' => function ($column = 'created_at') {
                return $this->orderBy($column, 'DESC');
            },
            'oldest' => function ($column = 'created_at') {
                return $this->orderBy($column, 'ASC');
            },
            'recent' => function (int $days = 7) {
                $date = date('Y-m-d', strtotime("-{$days} days"));
                return $this->whereDate('created_at', '>=', $date);
            },
            
            // Status Scopes
            'active' => function ($column = 'status') {
                return $this->where($column, 1);
            },
            'inactive' => function ($column = 'status') {
                return $this->where($column, 0);
            },
            
            // Time-based Scopes
            'thisWeek' => function ($column = 'created_at') {
                return $this->whereBetween($column, [
                    date('Y-m-d', strtotime('monday this week')),
                    date('Y-m-d', strtotime('sunday this week'))
                ]);
            },
            'thisMonth' => function ($column = 'created_at') {
                return $this->whereMonth($column, date('m'))
                           ->whereYear($column, date('Y'));
            },
            'thisYear' => function ($column = 'created_at') {
                return $this->whereYear($column, date('Y'));
            },
            
            // Verification Scopes
            'verified' => function ($column = 'email_verified_at') {
                return $this->whereNotNull($column);
            },
            'unverified' => function ($column = 'email_verified_at') {
                return $this->whereNull($column);
            },
            
            // Role-based Scopes
            'admin' => function () {
                return $this->where('role', 'admin');
            },
            'notAdmin' => function () {
                return $this->where('role', '!=', 'admin');
            }
        ];
        
        // Register scopes
        if (!empty($listOfScope)) {
            $db->scopes($listOfScope);
        }
    } catch (Exception $e) {
        logger()->logException($e);
    }
}
```

### Using Query Scopes

Here are comprehensive examples of using scopes in your queries:

```php
// Basic Usage Examples

// Get all active users
$activeUsers = db()->table('users')
    ->active()
    ->get();

// Get recently deleted users from last 14 days
$recentlyDeleted = db()->table('users')
    ->onlyTrashed()
    ->recent(14)
    ->get();

// Get verified users registered this month
$newVerifiedUsers = db()->table('users')
    ->verified()
    ->thisMonth()
    ->get();

// Complex Examples

// Get active admins who registered this week
$newAdmins = db()->table('users')
    ->active()
    ->admin()
    ->thisWeek()
    ->get();

// Get unverified users who registered last 30 days
$unverifiedUsers = db()->table('users')
    ->unverified()
    ->recent(30)
    ->orderBy('created_at', 'DESC')
    ->get();

// Get inactive non-admin users
$inactiveRegularUsers = db()->table('users')
    ->inactive()
    ->notAdmin()
    ->get();

// Scopes with Relationships
$activeUsersWithPosts = db()->table('users')
    ->active()
    ->with('posts', 'posts', 'user_id', 'id', function($query) {
        $query->latest() // Using scope in relationship
              ->withTrashed(); // Including soft-deleted posts
    })
    ->get();

// Scopes with Parameters
$customDateRange = db()->table('orders')
    ->latest('order_date') // Using custom column
    ->recent(90) // Last 90 days
    ->get();

// Combining Multiple Scopes with Regular Query Builder Methods
$result = db()->table('users')
    ->select('id', 'name', 'email', 'created_at')
    ->active()
    ->verified()
    ->thisMonth()
    ->where('subscription_status', 'premium')
    ->latest()
    ->paginate(20);
```

#### Query Macros

Macros let you define custom query methods:

```php
// Using the whereLike macro for easier LIKE queries
$users = db()->table('users')
    ->whereLike('name', 'john') // Equivalent to ->where('name', 'LIKE', '%john%')
    ->get();
```

### How to Define Custom Scopes and Macros

Create a new file in `controllers/ScopeMacroQuery/Scope.php` for scopes:

```php
function scopeQuery($db)
{
    $listOfScope = [
        'withTrashed' => function ($table = null) {
            return $this->whereNull(empty($table) ? 'deleted_at' : "{$table}.deleted_at");
        },
        'onlyTrashed' => function ($table = null) {
            return $this->whereNotNull(empty($table) ? 'deleted_at' : "{$table}.deleted_at");
        },
        'active' => function () {
            return $this->where('status', 1);
        },
        'inactive' => function () {
            return $this->where('status', 0);
        }
    ];

    // Register scopes
    if (!empty($listOfScope)) {
        $db->scopes($listOfScope);
    }
}
```

Create a new file in `controllers/ScopeMacroQuery/Macro.php` for macros:

```php
function macroQuery($db)
{
    $listOfMacros = [
        'whereLike' => function ($column, $value) {
            return $this->where($column, 'LIKE', "%{$value}%");
        },
        'whereNotLike' => function ($column, $value) {
            return $this->where($column, 'NOT LIKE', "%{$value}%");
        },
        'whereStartsWith' => function ($column, $value) {
            return $this->where($column, 'LIKE', "{$value}%");
        },
        'whereEndsWith' => function ($column, $value) {
            return $this->where($column, 'LIKE', "%{$value}");
        }
    ];

    // Register macros
    if (!empty($listOfMacros)) {
        $db->macros($listOfMacros);
    }
}
```

### Basic Queries (Builder)

```php
// Select all users
$users = db()->table('users')->get();

// Faster way without using ->table()
$usersAll = db()->get('users');
$usersSingle = db()->fetch('users');
$usersCount = db()->count('users');

// Select specific columns with conditions
$users = db()->table('users')
    ->select('id, name, email')
    ->where('status', 1)
    ->get();

// Get single record
$user = db()->table('users')
    ->where('id', 1)
    ->fetch();

// Get count record
$user = db()->table('users')
    ->where('status', 1)
    ->count();

// Check if the records is exist or not
$userExists = db()->table('users')
    ->where('email', 'test@gmail.com')
    ->whereNull('deleted_at')
    ->exists();

// Process records in batches using cursor (memory efficient)
$cursor = db()->table('users')
    ->where('status', 1)
    ->cursor(300);

foreach ($cursor as $user) {
    // Process each user record
    echo $user['name'] . "\n";
}

// Process records in chunks of 300
db()->table('users')
    ->where('status', 1)
    ->chunk(300, function($data) {
        foreach ($data as $user) {
            // Process each user in the chunk
            echo $user['name'] . "\n";
        }
    });

// Lazy load records in batches of 200
$users = db()->table('users')
    ->where('status', 1)
    ->lazy(200);

foreach ($users as $user) {
    // Each user is loaded on-demand
    echo $user['name'] . "\n";
}

// Extract single column values using pluck
// Simple pluck - returns array of values
$names = db()->table('users')->pluck('name');
// Result: ['John', 'Jane', 'Mike', ...]

// Pluck with key - returns associative array
$userEmails = db()->table('users')->pluck('email', 'id');
// Result: [1 => 'john@example.com', 2 => 'jane@example.com', ...]

// Pluck with nested relationships using dot notation
$schoolNames = db()->table('users')
    ->withOne('school_user', 'school_users', 'user_id', 'id', function ($q) {
        $q->withOne('school_info', 'schools', 'id', 'school_id');
    })
    ->pluck('school_user.school_info.name');
// Result: ['School A', 'School B', 'School C', ...]

// Pluck with multiple levels and custom keys
$profilesBySchool = db()->table('users')
    ->withOne('school_user', 'school_users', 'user_id', 'id', function ($q) {
        $q->withOne('school_info', 'schools', 'id', 'school_id')
          ->withOne('profile_info', 'school_profiles', 'id', 'school_profile_id');
    })
    ->pluck('school_user.profile_info.display_name', 'school_user.school_info.name');
// Result: ['School A' => 'Admin Profile', 'School B' => 'Teacher Profile', ...]

// Pluck works with array relationships (automatically takes first item)
$firstSchoolNames = db()->table('users')
    ->with('school_users', 'school_users', 'user_id', 'id', function ($q) {
        $q->withOne('school_info', 'schools', 'id', 'school_id');
    })
    ->pluck('school_users.school_info.name');
// Result: Gets school name from first school_user relationship

// Pluck with filtering and limits
$activeUserNames = db()->table('users')
    ->where('status', 1)
    ->limit(50)
    ->pluck('name', 'id');

// Use pluck with lazy loading for memory efficiency
$allEmails = db()->table('users')
    ->lazy(500) // Process in chunks of 500
    ->pluck('email')
    ->all(); // Convert lazy collection to array

// Basic where
$users = db()->table('users')->where('status', 1)->fetch();

// WHERE with '=' (default : No need to specify =)
$users = db()->table('users')->where('status', '=', 1)->paginate();

// WHERE with '<>'
$users = db()->table('users')->where('role', '<>', 'admin')->get();

// WHERE with '!='
$users = db()->table('users')->where('status', '!=', 0)->get();

// WHERE with '>'
$users = db()->table('users')->where('score', '>', 80)->get();

// WHERE with '<'
$users = db()->table('users')->where('age', '<', 30)->get();

// WHERE with '>='
$users = db()->table('users')->where('created_at', '>=', '2024-01-01')->get();

// WHERE with '<='
$users = db()->table('users')->where('created_at', '<=', '2024-12-31')->get();

// WHERE with 'LIKE'
$users = db()->table('users')->where('name', 'LIKE', '%john%')->fetch();

// WHERE with 'NOT LIKE'
$users = db()->table('users')->where('name', 'NOT LIKE', '%john%')->get();

// WHERE with ARRAY (use default '='), it will chaining multiple where()
$users = db()->table('users')->where(['name' => 'john', 'status' => 1])->get();

// WHERE with closure/callback
$users = db()->table('users')->where(function ($query) {
        $query->where('status', '1')->orWhere('gender', 'm');
    })->toSql();

// Multiple where
$users = db()->table('users')->where('status', 1)->where('role', 'admin')->get();

// orWhere
$users = db()->table('users')->where('status', 1)->orWhere('role', 'admin')->get();

// whereIn & orWhereIn
$users = db()->table('users')->whereIn('id', [1, 2, 3])->orWhereIn('role', ['admin', 'editor'])->get();

// whereNotIn & orWhereNotIn
$users = db()->table('users')->whereNotIn('status', [0, 2])->orWhereNotIn('role', ['banned', 'guest'])->get();

// whereBetween & orWhereBetween
$users = db()->table('users')->whereBetween('created_at', ['2024-01-01', '2024-12-31'])->orWhereBetween('score', [50, 100])->get();

// whereNotBetween & orWhereNotBetween
$users = db()->table('users')->whereNotBetween('age', [18, 25])->orWhereNotBetween('salary', [1000, 2000])->get();

// whereNull & orWhereNull
$users = db()->table('users')->whereNull('deleted_at')->orWhereNull('last_login')->get();

// whereNotNull & orWhereNotNull
$users = db()->table('users')->whereNotNull('email_verified_at')->orWhereNotNull('profile_picture')->get();

// whereDate & orWhereDate
$users = db()->table('users')->whereDate('created_at', '2024-06-18')->orWhereDate('updated_at', '<=', '2024-06-01')->get();

// whereDay & orWhereDay
$users = db()->table('users')->whereDay('created_at', 18)->orWhereDay('updated_at', 1)->get();

// whereMonth & orWhereMonth
$users = db()->table('users')->whereMonth('created_at', 6)->orWhereMonth('updated_at', 5)->get();

// whereYear & orWhereYear
$users = db()->table('users')->whereYear('created_at', 2024)->orWhereYear('updated_at', 2023)->get();

// whereColumn - compare two columns
$users = db()->table('users')->whereColumn('created_at', '<', 'updated_at')->get();

// whereHas - filter by related records existence
$usersWithProfiles = db()->table('users')
    ->whereHas('user_profile', 'user_id', 'id', function($q) {
        $q->where('profile_status', 1);
    })
    ->get();

// whereDoesntHave - filter by missing related records
$usersWithoutProfiles = db()->table('users')
    ->whereDoesntHave('user_profile', 'user_id', 'id')
    ->get();

```

### Additional Query Methods

```php
// distinct - get unique records
$uniqueStatuses = db()->table('users')->distinct('user_status')->get();

// value - get single column value from first record
$email = db()->table('users')->where('id', 1)->value('email');

// exists & doesntExist
$hasUsers = db()->table('users')->where('status', 1)->exists(); // returns true/false
$isEmpty = db()->table('users')->where('status', 99)->doesntExist(); // returns true/false

// firstOrFail - throws exception if not found
$user = db()->table('users')->where('id', $id)->firstOrFail();

// sole - throws exception if none or multiple found
$user = db()->table('users')->where('email', $email)->sole();

// firstOrCreate - find or create a record
$user = db()->table('users')->firstOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe', 'status' => 1]
);

// increment & decrement
db()->table('users')->where('id', 1)->increment('login_count');
db()->table('users')->where('id', 1)->increment('points', 10);
db()->table('products')->where('id', 1)->decrement('stock', 5);

// skip & take (aliases for offset & limit)
$users = db()->table('users')->skip(10)->take(5)->get();

// forPage - paginate results
$users = db()->table('users')->forPage(2, 15)->get(); // page 2, 15 per page

// union & unionAll
$admins = db()->table('users')->where('role', 'admin');
$editors = db()->table('users')->where('role', 'editor');
$staff = $admins->union($editors)->get();

// inRandomOrder - randomize results
$randomUsers = db()->table('users')->inRandomOrder()->limit(5)->get();

// latest & oldest
$newestUsers = db()->table('users')->latest()->limit(10)->get();
$oldestUsers = db()->table('users')->oldest()->limit(10)->get();

// reorder - remove existing order and set new one
$users = db()->table('users')->orderBy('name')->reorder('created_at', 'DESC')->get();

// when & unless - conditional query building
$status = request()->input('status');
$users = db()->table('users')
    ->when($status, function($q) use ($status) {
        $q->where('status', $status);
    })
    ->unless(empty($search), function($q) use ($search) {
        $q->where('name', 'LIKE', "%{$search}%");
    })
    ->get();

// tap - inspect query without modifying
$users = db()->table('users')
    ->where('status', 1)
    ->tap(function($query) {
        logger()->log($query->toSql());
    })
    ->get();
```

### Index Hints

```php
// useIndex - suggest MySQL to use specific index
$users = db()->table('users')
    ->useIndex('idx_users_status')
    ->where('status', 1)
    ->get();

// forceIndex - force MySQL to use specific index
$users = db()->table('users')
    ->forceIndex('idx_users_email')
    ->where('email', $email)
    ->get();

// ignoreIndex - tell MySQL to ignore specific index
$users = db()->table('users')
    ->ignoreIndex('idx_users_created')
    ->get();

// Multiple indexes
$users = db()->table('users')
    ->useIndex(['idx_users_status', 'idx_users_email'])
    ->where('status', 1)
    ->get();
```

### Query Caching & Performance

```php
// Enable query caching with TTL (in seconds)
$users = db()->enableQueryCache(3600)
    ->table('users')
    ->where('status', 1)
    ->get();

// Disable query caching
db()->disableQueryCache();

// Get performance report
$report = db()->getPerformanceReport();

// Analyze query performance
$analysis = db()->table('users')
    ->where('status', 1)
    ->analyze();

// Enable profiling
db()->setProfilingEnabled(true);

// Get profiler data
$profilerData = db()->profiler();
```

### Transaction with Callback

```php
// Automatic transaction with callback (auto commit/rollback)
$result = db()->transaction(function($db) {
    $db->table('users')->insert(['name' => 'John']);
    $db->table('user_profile')->insert(['user_id' => $db->getPdo()->lastInsertId()]);
    
    return true; // Commit on success
});

// Manual transaction control
db()->beginTransaction();
try {
    db()->table('users')->insert(['name' => 'Jane']);
    db()->table('logs')->insert(['action' => 'user_created']);
    db()->commit();
} catch (Exception $e) {
    db()->rollback();
    throw $e;
}
```

### Dry Run Mode

```php
// Enable dry run - builds query without executing
$query = db()->table('users')
    ->where('status', 1)
    ->dryRun()
    ->get();

// Returns the query info without executing
// Useful for debugging and testing queries
```

## Security Notes

- Always use `->safeOutput()` when working with the query builder before calling `->get()`, `->fetch()`, `->paginate()`, or `->paginate_ajax()` to prevent XSS injection from being displayed on the frontend.
- CSRF protection is enabled by default for all POST/PUT/PATCH/DELETE requests. Use `@csrf` in forms. AJAX calls via `callApi()` automatically include the CSRF token.
- CSP headers are configurable in `app/config/security.php` — add CDN domains to the `csp` array without changing code.
- Trusted proxies are configurable in `security.php` to prevent IP spoofing.
- All passwords use `password_hash(PASSWORD_DEFAULT)` (bcrypt) and `password_verify()`.
- Bearer tokens are SHA-256 hashed before database storage.
- Session hardening: HttpOnly, SameSite=Lax, Secure (production), strict mode, session regeneration on login.
- Scheduler output paths are sanitized against directory traversal.
- Rate limiter file writes use `LOCK_EX` for atomic operations.

### Advanced Queries with Relationships (Using eager loading)

```php
// Complex query with nested relationships
$db = db(); // ensure it using the same connection for all query related
$userData = $db->table('users')
    ->select('id, name, user_preferred_name, email')
    ->where('id', $userID)
    ->withOne('profile', 'user_profile', 'user_id', 'id', function ($db) {
        $db->select('id, user_id, role_id')
            ->where('profile_status', 1)
            ->where('is_main', 1)
            ->withOne('roles', 'master_roles', 'id', 'role_id', function ($db) {
                $db->select('id,role_name')->where('role_status', 1)
                    ->with('permission', 'system_permission', 'role_id', 'id', function ($db) {
                        $db->select('id,role_id,abilities_id')
                            ->withOne('abilities', 'system_abilities', 'id', 'abilities_id', function ($db) {
                                $db->select('id,abilities_name,abilities_slug');
                            });
                    });
            });
    })
    ->fetch();
```

## Eager Loading (Relationships)

### `with($alias, $table, $foreign_key, $local_key, \Closure $callback = null)`

| Parameter      | Description                                              | Example Value      |
|----------------|---------------------------------------------------------|--------------------|
| `$alias`       | Key name for related data in result                     | `'posts'`          |
| `$table`       | Related table name                                      | `'posts'`          |
| `$foreign_key` | Foreign key in related table                            | `'user_id'`        |
| `$local_key`   | Local key in main table                                 | `'id'`             |
| `$callback`    | (Optional) Closure to customize the relation query      | `function($q){}`   |

**Example:**
```php
// Loads all posts for each user
db()->table('users')->with('posts', 'posts', 'user_id', 'id')->paginate();
```

---

### `withOne($alias, $table, $foreign_key, $local_key, \Closure $callback = null)`

| Parameter      | Description                                              | Example Value      |
|----------------|---------------------------------------------------------|--------------------|
| `$alias`       | Key name for related data in result                     | `'profile'`        |
| `$table`       | Related table name                                      | `'profiles'`       |
| `$foreign_key` | Foreign key in related table                            | `'user_id'`        |
| `$local_key`   | Local key in main table                                 | `'id'`             |
| `$callback`    | (Optional) Closure to customize the relation query      | `function($q){}`   |

**Example:**
```php
// Loads the profile for each user
db()->table('users')->withOne('profile', 'profiles', 'user_id', 'id')->get();
```

### Insert, Update, Delete, Soft delete, InsertOrUpdate, Truncate

```php
// Insert new record
db()->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT),
    'created_at' => timestamp()
]);

// Update existing record
db()->table('users')
    ->where('id', 1)
    ->update(['name' => 'Jane Doe']);

// Delete record
db()->table('system_login_attempt')
    ->where('user_id', $userData['id'])
    ->delete();

// Soft delete a user (set deleted_at to current timestamp)
db()->table('users')
    ->where('id', 1)
    ->softDelete();

// Soft delete and set status to 0 (inactive)
db()->table('users')
    ->where('id', 1)
    ->softDelete(['deleted_at' => date('Y-m-d H:i:s'), 'status' => 0]);

// Soft delete with custom column and value
db()->table('users')
    ->where('id', 1)
    ->softDelete('status', 0);

// Insert or Update record (similar to upsert laravel)
db()->table('users')->upsert(['name' => 'Jane Doe']);

// Insert or Update record 
db()->table('users')->insertOrUpdate(
    [
        'id' => request()->input('id')
    ],
    request()->all()
);

// Truncate table
db()->table('users')->truncate(); // or db()->truncate('users'); 
```

# Raw SQL Queries (No Eager Loading Support)

## Database Query Methods Overview

This document provides examples of database query methods including `selectQuery()`, `query()`, `get()`, `fetch()`, and `execute()`.

## Important Notes

- These methods **do not** support eager loading of related data. If you need to load related models or entities, use the ORM or query builder features instead.
- Always sanitize and validate user input to prevent SQL injection when using raw queries.
- Use parameter binding where possible for added security.

## Query Methods Overview

### ->query() with ->get() and ->fetch()

Used for SELECT queries that return data:

```php
// Parameterized queries for security. Can use either ? or :0
$userData = db()->query(
    "SELECT `id`, `password` FROM `users` WHERE `email` = :0 OR `username` = :0", 
    [$username]
)->get();

// Count queries with time conditions
$countAttempt = db()->query(
    "SELECT COUNT(*) as count FROM `system_login_attempt` 
     WHERE `ip_address` = ? AND `time` > NOW() - INTERVAL 10 MINUTE AND `user_id` = ?", 
    [$ipUser, $userData['id']]
)->fetch();
```

### ->execute() Method

Used for raw SQL queries that don't return data or for operations that modify the database structure. Always chained after `->query()`. Supports all SQL statement types:

#### SELECT Examples
```php
// Basic SELECT with execute (returns set of records)
$result = db()->query("SELECT * FROM users WHERE active = 1")->execute();

// SELECT with parameters
$result = db()->query("SELECT * FROM products WHERE category = ? AND price > ?", ['electronics', 100])->execute();

// More simple and faster
$result = db()->selectQuery("SELECT * FROM user_profile WHERE banned != 1");

// More simple and faster with parameters
$result = db()->selectQuery("SELECT * FROM user_profile WHERE banned != ?", [1]);
```

#### INSERT Examples
```php
// Insert new user
$result = db()->query(
    "INSERT INTO users (username, email, password, created_at) VALUES (?, ?, ?, NOW())",
    ['john_doe', 'john@example.com', 'hashed_password']
)->execute();

// Insert multiple values
$result = db()->query(
    "INSERT INTO categories (name, description) VALUES ('Tech', 'Technology products'), ('Home', 'Home appliances')"
)->execute();
```

#### UPDATE Examples
```php
// Update user information
$result = db()->query(
    "UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?",
    ['newemail@example.com', 123]
)->execute();

// Update multiple columns with conditions
$result = db()->query(
    "UPDATE products SET price = price * 1.1, updated_at = NOW() WHERE category = ?",
    ['electronics']
)->execute();
```

#### DELETE Examples
```php
// Delete specific user
$result = db()->query("DELETE FROM users WHERE id = ?", [123])->execute();

// Delete with multiple conditions
$result = db()->query(
    "DELETE FROM login_attempts WHERE ip_address = ? AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    ['192.168.1.1']
)->execute();
```

#### TRUNCATE Examples
```php
// Clear all data from table
$result = db()->query("TRUNCATE TABLE temp_data")->execute();

// Truncate log table
$result = db()->query("TRUNCATE TABLE system_logs")->execute();
```

#### SHOW Examples
```php
// Show all tables
$result = db()->query("SHOW TABLES")->execute();

// Show table structure
$result = db()->query("SHOW COLUMNS FROM users")->execute();

// Show database information
$result = db()->query("SHOW DATABASES")->execute();
```

#### DESCRIBE Examples
```php
// Describe table structure
$result = db()->query("DESCRIBE users")->execute();

// Describe specific table
$result = db()->query("DESCRIBE products")->execute();
```

#### DROP Examples
```php
// Drop table
$result = db()->query("DROP TABLE IF EXISTS temp_table")->execute();

// Drop database
$result = db()->query("DROP DATABASE IF EXISTS old_database")->execute();

// Drop index
$result = db()->query("DROP INDEX idx_email ON users")->execute();
```

#### ALTER Examples
```php
// Add new column
$result = db()->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) AFTER email")->execute();

// Modify column
$result = db()->query("ALTER TABLE users MODIFY COLUMN username VARCHAR(100) NOT NULL")->execute();

// Drop column
$result = db()->query("ALTER TABLE users DROP COLUMN old_field")->execute();

// Add index
$result = db()->query("ALTER TABLE users ADD INDEX idx_username (username)")->execute();
```

#### GRANT Examples
```php
// Grant privileges to user
$result = db()->query("GRANT SELECT, INSERT ON database.* TO 'username'@'localhost'")->execute();

// Grant all privileges
$result = db()->query("GRANT ALL PRIVILEGES ON *.* TO 'admin'@'%' WITH GRANT OPTION")->execute();
```

#### REVOKE Examples
```php
// Revoke specific privileges
$result = db()->query("REVOKE INSERT, UPDATE ON database.* FROM 'username'@'localhost'")->execute();

// Revoke all privileges
$result = db()->query("REVOKE ALL PRIVILEGES ON *.* FROM 'user'@'localhost'")->execute();
```

## Method Usage Guidelines

- Use `->query()->get()` or `->query()->fetch()` or `->selectQuery()` for SELECT statements where you need the returned data
- Use `->query()->execute()` for:
  - INSERT, UPDATE, DELETE operations
  - DDL statements (CREATE, ALTER, DROP)
  - Administrative commands (GRANT, REVOKE, SHOW, DESCRIBE)

## Parameter Binding

Both methods support parameter binding for security:
- Use `?` for positional parameters
- Use `:0`, `:1`, etc. for named parameters
- Always use parameterized queries to prevent SQL injection

## Controllers (Class-Based)

Controllers in SimplePHP use class methods routed via the HTTP Router:

### Web Controller Example

```php
<?php
// app/http/controllers/AuthController.php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth/login');
    }

    public function authorize(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');

        $userData = db()->query(
            "SELECT `id`, `password` FROM `users` WHERE (`email` = :0 OR `username` = :0) AND `deleted_at` IS NULL",
            [$username]
        )->fetch();

        if (empty($userData) || !password_verify($password, $userData['password'])) {
            return ['code' => 400, 'message' => 'Invalid username or password'];
        }

        $response = loginSessionStart($userData, 1);

        // Clear login attempts on success
        db()->table('system_login_attempt')->where('user_id', $userData['id'])->delete();

        return $response;
    }

    public function logout()
    {
        session_destroy();
        return [
            'code' => 200,
            'message' => 'Logout',
            'redirectUrl' => url(REDIRECT_LOGIN),
        ];
    }
}
```

### API Controller Example

```php
<?php
// app/http/controllers/Api/UserApiController.php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreUserRequest;
use Core\Http\Request;

class UserApiController
{
    public function index(Request $request): array
    {
        $limit = (int) $request->input('limit', 20);
        $data = db()->table('users')
            ->select('id, name, email, username, user_status, created_at')
            ->whereNull('deleted_at')
            ->orderBy('id', 'DESC')
            ->limit(min($limit, 100))
            ->safeOutput()
            ->get();

        return ['code' => 200, 'data' => $data];
    }

    public function store(StoreUserRequest $request): array
    {
        $payload = $request->validated();
        $insert = db()->table('users')->insert([
            'name'     => $payload['name'],
            'email'    => $payload['email'],
            'username' => $payload['username'],
            'password' => password_hash($payload['password'], PASSWORD_DEFAULT),
        ]);

        return isSuccess($insert['code'] ?? 500)
            ? ['code' => 201, 'message' => 'User created']
            : ['code' => 422, 'message' => 'Failed to create user'];
    }
}
```

### Route Registration

```php
// app/routes/web.php
$router->get('/login', [AuthController::class, 'showLogin'])->name('login');
$router->post('/auth/login', [AuthController::class, 'authorize'])->name('auth.login');
$router->post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

// app/routes/api.php
$router->group(['prefix' => 'api/v1', 'middleware' => ['auth.api']], function ($router) {
    $router->get('/users', [UserApiController::class, 'index']);
    $router->post('/users', [UserApiController::class, 'store']);
});
```

## Request Handling

### Getting Request Data

```php
// Secure way to get input data
$username = request()->input('username');
$email = request()->input('email');
$password = request()->input('password');

// Get request information
$userIP = request()->ip();
$userAgent = request()->userAgent();
$platform = request()->platform();
$browser = request()->browser();
```

### Response Handling

```php
// JSON responses
jsonResponse([
    'code' => 200,
    'message' => 'Success',
    'data' => $data
]);

// Response with redirect
return [
    'code' => 200,
    'message' => 'Login successful',
    'redirectUrl' => url('/dashboard')
];
```

## File Structure

```
simplephp/
├── app/                           
│   ├── database/                   # Database migrations & seeders
│   │    ├── migrations/            # Migration files (YYYYMMDD_00x_name.php)
│   │    ├── seeders/               # Seeder files (YYYYMMDD_00x_NameSeeder.php)
│   │    └── deploy.json            # Migration & seeder tracking (auto-generated)
│   ├── routes/                     # Route definitions (web, api, console)
│   │    ├── web.php                # Web routes
│   │    ├── api.php                # API routes
│   │    └── console.php            # Console commands & schedules
│   ├── config/                    # System configuration
│   │    ├── api.php
│   │    ├── cache.php              # Cache driver config
│   │    ├── config.php
│   │    ├── database.php
│   │    ├── framework.php          # Middleware aliases, groups, service providers
│   │    ├── integration.php
│   │    ├── mailer.php
│   │    ├── queue.php              # Job queue driver config
│   │    └── security.php
│   ├── http/                       # HTTP layer
│   │    ├── Kernel.php             # HTTP kernel (middleware pipeline)
│   │    ├── controllers/           # Controller classes
│   │    ├── middleware/            # Custom middleware
│   │    │    ├── RequireAuth.php    # Unified session+token auth
│   │    │    ├── RequirePermission.php
│   │    │    ├── RequireSessionAuth.php
│   │    │    ├── RequireApiToken.php
│   │    │    ├── EnsureGuest.php
│   │    │    ├── RateLimit.php
│   │    │    └── SetSecurityHeaders.php
│   │    └── requests/              # Form request validation
│   ├── helpers/                    # PHP Function helpers
│   ├── jobs/                       # Queue job classes (created by make:job)
│   └── views/                      # View templates (Blade-like)
├── systems/                        # Core framework files
│   ├── Components/                 # System components
│   │   ├── Api.php                 # API token auth + rate limiting
│   │   ├── Auth.php                # Authentication manager
│   │   ├── Backup.php              # Spatie-like backup system
│   │   ├── CSRF.php                # CSRF protection
│   │   ├── Debug.php               # Debug utilities
│   │   ├── Files.php               # File upload handling
│   │   ├── HTML.php                # HTML utilities
│   │   ├── Input.php               # Input sanitization
│   │   ├── Logger.php              # Logging component
│   │   ├── Request.php             # Request utilities
│   │   ├── TaskRunner.php          # Task execution
│   │   └── Validation.php          # Validation engine
│   ├── Core/                       # Core functionality
│   │    ├── Collection.php         # Fluent array wrapper (60+ methods)
│   │    ├── LazyCollection.php     # Iterator-based chunked collection
│   │    ├── Cache/                 # CacheManager, FileStore, ArrayStore
│   │    ├── Console/               # Console Kernel + built-in Commands
│   │    ├── Database/              # Database drivers and query builder
│   │    ├── Http/                  # HTTP request/response/kernel
│   │    ├── Queue/                 # Job, Dispatcher, Worker
│   │    ├── Routing/               # Router, Pipeline, ServiceProvider
│   │    └── View/BladeEngine.php   # Blade template engine
│   ├── Middleware/                  # Framework middleware + traits
│   ├── app.php                     # DB connection, scopes, middleware
│   └── hooks.php                   # Autoloader, helpers (collect, cache, dispatch)
├── storage/
│   ├── cache/                      # Compiled views, query cache, app cache
│   └── backups/                    # Backup archives (auto-created)
├── public/                         # Public web files
├── logs/                           # Application logs
├── docs/                           # Documentation
├── bootstrap.php                   # Application bootstrap
├── myth                            # CLI entry point (like Laravel's artisan)
└── index.php                       # Web entry point
```

## Helper Functions

SimplePHP includes various helper functions organized by category:

- **API Helpers**: API response utilities
- **Array Helpers**: Array manipulation utilities  
- **Date/Time Helpers**: Date formatting and timestamp utilities
- **Debug Helpers**: Debugging and logging utilities
- **General Helpers**: Common utility functions
- **Project Helpers**: Project-specific utilities
- **Session Helpers**: Session management utilities
- **Upload Helpers**: File upload handling

### Global Helpers (hooks.php)

| Function | Description |
|----------|-------------|
| `collect($items)` | Create a `Collection` instance — `collect([1,2,3])->map(fn($v) => $v * 2)` |
| `cache($key, $default)` | Get/set cache or return `CacheManager` — `cache('key')`, `cache(['k' => 'v'], 300)` |
| `dispatch($job)` | Dispatch a job to the queue — `dispatch(new SendEmail($user))` |
| `db()` | Get the Database instance |
| `auth()` | Get the Auth instance |
| `csrf()` | Get the CSRF instance |
| `logger()` | Get the Logger instance |
| `config($key)` | Load a config file by name |
| `request()` | Get the Request instance |
| `response()` | Get the Response class |
| `route($name, $params)` | Generate URL from named route |
| `blade_engine()` | Get the BladeEngine instance |

### Database Operations

| Function                | Description                                                                                                 |
|-------------------------|-------------------------------------------------------------------------------------------------------------|
| `table()`               | Sets the table for the query.                                                                               |
| `distinct()`            | Adds DISTINCT to the query, optionally with specific columns.                                               |
| `select()`              | Specifies columns to select.                                                                                |
| `selectRaw()`           | Selects columns using a raw SQL expression.                                                                 |
| `where()`               | Adds a WHERE condition to the query.                                                                        |
| `orWhere()`             | Adds an OR WHERE condition.                                                                                 |
| `whereRaw()`            | Adds a raw WHERE condition with optional bindings.                                                          |
| `whereColumn()`         | Adds a WHERE condition comparing two columns.                                                               |
| `orWhereColumn()`       | Adds an OR WHERE condition comparing two columns.                                                           |
| `whereIn()`             | Adds a WHERE IN condition.                                                                                  |
| `orWhereIn()`           | Adds an OR WHERE IN condition.                                                                              |
| `whereNotIn()`          | Adds a WHERE NOT IN condition.                                                                              |
| `orWhereNotIn()`        | Adds an OR WHERE NOT IN condition.                                                                          |
| `whereBetween()`        | Adds a WHERE BETWEEN condition.                                                                             |
| `orWhereBetween()`      | Adds an OR WHERE BETWEEN condition.                                                                         |
| `whereNotBetween()`     | Adds a WHERE NOT BETWEEN condition.                                                                         |
| `orWhereNotBetween()`   | Adds an OR WHERE NOT BETWEEN condition.                                                                     |
| `whereNull()`           | Adds a WHERE IS NULL condition.                                                                             |
| `orWhereNull()`         | Adds an OR WHERE IS NULL condition.                                                                         |
| `whereNotNull()`        | Adds a WHERE IS NOT NULL condition.                                                                         |
| `orWhereNotNull()`      | Adds an OR WHERE IS NOT NULL condition.                                                                     |
| `whereDate()`           | Adds a WHERE clause for a date comparison.                                                                  |
| `orWhereDate()`         | Adds an OR WHERE clause for a date comparison.                                                              |
| `whereDay()`            | Adds a WHERE clause for a specific day.                                                                     |
| `orWhereDay()`          | Adds an OR WHERE clause for a specific day.                                                                 |
| `whereMonth()`          | Adds a WHERE clause for a specific month.                                                                   |
| `orWhereMonth()`        | Adds an OR WHERE clause for a specific month.                                                               |
| `whereYear()`           | Adds a WHERE clause for a specific year.                                                                    |
| `orWhereYear()`         | Adds an OR WHERE clause for a specific year.                                                                |
| `whereTime()`           | Adds a WHERE clause for a specific time.                                                                    |
| `orWhereTime()`         | Adds an OR WHERE clause for a specific time.                                                                |
| `whereJsonContains()`   | Adds a WHERE clause for JSON column value matching.                                                         |
| `whereHas()`            | Adds a WHERE EXISTS subquery for related records.                                                           |
| `orWhereHas()`          | Adds an OR WHERE EXISTS subquery for related records.                                                       |
| `whereDoesntHave()`     | Adds a WHERE NOT EXISTS subquery for missing related records.                                               |
| `orWhereDoesntHave()`   | Adds an OR WHERE NOT EXISTS subquery for missing related records.                                           |
| `when()`                | Conditionally adds clauses to the query based on a given value or callback.                                 |
| `unless()`              | Inverse of when() - adds clauses when condition is false.                                                   |
| `tap()`                 | Passes the query to a callback without modifying it.                                                        |
| `join()`                | Adds a JOIN clause to the query.                                                                            |
| `leftJoin()`            | Adds a LEFT JOIN clause.                                                                                    |
| `rightJoin()`           | Adds a RIGHT JOIN clause.                                                                                   |
| `innerJoin()`           | Adds an INNER JOIN clause.                                                                                  |
| `outerJoin()`           | Adds a FULL OUTER JOIN clause.                                                                              |
| `union()`               | Combines results from two queries using UNION.                                                              |
| `unionAll()`            | Combines results from two queries using UNION ALL (includes duplicates).                                    |
| `orderBy()`             | Adds an ORDER BY clause.                                                                                    |
| `orderByRaw()`          | Adds a raw ORDER BY clause.                                                                                 |
| `latest()`              | Orders by column descending (default: created_at).                                                          |
| `oldest()`              | Orders by column ascending (default: created_at).                                                           |
| `reorder()`             | Removes existing orders and optionally sets a new one.                                                      |
| `inRandomOrder()`       | Orders results randomly.                                                                                    |
| `groupBy()`             | Adds a GROUP BY clause.                                                                                     |
| `having()`              | Adds a HAVING clause.                                                                                       |
| `havingRaw()`           | Adds a raw HAVING clause.                                                                                   |
| `limit()`               | Sets the LIMIT for the query.                                                                               |
| `offset()`              | Sets the OFFSET for the query.                                                                              |
| `skip()`                | Alias for offset() - sets offset for query.                                                                 |
| `take()`                | Alias for limit() - limits query results.                                                                   |
| `forPage()`             | Sets limit and offset for a specific page.                                                                  |
| `with()`                | Eager loads related data (one-to-many).                                                                     |
| `withOne()`             | Eager loads related data (one-to-one).                                                                      |
| `withCount()`           | Adds a count subquery for related data.                                                                     |
| `withSum()`             | Adds a sum subquery for related data.                                                                       |
| `withAvg()`             | Adds an average subquery for related data.                                                                  |
| `withMin()`             | Adds a minimum value subquery for related data.                                                             |
| `withMax()`             | Adds a maximum value subquery for related data.                                                             |
| `useIndex()`            | Hints MySQL to use specific index(es).                                                                      |
| `forceIndex()`          | Forces MySQL to use specific index(es).                                                                     |
| `ignoreIndex()`         | Tells MySQL to ignore specific index(es).                                                                   |
| `dryRun()`              | Enables dry run mode - builds query without executing.                                                      |
| `selectQuery()`         | Executes a SELECT query with optional bindings.                                                             |
| `query()`               | Prepares a raw SQL query for execution.                                                                     |
| `execute()`             | Executes the previously set raw SQL query.                                                                  |
| `get()`                 | Executes the built SELECT query and returns all results.                                                    |
| `fetch()`               | Executes the built SELECT query and returns the first result.                                               |
| `count()`               | Returns the count of records.                                                                               |
| `exists()`              | Returns boolean true if records exist, false otherwise.                                                     |
| `doesntExist()`         | Returns boolean true if no records exist, false otherwise.                                                  |
| `value()`               | Gets a single column value from the first result.                                                           |
| `firstOrFail()`         | Gets first result or throws exception if not found.                                                         |
| `sole()`                | Gets the only matching record, throws exception if none or multiple found.                                  |
| `pluck()`               | Extracts values from a single column, supports dot notation for relations.                                  |
| `chunk()`               | Processes results in chunks for large datasets.                                                             |
| `cursor()`              | Returns a generator for iterating results in chunks.                                                        |
| `lazy()`                | Returns a lazy collection for large result sets.                                                            |
| `paginate()`            | Returns paginated results.                                                                                  |
| `setPaginateFilterColumn()` | Sets columns to use for filtering in pagination.                                                        |
| `paginate_ajax()`       | Handles bootstrap AJAX datatable pagination with search and ordering.                                       |
| `toSql()`               | Returns the built SQL query as a string.                                                                    |
| `toDebugSql()`          | Returns the SQL query with bound values for debugging.                                                      |
| `insert()`              | Inserts a new record into the table.                                                                        |
| `batchInsert()`         | Inserts multiple records in a single query.                                                                 |
| `update()`              | Updates records in the table.                                                                               |
| `batchUpdate()`         | Updates multiple records in a single query.                                                                 |
| `increment()`           | Increments a column value by a given amount.                                                                |
| `decrement()`           | Decrements a column value by a given amount.                                                                |
| `delete()`              | Deletes records from the table.                                                                             |
| `softDelete()`          | Soft deletes or updates records by setting the specified column(s) to a value.                              |
| `truncate()`            | Truncates (empties) the table.                                                                              |
| `upsert()`              | Inserts or updates records based on unique key.                                                             |
| `insertOrUpdate()`      | Inserts or updates records based on unique key or conditions.                                               |
| `firstOrCreate()`       | Finds first matching record or creates a new one.                                                           |
| `toArray()`             | Sets the return type to array.                                                                              |
| `toObject()`            | Sets the return type to object.                                                                             |
| `toJson()`              | Sets the return type to JSON.                                                                               |
| `safeInput()`           | Enables input sanitization before insert/update.                                                            |
| `safeOutput()`          | Enables output sanitization.                                                                                |
| `safeOutputWithException()` | Enables output sanitization with specific field exceptions.                                             |
| `hasColumn()`           | Checks if a column exists in the table.                                                                     |
| `analyze()`             | Analyzes query performance and returns optimization suggestions.                                            |
| `profiler()`            | Returns query profiler information.                                                                         |
| `transaction()`         | Wraps operations in a transaction with automatic commit/rollback.                                           |
| `beginTransaction()`    | Begins a database transaction.                                                                              |
| `commit()`              | Commits the current transaction.                                                                            |
| `rollback()`            | Rolls back the current transaction.                                                                         |
| `enableQueryCache()`    | Enables query caching with optional TTL.                                                                    |
| `disableQueryCache()`   | Disables query caching.                                                                                     |
| `getPerformanceReport()`| Returns performance metrics and statistics.                                                                 |
| `cleanupConnections()`  | Cleans up idle database connections.                                                                        |
| `setProfilingEnabled()` | Enables or disables query profiling.                                                                        |
| `isProfilingEnabled()`  | Checks if profiling is enabled.                                                                             |
| `addConnection()`       | Adds a new database connection configuration.                                                               |
| `setConnection()`       | Switches to a different database connection.                                                                |
| `getConnection()`       | Gets the current or specified connection.                                                                   |
| `setDatabase()`         | Switches to a different database.                                                                           |
| `getDatabase()`         | Gets the current database name.                                                                             |
| `getPlatform()`         | Gets the database platform name.                                                                            |
| `getDriver()`           | Gets the database driver name.                                                                              |
| `getVersion()`          | Gets the database server version.                                                                           |
| `getPdo()`              | Gets the underlying PDO instance.                                                                           |
| `disconnect()`          | Disconnects from the database.                                                                              |
| `reset()`               | Resets the query builder state.                                                                             |

## Modern HTTP Router

SimplePHP includes a fast, indexed HTTP router with middleware pipeline support:

```php
// app/routes/web.php
$router->get('/', [DashboardController::class, 'index']);
$router->get('/users', [UserController::class, 'index'])->middleware('auth');
$router->post('/users', [UserController::class, 'store'])->middleware('auth:session');

// Array middleware syntax
$router->post('/api/upload', [UploadController::class, 'store'])
    ->middleware(['throttle:120,1', 'xss', 'auth:token']);

// Route groups with shared middleware
$router->group(['middleware' => ['auth', 'permission:admin']], function($router) {
    $router->get('/admin/settings', [SettingsController::class, 'index']);
    $router->post('/admin/settings', [SettingsController::class, 'update']);
});

// Resource routes
$router->resource('/roles', RoleController::class);

// Multi-parameter routes
$router->get('/users/{id}/posts/{postId}', [PostController::class, 'show']);

// Route parameter constraints
$router->get('/users/{id}', [UserController::class, 'show'])
    ->whereNumber('id')
    ->name('users.show');

// API versioning via group prefix
$router->group(['prefix' => '/api/v1', 'middleware' => ['auth.api']], function ($router) {
    $router->resource('/users', UserApiController::class);
});
```

### Route Performance
- Static routes use O(1) hashmap lookup
- Dynamic routes use pre-compiled regex patterns
- Automatic 405 Method Not Allowed responses with `Allow` header

## Schema Builder & Migrations

### Schema Builder

Create and modify tables using a fluent, Laravel-like API:

```php
use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;

// Create a table
Schema::create('posts', function (Blueprint $table) {
    $table->id();                                  // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->text('body')->nullable();
    $table->enum('status', ['draft', 'published'])->default('draft');
    $table->timestamps();                          // created_at + updated_at
    $table->softDeletes();                         // deleted_at (nullable)
});

// Modify a table
Schema::table('posts', function (Blueprint $table) {
    $table->string('subtitle')->nullable()->after('title');
    $table->dropColumn('metadata');
});

// Introspection
Schema::hasTable('users');             // bool
Schema::hasColumn('users', 'email');   // bool
Schema::getColumnListing('users');     // ['id', 'name', ...]
```

### Migrations

Migrations live in `app/database/migrations/` with format `YYYYMMDD_00x_name.php`:

```php
<?php
use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string $table = 'posts';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table);
    }
};
```

### Seeders

Seeders live in `app/database/seeders/` with format `YYYYMMDD_00x_NameSeeder.php`:

```php
<?php
use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string $table = 'master_roles';
    protected string $connection = 'default';

    public function run(): void
    {
        // Simple insert
        $this->insert($this->table, [
            'role_name' => 'Administrator',
            'role_rank' => 1000,
            'role_status' => 1,
        ]);

        // Insert or update (upsert)
        $this->insertOrUpdate($this->table, ['id' => 1], [
            'role_name' => 'Super Administrator',
            'role_rank' => 9999,
            'role_status' => 1,
        ]);
    }
};
```

### Migration Commands

```bash
php myth migrate                    # Run all pending migrations
php myth migrate:rollback           # Rollback last batch
php myth migrate:rollback --step=3  # Rollback 3 batches
php myth migrate:reset              # Rollback everything
php myth migrate:fresh              # Drop all tables + re-migrate (destructive)
php myth migrate:status             # Show migration & seeder status
php myth db:seed                    # Run all pending seeders
php myth db:seed --class=MasterRolesSeeder  # Run specific seeder
php myth make:migration create_posts_table  # Generate migration
php myth make:seeder PostsSeeder            # Generate seeder
```

Migration and seeder state is tracked in `app/database/deploy.json` (no database table needed). Both migrations and seeders are recorded with timestamps, batch numbers, and status.

## Myth CLI (Artisan-like Console)

Run commands via `php myth <command>`:

```bash
# Scaffolding
php myth make:controller UserController --resource
php myth make:controller Api/V2/OrderController --api
php myth make:middleware CheckAge
php myth make:request StoreUserRequest
php myth make:model Product
php myth make:job SendWelcomeEmail

# Cache management
php myth cache:clear              # Clear all caches
php myth cache:clear views        # Clear only view cache
php myth view:clear               # Clear compiled views

# Route inspection
php myth route:list               # Display all registered routes

# Queue management
php myth queue:work               # Start processing jobs
php myth queue:work --queue=emails --tries=5 --sleep=5
php myth queue:work --once        # Process single job then exit
php myth queue:failed             # List failed jobs
php myth queue:retry {id}         # Retry specific failed job
php myth queue:retry all          # Retry all failed jobs
php myth queue:flush              # Delete all failed jobs
php myth queue:clear              # Clear pending jobs

# Database & Backup
php myth db:backup                # Quick database backup
php myth backup:run               # Full backup (DB + files)
php myth backup:run --only-db     # Database only
php myth backup:run --only-files  # Files only
php myth backup:clean --days=30   # Remove backups older than 30 days

# Migrations & Seeders
php myth migrate                  # Run pending migrations
php myth migrate:rollback         # Rollback last batch
php myth migrate:reset            # Rollback all migrations
php myth migrate:fresh            # Drop all + re-migrate
php myth migrate:status           # Show status table
php myth db:seed                  # Run all pending seeders
php myth make:migration create_posts_table
php myth make:seeder PostsSeeder

# Development
php myth serve --port=8080        # Start PHP dev server
php myth key:generate             # Generate app key
php myth storage:link             # Create storage symlink

# Scheduler (add to crontab: * * * * * php /path/to/myth schedule:run)
php myth schedule:run             # Run due scheduled tasks
php myth schedule:list            # Show all scheduled tasks
```

### Custom Commands
```php
// app/routes/console.php
$console->command('mail:send {user} {--queue}', function($args, $options) use ($console) {
    $userId = $args[0] ?? null;
    $useQueue = $options['queue'] ?? false;
    $console->info("Sending mail to user {$userId}...");
    // ... logic
    $console->success('Mail sent!');
})->describe('Send email to a user');
```

### Task Scheduling

Define schedules in `app/routes/console.php` using a fluent API:

```php
// app/routes/console.php — inside the schedule section
$console->getSchedule()->command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->description('Daily full backup');

$console->getSchedule()->command('backup:clean --days=30')
    ->weekly()->sundays()->at('03:00')
    ->description('Clean old backups');

$console->getSchedule()->command('cache:clear query')
    ->hourly()
    ->description('Purge stale query cache');
```

Run the scheduler every minute via crontab:
```bash
* * * * * php /path/to/myth schedule:run >> /dev/null 2>&1
```

## Collection

A fluent wrapper for arrays with 60+ methods, inspired by Laravel's Collection:

```php
$users = collect([
    ['name' => 'John', 'age' => 30, 'role' => 'admin'],
    ['name' => 'Jane', 'age' => 25, 'role' => 'user'],
    ['name' => 'Bob',  'age' => 35, 'role' => 'admin'],
]);

// Filter, sort, transform
$adminNames = $users
    ->where('role', 'admin')
    ->sortBy('name')
    ->pluck('name')
    ->toArray();  // ['Bob', 'John']

// Aggregation
$users->avg('age');     // 30
$users->sum('age');     // 90
$users->min('age');     // 25
$users->max('age');     // 35

// Grouping
$byRole = $users->groupBy('role');
// ['admin' => Collection(John, Bob), 'user' => Collection(Jane)]

// Chaining with conditions
$result = collect($data)
    ->when($search, fn($c) => $c->filter(fn($r) => str_contains($r['name'], $search)))
    ->whereNotNull('email')
    ->unique('email')
    ->values()
    ->toArray();

// Available methods include: map, filter, reject, where, whereIn, whereNull,
// whereBetween, pluck, sortBy, groupBy, keyBy, chunk, flatten, merge, unique,
// first, last, contains, reduce, each, pipe, tap, when, unless, and many more.
```

## Cache System

Unified cache API with file and array drivers:

```php
// Via helper
cache()->put('key', $value, 300);           // Store for 5 minutes
$value = cache('key');                       // Get value
cache(['key' => 'value'], 300);             // Batch put

// Remember pattern (cache-aside)
$users = cache()->remember('all_users', 600, function () {
    return db()->table('users')->get();
});

// Counters, pull, add
cache()->increment('page_views');
$token = cache()->pull('one-time-token');    // Get + delete
cache()->add('lock', true, 60);             // Only if not exists
cache()->flush();                            // Clear all
```

Configure in `app/config/cache.php`. Supports `file` (default) and `array` drivers.

## Job Queue

Database-backed job queue for asynchronous task processing:

```php
// Create a job
// php myth make:job SendWelcomeEmail

class SendWelcomeEmail extends \Core\Queue\Job
{
    public function __construct(private string $email) {}
    
    public function handle(): void
    {
        mailer()->to($this->email)->send('welcome');
    }
    
    public function failed(\Throwable $e): void
    {
        logger()->error("Send failed: " . $e->getMessage());
    }
}

// Dispatch
dispatch(new SendWelcomeEmail('user@example.com'));
dispatch((new SendWelcomeEmail($email))->onQueue('emails')->delay(60));
```

Run workers: `php myth queue:work --queue=emails --tries=3`

Configure in `app/config/queue.php`. Supports `database` and `sync` drivers. Tables auto-created on first use.

## Backup System

Spatie-like backup component supporting database + file backups with cron integration:

```php
use Components\Backup;

// Database backup only
$result = (new Backup())->database()->run();

// Full backup (DB + project files)
$result = (new Backup())->database()->files()->run();

// Custom configuration
$backup = new Backup([
    'backup_path' => '/custom/path',
    'filename_prefix' => 'myapp',
    'directories' => ['/path/to/app', '/path/to/config'],
    'exclude' => ['*.log', 'vendor', 'node_modules'],
    // Optional override: exact binary path (if known)
    'mysqldump_path' => '/usr/bin/mysqldump',

    // Optional search list (supports glob patterns) when path is not fixed
    'mysqldump_search_paths' => [
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        'C:/Program Files/MySQL/*/bin/mysqldump.exe',
        'C:/xampp/mysql/bin/mysqldump.exe',
    ],
]);
$result = $backup->database()->files()->run();

// Cleanup old backups
$removed = (new Backup())->cleanup(30); // Remove backups older than 30 days

// List existing backups
$backups = (new Backup())->listBackups();
```

### Backup via Cron
```bash
# Automated via console scheduler (already configured):
# Daily at 2:00 AM - Full backup
# Weekly Sunday 3:00 AM - Cleanup old backups

# Or add directly to crontab:
0 2 * * * php /path/to/myth backup:run >> /var/log/backup.log 2>&1
0 3 * * 0 php /path/to/myth backup:clean --days=30 >> /var/log/backup.log 2>&1
```

## Authentication & Middleware

### Laravel-like Auth Component

The `Auth` class provides configurable session keys, `login()`/`attempt()` methods, and OAuth/Socialite support:

```php
// Attempt credentials without logging in
$user = auth()->attempt(['email' => $email, 'password' => $password]);

// Login by user ID (regenerates session)
auth()->login($userId, [
    'userFullName' => $user['name'],
    'userEmail'    => $user['email'],
    'roleID'       => $profile['role_id'],
    'permissions'  => getPermissionSlug($permissions),
]);

// OAuth / Socialite login
$authUser = auth()->socialite('google', $socialUserData, function (&$userData) {
    $userData['status'] = 1;
});

// Token authentication
$token = auth()->createToken($userId, 'api-access', ['user-view'], 30);
auth()->revokeAllTokens($userId);

// Session helpers
auth()->check();    // true/false
auth()->id();       // user ID
auth()->user();     // session/token user data
auth()->logout();   // destroys session
```

### Unified Auth Middleware

SimplePHP supports both session and API token authentication through a unified `auth` middleware:

```php
// Accept either session or token auth
$router->get('/profile', [ProfileController::class, 'show'])->middleware('auth');

// Session-only (web pages)
$router->get('/dashboard', [DashboardController::class, 'index'])->middleware('auth:session');

// Token-only (API endpoints)
$router->get('/api/users', [ApiUserController::class, 'index'])->middleware('auth:token');

// Permission checking works with both auth types
$router->get('/admin', [AdminController::class, 'index'])->middleware('permission:admin-access');
```

### Available Middleware Aliases
| Alias | Class | Description |
|-------|-------|-------------|
| `auth` | `RequireAuth` | Session or token authentication |
| `auth.web` | `RequireSessionAuth` | Session-only authentication |
| `auth.api` | `RequireApiToken` | Token-only (Bearer) authentication |
| `guest` | `EnsureGuest` | Redirect authenticated users |
| `permission` | `RequirePermission` | Check RBAC permission (session + token) |
| `throttle` | `RateLimit` | Laravel-style rate limiting |
| `aggressive-throttle` | `ThrottleRequests` | Aggressive IP-based throttling with blocking |
| `xss` | `XssProtection` | XSS pattern detection on input |
| `headers` | `SetSecurityHeaders` | CSP, HSTS, and security headers |

### Middleware Groups

Groups bundle multiple middleware under one name. Configure in `app/config/framework.php`:

```php
'middleware_groups' => [
    'web' => ['headers', 'throttle:web'],
    'api' => ['headers', 'throttle:api', 'xss', 'api.log'],
],
```

Use in routes — groups are automatically expanded:
```php
$router->get('/page', [PageController::class, 'show'])->middleware('web');
// Expands to: ['headers', 'throttle:web']

// Mix groups with individual middleware (array syntax)
$router->get('/admin', [AdminController::class, 'index'])
    ->middleware(['web', 'auth.web', 'permission:admin-access']);
```

### Router Error Views & Browser 404 Redirect

Router fallback behavior is config-driven in `app/config/framework.php`:

```php
'error_views' => [
    '404'         => 'app/views/errors/404.php',
    'general'     => 'app/views/errors/general_error.php',
    'error_image' => 'general/images/nodata/403.png',
],

// For browser requests that do not match any route
'not_found_redirect' => [
    'web' => 'login', // route name or URL path
],

// Scope/macro auto-loading used by db()
'scope_macro' => [
    'base_path' => 'app/database/',
    'folders'   => ['ScopeMacroQuery'],
    'files'     => [],
],
```

Notes:
- Browser HTML requests with unmatched routes redirect to `login` (or your configured target).
- API/AJAX/JSON requests continue to receive JSON `404` responses.
- Scope/macro files are loaded from `app/database/ScopeMacroQuery/` by default.

### Middleware Traits

Composable traits in `systems/Middleware/Traits/` can be used to build custom middleware:

```php
use Middleware\Traits\RateLimitingThrottleTrait;
use Middleware\Traits\XssProtectionTrait;
use Middleware\Traits\SecurityHeadersTrait;
use Middleware\Traits\PermissionAbilitiesTrait;
```

### Configurable Security Headers (CSP)

CSP and Permissions-Policy are configured in `app/config/security.php` — no hardcoded values:

```php
'csp' => [
    'script-src' => ["'self'", "'unsafe-inline'", "cdn.datatables.net", "cdn.jsdelivr.net"],
    'style-src'  => ["'self'", "'unsafe-inline'", "fonts.googleapis.com"],
    'font-src'   => ["'self'", "fonts.gstatic.com"],
    // Add any CDN domain here — no code changes needed
],
```

## Blade Template Engine

### Directives

```blade
{{-- Standard Laravel-like directives --}}
@if($condition) ... @elseif($other) ... @else ... @endif
@foreach($items as $item) ... @endforeach
@for($i = 0; $i < 10; $i++) ... @endfor
@while($condition) ... @endwhile

{{-- Forelse (with empty fallback) --}}
@forelse($users as $user)
    <li>{{ $user['name'] }}</li>
@empty
    <li>No users found.</li>
@endforelse

{{-- Output --}}
{{ $variable }}          {{-- Escaped output --}}
{!! $rawHtml !!}         {{-- Unescaped output --}}

{{-- Form helpers --}}
@method('PUT')           {{-- Hidden _method field --}}
@csrf                    {{-- CSRF token field --}}

{{-- Conditional attributes --}}
@checked($isActive)      {{-- checked="checked" if true --}}
@selected($isDefault)    {{-- selected="selected" if true --}}
@disabled($isLocked)     {{-- disabled="disabled" if true --}}
@readonly($isReadonly)    {{-- readonly="readonly" if true --}}
@required($isRequired)   {{-- required="required" if true --}}

{{-- Class merging --}}
@class(['btn', 'btn-primary' => $isPrimary, 'disabled' => $isDisabled])

{{-- Error handling --}}
@error('email')
    <span class="text-danger">{{ $message }}</span>
@enderror

{{-- Environment --}}
@env('production')
    {{-- Production-only content --}}
@endenv

@production
    {{-- Shorthand for production --}}
@endproduction

{{-- Includes and sections --}}
@include('partial', ['key' => 'value'])
@section('content') ... @endsection
@yield('content')
@extends('layout')
```

### View Caching
- Compiled views cached in `storage/cache/views/`
- Cache key includes file modification time — automatically invalidates on changes
- Thread-safe writes with `LOCK_EX`
- OPcache integration for compiled PHP files

## Security

### CSRF Protection

CSRF protection is enabled by default for all state-changing requests (POST, PUT, PATCH, DELETE). API routes are excluded since they use Bearer token authentication.

**In Blade forms:**
```blade
<form method="POST" action="{{ route('users.store') }}">
    @csrf
    <!-- form fields -->
</form>
```

**In AJAX requests:**
```javascript
// Token is accepted via X-CSRF-TOKEN header
const token = document.querySelector('meta[name="csrf-token"]').content;
axios.defaults.headers.common['X-CSRF-TOKEN'] = token;

// Or include in POST body as csrf_token
callApi('post', '/users', { csrf_token: token, name: 'John' });
```

**Excluding routes from CSRF** (`app/config/security.php`):
```php
'csrf_exclude_uris' => [
    'api/*',        // All API routes (use Bearer tokens instead)
    'webhooks/*',   // Third-party webhooks
],
```

### Built-in Security Features

| Feature | Description |
|---------|-------------|
| XSS Prevention | All input auto-sanitized; `{{ }}` escapes output; `safeOutput()` on queries |
| SQL Injection | Parameterized PDO queries; `safeTable()` for dynamic table names |
| CSRF Tokens | Opt-out model with cookie + hidden field; supports AJAX headers |
| Rate Limiting | Per-route throttling via `throttle:60,1` middleware |
| RBAC Permissions | Role-based access control checked at middleware level |
| Security Headers | HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| Trusted Proxies | IP spoofing prevention — forwarded headers only honored from trusted IPs |
| Session Fixation | `session_regenerate_id()` called on every login |
| Password Hashing | bcrypt via `password_hash(PASSWORD_DEFAULT)` |
| Token Security | Bearer tokens SHA-256 hashed before storage; TTL capped at 30 days |

## Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher
- ext-zip (for backup compression)
- ext-pdo (for database access)

## Contributing

1. Fork the repository
2. Create your feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

---

**SimplePHP** - Simple PHP structure for modern web applications
