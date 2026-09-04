// preload.js - runs in isolated context before the page loads
// Exposes a minimal API to the renderer via contextBridge
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  // Minimizes the native window by sending an IPC message to the main process
  minimize: () => ipcRenderer.send('minimize-window')
});
