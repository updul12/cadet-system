# TaTU Air Force Cadet Management System

A PHP/MySQL portal for managing air force cadets: roster, enlistment & bulk
import (Excel/CSV/Google Forms), attendance, fitness, promotions, discipline,
inventory, duties, news and RBAC user management.

No framework, no Composer, no npm — plain PHP with a small hand-rolled core
layer and Bootstrap 5 served from `assets/vendor`.

---

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `libxml`, `simplexml`, `xmlreader`, `dom`, `zlib`, `session` |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Server | Apache with `.htaccess` (`mod_rewrite` not required) or nginx with equivalent rules |

> The `zip` extension is **optional** — `.xlsx` import/export works without it
> (a pure-PHP ZIP reader/writer is included in `core/zipfile.php`).

---

## Project layout

```
cadet-system/
├── index.php, join.php, news.php, ...   public website
├── cadet_db.sql                         full schema + seed data
├── .env.example                         environment template
├── config/                              DB connection + constants
├── core/                                bootstrap, auth, RBAC, security, helpers,
│                                        spreadsheet (CSV/XLSX), zipfile
├── layouts/                             page shells (public / portal / login)
├── includes/                            shims that load the real files
├── portal/                              authenticated application
│   ├── cadets/      list, create, view, import
│   ├── operations/  attendance, fitness, promotions, discipline, duties
│   ├── logistics/   inventory
│   └── admin/       users, news, inquiries, applications, backup, audit, settings
├── error/                               403 / 404 / 500 pages
├── assets/                              css, js, vendored Bootstrap
└── storage/                             photos & logs (not in git)
```

---

## Local setup (XAMPP)

1. Clone into `htdocs` (keep the folder name, or change it — paths are derived
   automatically):

   ```bash
   cd /c/xampp/htdocs
   git clone https://github.com/<you>/<repo>.git cadet-system
   ```

2. Create the database and load the schema:

   ```bash
   mysql -uroot -e "CREATE DATABASE cadet_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -uroot cadet_db < cadet_db.sql
   ```

3. Create your environment file:

   ```bash
   cp .env.example .env
   ```

   then edit `.env`:

   ```
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=cadet_db
   DB_USER=root
   DB_PASS=
   APP_DEBUG=false
   ```

4. Make storage writable:

   ```bash
   chmod -R 775 storage
   ```

5. Open `http://localhost/cadet-system/` (portal login at `/portal/login.php`).

### Signing in for the first time

`cadet_db.sql` seeds an `admin` account with a placeholder password hash.
Set your own password:

```bash
php -r "echo password_hash('YourNewPass1!', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
UPDATE users
   SET password_hash = '<paste the hash here>'
 WHERE username = 'admin';
```

---

## Deploying (shared hosting / VPS)

GitHub Pages cannot run PHP — deploy to any PHP host (cPanel shared hosting,
DigitalOcean/AWS VPS, RunCloud, etc.).

1. **Upload** the repository contents to the web root (e.g. `public_html/`),
   or `git clone` it on the server.
2. **Create a MySQL database** in your host's control panel and import
   `cadet_db.sql` (phpMyAdmin → Import, or `mysql ... < cadet_db.sql`).
3. **Create `.env`** on the server with the database credentials your host
   gave you. Never commit this file.
4. **Permissions**: `storage/` must be writable by the web server
   (`chmod -R 775 storage`) — photos and logs are written there.
5. **Apache**: `.htaccess` files are included; make sure
   `AllowOverride All` is enabled for the site directory.
   **nginx**: deny direct access to `includes/`, `core/`, `config/`,
   `layouts/` and `storage/` (they already block PHP execution via `.htaccess`).
6. Set the admin password as described above, then sign in and check
   **Admin → System settings** (system name, logo, WhatsApp number).
7. Force HTTPS in your host panel or with a Let's Encrypt certificate.

### After a fresh deploy

* Uploaded files (`storage/photos/*`) and logs are **not** in git —
  re-upload the site logo under **Admin → System settings**, and profile
  photos under each cadet's dossier.
* The database backup tool lives at **Admin → Database backup**.

---

## Bulk cadet import

**Cadets → Import (Excel / CSV)** accepts:

* `.xlsx` (Excel, or a Google Sheet downloaded as Excel)
* `.csv` (Excel "Save as CSV", or Google Sheet → File → Download → CSV)

Google Forms: link the form to a Google Sheet, then download that sheet and
upload it here. Blank `service_no` cells are auto-assigned `TaTUAFCC####`;
rows with problems are skipped and listed in the import report.
Both import templates are downloadable from that page.

---

## Environment variables

| Key | Default | Purpose |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `cadet_db` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | *(empty)* | Database password |
| `APP_DEBUG` | `false` | Verbose errors — keep `false` in production |

---

## License / credits

Internal project of the TaTU Air Force Cadet unit. Bootstrap 5 and Bootstrap
Icons are vendored under `assets/vendor` (MIT).
