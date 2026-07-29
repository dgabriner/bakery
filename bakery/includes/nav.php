<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// Get the current page name for active state
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav class="main-nav">
    <div class="nav-container">
        <div class="nav-header">
            <div class="nav-brand">
                <a href="/bakery/index.php">🥖 Bakery Manager</a>
            </div>
            <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </button>
        </div>
        
        <div class="nav-menu" id="navMenu">
            <!-- Desktop: Grouped navigation with dropdowns -->
            <div class="desktop-nav">
                <!-- Dashboard -->
                <a href="/bakery/index.php" class="nav-link <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                    <i class="nav-icon">📊</i> <span class="nav-text">Dashboard</span>
                </a>

                <!-- Product Management Dropdown -->
                <div class="nav-dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo in_array($current_page, ['products', 'dough_types', 'formulas', 'ingredients']) ? 'active' : ''; ?>">
                        <i class="nav-icon">🥖</i> <span class="nav-text">Products</span>
                        <i class="dropdown-arrow">▼</i>
                    </a>
                    <div class="dropdown-menu">
                        <a href="/bakery/products.php" class="dropdown-link <?php echo $current_page === 'products' ? 'active' : ''; ?>">
                            <i class="nav-icon">🥖</i> Products
                        </a>
                        <a href="/bakery/dough_types.php" class="dropdown-link <?php echo $current_page === 'dough_types' ? 'active' : ''; ?>">
                            <i class="nav-icon">🧾</i> Dough Types
                        </a>
                        <a href="/bakery/formulas.php" class="dropdown-link <?php echo $current_page === 'formulas' ? 'active' : ''; ?>">
                            <i class="nav-icon">⚖️</i> Formulas
                        </a>
                        <a href="/bakery/ingredients.php" class="dropdown-link <?php echo $current_page === 'ingredients' ? 'active' : ''; ?>">
                            <i class="nav-icon">🧂</i> Ingredients
                        </a>
                    </div>
                </div>

                <!-- Customer Management Dropdown -->
                <div class="nav-dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo in_array($current_page, ['customers', 'customer_schedule', 'customer_overview', 'zones', 'leads']) ? 'active' : ''; ?>">
                        <i class="nav-icon">👥</i> <span class="nav-text">Customers</span>
                        <i class="dropdown-arrow">▼</i>
                    </a>
                    <div class="dropdown-menu">
                        <a href="/bakery/customers.php" class="dropdown-link <?php echo $current_page === 'customers' ? 'active' : ''; ?>">
                            <i class="nav-icon">👥</i> Customers
                        </a>
                        <a href="/bakery/customer_schedule.php" class="dropdown-link <?php echo $current_page === 'customer_schedule' ? 'active' : ''; ?>">
                            <i class="nav-icon">📅</i> Schedule
                        </a>
                        <a href="/bakery/customer_overview.php" class="dropdown-link <?php echo $current_page === 'customer_overview' ? 'active' : ''; ?>">
                            <i class="nav-icon">📊</i> Overview
                        </a>
                        <a href="/bakery/zones.php" class="dropdown-link <?php echo $current_page === 'zones' ? 'active' : ''; ?>">
                            <i class="nav-icon">🗺️</i> Zones
                        </a>
                        <a href="/bakery/leads.php" class="dropdown-link <?php echo $current_page === 'leads' ? 'active' : ''; ?>">
                            <i class="nav-icon">🎯</i> Leads
                        </a>
                    </div>
                </div>

                <!-- Order Management Dropdown -->
                <div class="nav-dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo in_array($current_page, ['orders', 'standing_orders', 'standing_orders_manager', 'daily_orders', 'bread_distribution', 'product_distribution']) ? 'active' : ''; ?>">
                        <i class="nav-icon">📝</i> <span class="nav-text">Orders</span>
                        <i class="dropdown-arrow">▼</i>
                    </a>
                    <div class="dropdown-menu">
                        <a href="/bakery/daily_orders.php" class="dropdown-link <?php echo $current_page === 'daily_orders' ? 'active' : ''; ?>">
                            <i class="nav-icon">📋</i> Daily Orders
                        </a>
                        <a href="/bakery/standing_orders.php" class="dropdown-link <?php echo $current_page === 'standing_orders' ? 'active' : ''; ?>">
                            <i class="nav-icon">📅</i> Standing Orders
                        </a>
                        <a href="/bakery/standing_orders_manager.php" class="dropdown-link <?php echo $current_page === 'standing_orders_manager' ? 'active' : ''; ?>">
                            <i class="nav-icon">🗓️</i> Standing Orders Manager
                        </a>
                        <a href="/bakery/bread_distribution.php" class="dropdown-link <?php echo $current_page === 'bread_distribution' ? 'active' : ''; ?>">
                            <i class="nav-icon">🍞</i> Bread Distribution
                        </a>
                        <a href="/bakery/product_distribution.php" class="dropdown-link <?php echo $current_page === 'product_distribution' ? 'active' : ''; ?>">
                            <i class="nav-icon">🔍</i> Product Distribution
                        </a>
                        <a href="/bakery/orders.php" class="dropdown-link <?php echo $current_page === 'orders' ? 'active' : ''; ?>">
                            <i class="nav-icon">📝</i> Orders
                        </a>
                    </div>
                </div>

                <!-- Production Dropdown -->
                <div class="nav-dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo in_array($current_page, ['production', 'pack_list']) ? 'active' : ''; ?>">
                        <i class="nav-icon">⚙️</i> <span class="nav-text">Production</span>
                        <i class="dropdown-arrow">▼</i>
                    </a>
                    <div class="dropdown-menu">
                        <a href="/bakery/production.php" class="dropdown-link <?php echo $current_page === 'production' ? 'active' : ''; ?>">
                            <i class="nav-icon">⚙️</i> Production
                        </a>
                        <a href="/bakery/pack_list.php" class="dropdown-link <?php echo $current_page === 'pack_list' ? 'active' : ''; ?>">
                            <i class="nav-icon">📦</i> Pack List
                        </a>
                    </div>
                </div>

                <!-- Routes & Delivery Dropdown -->
                <div class="nav-dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo in_array($current_page, ['standing_routes', 'daily_route', 'driver', 'driver_overview', 'route_manager', 'map', 'route_tester', 'call_headquarters', 'driver_assignment']) ? 'active' : ''; ?>">
                        <i class="nav-icon">🚚</i> <span class="nav-text">Routes</span>
                        <i class="dropdown-arrow">▼</i>
                    </a>
                    <div class="dropdown-menu">
                        <a href="/bakery/standing_routes.php" class="dropdown-link <?php echo $current_page === 'standing_routes' ? 'active' : ''; ?>">
                            <i class="nav-icon">🚚</i> Standing Routes
                        </a>
                        <a href="/bakery/daily_route.php" class="dropdown-link <?php echo $current_page === 'daily_route' ? 'active' : ''; ?>">
                            <i class="nav-icon">📋</i> Daily Route
                        </a>
                        <a href="/bakery/driver_assignment.php" class="dropdown-link <?php echo $current_page === 'driver_assignment' ? 'active' : ''; ?>">
                            <i class="nav-icon">🚚</i> Driver Assignment
                        </a>
                        <a href="/bakery/driver.php" class="dropdown-link <?php echo $current_page === 'driver' ? 'active' : ''; ?>">
                            <i class="nav-icon">📱</i> Driver Tracking
                        </a>
                        <a href="/bakery/driver_overview.php" class="dropdown-link <?php echo $current_page === 'driver_overview' ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            <span>Driver Overview</span>
                        </a>
                        <a href="/bakery/driver_list.php" class="dropdown-link <?php echo $current_page === 'driver_list' ? 'active' : ''; ?>">
                            <i class="fas fa-route"></i>
                            <span>Driver Route List</span>
                        </a>
                        <a href="/bakery/route_manager.php" class="dropdown-link <?php echo $current_page === 'route_manager' ? 'active' : ''; ?>">
                            <i class="nav-icon">👁️</i> Route Manager
                        </a>
                        <a href="/bakery/map.php" class="dropdown-link <?php echo $current_page === 'map' ? 'active' : ''; ?>">
                            <i class="nav-icon">🗺️</i> Map
                        </a>
                        <a href="/bakery/route_tester.php" class="dropdown-link <?php echo $current_page === 'route_tester' ? 'active' : ''; ?>">
                            <i class="nav-icon">🧪</i> Route Tester
                        </a>
                        <a href="/bakery/call_headquarters.php" class="dropdown-link <?php echo $current_page === 'call_headquarters' ? 'active' : ''; ?>">
                            <i class="nav-icon">📞</i> Call HQ
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Mobile: Organized sections (keeping existing mobile structure) -->
            <div class="mobile-nav">
                <div class="nav-section">
                    <h3 class="nav-section-title">📊 Dashboard</h3>
                    <div class="nav-links">
                        <a href="/bakery/index.php" class="nav-link <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                            <i class="nav-icon">📊</i> Database Overview
                        </a>
                    </div>
                </div>

                <div class="nav-section">
                    <h3 class="nav-section-title">🥖 Product Management</h3>
                    <div class="nav-links">
                        <a href="/bakery/products.php" class="nav-link <?php echo $current_page === 'products' ? 'active' : ''; ?>">
                            <i class="nav-icon">🥖</i> Products
                        </a>
                        <a href="/bakery/dough_types.php" class="nav-link <?php echo $current_page === 'dough_types' ? 'active' : ''; ?>">
                            <i class="nav-icon">🧾</i> Dough Types
                        </a>
                        <a href="/bakery/formulas.php" class="nav-link <?php echo $current_page === 'formulas' ? 'active' : ''; ?>">
                            <i class="nav-icon">⚖️</i> Formulas
                        </a>
                        <a href="/bakery/ingredients.php" class="nav-link <?php echo $current_page === 'ingredients' ? 'active' : ''; ?>">
                            <i class="nav-icon">🧂</i> Ingredients
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <h3 class="nav-section-title">👥 Customer Management</h3>
                    <div class="nav-links">
                        <a href="/bakery/customers.php" class="nav-link <?php echo $current_page === 'customers' ? 'active' : ''; ?>">
                            <i class="nav-icon">👥</i> Customers
                        </a>
                        <a href="/bakery/customer_schedule.php" class="nav-link <?php echo $current_page === 'customer_schedule' ? 'active' : ''; ?>">
                            <i class="nav-icon">📅</i> Customer Schedule
                        </a>
                        <a href="/bakery/customer_overview.php" class="nav-link <?php echo $current_page === 'customer_overview' ? 'active' : ''; ?>">
                            <i class="nav-icon">📊</i> Customer Overview
                        </a>
                        <a href="/bakery/zones.php" class="nav-link <?php echo $current_page === 'zones' ? 'active' : ''; ?>">
                            <i class="nav-icon">🗺️</i> Zones
                        </a>
                        <a href="/bakery/leads.php" class="nav-link <?php echo $current_page === 'leads' ? 'active' : ''; ?>">
                            <i class="nav-icon">🎯</i> Leads
                        </a>
                    </div>
                </div>

                <div class="nav-section">
                    <h3 class="nav-section-title">📝 Orders</h3>
                    <div class="nav-links">
                        <a href="/bakery/daily_orders.php" class="nav-link <?php echo $current_page === 'daily_orders' ? 'active' : ''; ?>">
                            <i class="nav-icon">📋</i> Daily Orders
                        </a>
                        <a href="/bakery/standing_orders.php" class="nav-link <?php echo $current_page === 'standing_orders' ? 'active' : ''; ?>">
                            <i class="nav-icon">📅</i> Standing Orders
                        </a>
                        <a href="/bakery/standing_orders_manager.php" class="nav-link <?php echo $current_page === 'standing_orders_manager' ? 'active' : ''; ?>">
                            <i class="nav-icon">🗓️</i> Standing Orders Manager
                        </a>
                        <a href="/bakery/bread_distribution.php" class="nav-link <?php echo $current_page === 'bread_distribution' ? 'active' : ''; ?>">
                            <i class="nav-icon">🍞</i> Bread Distribution
                        </a>
                        <a href="/bakery/product_distribution.php" class="nav-link <?php echo $current_page === 'product_distribution' ? 'active' : ''; ?>">
                            <i class="nav-icon">🔍</i> Product Distribution
                        </a>
                        <a href="/bakery/orders.php" class="nav-link <?php echo $current_page === 'orders' ? 'active' : ''; ?>">
                            <i class="nav-icon">📝</i> Orders
                        </a>
                    </div>
                </div>

                <div class="nav-section">
                    <h3 class="nav-section-title">⚙️ Production</h3>
                    <div class="nav-links">
                        <a href="/bakery/production.php" class="nav-link <?php echo $current_page === 'production' ? 'active' : ''; ?>">
                            <i class="nav-icon">⚙️</i> Production
                        </a>
                        <a href="/bakery/pack_list.php" class="nav-link <?php echo $current_page === 'pack_list' ? 'active' : ''; ?>">
                            <i class="nav-icon">📦</i> Pack List
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <h3 class="nav-section-title">🚚 Routes & Delivery</h3>
                    <div class="nav-links">
                        <a href="/bakery/standing_routes.php" class="nav-link <?php echo $current_page === 'standing_routes' ? 'active' : ''; ?>">
                            <i class="nav-icon">🚚</i> Standing Routes
                        </a>
                        <a href="/bakery/daily_route.php" class="nav-link <?php echo $current_page === 'daily_route' ? 'active' : ''; ?>">
                            <i class="nav-icon">📋</i> Daily Route
                        </a>
                        <a href="/bakery/driver_assignment.php" class="nav-link <?php echo $current_page === 'driver_assignment' ? 'active' : ''; ?>">
                            <i class="nav-icon">🚚</i> Driver Assignment
                        </a>
                        <a href="/bakery/driver.php" class="nav-link <?php echo $current_page === 'driver' ? 'active' : ''; ?>">
                            <i class="nav-icon">📱</i> Driver Tracking
                        </a>
                        <a href="/bakery/driver_overview.php" class="nav-link <?php echo $current_page === 'driver_overview' ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            <span>Driver Overview</span>
                        </a>
                        <a href="/bakery/driver_list.php" class="nav-link <?php echo $current_page === 'driver_list' ? 'active' : ''; ?>">
                            <i class="fas fa-route"></i>
                            <span>Driver Route List</span>
                        </a>
                        <a href="/bakery/route_manager.php" class="nav-link <?php echo $current_page === 'route_manager' ? 'active' : ''; ?>">
                            <i class="nav-icon">👁️</i> Route Manager
                        </a>
                        <a href="/bakery/map.php" class="nav-link <?php echo $current_page === 'map' ? 'active' : ''; ?>">
                            <i class="nav-icon">🗺️</i> Customer Map
                        </a>
                        <a href="/bakery/route_tester.php" class="nav-link <?php echo $current_page === 'route_tester' ? 'active' : ''; ?>">
                            <i class="nav-icon">🧪</i> Route Tester
                        </a>
                        <a href="/bakery/call_headquarters.php" class="nav-link <?php echo $current_page === 'call_headquarters' ? 'active' : ''; ?>">
                            <i class="nav-icon">📞</i> Call HQ
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const navMenu = document.getElementById('navMenu');
    const body = document.body;
    
    mobileMenuToggle.addEventListener('click', function() {
        navMenu.classList.toggle('active');
        mobileMenuToggle.classList.toggle('active');
        body.classList.toggle('nav-open');
    });
    
    // Close menu when clicking on a link (mobile)
    const navLinks = document.querySelectorAll('.nav-link:not(.dropdown-toggle)');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                navMenu.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                body.classList.remove('nav-open');
            }
        });
    });
    
    // Handle dropdown toggles for mobile
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                e.preventDefault();
                const dropdown = this.parentElement;
                dropdown.classList.toggle('mobile-active');
            }
        });
    });
    
    // Close menu when clicking outside (mobile)
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768) {
            const isClickInsideNav = event.target.closest('.main-nav');
            if (!isClickInsideNav && navMenu.classList.contains('active')) {
                navMenu.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                body.classList.remove('nav-open');
            }
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            navMenu.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
            body.classList.remove('nav-open');
            // Remove mobile dropdown states
            document.querySelectorAll('.nav-dropdown.mobile-active').forEach(dropdown => {
                dropdown.classList.remove('mobile-active');
            });
        }
    });
});
</script>

<style>
/* Reset and base styles */
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

body.nav-open {
    overflow: hidden;
}

.main-nav {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    min-height: 70px;
}

.nav-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1rem;
    min-height: 70px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
}

.nav-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    height: 35px;
    flex-shrink: 0;
}

.nav-brand {
    flex-shrink: 0;
}

.nav-brand a {
    color: white;
    font-size: 1.4rem;
    font-weight: bold;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: color 0.3s ease;
}

.nav-brand a:hover {
    color: #3498db;
}

.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: background-color 0.3s ease;
}

.mobile-menu-toggle:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

.hamburger-line {
    display: block;
    width: 25px;
    height: 3px;
    background-color: white;
    margin: 5px 0;
    transition: all 0.3s ease;
    border-radius: 2px;
}

.mobile-menu-toggle.active .hamburger-line:nth-child(1) {
    transform: rotate(45deg) translate(6px, 6px);
}

.mobile-menu-toggle.active .hamburger-line:nth-child(2) {
    opacity: 0;
}

.mobile-menu-toggle.active .hamburger-line:nth-child(3) {
    transform: rotate(-45deg) translate(6px, -6px);
}

/* Desktop Navigation */
.nav-menu {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    flex: 1;
    min-height: 30px;
}

.desktop-nav {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.mobile-nav {
    display: none;
}

/* Navigation Links */
.nav-link {
    color: #ecf0f1;
    text-decoration: none;
    padding: 0.6rem 1rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.3s ease;
    white-space: nowrap;
    font-size: 0.9rem;
    background-color: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.1);
    font-weight: 500;
    line-height: 1;
    position: relative;
}

.nav-link:hover {
    background-color: #3498db;
    border-color: #2980b9;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.nav-link.active {
    background-color: #e74c3c;
    border-color: #c0392b;
    color: white;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(231, 76, 60, 0.3);
}

.nav-icon {
    font-size: 1rem;
    flex-shrink: 0;
}

.nav-text {
    font-size: 0.9rem;
}

/* Dropdown Styles */
.nav-dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle {
    cursor: pointer;
}

.dropdown-arrow {
    font-size: 0.7rem;
    margin-left: 0.3rem;
    transition: transform 0.3s ease;
}

.nav-dropdown:hover .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    min-width: 180px;
    z-index: 1001;
    padding: 0.5rem 0;
}

.nav-dropdown:hover .dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.dropdown-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.7rem 1rem;
    color: #ecf0f1;
    text-decoration: none;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    border: none;
    background: none;
    border-radius: 0;
    width: 100%;
}

.dropdown-link:hover {
    background-color: #3498db;
    transform: none;
    box-shadow: none;
    border: none;
}

.dropdown-link.active {
    background-color: #e74c3c;
    color: white;
    font-weight: 600;
}

.dropdown-link .nav-icon {
    font-size: 0.9rem;
}

/* Mobile styles */
@media (max-width: 768px) {
    .main-nav {
        min-height: 60px;
    }
    
    .nav-container {
        padding: 0 1rem;
        min-height: 60px;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }
    
    .nav-header {
        height: 60px;
        flex-shrink: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }
    
    .nav-brand a {
        font-size: 1.2rem;
        color: white;
    }
    
    .mobile-menu-toggle {
        display: block;
    }
    
    .desktop-nav {
        display: none;
    }
    
    .mobile-nav {
        display: block;
    }
    
    .nav-menu {
        position: fixed;
        top: 60px;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        padding: 1.5rem;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        justify-content: flex-start;
        align-items: flex-start;
        flex-direction: column;
        z-index: 999;
    }
    
    .nav-menu.active {
        transform: translateX(0);
    }
    
    .nav-section {
        margin-bottom: 1.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding-bottom: 1.5rem;
        width: 100%;
    }
    
    .nav-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .nav-section-title {
        color: #3498db;
        font-size: 1rem;
        font-weight: 600;
        margin: 0 0 1rem 0;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #3498db;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    
    .nav-section .nav-links {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    
    .nav-section .nav-link {
        color: #ecf0f1;
        padding: 1rem 1.25rem;
        font-size: 1rem;
        border-radius: 8px;
        background-color: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.15);
        justify-content: flex-start;
        margin-bottom: 0;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s ease;
    }
    
    .nav-section .nav-link:hover,
    .nav-section .nav-link:active {
        background-color: #3498db;
        border-color: #2980b9;
        color: white;
        transform: none;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    
    .nav-section .nav-link.active {
        background-color: #e74c3c;
        border-color: #c0392b;
        color: white;
        box-shadow: 0 2px 6px rgba(231, 76, 60, 0.4);
    }
    
    .nav-section .nav-icon {
        font-size: 1.2rem;
        margin-right: 0.25rem;
    }
}

/* Responsive adjustments */
@media (max-width: 1200px) {
    .nav-link {
        padding: 0.5rem 0.8rem;
        font-size: 0.85rem;
    }
    
    .nav-text {
        font-size: 0.85rem;
    }
}

@media (max-width: 992px) {
    .nav-link {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
    }
    
    .nav-text {
        display: none;
    }
    
    .dropdown-arrow {
        display: none;
    }
    
    .dropdown-menu {
        min-width: 160px;
    }
}

/* iPhone specific optimizations */
@media (max-width: 480px) and (-webkit-min-device-pixel-ratio: 2) {
    .nav-header {
        height: 56px;
    }
    
    .nav-brand a {
        font-size: 1.1rem;
    }
    
    .nav-menu {
        top: 56px;
        padding: 1rem;
    }
    
    .nav-section .nav-link {
        padding: 0.9rem 1.1rem;
        font-size: 0.95rem;
        min-height: 48px;
    }
}
</style> 