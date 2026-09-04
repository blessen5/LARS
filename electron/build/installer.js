const createWindowsInstaller = require('electron-winstaller').createWindowsInstaller;
const path = require('path');

getInstallerConfig()
  .then(createWindowsInstaller)
  .catch((error) => {
    console.error(error.message || error);
    process.exit(1);
  });

function getInstallerConfig() {
  console.log('Creating Windows Installer...');
  const rootPath = path.join('./');
  const outPath = path.join(rootPath, 'release-builds');

  return Promise.resolve({
    appDirectory: path.join(outPath, 'Lab-Activity-System-win32-x64'),
    authors: 'Your Organization',
    noMsi: true,
    outputDirectory: path.join(outPath, 'windows-installer'),
    exe: 'LabActivitySystem.exe',
    setupExe: 'LabActivitySystemSetup.exe',
    setupIcon: path.join(rootPath, 'assets', 'icons', 'win', 'icon.ico'),
    loadingGif: path.join(rootPath, 'assets', 'icons', 'win', 'installing.gif')
  });
}