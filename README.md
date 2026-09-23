# photo-proofing-WD 📸

A professional, lightweight photo proofing system designed for photographers by WowkDigital to share sessions with clients and collect their selections efficiently.

## ✨ Key Features

- **End-to-End Security**: Features AES-GCM client-side encryption for secure photo sharing. Decryption happens purely on the client side using a secure key.
- **Lightweight Architecture**: No heavy frameworks or external database servers required. Runs on PHP 7.4+ and SQLite 3 with WAL mode and foreign key integrity.
- **Diagnostics & Security Center**: Dedicated administrative diagnostic dashboard (`/admin/diagnostics.php`) with real-time permissions check, SQLite integrity verification, Telegram notification tests, and installer cleanup auditing.
- **Automated Installer & Self-Destruct**: Convenient initial setup wizard (`install.php`) with optional automatic self-deletion upon completion for maximum instance security.
- **Production Update Script**: Automated backup and zero-downtime deployment script in `scripts/update_production.sh`.
- **Client Features**: 
  - Switchable responsive grid layouts (2 to 6 columns).
  - High-performance Lightbox with touch/swipe gesture support.
  - Easy selection system with a single click.
  - Integrated order form with social media contact options and instant Telegram bot alerts.
- **Admin Panel**: Multi-album management, key vault protection, bulk photo uploads, and selection history exports.

## 🛠️ Tech Stack

- **Backend**: PHP 7.4+ (Vanilla, PDO SQLite, GD, cURL)
- **Database**: SQLite 3
- **Frontend**: HTML5, Modern Vanilla JavaScript, Tailwind CSS (CDN)
- **Libraries**: Lucide Icons, Canvas-Confetti

## 🚀 Quick Setup

1. **Clone & Upload**: Clone the repository or upload the files to your PHP server (PHP 7.4+).
2. **Permissions**: Ensure `/data`, `/photos`, and `/selection_logs` folders are writable by the web server.
3. **Run Installer**: Open `yourdomain.com/install.php` in your browser.
4. **Configure**: Follow the easy steps to set your title, admin password, and contact links. Keep the *"Automated self-destruct"* checkbox selected to remove `install.php` automatically after setup.
5. **Go Live**: Log in to `/admin` and start uploading your first session!

---
*Created by [WowkDigital](https://github.com/WowkDigital)*
