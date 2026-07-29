<?php
/**
 * Bakery Management System - Dashboard Overview
 * 
 * Main dashboard displaying key business metrics and system features overview.
 * Provides quick access to all major system functions and real-time statistics.
 * 
 * @package BakeryManagement
 * @author Bakery Management Team
 * @version 1.0
 */

// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Set page title
$page_title = 'Bakery Overview';

// Include header and navigation
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Fetch business statistics using centralized function
try {
    $stats = get_business_statistics($db);
    
    // Calculate percentage insights
    $customers_with_zones_pct = $stats['total_customers'] > 0 ? 
        round(($stats['customers_with_zones'] / $stats['total_customers']) * 100, 1) : 0;
    
    $customers_with_phone_pct = $stats['total_customers'] > 0 ? 
        round(($stats['customers_with_phone'] / $stats['total_customers']) * 100, 1) : 0;
    
    $customers_with_standing_pct = $stats['total_customers'] > 0 ? 
        round(($stats['customers_with_standing_orders'] / $stats['total_customers']) * 100, 1) : 0;
    
} catch (Exception $e) {
    // Fallback to default values if statistics gathering fails
    $stats = array_fill_keys([
        'total_products', 'total_customers', 'total_orders', 'total_ingredients',
        'recent_orders', 'pending_orders', 'active_leads', 'customers_with_zones',
        'customers_with_phone', 'customers_with_email', 'customers_with_standing_orders',
        'total_standing_routes', 'total_formulas', 'total_dough_types', 'production_schedules'
    ], 0);
    
    $customers_with_zones_pct = $customers_with_phone_pct = $customers_with_standing_pct = 0;
    $db_error = $e->getMessage();
}
?>

<div class="container">
    <div class="overview-header">
        <h1>🥖 Bakery Management Overview</h1>
        <p class="overview-subtitle">Complete business management system for artisan bakeries</p>
    </div>

    <?php if (isset($db_error)): ?>
        <div class="alert alert-warning">
            <strong>⚠️ Database Notice:</strong> Some statistics may be incomplete due to missing tables. 
            <small>Error: <?php echo htmlspecialchars($db_error); ?></small>
        </div>
    <?php endif; ?>

    <!-- Key Statistics Dashboard -->
    <div class="stats-dashboard">
        <h2>📊 Key Business Metrics</h2>
        
        <!-- Primary Metrics Grid -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-icon">🥖</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="stat-label">Total Products</div>
                </div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-icon">👥</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon">📝</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                    <div class="stat-label">Total Orders</div>
                    <?php if ($stats['total_orders'] == 0): ?>
                        <div class="stat-note">Table may not exist</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon">🧂</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['total_ingredients']); ?></div>
                    <div class="stat-label">Ingredients</div>
                    <?php if ($stats['total_ingredients'] == 0): ?>
                        <div class="stat-note">Table may not exist</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Secondary Metrics Grid -->
        <div class="stats-grid secondary">
            <div class="stat-card recent">
                <div class="stat-icon">🕐</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['recent_orders']); ?></div>
                    <div class="stat-label">Orders This Week</div>
                </div>
            </div>
            
            <div class="stat-card pending">
                <div class="stat-icon">⏳</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['pending_orders']); ?></div>
                    <div class="stat-label">Pending Orders</div>
                </div>
            </div>
            
            <div class="stat-card leads">
                <div class="stat-icon">🎯</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['active_leads']); ?></div>
                    <div class="stat-label">Active Leads</div>
                </div>
            </div>
            
            <div class="stat-card routes">
                <div class="stat-icon">🚚</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($stats['total_standing_routes']); ?></div>
                    <div class="stat-label">Delivery Days</div>
                </div>
            </div>
        </div>
        
        <!-- Business Insights Grid -->
        <div class="insights-grid">
            <div class="insight-card">
                <h3>🗺️ Customer Distribution</h3>
                <div class="insight-stat">
                    <span class="big-number"><?php echo $customers_with_zones_pct; ?>%</span>
                    <span class="insight-label">of customers have assigned delivery zones</span>
                </div>
                <div class="insight-detail">
                    <?php echo number_format($stats['customers_with_zones']); ?> out of 
                    <?php echo number_format($stats['total_customers']); ?> customers
                </div>
            </div>
            
            <div class="insight-card">
                <h3>📞 Contact Information</h3>
                <div class="insight-stat">
                    <span class="big-number"><?php echo $customers_with_phone_pct; ?>%</span>
                    <span class="insight-label">of customers have phone numbers</span>
                </div>
                <div class="insight-detail">
                    <?php echo number_format($stats['customers_with_email']); ?> customers have email addresses
                </div>
            </div>
            
            <div class="insight-card">
                <h3>⚖️ Production Ready</h3>
                <div class="insight-stat">
                    <span class="big-number"><?php echo number_format($stats['total_formulas']); ?></span>
                    <span class="insight-label">recipes available for production</span>
                </div>
                <div class="insight-detail">
                    <?php echo number_format($stats['total_dough_types']); ?> dough types defined
                </div>
            </div>
        </div>
    </div>

    <!-- System Features Overview -->
    <div class="features-overview">
        <h2>🔧 System Features</h2>
        
        <!-- Core Management Section -->
        <div class="feature-section">
            <div class="section-header">
                <h3>🏪 Core Management</h3>
                <p>Essential business operations and product management</p>
            </div>
            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">🥖</div>
                    <h4><a href="/bakery/products.php">Products</a></h4>
                    <p>Manage your bakery's product catalog including breads, pastries, and specialty items. Set prices, descriptions, and track inventory.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['total_products']); ?> products</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🧾</div>
                    <h4><a href="/bakery/dough_types.php">Dough Types</a></h4>
                    <p>Define different dough types and their characteristics. Essential for production planning and recipe development.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['total_dough_types']); ?> types</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">⚖️</div>
                    <h4><a href="/bakery/formulas.php">Formulas</a></h4>
                    <p>Create and manage detailed recipes with precise measurements, baking times, and production notes for consistent quality.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['total_formulas']); ?> formulas</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🧂</div>
                    <h4><a href="/bakery/ingredients.php">Ingredients</a></h4>
                    <p>Track ingredients, suppliers, costs, and inventory levels. Essential for accurate costing and production planning.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['total_ingredients']); ?> ingredients</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders & Customers Section -->
        <div class="feature-section">
            <div class="section-header">
                <h3>👥 Orders & Customers</h3>
                <p>Customer relationship management and order processing</p>
            </div>
            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">📝</div>
                    <h4><a href="/bakery/orders.php">Orders</a></h4>
                    <p>Process customer orders, track order status, manage special requests, and handle billing. Complete order lifecycle management.</p>
                    <div class="feature-stats">
                        <span class="stat-badge total"><?php echo number_format($stats['total_orders']); ?> total</span>
                        <span class="stat-badge pending"><?php echo number_format($stats['pending_orders']); ?> pending</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">👥</div>
                    <h4><a href="/bakery/customers.php">Customers</a></h4>
                    <p>Maintain customer database with contact information, delivery preferences, zones, and order history for personalized service.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['total_customers']); ?> customers</span>
                        <span class="stat-badge zones"><?php echo $customers_with_zones_pct; ?>% zoned</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📅</div>
                    <h4><a href="/bakery/customer_schedule.php">Schedule</a></h4>
                    <p>Visual customer delivery schedule organized by zones and days. Plan driver routes and manage delivery logistics efficiently.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['total_standing_routes']); ?> delivery days</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h4><a href="/bakery/leads.php">Leads</a></h4>
                    <p>Track potential customers, manage follow-ups, and convert prospects into customers. Complete sales pipeline management.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['active_leads']); ?> active</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📅</div>
                    <h4><a href="/bakery/standing_orders.php">Standing Orders</a></h4>
                    <p>Manage recurring customer orders for regular deliveries. Automate weekly/monthly orders and ensure consistent revenue.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['customers_with_standing_orders']); ?> customers</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Production & Delivery Section -->
        <div class="feature-section">
            <div class="section-header">
                <h3>🚚 Production & Delivery</h3>
                <p>Production planning, logistics, and delivery management</p>
            </div>
            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">⚙️</div>
                    <h4><a href="/bakery/production.php">Production</a></h4>
                    <p>Plan daily production schedules, calculate required quantities, and track production progress. Optimize your baking workflow.</p>
                    <div class="feature-stats">
                        <span class="stat-badge"><?php echo number_format($stats['production_schedules']); ?> upcoming</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📦</div>
                    <h4><a href="/bakery/pack_list.php">Pack List</a></h4>
                    <p>Generate packing lists for orders and deliveries. Ensure all items are prepared and organized for efficient delivery.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">Daily lists</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🚚</div>
                    <h4><a href="/bakery/standing_routes.php">Standing Routes</a></h4>
                    <p>Configure regular delivery routes with assigned drivers and customers. Optimize delivery efficiency and driver schedules.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">Route planning</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📋</div>
                    <h4><a href="/bakery/daily_route.php">Daily Route</a></h4>
                    <p>Generate and manage daily delivery routes. View customer deliveries organized by driver and optimize route efficiency.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">Daily delivery</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h4><a href="/bakery/driver.php">Driver Tracking</a></h4>
                    <p>Real-time GPS tracking for drivers. Monitor delivery progress, track locations, and ensure timely customer service.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">GPS tracking</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">👁️</div>
                    <h4><a href="/bakery/route_manager.php">Route Manager</a></h4>
                    <p>Comprehensive route oversight and management. Monitor all driver activities, routes, and delivery performance in real-time.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">Management</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🗺️</div>
                    <h4><a href="/bakery/map.php">Customer Map</a></h4>
                    <p>Interactive map showing all customers with zone-based color coding. Visualize delivery areas and optimize route planning.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">Interactive map</span>
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🧪</div>
                    <h4><a href="/bakery/route_tester.php">Route Tester</a></h4>
                    <p>Test and optimize delivery routes. Experiment with different route configurations to find the most efficient delivery paths.</p>
                    <div class="feature-stats">
                        <span class="stat-badge">Route optimization</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="quick-actions">
        <h2>🚀 Quick Actions</h2>
        <div class="actions-grid">
            <a href="/bakery/orders.php" class="action-card primary">
                <div class="action-icon">📝</div>
                <div class="action-content">
                    <h4>New Order</h4>
                    <p>Create a new customer order</p>
                </div>
            </a>
            
            <a href="/bakery/production.php" class="action-card success">
                <div class="action-icon">⚙️</div>
                <div class="action-content">
                    <h4>Plan Production</h4>
                    <p>Schedule today's baking</p>
                </div>
            </a>
            
            <a href="/bakery/daily_route.php" class="action-card warning">
                <div class="action-icon">📋</div>
                <div class="action-content">
                    <h4>Daily Routes</h4>
                    <p>View today's deliveries</p>
                </div>
            </a>
            
            <a href="/bakery/map.php" class="action-card info">
                <div class="action-icon">🗺️</div>
                <div class="action-content">
                    <h4>Customer Map</h4>
                    <p>View delivery zones</p>
                </div>
            </a>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.overview-header {
    text-align: center;
    margin-bottom: 40px;
}

.overview-header h1 {
    color: #2c3e50;
    font-size: 2.5rem;
    margin-bottom: 10px;
}

.overview-subtitle {
    color: #7f8c8d;
    font-size: 1.2rem;
    margin: 0;
}

/* Alert styling */
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
}

.alert-warning {
    color: #856404;
    background-color: #fff3cd;
    border-color: #ffeaa7;
}

/* Stats Dashboard */
.stats-dashboard {
    margin-bottom: 50px;
}

.stats-dashboard h2 {
    color: #2c3e50;
    margin-bottom: 25px;
    font-size: 1.8rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stats-grid.secondary {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-card.success { background: linear-gradient(135deg, #48c6ef 0%, #6f86d6 100%); }
.stat-card.warning { background: linear-gradient(135deg, #feac5e 0%, #c779d0 100%); }
.stat-card.info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.stat-card.recent { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
.stat-card.pending { background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); }
.stat-card.leads { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
.stat-card.routes { background: linear-gradient(135deg, #96fbc4 0%, #f9f586 100%); }

.stat-icon {
    font-size: 3rem;
    opacity: 0.9;
}

.stat-content {
    color: white;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: bold;
    line-height: 1;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 1rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-note {
    font-size: 0.8rem;
    opacity: 0.7;
    font-style: italic;
}

/* Insights Grid */
.insights-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 30px;
}

.insight-card {
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.insight-card h3 {
    color: #2c3e50;
    margin: 0 0 15px 0;
    font-size: 1.2rem;
}

.insight-stat {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 10px;
}

.big-number {
    font-size: 2.5rem;
    font-weight: bold;
    color: #3498db;
}

.insight-label {
    color: #7f8c8d;
    font-size: 1rem;
}

.insight-detail {
    color: #95a5a6;
    font-size: 0.9rem;
}

/* Features Overview */
.features-overview h2 {
    color: #2c3e50;
    margin-bottom: 30px;
    font-size: 1.8rem;
}

.feature-section {
    margin-bottom: 50px;
}

.section-header {
    margin-bottom: 25px;
}

.section-header h3 {
    color: #2c3e50;
    font-size: 1.5rem;
    margin: 0 0 10px 0;
}

.section-header p {
    color: #7f8c8d;
    margin: 0;
    font-size: 1.1rem;
}

.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 25px;
}

.feature-card {
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 25px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.feature-card:hover {
    border-color: #3498db;
    box-shadow: 0 5px 25px rgba(52, 152, 219, 0.15);
    transform: translateY(-3px);
}

.feature-icon {
    font-size: 3rem;
    margin-bottom: 15px;
}

.feature-card h4 {
    color: #2c3e50;
    margin: 0 0 10px 0;
    font-size: 1.3rem;
}

.feature-card h4 a {
    color: inherit;
    text-decoration: none;
}

.feature-card h4 a:hover {
    color: #3498db;
}

.feature-card p {
    color: #7f8c8d;
    margin: 0 0 15px 0;
    line-height: 1.6;
}

.feature-stats {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.stat-badge {
    background: #ecf0f1;
    color: #2c3e50;
    padding: 4px 10px;
    border-radius: 15px;
    font-size: 0.85rem;
    font-weight: 600;
}

.stat-badge.total { background: #e8f5e8; color: #27ae60; }
.stat-badge.pending { background: #fff3cd; color: #856404; }
.stat-badge.zones { background: #e3f2fd; color: #1976d2; }

/* Quick Actions */
.quick-actions {
    margin-top: 50px;
}

.quick-actions h2 {
    color: #2c3e50;
    margin-bottom: 25px;
    font-size: 1.8rem;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.action-card {
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.action-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 25px rgba(0,0,0,0.15);
    text-decoration: none;
}

.action-card.primary:hover { border-color: #3498db; }
.action-card.success:hover { border-color: #27ae60; }
.action-card.warning:hover { border-color: #f39c12; }
.action-card.info:hover { border-color: #17a2b8; }

.action-icon {
    font-size: 2.5rem;
}

.action-content h4 {
    color: #2c3e50;
    margin: 0 0 5px 0;
    font-size: 1.2rem;
}

.action-content p {
    color: #7f8c8d;
    margin: 0;
    font-size: 0.95rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    .overview-header h1 {
        font-size: 2rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .feature-grid {
        grid-template-columns: 1fr;
    }
    
    .actions-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-number {
        font-size: 2rem;
    }
    
    .big-number {
        font-size: 2rem;
    }
}

@media (max-width: 480px) {
    .insights-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .action-card {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?> 