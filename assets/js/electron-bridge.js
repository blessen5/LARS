// electron-bridge.js
// Frontend bridge to call Electron minimize API if running inside the Electron wrapper.
// This defines the global function minimizeWindow() which dashboards already call.

/*
  Usage:
  - Include this script on dashboard pages (before </body>):
      <script src="assets/js/electron-bridge.js"></script>
  - The existing Continue buttons that call minimizeWindow() will work both
    in the browser and inside Electron.
*/

function minimizeWindow() {
  try {
    if (window.electronAPI && typeof window.electronAPI.minimize === 'function') {
      // Running inside Electron - call the exposed API
      window.electronAPI.minimize();
    } else {
      // Not running inside Electron - fallback behavior: attempt to blur or show a message
      // We keep this non-intrusive so normal web usage is not broken.
      console.info('minimizeWindow: electronAPI not available; running in browser.');
    }
  } catch (e) {
    console.error('minimizeWindow error', e);
  }
}
