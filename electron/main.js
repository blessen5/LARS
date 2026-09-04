// main.js - Electron main process
// Creates a normal BrowserWindow and listens for IPC 'minimize-window' to minimize it.
const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');

let win = null;

function createWindow() {
  win = new BrowserWindow({
    width: 1200,
    height: 800,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  // Load configuration
  const fs = require('fs');
  let serverIP = 'localhost'; // default value
  
  // Try to read server IP from config file
  try {
    const configPath = path.join(__dirname, 'server-config.json');
    if (fs.existsSync(configPath)) {
      const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
      serverIP = config.serverIP;
    }
  } catch (error) {
    console.log('Using default server: localhost');
  }

  const SERVER_URL = `http://${serverIP}/lab_activity/login.php`;
  console.log('Connecting to:', SERVER_URL);
  win.loadURL(SERVER_URL);
  
  // Handle offline scenarios
  win.webContents.on('did-fail-load', () => {
    win.loadFile(path.join(__dirname, 'offline.html'));
  });

  // Uncomment to open DevTools during development
  // win.webContents.openDevTools();

  win.on('closed', () => {
    win = null;
  });
}

// Listen for IPC from renderer to minimize the window
ipcMain.on('minimize-window', (event) => {
  if (win && !win.isDestroyed()) {
    win.minimize();
  }
});

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
  // Quit app on all platforms when windows closed
  app.quit();
});

app.on('activate', () => {
  if (win === null) createWindow();
});
