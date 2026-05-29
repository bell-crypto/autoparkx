# AutoParkX

ระบบจองที่จอดรถ / จัดการผู้ใช้ / Wallet / แจ้งปัญหา / แจ้งเตือนผ่าน LINE สำหรับงานเว็บ PHP + MySQL

## Tech Stack

- Frontend: HTML, CSS, JavaScript
- Backend: PHP
- Database: MySQL
- Notification: LINE Messaging API / OneSignal

## Project Structure

```txt
.
├── api/                 # PHP API endpoints
├── icons/               # PWA icons
├── *.html               # Frontend pages
├── manifest.json        # PWA manifest
├── .env.example         # Environment example
├── .gitignore           # Git ignore rules
└── README.md
```

## Setup

1. Clone this repository.
2. Copy database config example:

```bash
cp api/db.example.php api/db.php
```

3. Edit `api/db.php` and add your real database credentials.
4. Import your MySQL database manually in phpMyAdmin or your hosting control panel.
5. Upload the project files to `public_html` on your hosting.

## Security Notes

This GitHub-ready package intentionally excludes:

- real database config: `db.php`, `api/db.php`
- logs: `*.log`, `logs/`
- server stats: `stats/`
- user uploaded images: `uploads/`, `public/uploads/`
- zip/backups: `*.zip`, `*.tar.gz`, `*.sql`

Do not commit real tokens, database passwords, user images, or SQL backups to GitHub.
