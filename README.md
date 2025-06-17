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
2. Edit `env.php` with your database and mail settings

3. Import example database schema in #db folder

## Configuration

### Environment Setup

Edit `env.php` to configure your application:

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

## Database Usage

SimplePHP provides an elegant query builder for database operations:

### Basic Queries (Builder)

```php
// Select all users
$users = db()->table('users')->get();

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
```

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

### Insert, Update, Delete, Truncate

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

// Insert or Update record (similar to insertOrUpdate/upsert laravel)
db()->table('users')->upsert(['name' => 'Jane Doe']);

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
require_once '../init.php';

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
├── controllers/              # Controller functions
│   ├── AuthController.php   # Authentication functions
│   ├── RoleController.php   # Role management functions
│   └── UserController.php   # User management functions
├── systems/                 # Core system files
│   ├── Components/          # System components
│   │   ├── Debug.php
│   │   ├── Logger.php
│   │   └── Request.php
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
├── views/                  # View templates
│   ├── _templates/         # Template files
│   └── auth/               # Authentication views
│       └── login.php
├── public/                # Public web files
│   └── helpers/           # PHP Function helpers
│       ├── custom_api_helper.php
│       ├── custom_array_helper.php
│       ├── custom_date_time_helper.php
│       ├── custom_debug_helper.php
│       ├── custom_general_helper.php
│       ├── custom_project_helper.php
│       ├── custom_session_helper.php
│       └── custom_upload_helper.php
├── logs/                  # Application logs
├── env.php                # Environment configuration
└── init.php              
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
| `chunk()`               | Processes results in chunks for large datasets.                                                             |
| `cursor()`              | Returns a generator for iterating results in chunks.                                                        |
| `lazy()`                | Returns a lazy collection for large result sets.                                                            |
| `paginate()`            | Returns paginated results.                                                                                  |
| `setPaginateFilterColumn()` | Sets columns to use for filtering in pagination.                                                        |
| `paginate_ajax()`       | Handles AJAX pagination with search and ordering.                                                           |
| `toSql()`               | Returns the built SQL query as a string.                                                                    |
| `toDebugSql()`          | Returns the SQL query with bound values for debugging.                                                      |
| `insert()`              | Inserts a new record into the table.                                                                        |
| `update()`              | Updates records in the table.                                                                               |
| `delete()`              | Deletes records from the table.                                                                             |
| `truncate()`            | Truncates (empties) the table.                                                                              |
| `upsert()`              | Inserts or updates records based on unique key.                                                             |
| `toArray()`             | Sets the return type to array.                                                                              |
| `toObject()`            | Sets the return type to object.                                                                             |
| `toJson()`              | Sets the return type to JSON.                                                                               |
| `safeOutput()`          | Enables output sanitization.                                                                                |
| `profiler()`            | Returns query profiler information.                                                                         |
| `beginTransaction()`    | Begins a database transaction.                                                                              |
| `commit()`              | Commits the current transaction.                                                                            |
| `rollback()`            | Rolls back

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher

## Contributing

1. Fork the repository
2. Create your feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

---

**SimplePHP** - Simple PHP structure for modern web applications
