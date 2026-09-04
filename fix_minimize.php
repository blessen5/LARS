<?php
// This script ensures proper minimize feature structure in dashboard files

// Check and fix includes
$dashboards = [
    __DIR__ . '/admin_dashboard.php',
    __DIR__ . '/staff_dashboard.php',
    __DIR__ . '/dashboard.php'
];

foreach ($dashboards as $dashboard) {
    $content = file_get_contents($dashboard);
    
    // Remove any existing minimize feature includes
    $content = preg_replace('/\s*<\?php include .?includes\/minimize.*?.php.; \?>\s*/', "\n", $content);
    
    // Add minimize feature at the end
    $content = str_replace('</body>', "    <?php include 'includes/minimize-feature.php'; ?>\n</body>", $content);
    
    // Add mainContent wrapper if missing
    if (strpos($content, 'id="mainContent"') === false) {
        $content = preg_replace(
            '/(include .?includes\/header.php.; \?>)\s*/',
            "$1\n    <div id=\"mainContent\">\n",
            $content
        );
        $content = str_replace('</body>', "    </div>\n</body>", $content);
    }
    
    file_put_contents($dashboard, $content);
}

echo "Dashboards updated successfully!\n";