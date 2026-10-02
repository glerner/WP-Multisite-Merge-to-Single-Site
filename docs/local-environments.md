# Local Development Environments Setup Guide

This guide covers setting up your **Destination WordPress** installation using the three common local development tools: **Lando**, **Local by Flywheel**, and **WordPress Studio**.

---

## 1. Lando

### Architecture: Does having `database: 'wordpress'` collide?
By default, Lando recipes set credentials to `database: wordpress`, `user: wordpress`, `password: wordpress`.

- **Separate Lando Projects (Recommended)**:
  If your source multisite is in `~/sites/source-multisite/` and your destination is in `~/sites/destination-wp/`, each folder has its own `.lando.yml` and runs in **its own isolated Docker container**.
  - Lando maps each container's MySQL port to a different random host port on `127.0.0.1` (e.g. 32789 vs 32805).
  - Both databases can safely use `database: 'wordpress'` because they live on completely different MySQL servers!
  - `merge-multisite`'s `LandoEndpointResolver` automatically finds the right port by walking up from each `uploads_path` to its `.lando.yml` and querying `lando info`.

### Customizing the Database Name in Lando
In Lando's WordPress recipe, `config.database` defines the database **type and version** (e.g. `database: mysql:8.0`), while the default database name created inside the container is always `wordpress`.

If you want a custom database name (such as `destination_wp`):

1. **Option A (Create as MySQL root and grant permissions)**:
   In MySQL, the default user `'wordpress'` only has permissions on the `'wordpress'` database and cannot create new databases. Run this as MySQL root to create the database and grant access:
   ```bash
   lando mysql -u root -e "CREATE DATABASE IF NOT EXISTS destination_wp; GRANT ALL PRIVILEGES ON destination_wp.* TO 'wordpress'@'%'; FLUSH PRIVILEGES;"
   ```
   Then in `wp-config.php`, set `define( 'DB_NAME', 'destination_wp' );`.

2. **Option B (Use the default 'wordpress' database — recommended)**:
   Because this Lando project is in its own isolated container, using the pre-created `wordpress` database does not conflict with any other project. In `wp-config.php`, simply keep:
   ```php
   define( 'DB_NAME', 'wordpress' );
   ```
   and skip `lando wp db create` entirely.

### Creating the Site via Lando WP-CLI

Step-by-step setup in `~/sites/destination-wp/`:

```bash
# 1. Create project folder and copy an existing .lando.yml file to destination folder
mkdir ~/sites/destination-wp
cd ~/sites/destination-wp
cp ../wordpress/.lando.yml .

# Edit .lando.yml:
# - Set name: destination
# - Update all *.lndo.site entries throughout the file to destination.lndo.site
# - Update ServerName: destination.lndo.site
lando rebuild -y

# 2. Download WordPress core
# Note: do NOT pass an absolute host path like --path=/home/... into lando wp;
# inside the Docker container, host paths trigger permission errors. Running directly
# inside the destination directory defaults to the current directory cleanly:
lando wp core download --force

# 3. Create wp-config.php and generate unique salts
lando wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=database
lando wp config shuffle-salts

# 4. Install WordPress as a single site
lando wp core install \
  --url="https://destination.lndo.site" \
  --title="Merged Destination Site" \
  --admin_user="yourSecureAdminUser" \
  --admin_password="yourSecurePassword" \
  --admin_email="yourAdminEmail"
```

---

## 2. Local by Flywheel

### Architecture & Connection
Local by Flywheel runs sites locally using either Unix domain sockets (macOS/Linux) or localhost ports.

- **Automatic Socket Resolution**:
  In `config/config.php`, you do not need to hardcode changing ports. Use the built-in `'local'` driver:
  ```php
  'destination' => array(
      'connection'   => array(
          'driver' => 'local',
          'site'   => 'destination-wp', // Name of the site in Local
      ),
      'database'     => 'local',
      'username'     => 'root',
      'password'     => 'root',
      'table_prefix' => 'wp_',
      'uploads_path' => '~/Local Sites/destination-wp/app/public/wp-content/uploads',
  ),
  ```
- `LocalEndpointResolver` automatically reads Local's `sites.json` and connects via the site's MySQL socket while Local is running.

---

## 3. WordPress Studio (Automattic)

### Architecture & Notes
WordPress Studio is Automattic's lightweight local development environment.

- **Storage Engine**:
  WordPress Studio uses **SQLite** (via the SQLite Database Integration plugin) by default rather than MySQL.
- **Connecting with `merge-multisite`**:
  Because `merge-multisite` connects via PDO MySQL, if using WordPress Studio:
  - You can use WordPress Studio to design and preview block themes.
  - For the live database migration target, a MySQL-based local environment (Lando or Local by Flywheel) is recommended for full MySQL table schema compatibility (`InnoDB`, table prefixes, and keyset pagination).

---

## Post-Setup Step: Harden Admin ID

Once your destination site is installed in any local environment, run:
```bash
php bin/harden-admin-id.php
```
This renumbers the initial administrator user from ID 1 to a random ID to prevent collisions with migrated authors, and prints the `'admin_user_id'` to paste into `config/config.php`.
