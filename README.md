# Support Access Manager

Support Access Manager is a lightweight PHP class for WordPress that allows temporary admin accounts to be created with expiration and access limits. It can be easily dropped into any project or installed via Composer.

## Installation

### 1. Install via Composer (Recommended)
To include Support Access Manager in your WordPress plugin or theme, add it as a dependency:

```sh
composer require derekashauer/support-access-manager
```

If the package is not on Packagist, add the following to your `composer.json` before requiring it:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/derekashauer/support-access-manager"
    }
]
```

Then run:

```sh
composer update
```

### 2. Manually Include the Class
If you prefer not to use Composer, simply download `Support_Access_Manager.php` and include it in your project:

```php
require_once 'path/to/Support_Access_Manager.php';
new Support_Access_Manager();
```

---

## Usage

Once installed, initialize the class in your plugin:

```php
require_once ABSPATH . 'vendor/autoload.php'; // If using Composer

new Support_Access_Manager();
```

This will:
- Add a **Support Access** menu under **Users** in the WordPress admin.
- Allow temporary admin users to be created.
- Automatically delete expired temporary admins.
- Provide a unique login URL with optional limits and timeouts.

---

## Features

✅ Create temporary admin accounts  
✅ Set expiration times (e.g., auto-delete after 24 hours)  
✅ Limit number of logins per account  
✅ Secure login URLs for support access  
✅ Cron-based cleanup of expired accounts  

---

## License

This project is licensed under the **GPL-3.0**.

---

## Contributing

If you’d like to contribute, fork the repository and submit a pull request. Issues and feature requests are welcome.

---

## Author

**Derek Ashauer**  
[GitHub](https://github.com/derekashauer)
My Plugins: [WP Sunshine](https://www.wpsunshine.com) | [Conversion Bridge](https://conversionbridgewp.com) | [Sunshine Photo Cart](https://www.sunshinephotocart.com)
