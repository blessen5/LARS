# LARS — Lab Activity Reporting System

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Electron](https://img.shields.io/badge/Electron-25.0-47848F?style=for-the-badge&logo=electron&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![Use Case](https://img.shields.io/badge/Institutions-Schools%20%7C%20Colleges%20%7C%20Universities-orange?style=for-the-badge)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

**LARS (Lab Activity Reporting System)** is an all-in-one laboratory management, session logging, and reporting platform tailored for **schools, colleges, universities, and educational institutions**. 

It streamlines day-to-day computer laboratory operations by connecting **Students**, **Teachers / Lab Instructors**, and **Administrators** into a unified, real-time ecosystem across web and desktop environments.

---

## 📖 About the Project

In traditional educational computer labs, managing student attendance, tracking system utilization, recording practical tasks, and resolving hardware issues often rely on manual paper logbooks, verbal complaints, and fragmented records. This leads to untracked lab hours, unaccounted hardware downtime, and significant administrative overhead for teachers.

**LARS (Lab Activity Reporting System)** was engineered to transform computer laboratories into smart, paperless, and fully automated learning spaces.

### 🎯 Primary Objectives
- **Automated Accountability:** Accurately record which student used which computer, for what subject, and for how long.
- **Paperless Workflow:** Eliminate physical lab register books with digital session logs, instant activity submission, and automated PDF attendance generation.
- **Proactive Maintenance:** Allow students to report broken mice, malfunctioning keyboards, or OS crashes right from their desktop, enabling technicians to fix issues swiftly.
- **Empowered Teaching:** Free up faculty from roll-calling and manual verification so they can focus on mentoring students during practicals.

### 🏢 Who is LARS For?
- **Schools (K-12 & Higher Secondary):** For ICT and computer science lab session scheduling and student attendance.
- **Colleges & Universities:** For Engineering, BCA/MCA, B.Sc Computer Science, and Data Science departments managing multi-lab infrastructures.
- **Polytechnics & Vocational Institutes:** For programming, web development, and CAD lab resource management.
- **Computer Training Centers & Bootcamps:** For student lab hour tracking and system access monitoring.

---

##  Key Institutional Benefits

-  **For Students:** Seamless sign-in, session timer tracking, lab exercise/task logging, and instant computer issue reporting.
-  **For Teachers & Faculty:** Effortless digital attendance marking, live monitoring of student activities during practical hours, subject/curriculum management, and batch-wise performance reviews.
-  **For Lab Administrators & Technicians:** Hardware inventory tracking, live workstation occupancy maps, rapid issue resolution workflows, institution branding, and exportable PDF/Excel compliance reports.

---



## ✨ Key Features by Role

### 👨‍🎓 1. Student Portal
- **Quick & Secure Authentication:** Students log in using their unique Institutional Roll/Admission Number and password.
- **Subject & Lab Session Selection:** Choose the practical subject or course session upon logging in.
- **Workstation Usage Timer:** Real-time session duration counter with pause/resume capabilities.
- **Activity & Practical Task Submission:** Submit details of experiments performed, programs written, and software tools utilized during the lab period.
- **Computer Hardware/Software Issue Reporting:** Report faulty peripherals, OS errors, or hardware issues on the current workstation directly to lab staff.
- **Floating Desktop Timer Bar:** Minimalist, unobtrusive floating widget that keeps the timer visible without interfering with coding IDEs or lab tools.
- **Resolution Alerts & Notifications:** Receive instant notifications when reported issues are marked as resolved by the lab assistant.

---

### 👨‍🏫 2. Teacher & Faculty Portal
- **Batch & Class Management:** Filter and view students by academic year, department, semester, and batch.
- **Digital Lab Attendance:**
  - Mark daily student attendance per subject and lab period with a single click.
  - Review historical attendance logs with date and session filters.
  - Download official attendance sheets in PDF and Excel/CSV formats.
- **Student Practical Activity Inspection:** Track what each student is working on in real time to verify lab progress.
- **Subject & Lab Curriculum Management:** Add, edit, or configure practical course codes and subjects.
- **Student Registration Approvals:** Review, verify, and approve new student account requests before granting lab access.

---

### 🛡️ 3. Admin & Lab In-Charge Control Center
- **Institutional User Management:** Complete role-based access control (Admin, Teacher/Staff, Student) with create, edit, deactivate, and password reset privileges.
- **Lab Hardware & System Inventory:**
  - Register and catalog all lab computers with unique IDs (`SYS-XXXX`) and lab names.
  - View real-time occupancy (which computer is in use, by which student, and from which IP).
- **System Issue Tracking & Maintenance Queue:**
  - Interactive dashboard showing active student-reported system defects.
  - One-click "Fixed" resolution action with automated feedback toasts and student notifications.
- **Automated Reporting & Analytics Engine:**
  - Institutional usage trends, peak computer usage hours, and session analytics.
  - Generate and export branded PDF reports (powered by TCPDF) and audit logs.
- **Institution Customization:** Update the school/college name and upload official institutional logos to customize all screens and headers.

---

## 🛠️ Tech Stack

| Component | Technology / Library |
|---|---|
| **Frontend UI** | HTML5, CSS3 (Light/Dark themes), JavaScript (ES6+), Bootstrap 5, Bootstrap Icons, AJAX |
| **Backend Core** | PHP 8.x (High-performance procedural API endpoints) |
| **Database** | MySQL 8.0 / MariaDB |
| **Desktop Client** | Electron.js (v25.x), Node.js |
| **Document Generation** | TCPDF (High-fidelity PDF reports for attendance & trends) |
| **Helper Tools** | Python 3.x / Flask (PC Configuration & Hardware Advisor) |
| **Deployment Scripts** | Windows Batch scripts for automated LAN & Portable WAMP launch |

---

## 🚀 Installation & Setup

### 1. Prerequisites
- **Web Server:** [WampServer](https://www.wampserver.com/) (recommended) or [XAMPP](https://www.apachefriends.org/) with PHP 8.0+ and MySQL 5.7+ / 8.0+.
- **Node.js & npm:** [Node.js LTS](https://nodejs.org/) (needed only if building/running the Electron desktop client).
- **Python (Optional):** Python 3.9+ (only needed if running the standalone PC recommendation tool `app.py`).

---

### 2. Web Application Setup (WAMP / XAMPP)

1. Place the project files in your web server's public directory:
   - **WampServer:** `C:\wamp64\www\lab_activity`
   - **XAMPP:** `C:\xampp\htdocs\lab_activity`
2. Start **Apache** and **MySQL** from your WAMP/XAMPP control panel.
3. Open your browser and go to:
   ```
   http://localhost/lab_activity/login.php
   ```

---

### 3. Database Setup

1. Open **phpMyAdmin** (`http://localhost/phpmyadmin`).
2. Create a database named `LARS`:
   ```sql
   CREATE DATABASE LARS CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import the SQL table definitions from the project root:
   - `SYSTEMS_TABLE_SQL.sql` — Systems inventory table
   - `SYSTEM_USAGE_TABLE_SQL.sql` — Live workstation usage table
   - `issues_table_fix.sql` — System issue reporting & notification tables
4. Ensure your database connection settings in [`login.php`](file:///c:/wamp64/www/lab_activity/login.php) match your server:
   ```php
   $db_host = 'localhost';
   $db_user = 'root';
   $db_pass = ''; // Default in WAMP is empty
   $db_name = 'LARS';
   ```

---

### 4. Desktop Client Setup (Electron)

If you wish to run the app as a dedicated desktop client on student workstations:

```bash
# Navigate to the electron directory
cd electron

# Install dependencies
npm install

# Start the desktop application
npm start
```

#### Build Windows Installer / Executable:
```bash
# Generate standalone portable Windows build
npm run package-win

# Generate full Windows NSIS installer
npm run build
```

---

## 🌐 Multi-Computer Lab LAN Deployment

To deploy LARS across a computer laboratory where multiple student workstations connect to one teacher/server PC:

### On the Server / Teacher Computer:
1. Ensure WAMP/Apache is running.
2. Find the server's local IP address:
   ```cmd
   ipconfig
   ```
   *(e.g., `192.168.1.100`)*

### On Student Client Workstations:
1. Run [`test_setup.bat`](file:///c:/wamp64/www/lab_activity/test_setup.bat).
2. Select option **2 (Set up Client Computer)** and enter the Server IP (`192.168.1.100`).
3. This automatically configures `electron/server-config.json`.
4. Launch the desktop app or open `http://192.168.1.100/lab_activity/login.php` in the browser.

---

## 💾 Portable USB / Pendrive Deployment

LARS includes plug-and-play batch utilities allowing an instructor or technician to run the entire stack straight from a USB flash drive:

- **[`start_electron_wamp.bat`](file:///c:/wamp64/www/lab_activity/start_electron_wamp.bat):** Starts portable Apache/MySQL, boots the Electron desktop app, and stops background services upon window close.
- **[`start_portable.bat`](file:///c:/wamp64/www/lab_activity/start_portable.bat):** Quick-launches portable web and database servers.
- **[`stop_portable.bat`](file:///c:/wamp64/www/lab_activity/stop_portable.bat):** Safely kills running portable web services before drive removal.

---

## 📂 Directory Structure

```text
lab_activity/
├── assets/                     # CSS stylesheets, custom JS, institution logos & icons
├── electron/                   # Desktop client codebase & build configurations
│   ├── main.js                 # Electron main process & IPC handlers
│   ├── preload.js              # Secure preload bridge
│   ├── package.json            # Node.js dependencies and build scripts
│   └── server-config.json      # Dynamic server IP configuration for lab network
├── includes/                   # Common templates & floating widget components
│   ├── header.php              # Shared navigation header & institution branding
│   ├── footer.php              # Global footer
│   ├── minimize-bar.php        # Non-intrusive floating student timer UI
│   └── minimize-core.php       # Floating widget logic and styles
├── logs/                       # System & error logs
├── TCPDF-main/                 # TCPDF library for automated attendance & trend reports
├── templates/                  # Flask HTML templates (for PC recommender)
├── activities.php              # Student lab activity logging page
├── admin_dashboard.php         # Admin master control panel & hardware tracker
├── staff_dashboard.php         # Teacher & faculty portal (attendance, batches, logs)
├── dashboard.php               # Student dashboard & session timer hub
├── login.php                   # Unified institutional login gateway
├── register.php                # Student account self-registration
├── welcome.php                 # Post-login subject / practical selection
├── manage_systems.php          # Lab computer inventory manager
├── manage_subjects.php         # Academic subject & course manager
├── ajax_*.php                  # Asynchronous endpoints (attendance, timers, issues, filters)
├── export_*.php                # Report export handlers (CSV / Excel / PDF)
├── generate_trend_*.php        # Trend analysis & PDF generation scripts
├── start_electron_wamp.bat     # Portable all-in-one launcher
├── test_setup.bat              # LAN interactive setup script
└── README.md                   # Project documentation
```

---

## 🗄️ Database Schema Overview

| Table | Purpose |
|---|---|
| **`users`** | User profiles, passwords (hashed), admission/roll numbers, roles (`admin`, `staff`, `student`), department, and approval status |
| **`subjects`** | Academic courses, practical subjects, and lab syllabi |
| **`systems`** | Catalog of lab computers (`system_id`, `system_name`, login & usage stats) |
| **`system_usage`** | Real-time heartbeat tracker recording current active users, workstation IDs, IPs, and timestamps |
| **`issues`** | Hardware & software trouble tickets submitted by students with status (`pending`, `fixed`) and resolution timestamps |
| **`attendance`** | Date and session-specific attendance records marked by teachers |
| **`user_sessions` / `activity_logs`** | Granular logs of student work, practical experiments, and software utilized |
| **`settings`** | Institution configuration (school/college name, official logo file path) |

---

## ❓ Troubleshooting & FAQs

#### 1. "Database connection failed" error
- Ensure MySQL is running in WAMP or XAMPP (green tray icon in WAMP).
- Verify the database `LARS` exists in phpMyAdmin.
- Check `$db_host`, `$db_user`, and `$db_pass` in [`login.php`](file:///c:/wamp64/www/lab_activity/login.php).

#### 2. Student client PCs cannot connect to the teacher server
- Confirm both computers are connected to the same Local Area Network (LAN / Wi-Fi).
- Verify Windows Firewall on the server machine is not blocking Apache (Port 80).
- Run [`test_setup.bat`](file:///c:/wamp64/www/lab_activity/test_setup.bat) on the client PC to verify connection.

#### 3. "Fixed" button on Admin Dashboard does not update
- Run [`issues_table_fix.sql`](file:///c:/wamp64/www/lab_activity/issues_table_fix.sql) in phpMyAdmin to verify the `issues` table has the required `fixed_at` column.
- See [`FIXED_BUTTON_IMPROVEMENTS.md`](file:///c:/wamp64/www/lab_activity/FIXED_BUTTON_IMPROVEMENTS.md) for full notes.

---

## 📄 License

This project is open-source and released under the **MIT License**. You are welcome to customize and deploy it across your school, college, university, or educational organization.
