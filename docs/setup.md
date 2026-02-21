# Cozy News Corner — Setup Guide

## Prerequisites

Before installing, ensure the following are present on your server.

### Operating System

- **Debian Trixie (13)** or later

### PHP

- **PHP 8.4** or later
- Required extensions: `pdo`, `pdo_sqlite`, `curl`, `dom`, `json`, `libxml`

Check that all extensions are available:

```bash
php -m | grep -E 'pdo|pdo_sqlite|curl|dom|json|libxml'
```

Install any missing extensions (example):

```bash
sudo apt install php8.4-sqlite3 php8.4-curl php8.4-xml
```

### Apache

- **Apache 2.4** with modules `mod_rewrite` and `mod_headers` enabled

Enable the required modules:

```bash
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

---

## Apache VirtualHost Configuration

Create a new site configuration file (e.g. `/etc/apache2/sites-available/shownotes.conf`):

```apache
<VirtualHost *:80>
    ServerName shownotes.local
    DocumentRoot /srv/show-notes-generator/www

    <Directory /srv/show-notes-generator/www>
        AllowOverride All
        Require all granted
    </Directory>

    # Deny direct HTTP access to directories outside the web root
    <Directory /srv/show-notes-generator/include>
        Require all denied
    </Directory>
    <Directory /srv/show-notes-generator/etc>
        Require all denied
    </Directory>
    <Directory /srv/show-notes-generator/var>
        Require all denied
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/shownotes-error.log
    CustomLog ${APACHE_LOG_DIR}/shownotes-access.log combined
</VirtualHost>
```

Adjust `ServerName` and the paths to match your installation directory.

---

## File Permissions

The web server user (`www-data` on Debian) must be able to write to the `var/` directory so that SQLite can create and update `shownotes.sqlite`:

```bash
sudo chown -R www-data:www-data /srv/show-notes-generator
sudo chmod -R 755 /srv/show-notes-generator
sudo chmod 775 /srv/show-notes-generator/var
```

After the first request, you can tighten the database file itself:

```bash
sudo chmod 664 /srv/show-notes-generator/var/shownotes.sqlite
```

---

## Installation Sequence

1. **Clone or copy the project files** to your server:

   ```bash
   git clone <repo-url> /srv/show-notes-generator
   # — or —
   cp -r show-notes-generator/ /srv/show-notes-generator/
   ```

2. **Create the Apache site configuration** as shown in the VirtualHost section above.

3. **Enable the site** and reload Apache:

   ```bash
   sudo a2ensite shownotes
   sudo systemctl reload apache2
   ```

4. **Set file permissions** as shown in the File Permissions section above.

5. **Add the hostname to your local DNS or `/etc/hosts`** (if using `shownotes.local`):

   ```
   192.168.x.x  shownotes.local
   ```

6. **Navigate to the app** in your browser: `http://shownotes.local`

   The database file is created automatically on the first request — no manual database setup is required.

---

## First-Run Verification

After following the installation sequence, verify the stack is working:

1. **HTTP 200 response** — Open `http://shownotes.local` in your browser. The page should load without error.

2. **Database created** — Confirm the SQLite file exists on the server:

   ```bash
   ls -lh /srv/show-notes-generator/var/shownotes.sqlite
   ```

3. **INITIAL_STATE present** — View the page source (`Ctrl+U` in most browsers) and search for `INITIAL_STATE`. You should see a line like:

   ```js
   const INITIAL_STATE = {"episode":{...},"items":{...},"config":{...}};
   ```

4. **No PHP errors** — Check the Apache error log for any PHP warnings or fatal errors:

   ```bash
   sudo tail -20 /var/log/apache2/shownotes-error.log
   ```

   The log should be empty (or contain only informational entries) after a clean page load.

---

## Configuration

Static values (show title, tagline, section names, scrape settings) are in `etc/config.php`. Edit that file to customise the application — no other file needs to change.

| Key | Default | Description |
|-----|---------|-------------|
| `show_title` | `Cozy News Corner` | Used in the Markdown heading and page `<title>` |
| `show_tagline` | `Your source for Open Source news` | Appended to the Markdown heading |
| `sections.vulnerability` | `Vulnerability` | Display name for the vulnerability section |
| `sections.news` | `News` | Display name for the news section |
| `db_path` | `var/shownotes.sqlite` | Path to the SQLite database file |
| `scrape_timeout` | `5` | cURL timeout in seconds for URL metadata fetching |
| `scrape_useragent` | `Mozilla/5.0 … Chrome/120 …` | HTTP User-Agent sent when fetching article URLs |
| `max_redirects` | `5` | Maximum HTTP redirects to follow during scraping |
