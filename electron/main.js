// main.js - Electron main process
const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');

let win = null;

function createWindow() {
  win = new BrowserWindow({
    width: 1200,
    height: 800,
    minWidth: 800,
    minHeight: 600,
    title: 'LARS — Lab Activity Reporting System',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      enableRemoteModule: false
    }
  });

  // Load configuration
  let serverIP = 'localhost';
  
  try {
    const configPath = path.join(__dirname, 'server-config.json');
    if (fs.existsSync(configPath)) {
      const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
      if (config && config.serverIP) {
        serverIP = config.serverIP.trim();
      }
    }
  } catch (error) {
    console.error('Error reading server config, falling back to localhost:', error);
  }

  const SERVER_URL = `http://${serverIP}/lab_activity/login.php`;
  console.log('Connecting to:', SERVER_URL);
  win.loadURL(SERVER_URL);
  
  // Handle offline / connection failure scenarios
  win.webContents.on('did-fail-load', (event, errorCode, errorDescription) => {
    console.warn(`Page load failed (${errorCode}): ${errorDescription}`);
    const offlinePath = path.join(__dirname, 'offline.html');
    if (fs.existsSync(offlinePath)) {
      win.loadFile(offlinePath);
    }
  });

  win.on('closed', () => {
    win = null;
  });
}

// IPC handlers for window controls
ipcMain.on('minimize-window', () => {
  if (win && !win.isDestroyed()) {
    win.minimize();
  }
});

ipcMain.on('toggle-maximize', () => {
  if (win && !win.isDestroyed()) {
    if (win.isMaximized()) {
      win.unmaximize();
    } else {
      win.maximize();
    }
  }
});

ipcMain.on('close-window', () => {
  if (win && !win.isDestroyed()) {
    win.close();
  }
});

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
  app.quit();
});

app.on('activate', () => {
  if (win === null) createWindow();
});

