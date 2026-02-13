<?php
/**
 * Gscheid
 *
 * A lightweight selfhostable webapp to store quotes in a database
 * and access them via API.
 *
 * @version v1.0.1
 * @author Simon Eller
 * @license https://github.com/simon-eller/gscheid/blob/main/LICENSE
 * @link https://github.com/simon-eller/gscheid
 */

// Load contents of external config file
$config_file = __DIR__ . "/config.php";
if (is_readable($config_file)) {
    @include($config_file);
}

// Private key and session name to store to the session
if (!defined("SESSION_ID")) {
    define("SESSION_ID", "gscheid");
}

// Set path to sqlite database
$dbFile = __DIR__ . "/gscheid.db";

// Connect to database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");

    // Create tables if not existing yet
    $pdo->exec("CREATE TABLE IF NOT EXISTS authors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS quotes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quote TEXT,
        author_id INTEGER,
        date TEXT,
        FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT UNIQUE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS quotes_categories (
        quote_id INTEGER,
        category_id INTEGER,
        PRIMARY KEY (quote_id, category_id),
        FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

@set_time_limit(600);
ini_set("default_charset", "UTF-8");

// Create PHP session
session_cache_limiter("nocache"); // Prevent logout issue after page was cached
session_name(SESSION_ID);

function session_error_handling_function($code, $msg, $file, $line)
{
    // Permission denied for default session, try to create a new one
    if ($code == 2) {
        session_abort();
        session_id(session_create_id());
        @session_start();
    }
}

set_error_handler("session_error_handling_function");
session_start();
restore_error_handler();

// Gettext stuff
$locales = [
    "de" => "de_DE.UTF-8",
    "en" => "en_US.UTF-8"
];

// Store language setting of current user
$current_user_lang = $_GET["lang"] ?? $_SESSION["lang"] ?? $default_lang;

if (array_key_exists($current_user_lang, $locales)) {
    // Store language in current users session
    $_SESSION["lang"] = $current_user_lang;
} else {
    // Fallback to default language
    $current_user_lang = $default_lang;
}

$system_locale = $locales[$current_user_lang];

// Set environment variables for gettext
putenv("LC_ALL=$system_locale");
setlocale(LC_ALL, $system_locale);

// Specify location of translation tables
bindtextdomain("messages", __DIR__ . "/locale");
bind_textdomain_codeset("messages", "UTF-8");

// Set domain
textdomain("messages");

// Remove lang parameter from url after lang is stored in session
if (isset($_GET["lang"])) {
    $params = $_GET; unset($params["lang"]);
    $q = http_build_query($params);
    header("Location: " . $_SERVER["PHP_SELF"] . ($q ? "?".$q : ""));
    exit;
}

//Generating CSRF Token
if (empty($_SESSION["token"])) {
    if (function_exists("random_bytes")) {
        $_SESSION["token"] = bin2hex(random_bytes(32));
    } else {
        $_SESSION["token"] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// logout
if (isset($_GET["logout"])) {
    unset($_SESSION[SESSION_ID]["logged"]);
    unset($_SESSION["token"]);
    header("Location: " . $_SERVER["PHP_SELF"], true, 302);
}

// Check if the user is logged in or not. If not, it will show the login form.
if (isset($_SESSION[SESSION_ID]["logged"], $auth_users[$_SESSION[SESSION_ID]["logged"]])) {
    // Logged in
} elseif (isset($_POST["login"], $_POST["username"], $_POST["password"], $_POST["token"])) {
    // Logging In
    sleep(1);
    if (function_exists("password_verify")) {
        if (isset($auth_users[$_POST["username"]]) && isset($_POST["password"]) && password_verify($_POST["password"], $auth_users[$_POST["username"]]) && verifyToken($_POST["token"])) {
            $_SESSION[SESSION_ID]["logged"] = $_POST["username"];
            set_msg(gettext("You are logged in."));
            header("Location: " . $_SERVER["PHP_SELF"], true, 302);
        } else {
            unset($_SESSION[SESSION_ID]["logged"]);
            set_msg(gettext("Login failed. Invalid username or password."), "danger");
            header("Location: " . $_SERVER["PHP_SELF"], true, 302);
        }
    } else {
        set_msg(gettext("password_hash not supported, Upgrade PHP version"), "danger");
    }
} else {
    // Form
    unset($_SESSION[SESSION_ID]["logged"]);
    show_header();
?>
    <div class="card p-5 text-center shadow mx-auto" style="max-width: 500px;">
        <h2 class="pb-2"><?php echo gettext("Login"); ?></h2>

        <form method="POST">
            <div class="mb-4">
                <input type="text" name="username" id="usernameInput" class="form-control" placeholder="<?php echo gettext("Username"); ?>" required autofocus>
            </div>

            <div class="mb-4">
                <input type="password" name="password" id="passwordInput" class="form-control" placeholder="<?php echo gettext("Password"); ?>" required>
            </div>

            <input type="hidden" name="token" value="<?php echo htmlentities($_SESSION["token"]); ?>" />

            <button type="submit" name="login" class="btn btn-primary w-100 btn-lg"><?php echo gettext("Login"); ?></button>

            <div class="mt-4">
                <?php show_msg(); ?>
            </div>
        </form>
    </div>

<?php
    show_footer();
    exit;
}

/*************************** ACTIONS ***************************/

// Return random quote
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["random_quote"])) {

    if (function_exists("password_verify")) {
        if (isset($_GET["api_key"]) && password_verify($_GET["api_key"], $api_key)) {
            $stmt = $pdo->prepare("
                SELECT
                    q.id,
                    q.quote,
                    q.date,
                    a.name AS author,
                    GROUP_CONCAT(c.category, ', ') AS categories
                FROM quotes q
                LEFT JOIN authors a ON q.author_id = a.id
                LEFT JOIN quotes_categories qc ON q.id = qc.quote_id
                LEFT JOIN categories c ON qc.category_id = c.id
                GROUP BY q.id
                ORDER BY RANDOM()
                LIMIT 1
            ");
            $stmt->execute();
            $quote = $stmt->fetch();

            if ($quote) {
                header("Content-Type: application/json; charset=utf-8");
                echo json_encode($quote, JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            http_response_code(401);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["error" => gettext("Invalid or missing API key.")], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        set_msg(gettext("password_hash not supported, Upgrade PHP version"), "danger");
    }
}

// Handle AJAX requests for auto-completing authors
if (isset($_SESSION[SESSION_ID]["logged"], $auth_users[$_SESSION[SESSION_ID]["logged"]]) && $_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["search_author"])) {
    $stmt = $pdo->prepare("SELECT name FROM authors WHERE name LIKE ? LIMIT 10");
    $stmt->execute(["%" . $_GET["search_author"] . "%"]);

    // Return contents of column name as JSON
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle AJAX requests for auto-completing categories
if (isset($_SESSION[SESSION_ID]["logged"], $auth_users[$_SESSION[SESSION_ID]["logged"]]) && $_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["search_category"])) {
    $stmt = $pdo->prepare("SELECT category FROM categories WHERE category LIKE ? LIMIT 10");
    $stmt->execute(["%" . $_GET["search_category"] . "%"]);

    // Return contents of column category as JSON
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
    exit;
}

// If form to add new quote was submitted
if (isset($_SESSION[SESSION_ID]["logged"], $auth_users[$_SESSION[SESSION_ID]["logged"]]) && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_quote"]) && isset($_POST["quote"])) {
    $quote_text = trim($_POST["quote"]);
    $author_name = trim($_POST["author"] ?? '');
    $date = trim($_POST["date"] ?? '');
    $categories_name = trim($_POST["categories"] ?? '');

    $author_id = null;

    if ($author_name !== '') {
        // Check if matching entry of author exists in database
        $stmt = $pdo->prepare("SELECT id FROM authors WHERE name = ?");
        $stmt->execute([$author_name]);
        $author_id = $stmt->fetchColumn();

        // Else create new author entry in database
        if (!$author_id) {
            $stmt = $pdo->prepare("INSERT INTO authors (name) VALUES (?)");
            $stmt->execute([$author_name]);
            $author_id = $pdo->lastInsertId();
        }
    }

    // Insert quote into database
    $stmt = $pdo->prepare("INSERT INTO quotes (quote, author_id, date) VALUES (?, ?, ?)");
    $stmt->execute([$quote_text, $author_id, $date]);
    $quote_id = $pdo->lastInsertId();

    if ($categories_name !== '') {
        // Split string at commas
        $categories_array = explode(",", $categories_name);

        foreach ($categories_array as $single_category) {
            $clean_category = trim($single_category);

            // Skip empty entries e.g. "Cat1, , Cat3"
            if ($clean_category === '') continue;

            // Check if matching entry of category exists in database
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE category = ?");
            $stmt->execute([$clean_category]);
            $category_id = $stmt->fetchColumn();

            // Else create new category entry in database
            if (!$category_id) {
                $stmt = $pdo->prepare("INSERT INTO categories (category) VALUES (?)");
                $stmt->execute([$clean_category]);
                $category_id = $pdo->lastInsertId();
            }

            // Connect new quote with categories
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO quotes_categories (quote_id, category_id) VALUES (?, ?)");
            $stmt->execute([$quote_id, $category_id]);
        }
    }

    set_msg(gettext("Quote added successfully."));
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Render admin panel when logged in
if (isset($_SESSION[SESSION_ID]["logged"])) {
    show_header(); ?>

    <div class="card p-4 mb-5 shadow-sm">
        <h5 class="card-title pb-2"><?php echo gettext("Add new quote"); ?></h5>

        <?php show_msg(); ?>

        <form method="POST" class="row g-3">
            <div class="col-12">
                <label class="form-label text-muted small mb-1"><?php echo gettext("Quote"); ?></label>
                <textarea name="quote" class="form-control" rows="3" required></textarea>
            </div>

            <div class="col-md-4">
                <label class="form-label text-muted small mb-1"><?php echo gettext("Author"); ?></label>
                <input type="text" name="author" id="authorInput" class="form-control" list="authorList" autocomplete="off" placeholder="Max Muster">
                <datalist id="authorList"></datalist>
            </div>

            <div class="col-md-4">
                <label class="form-label text-muted small mb-1"><?php echo gettext("Categories"); ?></label>
                <input type="text" name="categories" id="categoriesInput" class="form-control" list="categoriesList" autocomplete="off" placeholder="<?php echo gettext("Psychology,Film,Sports"); ?>">
                <datalist id="categoriesList"></datalist>
                <div class="form-text" style="font-size: 0.75rem;"><?php echo gettext("Separate multiple categories with commas"); ?></div>
            </div>

            <div class="col-md-4">
                <label class="form-label text-muted small mb-1"><?php echo gettext("Date"); ?></label>
                <input type="date" name="date" class="form-control">
            </div>

            <input type="hidden" name="token" value="<?php echo htmlentities($_SESSION["token"]); ?>" />

            <div class="col-12 mt-4 text-end">
                <button type="submit" name="add_quote" class="btn btn-primary px-4"><?php echo gettext("Add Quote"); ?></button>
            </div>
        </form>
    </div>

    <h3 class="mt-5 mb-4"><?php echo gettext("All Quotes"); ?></h3>

        <?php
        // Get quotes with authors and categories from database
        $stmt = $pdo->prepare("
            SELECT
                q.id,
                q.quote,
                q.date,
                a.name AS author_name,
                GROUP_CONCAT(c.category, ', ') AS categories_names
            FROM quotes q
            LEFT JOIN authors a ON q.author_id = a.id
            LEFT JOIN quotes_categories qc ON q.id = qc.quote_id
            LEFT JOIN categories c ON qc.category_id = c.id
            GROUP BY q.id
            ORDER BY q.id DESC
        ");
        $stmt->execute();
        $all_quotes = $stmt->fetchAll();

        if (empty($all_quotes)) {
            set_msg(gettext("No quotes found. Add your first one above!"), "info");
        } else {
            echo '<div class="row g-4">';

            foreach ($all_quotes as $q) {
                $quote_text = htmlspecialchars($q["quote"] ?? '');
                $author = htmlspecialchars($q["author_name"] ?? gettext("Unknown Author"));
                $date = htmlspecialchars($q["date"] ?? "");
                $categories = htmlspecialchars($q["categories_names"] ?? "");

                $formatted_date = "";
                if (!empty($date)) {
                    $formatted_date = date("d.m.Y", strtotime($date));
                }
                ?>

                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <figure>
                                <blockquote class="blockquote mb-4 flex-grow-1">
                                    <p class="fs-5"><?php echo $quote_text; ?></p>
                                </blockquote>

                                <figcaption class="blockquote-footer mb-0">
                                    <span class="fw-bold text-dark"><?php echo $author; ?></span>

                                    <?php if (!empty($formatted_date)): ?>
                                        <span class="text-muted">, <?php echo $formatted_date; ?></span>
                                    <?php endif; ?>
                                </figcaption>
                            </figure>
                        </div>

                        <?php if (!empty($categories)): ?>
                        <div class="card-footer bg-transparent text-muted small border-top-0 pt-0">
                            <span class="material-symbols-rounded fs-6 pe-2 text-primary align-middle">label</span>
                            <span class="fs-6 align-middle"><?php echo $categories; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
            }
            echo '</div>'; // Ende der row
        }
        ?>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const setupAutocomplete = (inputId, listId, paramName) => {
                const input = document.getElementById(inputId);
                const list = document.getElementById(listId);
                let timeout = null;

                if(!input) return;

                input.addEventListener("input", function() {
                    clearTimeout(timeout);
                    const val = this.value.trim();

                    // Send AJAX request when minimum 2 characters where typed
                    if (val.length < 2) {
                        list.innerHTML = "";
                        return;
                    }

                    // Wait 300ms after last keypress to debounce
                    timeout = setTimeout(() => {
                        fetch(`?${paramName}=${encodeURIComponent(val)}`)
                            .then(response => response.json())
                            .then(data => {
                              // Delete old suggestions
                              list.innerHTML = "";

                              // Create new suggestions
                              data.forEach(item => {
                                  const option = document.createElement("option");
                                  option.value = item;
                                  list.appendChild(option);
                              });
                            })
                            .catch(err => console.error("Fetch error:", err));
                    }, 300);
                });
            };

            // Initialize autocompletion
            setupAutocomplete("authorInput", "authorList", "search_author");
            setupAutocomplete("categoriesInput", "categoriesList", "search_category");
        });
        </script>

    <?php
    show_footer();
}

// Functions

/**
 * It prints the css/js files into html
 * @param key The key of the external file to print.
 */
function print_external($key)
{
    global $external;

    if (!array_key_exists($key, $external)) {
        // throw new Exception('Key missing in external: ' . key);
        echo "<!-- EXTERNAL: MISSING KEY $key -->";
        return;
    }

    echo "$external[$key]";
}

/**
 * Verify CSRF TOKEN and remove after certified
 * @param string $token
 * @return bool
 */
function verifyToken($token)
{
    if (hash_equals($_SESSION["token"], $token)) {
        return true;
    }
    return false;
}

/**
 * Show header
 */

function show_header()
{
    global $current_user_lang;
    header("Content-Type: text/html; charset=utf-8");
    header("Cache-Control: no-store, no-cache, must-revalidate");
?>
    <!DOCTYPE html>
    <html lang="<?php echo $current_user_lang; ?>">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="googlebot" content="noindex">

        <title>Gscheid</title>
        <?php print_external("css-bootstrap"); ?>
        <?php print_external("css-material-symbols"); ?>

        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' height='24px' viewBox='0 -960 960 960' width='24px' fill='%231f1f1f'%3E%3Cpath d='m262-300 58-100q-66 0-113-47t-47-113q0-66 47-113t113-47q66 0 113 47t47 113q0 23-5.5 42.5T458-480L331-260q-5 9-14 14.5t-20 5.5q-23 0-34.5-20t-.5-40Zm360 0 58-100q-66 0-113-47t-47-113q0-66 47-113t113-47q66 0 113 47t47 113q0 23-5.5 42.5T818-480L691-260q-5 9-14 14.5t-20 5.5q-23 0-34.5-20t-.5-40Z'/%3E%3C/svg%3E">
        <style>
            .card{
                transition: transform 0.2s;
            }
            .card:hover{
                transform: translateY(-2px);
            }
        </style>
    </head>

    <body class="bg-body-tertiary">
        <nav class="navbar navbar-expand-lg">
            <div class="container">
                <a class="navbar-brand icon-link" href="#">
                    <span class="material-symbols-rounded fs-2 pe-2 text-primary">format_quote</span>
                    <span class=" fs-1 fw-bold text-dark">Gscheid</span>
                </a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="<?php echo gettext("Toggle navigation"); ?>">
                        <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarContent">
                    <div class="pt-3 pt-lg-0 ms-auto d-flex align-items-center">
                        <ul class="navbar-nav">
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle icon-link" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="material-symbols-rounded align-middle fs-5">globe</span>
                                    <?php echo strtoupper($current_user_lang); ?>
                                </a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ["lang" => "de"])); ?>"><?php echo gettext("German"); ?></a></li>
                                    <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ["lang" => "en"])); ?>"><?php echo gettext("English"); ?></a></li>
                                </ul>
                            </li>
                        </ul>
                        <?php if (isset($_SESSION[SESSION_ID]["logged"])) { ?>
                        <a href="?logout" class="btn btn-outline-secondary btn-sm ms-2"><?php echo gettext("Logout"); ?></a>
                        <?php } ?>
                    </div>

                </div>
            </div>
        </nav>

        <div class="container py-5">
        <?php
}

/**
 * Show page footer
 */
function show_footer()
{
    ?>
    </div>

    <div class="pt-2 pt-md-0 text-center pb-5">
        <?php echo gettext("Made with"); ?>
        <span class="material-symbols-rounded fs-6">favorite</span>
        <?php echo gettext("by"); ?>
       <a class="text-dark" href="https://simon-eller.at">Simon Eller</a>
    </div>

    <?php print_external("js-bootstrap"); ?>
</body>

</html>

<?php
}

/**
 * Save message in session
 * @param string $msg
 * @param string $status
 */
function set_msg($msg, $status = "success")
{
    $_SESSION[SESSION_ID]["message"] = $msg;
    $_SESSION[SESSION_ID]["status"] = $status;
}

/**
 * Show alert message from session
 */
function show_msg()
{
    if (isset($_SESSION[SESSION_ID]["message"])) {
        $class = isset($_SESSION[SESSION_ID]["status"]) ? $_SESSION[SESSION_ID]["status"] : "success";
        echo '<div class="alert alert-' . $class . '" role="alert">' . $_SESSION[SESSION_ID]["message"] . '</div>';
        unset($_SESSION[SESSION_ID]["message"]);
        unset($_SESSION[SESSION_ID]["status"]);
    }
}
