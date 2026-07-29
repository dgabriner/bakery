# Bakery Management System

A modern, responsive bakery management system built with PHP and MySQL, featuring a clean MVC architecture and a user-friendly interface for managing products, customers, orders, and production schedules.

## Features

- **Product Management**: Add, edit, and track bakery products
- **Customer Management**: Maintain customer information and order history
- **Order Processing**: Manage customer orders and invoices
- **Production Planning**: Schedule and track production batches
- **Inventory Tracking**: Monitor ingredients and product stock levels
- **Reporting**: Generate reports on sales, production, and inventory

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.3 or higher
- Web server (Apache/Nginx) with mod_rewrite enabled
- Composer for dependency management

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/bakery-management-system.git
   cd bakery-management-system
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure the application**
   - Copy `.env.example` to `.env` and update the configuration:
     ```bash
     cp .env.example .env
     ```
   - Update database credentials and other settings in the `.env` file

4. **Set up the database**
   ```bash
   php database/migrations/install.php
   ```

5. **Set permissions**
   Ensure the `storage` and `bootstrap/cache` directories are writable by the web server.

6. **Generate application key**
   ```bash
   php artisan key:generate
   ```

7. **Access the application**
   - Point your web server to the `public` directory
   - Access the application in your browser at `http://localhost`
   - Default login: admin@example.com / password

## Project Structure

```
bakery/
├── config/                  # Configuration files
├── public/                  # Publicly accessible files
│   ├── css/                 # Compiled CSS
│   ├── js/                  # JavaScript files
│   └── index.php            # Application entry point
├── src/                     # Application source code
│   ├── Controllers/         # Controller classes
│   ├── Models/              # Model classes
│   ├── Database/            # Database connection and migrations
│   └── Services/            # Business logic services
├── templates/               # View templates
│   ├── layouts/             # Layout templates
│   ├── includes/            # Reusable template parts
│   └── views/               # View templates by feature
├── vendor/                  # Composer dependencies
├── .env.example             # Example environment file
├── composer.json            # Composer configuration
└── README.md                # This file
```

## Database Schema

### Main Tables

#### Products
- id (int, PK)
- name (varchar)
- description (text)
- price (decimal)
- active (boolean)
- created_at (datetime)
- updated_at (datetime)

#### Customers
- id (int, PK)
- name (varchar)
- email (varchar)
- phone (varchar)
- address (text)
- created_at (datetime)
- updated_at (datetime)

#### Orders
- id (int, PK)
- customer_id (int, FK)
- order_date (datetime)
- status (enum)
- total_amount (decimal)
- created_at (datetime)
- updated_at (datetime)

#### Order Items
- id (int, PK)
- order_id (int, FK)
- product_id (int, FK)
- quantity (int)
- unit_price (decimal)
- total_price (decimal)

## Development

### Coding Standards
- Follow PSR-12 coding standards
- Use type hints and return type declarations
- Write unit tests for new features
- Document your code with PHPDoc blocks

### Git Workflow
1. Create a new branch for your feature: `git checkout -b feature/your-feature-name`
2. Make your changes and commit them: `git commit -am 'Add some feature'`
3. Push to the branch: `git push origin feature/your-feature-name`
4. Create a Pull Request

### Testing
Run the test suite:
```bash
phpunit
```

## Security

- Always validate and sanitize user input
- Use prepared statements for database queries
- Store sensitive configuration in environment variables
- Keep dependencies up to date
- Regularly backup your database

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For support, please email support@example.com or open an issue in the issue tracker.
