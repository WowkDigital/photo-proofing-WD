# photo-proofing-WD 📸

A professional, lightweight photo proofing system designed for photographers by WowkDigital to share sessions with clients and collect their selections efficiently.

## ✨ Key Features

- **End-to-End Security**: Features full AES-GCM client-side encryption for secure photo sharing. Decryption happens on the client side using a secure key.
- **Lightweight Architecture**: No heavy frameworks or complex database servers. Runs on PHP 7.4+ and SQLite.
- **Responsive Design**: Modern, mobile-first UI built with Tailwind CSS and Lucide Icons.
- **Client Features**: 
  - Switchable grid layouts (2 to 6 columns).
  - High-performance Lightbox with touch/swipe support.
  - Easy selection system with a single click.
  - Integrated order form with social media contact options.
- **Admin Panel**: Effortless bulk photo uploads and selection management.

## 🛠️ Tech Stack

- **Backend**: PHP 7.4+ (Vanilla)
- **Database**: SQLite 3
- **Frontend**: HTML5, Vanilla JavaScript, Tailwind CSS (CDN)
- **Libraries**: Lucide Icons, Canvas-Confetti

## 🚀 Quick Setup

1. **Deploy**: Upload files to your web server.
2. **Permissions**: Ensure `/data`, `/photos`, and `/selection_logs` are writable by the web server.
3. **Database**: Run `api/init_db.php` in your browser to initialize the SQLite structure.
4. **Configure**: Rename `api/config.php.example` to `api/config.php` and set your admin password and gallery titles.
5. **Go Live**: Log in to `/admin` and start uploading your first session!

---
*Created by [WowkDigital](https://github.com/WowkDigital)*
