# ❞ Gscheid

**Gscheid** is a lightweight and selfhostable tool to store quotes in a database with API to retreive random quotes.

## ✨ Features

* **Smart quote management:** Easily add quotes along with their author and date. Thanks to a relational database architecture (many-to-many), multiple categories can be effortlessly assigned to a single quote (simply separated by commas).
* **Live autocomplete (AJAX):** Intelligent, real-time server-side search suggestions for authors and categories as you type. Built entirely with Vanilla JavaScript (Fetch API) and HTML5 `<datalist>`—no heavy external libraries like jQuery required.
* **Automatic record creation:** The system automatically detects whether an author or category already exists. If not, they are seamlessly created in the background.
* **Secure JSON API:** A built-in endpoint (`?random_quote`) to fetch random quotes for external frontends or sites—securely protected by a hashed API key.
* **Zero-Config Database:** Uses a lightweight, file-based SQLite3 database. No complex MySQL setup is necessary; the system automatically creates all required tables on its very first run.
* **High Security Standards:**
  * **Password Security:** Modern encryption using `password_hash()` and `password_verify()`.
  * **CSRF Protection:** Every form and critical request is secured by server-generated, cryptographically strong tokens.
  * **SQL Injection Prevention:** Strict and consistent use of PDO Prepared Statements.
  * **XSS Protection:** Clean and safe data rendering using `htmlspecialchars` for all outputs.

* **Internationalization (i18n):** Full support for English and German via `gettext`.
* **Responsive Design:** Built with Bootstrap 5, works on desktop and mobile.

## 🛠️ Technology Stack

* **Backend:** PHP (Native, no framework required)
* **Database:** SQLite (Zero-configuration, file-based)
* **Frontend:** HTML5, CSS3, Bootstrap 5.3
* **Icons:** Google Material Symbols
* **Localization:** GNU gettext

## 🚀 Installation & Setup

### Prerequisites

* Webserver (Apache or Nginx)
* PHP 7.4 or higher
* **Extensions required:**
* `pdo_sqlite` (for the database)
* `gettext` (for translations)


### Steps

1. **Clone the repository** (or copy files to your webroot):
```bash
git clone https://github.com/simon-eller/gscheid.git
cd gscheid
```

2. **Change default configuration:**
Move the `config-sample.php` to `config.php` and edit the settings.

⚠️ Warning: Please set your own username and password in `$auth_users` before use. Password is encrypted with `password_hash()`.

```bash
mv config-sample.php config.php
nano config.php
```
