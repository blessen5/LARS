<?php
// minimize-feature.php - Include this at the bottom of each dashboard
?>
<!-- Minimize Feature -->
<?php include 'minimize-bar.php'; ?>
<?php include 'minimize-button.php'; ?>
<script src="assets/js/minimize.js"></script>
<!-- Debug Console (hidden in production) -->
<div id="minimizeDebug" style="display: none; position: fixed; bottom: 10px; right: 10px; 
     background: rgba(0,0,0,0.8); color: #fff; padding: 10px; border-radius: 4px; 
     font-family: monospace; font-size: 12px;"></div>
<script>
// Debug helper
function debugMinimize(msg) {
    const debug = document.getElementById('minimizeDebug');
    if (debug) {
        debug.style.display = 'block';
        debug.innerHTML += msg + '<br>';
    }
    console.log('Minimize Debug:', msg);
}
</script>