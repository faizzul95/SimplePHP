# SimplePHP

A lightweight PHP project structure with modern features for rapid web application development using procedural programming approach.

## Features

- **Organized File Structure** - Clean separation of controllers, helpers, and core components
- **Database Query Builder** - Fluent, expressive database interactions with Laravel-style eager loading — no models required
- **Request Handling** - Modern request/response utilities
- **Environment Configuration** - Multi-environment support
- **Helper Functions** - Extensive collection of utility functions

## Installation

1. Clone or download the project:
```bash
git clone https://github.com/faizzul95/simplephp.git
cd simplephp
```
2. Edit `app/config/*.php` with your database and mail settings

3. Import example database schema in #db folder

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

### Controller Actions

SimplePHP uses an action-based routing system. Controllers are invoked by specifying an `action` parameter that corresponds to the function name in the controller.

### Form Submissions

All forms must include a hidden input field with the `action` parameter to specify which controller function to invoke:

```html
<form method="POST" action="controllers/ExampleController.php">
    
    <!-- Your form fields here -->
    <input type="text" name="name" required>
    <input type="email" name="email" required>
    
    <!-- Required to have this hidden action -->
    <input type="hidden" name="action" value="save" readonly>
    <button type="submit">Submit</button>
</form>
```

### API Calls with callApi Wrapper

When using the `callApi` wrapper function, include the `action` parameter to specify which controller function to call:

```javascript
// Example: Calling the 'show' function in ExampleController
const res = await callApi('post', "controllers/ExampleController.php", {
    'action': 'show',
    'id': id
});

// Example: Calling the 'save' function
const saveRes = await callApi('post', "controllers/UserController.php", {
    'action': 'save',
    'name': 'John Doe',
    'email': 'john@example.com'
});

// Example: Calling the 'delete' function
const deleteRes = await callApi('post', "controllers/UserController.php", {
    'action': 'delete',
    'id': userId
});
```

### Controller Structure

Controllers should be structured with functions that correspond to different actions:

```php
<?php
// controllers/ExampleController.php

function save($request) {
    // Handle save logic
    $validation = request()->validate([
        'name' => 'required|string|min_length:3|max_length:255|secure_value',
        'email' => 'required|email|max_length:255|secure_value',
        'id' => 'numeric',
    ]);

    if (!$validation->passed()) {
        jsonResponse(['code' => 400, 'message' => $validation->getFirstError()]);
    }

    $result = db()->table('users')->insertOrUpdate(
        [
            'id' => request()->input('id') // Similar as $_POST['id'] or $request['id']
        ],
        request()->all()
    );

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to save user']);
    }

    jsonResponse(['code' => 200, 'message' => 'User saved']);
}

function show($request) {
    // Handle show logic
    $id = request()->input('id'); // Similar as $_POST['id'] or $request['id']
    
    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    $data = db()->table('users')->where('id', $id)->safeOutput()->fetch();
    // $data = db()->where('id', $id)->safeOutput()->fetch('users'); // without using table()

    if (!$data) {
        jsonResponse(['code' => 404, 'message' => 'User not found']);
    }
    
    jsonResponse(['code' => 200, 'data' => $data]);
}

function delete($request) {
    // Handle delete logic
    $id = request()->input('id'); // Similar as $_POST['id'] or $request['id']
    
    if (empty($id)) {
        jsonResponse(['code' => 400, 'message' => 'ID is required']);
    }

    // $result = db()->table('users')->where('id', $id)->delete();
    $result = db()->table('users')->where('id', $id)->softDelete();

    if (isError($result['code'])) {
        jsonResponse(['code' => 422, 'message' => 'Failed to delete data']);
    }

    jsonResponse(['code' => 200, 'message' => 'Data deleted']);
}
?>
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

```

## Security Notes

- Always use `->safeOutput` when working with the query builder before calling `->get`, `->fetch`, `->paginate`, or `->paginate_ajax` to prevent XSS injection from being displayed on the frontend.

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

## Controllers (Function-Based)

Controllers in SimplePHP are organized as functions rather than classes:

### Authentication Controller Example

```php
// controllers/AuthController.php
<?php
require_once '../bootstrap.php';

function authorize($request)
{
    $username = request()->input('username');
    $password = request()->input('password');
    
    $response = ['code' => 400, 'message' => 'Invalid username or password'];
    
    $userData = db()->query(
        "SELECT `id`, `password` FROM `users` WHERE `email` = :0 OR `username` = :0", 
        [$username]
    )->fetch();
    
    if (!empty($userData)) {
        // Rate limiting check
        $ipUser = request()->ip();
        $countAttempt = db()->query(
            "SELECT COUNT(*) as count FROM `system_login_attempt` 
             WHERE `ip_address` = ? AND `time` > NOW() - INTERVAL 10 MINUTE AND `user_id` = ?", 
            [$ipUser, $userData['id']]
        )->fetch();
        
        if ($countAttempt['count'] >= 5) {
            $response = [
                'code' => 429,
                'message' => 'Too many login attempts. Please try again later.',
            ];
            jsonResponse($response);
        }
        
        if (password_verify($password, $userData['password'])) {
            $response = loginSessionStart($userData, 1);
            // Clear login attempts on successful login
            db()->table('system_login_attempt')->where('user_id', $userData['id'])->delete();
        } else {
            // Log failed attempt
            db()->table('system_login_attempt')->insert([
                'ip_address' => $ipUser,
                'user_id' => $userData['id'],
                'user_agent' => request()->userAgent(),
                'time' => timestamp()
            ]);
        }
    }
    
    jsonResponse($response);
}

function logout()
{
    session_destroy();
    jsonResponse([
        'code' => 200,
        'message' => 'Logout',
        'redirectUrl' => url(REDIRECT_LOGIN),
    ]);
}
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
│   ├── routes/                     # Menu Routes
│   ├── config/                    # System configuration
│   │    ├── api.php
│   │    ├── config.php
│   │    ├── database.php
│   │    ├── integration.php
│   │    ├── mailer.php
│   │    ├── security.php
│   ├── helpers/                    # PHP Function helpers
│   │    ├── custom_api_helper.php
│   │    ├── custom_array_helper.php
│   │    ├── custom_date_time_helper.php
│   │    ├── custom_debug_helper.php
│   │    ├── custom_general_helper.php
│   │    ├── custom_mailer_helper.php
│   │    ├── custom_project_helper.php
│   │    ├── custom_session_helper.php
│   │    ├── custom_template_helper.php
│   │    └── custom_upload_helper.php
│   └── views/                      # View templates
|        ├── _templates/            # Template files
│        └── auth/                  # Authentication views
│             └── login.php
├── controllers/                    # Controller functions
│   ├── AuthController.php          # Authentication functions
│   ├── RoleController.php          # Role management functions
│   └── UserController.php          # User management functions
├── systems/                        # Core system files
│   ├── Components/                 # System components
│   │   ├── Debug.php
│   │   ├── Logger.php
│   │   ├── PageRouter.php
│   │   ├── Request.php
│   │   ├── Validation.php
│   │   ├── Input.php
│   │   ├── CSRF.php
│   │   ├── Files.php
│   │   └── HTML.php
│   ├── Core/               # Core functionality
│   │    ├── Database/       # Database drivers and helpers
│   │    │   ├── Drivers/
│   │    │   ├── Interface/
│   │    │   ├── BaseDatabase.php
│   │    │   ├── Database.php
│   │    │   ├── DatabaseCache.php
│   │    │   └── DatabaseHelper.php
│   │    └── LazyCollection.php
│   ├── Middleware/              
│   │    └── Traits/      
│   │        ├── PermissionAbilitiesTrait.php
│   │        ├── RateLimitingThrottleTrait.php
│   │        ├── SecurityHeadersTrait.php
│   │        └── XssProtectionTrait.php
│   └── app.php
├── public/                # Public web files
├── logs/                  # Application logs
└── bootstrap.php              
└── index.php              
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

### Database Operations

| Function                | Description                                                                                                 |
|-------------------------|-------------------------------------------------------------------------------------------------------------|
| `table()`               | Sets the table for the query.                                                                               |
| `select()`              | Specifies columns to select.                                                                                |
| `selectRaw()`           | Selects columns using a raw SQL expression.                                                                 |
| `where()`               | Adds a WHERE condition to the query.                                                                        |
| `orWhere()`             | Adds an OR WHERE condition.                                                                                 |
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
| `whereYear()`           | Adds a WHERE clause for a specific year.                                                                    |
| `orWhereYear()`         | Adds an OR WHERE clause for a specific year.                                                                |
| `whereTime()`           | Adds a WHERE clause for a specific time.                                                                    |
| `orWhereTime()`         | Adds an OR WHERE clause for a specific time.                                                                |
| `when()`                | Conditionally adds clauses to the query based on a given value or callback.                                 |
| `join()`                | Adds a JOIN clause to the query.                                                                            |
| `leftJoin()`            | Adds a LEFT JOIN clause.                                                                                    |
| `rightJoin()`           | Adds a RIGHT JOIN clause.                                                                                   |
| `innerJoin()`           | Adds an INNER JOIN clause.                                                                                  |
| `outerJoin()`           | Adds a FULL OUTER JOIN clause.                                                                              |
| `orderBy()`             | Adds an ORDER BY clause.                                                                                    |
| `orderByRaw()`          | Adds a raw ORDER BY clause.                                                                                 |
| `groupBy()`             | Adds a GROUP BY clause.                                                                                     |
| `having()`              | Adds a HAVING clause.                                                                                       |
| `havingRaw()`           | Adds a raw HAVING clause.                                                                                   |
| `limit()`               | Sets the LIMIT for the query.                                                                               |
| `offset()`              | Sets the OFFSET for the query.                                                                              |
| `with()`                | Eager loads related data (one-to-many).                                                                     |
| `withOne()`             | Eager loads related data (one-to-one).                                                                      |
| `withCount()`           | Adds a count subquery for related data.                                                                     |
| `withSum()`             | Adds a sum subquery for related data.                                                                       |
| `withAvg()`             | Adds an average subquery for related data.                                                                  |
| `withMin()`             | Adds a minimum value subquery for related data.                                                             |
| `withMax()`             | Adds a maximum value subquery for related data.                                                             |
| `selectQuery()`         | Executes a SELECT query with optional bindings.                                                             |
| `query()`               | Prepares a raw SQL query for execution.                                                                     |
| `execute()`             | Executes the previously set raw SQL query.                                                                  |
| `get()`                 | Executes the built SELECT query and returns all results.                                                    |
| `fetch()`               | Executes the built SELECT query and returns the first result.                                               |
| `count()`               | Abstract. Returns the count of records (must be implemented in child class).                                |
| `exists()`              | Abstract. Returns boolean true if records exist, false otherwise.(must be implemented in child class)       |
| `chunk()`               | Processes results in chunks for large datasets.                                                             |
| `cursor()`              | Returns a generator for iterating results in chunks.                                                        |
| `lazy()`                | Returns a lazy collection for large result sets.                                                            |
| `paginate()`            | Returns paginated results.                                                                                  |
| `setPaginateFilterColumn()` | Sets columns to use for filtering in pagination.                                                        |
| `paginate_ajax()`       | Handles bootstrap AJAX datatable pagination with search and ordering.                                       |
| `toSql()`               | Returns the built SQL query as a string.                                                                    |
| `toDebugSql()`          | Returns the SQL query with bound values for debugging.                                                      |
| `insert()`              | Inserts a new record into the table.                                                                        |
| `update()`              | Updates records in the table.                                                                               |
| `delete()`              | Deletes records from the table.                                                                             |
| `softDelete()`          | Soft deletes or updates records by setting the specified column(s) to a value.                              |
| `truncate()`            | Truncates (empties) the table.                                                                              |
| `upsert()`              | Inserts or updates records based on unique key.                                                             |
| `insertOrUpdate()`      | Inserts or updates records based on unique key or conditions.                                               |
| `toArray()`             | Sets the return type to array.                                                                              |
| `toObject()`            | Sets the return type to object.                                                                             |
| `toJson()`              | Sets the return type to JSON.                                                                               |
| `safeOutput()`          | Enables output sanitization.                                                                                |
| `profiler()`            | Returns query profiler information.                                                                         |
| `beginTransaction()`    | Begins a database transaction.                                                                              |
| `commit()`              | Commits the current transaction.                                                                            |
| `rollback()`            | Rolls back

## Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher

## Contributing

1. Fork the repository
2. Create your feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

---

**SimplePHP** - Simple PHP structure for modern web applications
