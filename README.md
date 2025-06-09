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

// Database configuration
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

### Basic Queries

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
```

### Advanced Queries with Relationships

```php
// Complex query with nested relationships
$userData = db()->table('users')
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

### Insert, Update, Delete

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
```

### Raw SQL Queries

```php
// Parameterized queries for security
$userData = db()->query(
    "SELECT `id`, `password` FROM `users` WHERE `email` = :0 OR `username` = :0", 
    [$username]
)->fetch();

// Count queries with time conditions
$countAttempt = db()->query(
    "SELECT COUNT(*) as count FROM `system_login_attempt` 
     WHERE `ip_address` = ? AND `time` > NOW() - INTERVAL 10 MINUTE AND `user_id` = ?", 
    [$ipUser, $userData['id']]
)->fetch();
```

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
├── helpers/                 # Helper functions
│   ├── .htaccess
│   ├── custom_api_helper.php
│   ├── custom_array_helper.php
│   ├── custom_date_time_helper.php
│   ├── custom_debug_helper.php
│   ├── custom_general_helper.php
│   ├── custom_project_helper.php
│   ├── custom_session_helper.php
│   └── custom_upload_helper.php
├── systems/                 # Core system files
│   ├── Components/          # System components
│   │   ├── Debug.php
│   │   ├── Logger.php
│   │   └── Request.php
│   └── Core/               # Core functionality
│       ├── Database/       # Database drivers and helpers
│       │   ├── Drivers/
│       │   ├── Interface/
│       │   ├── BaseDatabase.php
│       │   ├── Database.php
│       │   ├── DatabaseCache.php
│       │   └── DatabaseHelper.php
│       └── start.php
├── views/                  # View templates
│   ├── _templates/         # Template files
│   └── auth/              # Authentication views
│       └── login.php
├── public/                # Public web files
├── logs/                  # Application logs
├── env.php               # Environment configuration
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

## Usage Examples

### Database Operations

```php
// Get users with pagination
$users = db()->table('users')
    ->select('id, name, email, created_at')
    ->where('status', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->offset(0)
    ->get();
```

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
