// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Minimize.js loaded');

    // Wrap main content if not already wrapped
    function wrapMainContent() {
        const existingWrapper = document.getElementById('mainContent');
        if (!existingWrapper) {
            // Find the main container after header
            const headerEl = document.querySelector('header');
            if (headerEl && headerEl.nextElementSibling) {
                const wrapper = document.createElement('div');
                wrapper.id = 'mainContent';
                headerEl.parentNode.insertBefore(wrapper, headerEl.nextElementSibling);
                
                // Move all content after header into the wrapper
                while (wrapper.nextElementSibling) {
                    wrapper.appendChild(wrapper.nextElementSibling);
                }
                console.log('Main content wrapped');
            }
        }
    }

    // Initialize minimize functionality
    function initMinimize() {
        const minimizeBtn = document.getElementById('continueBtn');
        const mainContent = document.getElementById('mainContent');
        const minimizedBar = document.getElementById('minimizedBar');

        console.log('Elements found:', {
            minimizeBtn: !!minimizeBtn,
            mainContent: !!mainContent,
            minimizedBar: !!minimizedBar
        });

        if (minimizeBtn && mainContent && minimizedBar) {
            // Add click handler to minimize button
            minimizeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Minimize clicked');
                mainContent.style.display = 'none';
                minimizedBar.style.display = 'flex';
            });

            // Add click handler to minimized bar for restore
            minimizedBar.addEventListener('click', function() {
                console.log('Restore clicked');
                mainContent.style.display = 'block';
                minimizedBar.style.display = 'none';
            });
        }
    }

    // Run initialization
    wrapMainContent();
    initMinimize();
});