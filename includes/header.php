<?php
// Shared header included in non-dashboard pages
// Usage: include 'includes/header.php';
?>
<header class="header">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="header-title">Lab Activity Reporting System</h1>
            <div class="header-actions d-flex align-items-center gap-3">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <span class="user-info">User: <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?> | Role: <?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?></span>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">Login</a>
                    <a href="register.php" class="btn btn-secondary">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
