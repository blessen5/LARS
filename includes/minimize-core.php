<!-- Minimize Feature Core -->
<div style="text-align: right; padding: 10px 20px;">
    <button class="minimize-btn btn btn-primary" id="continueBtn" 
            style="background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%); 
                   color: white; padding: 8px 20px; border: none; 
                   border-radius: 6px; cursor: pointer; font-weight: 500;">
        🔽 Minimize Dashboard
    </button>
</div>

<!-- Minimized Bar -->
<div id="minimizedBar" style="display: none; position: fixed; bottom: 20px; left: 20px; 
     background: linear-gradient(135deg, #4c51bf 0%, #5b63d3 100%); color: white; 
     padding: 12px 24px; border-radius: 8px; cursor: pointer; z-index: 1000;
     box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
     align-items: center; gap: 8px; user-select: none;">
    📘 Lab Activity System — Click to Restore
</div>

<script>
// Minimize functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('Minimize script loaded');
    
    // Get required elements
    const minimizeBtn = document.getElementById('continueBtn');
    const minimizedBar = document.getElementById('minimizedBar');
    
    // Find or create main content wrapper
    let mainContent = document.getElementById('mainContent');
    if (!mainContent) {
        // Find all content after header
        const header = document.querySelector('header');
        if (header) {
            // Create wrapper
            mainContent = document.createElement('div');
            mainContent.id = 'mainContent';
            
            // Move everything after header into wrapper
            let currentElement = header.nextElementSibling;
            while (currentElement) {
                const nextElement = currentElement.nextElementSibling;
                if (currentElement !== minimizedBar) {
                    mainContent.appendChild(currentElement);
                }
                currentElement = nextElement;
            }
            
            // Insert wrapper after header
            header.parentNode.insertBefore(mainContent, header.nextSibling);
            console.log('Created mainContent wrapper');
        }
    }
    
    // Add minimize/restore functionality
    if (minimizeBtn && mainContent && minimizedBar) {
        console.log('Minimize elements found, adding handlers');
        
        minimizeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Minimizing dashboard');
            mainContent.style.display = 'none';
            minimizedBar.style.display = 'flex';
        });
        
        minimizedBar.addEventListener('click', function() {
            console.log('Restoring dashboard');
            mainContent.style.display = 'block';
            minimizedBar.style.display = 'none';
        });
    } else {
        console.error('Some minimize elements missing:', {
            minimizeBtn: !!minimizeBtn,
            mainContent: !!mainContent,
            minimizedBar: !!minimizedBar
        });
    }
});</script>