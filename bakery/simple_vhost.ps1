# Create a simple virtual host configuration
$configFile = "C:\xampp\apache\conf\extra\httpd-vhosts.conf"
$projectPath = "C:/Users/918825809/CascadeProjects/windsurf-project/bakery"

# Create a minimal configuration
$simpleConfig = @"
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "$projectPath"
    
    <Directory "$projectPath">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@

# Write the configuration
Set-Content $configFile $simpleConfig

Write-Host "Simple virtual host configuration created!"
Write-Host "Try restarting Apache now." 