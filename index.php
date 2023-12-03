<?php

// QSAMM - Quick staging access and maintenance mode

// Start PHP session
ob_start();

$config = '';

// Define some constants for QSAMM
define('QSAMM', TRUE);
define('VERSION', '1.0');
define('GITHUB', 'https://github.com/tryallthethings/qsamm');

// Define translations
$translations = [
  'de' => [ 
      'QSAMM configuration' => 'QSAMM Konfiguration',
      'Admin password' => 'Administrator-Passwort',
      'User password' => 'Benutzer-Passwort',
      'Login timeout' =>  'max. Login-Zeit',
      'e.g.' => 'z.B.',
      'Enable logging' => 'Protokollierung',
      'Redirect page filename' => 'Dateiname für Umleitung',
      'Page title' => 'Seitentitel',
      'Page heading' =>  'Seitenüberschrift',
      'Google font' => 'Google Schriftart',
      'Main color' => 'Hauptfarbe',
      'Background color' => 'Hintergrundfarbe',
      'Page content (supports minimal markdown)' => 'Seiteninhalt (unterstützt minimales Markdown)',
      'Save configuration' => 'Konfiguration speichern',
      'Invalid login details' => 'Ungültige Zugangsdaten',
      'Browser default (GPDR safe)' => 'Browser Standardeinstellung (DSGVO sicher)',
      'Login form' => 'Anmeldeformular',
      'Please login' => 'Bitte anmelden',
      'Login' => 'Einloggen',
      'Username' => 'Benutzername',
      'Password' => 'Passwort',
      'Authenticate' => 'Authentifizieren',
      'Authorized access only' => 'Nur autorisierter Zugang',
      'Show' => 'Anzeigen',
      'Staging / Maintenance mode first run configuration' => 'Entwickler / Wartungsseite Erstkonfiguration',
      'Invalid input. Please enter a number followed by MM, W, D, H, M, or S.' => 'Ungültige Eingabe. Bitte eine Nummer gefolgt von MM, W, D, H, M oder S eingeben',
  ],
];

// Detect browser language
$browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
$currentLang = array_key_exists($browserLang, $translations) ? $browserLang : 'en';

// Custom translation function
function translate($text) {
  global $translations, $currentLang;
  return $translations[$currentLang][$text] ?? $text;
}

// Shorthand translate function
function __($text) {
    return translate($text);
}

$updateConfig = false;
// Write config if user saved one and recreate info page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_config'])) {
    _log("Updating configuration", true);
    $updateConfig = true;
    writeConfig();
}

// Does a config file already exist? If not, show configuration dialog
if (!file_exists(__DIR__ . '/config.php')) {
    _log("Config file does not exist - creating it");
    echo showAdminForm($browserLang);
	exit();
} else {
	// Read config
	$config = require __DIR__ . '/config.php';
}

// Write a default .htaccess file to root if none exists
if (!file_exists(dirname(__DIR__, 1) . '/.htaccess')) {
    _log(".htaccess does not exist - creating it");
	createHtaccess($config);
}

// Write a default .htaccess file for PHP script folder if none exists
if (!file_exists(__DIR__ . '/.htaccess')) {
    _log(".htaccess for PHP script does not exist - creating it");
	createPHPHtaccess();
}

if (!file_exists(dirname(__DIR__, 1) . '/' . $config['redirect_page'] ) || $updateConfig) {
	_log("Info page does not or configuration was updated. Creating it (again)", true);
    createInfoPage($config);
}

// Run cleanup every time the script is called
cleanupHtaccess($config);

// Check Login form submitted 
if(isset($_POST['submit'])){

    // Check and assign submitted password to new variable
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Check if password matches defined password 
    if ($password == $config['user_password']){
      $htaccessFile = "../.htaccess";
      $htaccess = file_exists($htaccessFile) ? file($htaccessFile, FILE_IGNORE_NEW_LINES) : [];
      $htaccess = ensureRequiredBlocks($htaccess);
      $htaccess = addNewEntry($htaccess, $config, $_SERVER['REMOTE_ADDR'], $username);
  
      file_put_contents($htaccessFile, implode(PHP_EOL, $htaccess));
      header('Refresh: 2; URL=../');
      exit();
    }
  else if ($password == $config['admin_password'])
  {
    _log("Correct admin password entered - going into configuration");
    // Show admin form
    echo showAdminForm($browserLang, $config);
    exit();
  } else {
      // Unsuccessful attempt: show error message
      $msg= __('Invalid login details');
      _log("Invalid login details provided");
  }
}

// Function to add a new entry to .htaccess
function addNewEntry($htaccess, $config, $currentIp, $username) {
  $newEntry = '###NAME:' . date('Y-m-d H:i:s') . '|' . $username;
  $currentIpCond = 'RewriteCond %{REMOTE_ADDR} !^' . str_replace('.', '\.', $currentIp);
  _log("Adding new entry to .htaccess: " . $newEntry . " with IP " . $currentIp);
  $isAuthBlock = false;
  $updatedHtaccess = [];

  foreach ($htaccess as $line) {
      if (trim($line) == '###AUTHSTART###') $isAuthBlock = true;

      // Insert new entry before AUTHEND
      if ($isAuthBlock && trim($line) == '###AUTHEND###') {
          $updatedHtaccess[] = $newEntry;
          $updatedHtaccess[] = $currentIpCond;
          $isAuthBlock = false;
      }

      $updatedHtaccess[] = $line;
  }

  return $updatedHtaccess;
}

// Ensure QSAMM and AUTH blocks are present in the .htaccess file
function ensureRequiredBlocks($htaccess) {
  $requiredBlocks = [
      '###QSAMMSTART###',
      '###QSAMMEND###',
      '###AUTHSTART###',
      '###AUTHEND###'
  ];

  foreach ($requiredBlocks as $block) {
      if (!in_array($block, $htaccess)) {
          if ($block == '###AUTHSTART###' || $block == '###AUTHEND###') {
              // Insert AUTH block inside QSAMM block
              $qsammEndIndex = array_search('###QSAMMEND###', $htaccess);
              array_splice($htaccess, $qsammEndIndex, 0, $block);
          } else {
              // Add QSAMM block at the end of the file
              $htaccess[] = $block;
          }
      }
  }

  return $htaccess;
}

// Function to cleanup .htaccess file
function cleanupHtaccess($config) {
    $htaccessFile = "../.htaccess";
    if (file_exists($htaccessFile)) {
        $originalHtaccess = file($htaccessFile, FILE_IGNORE_NEW_LINES);
        $htaccess = ensureRequiredBlocks($originalHtaccess);
        $processedHtaccess = processHtaccess($htaccess, $config);

        if ($originalHtaccess !== $processedHtaccess) {
            file_put_contents($htaccessFile, implode(PHP_EOL, $processedHtaccess));
        }
    }
}

// Process the .htaccess file for cleanup
function processHtaccess($htaccess, $config) {
  $timeoutSeconds = convertTime($config['timeout']);
  $isAuthBlock = false;
  $isQSAMMBlock = false;
  $skipNextLine = false; // Flag to skip the next line (for RewriteCond)
  $updatedHtaccess = [];

  foreach ($htaccess as $line) {
      if (trim($line) == '###QSAMMSTART###') $isQSAMMBlock = true;
      if (trim($line) == '###QSAMMEND###') $isQSAMMBlock = false;

      if ($isQSAMMBlock) {
          if (trim($line) == '###AUTHSTART###') $isAuthBlock = true;
          if ($isAuthBlock) {
              // Check if the previous line was skipped (expired entry)
              if ($skipNextLine) {
                  $skipNextLine = false; // Reset the flag and skip this line
                  continue;
              }

              // Check if line contains expiration date and if it's expired
              if (preg_match('/###NAME:(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|/', $line, $matches)) {
                $expirationTime = strtotime($matches[1]);
                if (time() - $expirationTime > $timeoutSeconds) {
                    _log("Removing entry " . $line);
                    $skipNextLine = true; // Set the flag to skip the next line
                    continue; // Skip the expired entry line
                }
              }
          }
          if (trim($line) == '###AUTHEND###') {
              $isAuthBlock = false;
          }
      }
      $updatedHtaccess[] = $line;
  }

  return $updatedHtaccess;
}

// Write config file
function writeConfig() {
    $sec = "if(!defined('QSAMM')) {
        die('Direct access not permitted');
     }";
    _log("Writing config", true);
	$config = [
		'admin_password' => $_POST['admin_password'],
		'user_password' => $_POST['user_password'],
		'timeout' => $_POST['timeout'],
		'enable_logging' => isset($_POST['enable_logging']) ? true : false,
		'redirect_page' => $_POST['redirect_page'],
		'logo_path' => $_POST['logo_path'],
		'page_title' => $_POST['page_title'],
		'page_heading' => $_POST['page_heading'],
		'page_text' => $_POST['page_text'],
		'google_font' => $_POST['google_font'],
		'main_color' => $_POST['main_color'],
        'background_color' => $_POST['background_color']
	];
	file_put_contents(__DIR__ . '/config.php', '<?php 
    ' . $sec . '
    return ' . var_export($config, true) . '; 
    ?>');

    // Create backup subfolder if it doesn't exist
    if (!is_dir("backups")) {
        mkdir( "backups");
    }
    
    // Create a backup of .htaccess every time the config is changed
    if (file_exists("../.htaccess")) {
        copy ("../.htaccess", "backups/.htaccess_" . date('Y-m-d_H-i-s'));
    }
}

function showAdminForm($browserLang, $config = null) {
  $googleFonts = [
      __('Browser default (GPDR safe)'), 'Open Sans', 'Roboto', 'Lato', 'Slabo', 'Oswald', 
      'Source Sans Pro', 'Montserrat', 'Raleway', 'PT Sans', 'Roboto Condensed'
  ];

  $form = getHTML($browserLang) . '
      <style>
      ' . getCSS($config) . '
      </style>
      <script>
      // Reveal passwords 
      function toggleVisibility(fieldId, shouldShow) {
          var passwordField = document.getElementById(fieldId);
          passwordField.type = shouldShow ? \'text\' : \'password\';
      }

      // Validate timeout input
      function validateTimeout() {
        var timeoutInput = document.getElementsByName(\'timeout\')[0];
        var pattern = /^[0-9]+(MM|W|D|H|M|S)$/i;

        if (!pattern.test(timeoutInput.value)) {
            timeoutInput.setCustomValidity("' . __('Invalid input. Please enter a number followed by MM, W, D, H, M, or S.') . '");
        } else {
            timeoutInput.setCustomValidity(\'\');
        }
      }
      </script>
      <title>' . __('Staging / Maintenance mode first run configuration') . '</title>
  </head>
  <body>
  <form class="config-form" method="post">
  <h1>' . __('QSAMM configuration') . '</h1>
  <div class="form-row">
  <div>
      <label>' . __('Admin password') . '</label>
      <div class="password-container">
          <input type="password" name="admin_password" id="admin_password" class="password-field" value="' . (isset($config['admin_password']) ? $config['admin_password'] : '') . '">
          <span class="toggle-password" onmousedown="toggleVisibility(\'admin_password\', true)" onmouseout="toggleVisibility(\'admin_password\', false)">' . __('Show') . '</span>
      </div>
  </div>
  <div>
      <label>' . __('User password') . '</label>
      <div class="password-container">
          <input type="password" name="user_password" id="user_password" class="password-field" value="' . (isset($config['user_password']) ? $config['user_password'] : '') . '">
          <span class="toggle-password" onmousedown="toggleVisibility(\'user_password\', true)" onmouseout="toggleVisibility(\'user_password\', false)">' . __('Show') . '</span>
      </div>
  </div>
</div>

<!-- Timeout and Enable Logging Fields -->
<div class="form-row">
  <div>
      <label>' . __('Login timeout') . ' (' . __('e.g.') . ': 180S, 60M, 7D, 2W, 1MM): ' . '</label>
      <input type="text" name="timeout" value="' . (isset($config['timeout']) ? $config['timeout'] : '') . '" oninput="validateTimeout()" onblur="validateTimeout()" placeholder="1W">
  </div>
  <div>
      <label>' . __('Enable logging') . '</label>
      <input type="checkbox" name="enable_logging" ' . (isset($config['enable_logging']) && $config['enable_logging'] ? 'checked' : '') . '>
  </div>
</div>

<!-- Remaining Fields -->
<div class="form-row">
  <div>
      <label>' . __('Redirect page filename') . '</label>
      <input type="text" name="redirect_page" value="' . (isset($config['redirect_page']) ? $config['redirect_page'] : '') . '" placeholder="info.html" ' . (!empty($config) ? 'readonly class="config-readonly-input"' : '') . '>
      </div>
  <div>
      <label>' . __('Logo path') . '</label>
      <input type="text" name="logo_path" value="' . (isset($config['logo_path']) ? $config['logo_path'] : '') . '" placeholder="https://placehold.co/200x200">
  </div>
</div>

<div class="form-row">
  <div>
      <label>' . __('Page title') . '</label>
      <input type="text" name="page_title" value="' . (isset($config['page_title']) ? $config['page_title'] : '') . '" placeholder="Site maintenance">
  </div>
  <div>
      <label>' . __('Page heading') . '</label>
      <input type="text" name="page_heading" value="' . (isset($config['page_heading']) ? $config['page_heading'] : '') . '" placeholder="Currently down for maintenance">
  </div>
</div>

<!-- Google Font Selection and main color-->
<div class="form-row">
  <div>
      <label>' . __('Google font') . '</label>
      <select name="google_font" style="width: 100%;">
          <option value="">' . __('Select a font') . '</option>';

  foreach ($googleFonts as $font) {
      $selected = (isset($config['google_font']) && $config['google_font'] == $font) ? ' selected' : '';
      $form .= '<option value="' . $font . '"' . $selected . '>' . $font . '</option>';
  }

  $form .= '</select>
  </div>
  </div>
  <div class="form-row">
  <div>
      <label>' . __('Main color: ') . '</label>
      <input type="color" name="main_color" value="' . (isset($config['main_color']) ? $config['main_color'] : '#007bff') . '">
  </div>
  <div>
      <label>' . __('Background color: ') . '</label>
      <input type="color" name="background_color" value="' . (isset($config['background_color']) ? $config['background_color'] : '#007bff') . '">      
  </div>  
</div>

<!-- Large Textarea -->
<div class="form-row">
  <div style="flex-basis: 100%;">
      <label for="page_text">' . __('Page content (supports minimal markdown)') . '</label>
      <textarea rows="20" name="page_text" style="width: 100%;">' . (isset($config['page_text']) ? $config['page_text'] : '') . '</textarea>
  </div>
</div>

<!-- Submit Button -->
<div class="form-row">
<div>
    <a href="' . GITHUB . '"><h5>QSAMM v' . VERSION . '</h5></a>
</div>
  <div style="text-align: right; width: 100%;">
      <input type="submit" name="submit_config" value="' . __('Save configuration') . '">
  </div>
</div>
<details>
<summary>Show debug log</summary>
<div class="form-row debuglog">
' . getDebuglog(20) . '
</div>
  </form>
  </body>
  </html>';
  return $form;
}

// Create the info page for visitors based on the configuration
function createInfoPage($config){
  if ($config['google_font'] != __('Browser default (GPDR safe)')) {
    $googlefont = '@import url("https://fonts.googleapis.com/css2?family=' . $config['google_font'] . '");
    * {
      font-family: "' . $config['google_font'] . '", sans-serif;
    }';
  }
	$mainColor = $config['main_color'];

    $page = getHTML($browserLang) . '

		<title>' . $config['page_title'] . '</title>

		<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
		<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
		<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
		<link rel="manifest" href="site.webmanifest">
		<link rel="mask-icon" href="safari-pinned-tab.svg" color="' . $mainColor . '">
		<meta name="msapplication-TileColor" content="' . $mainColor . '">
		<meta name="theme-color" content="#ffffff">

		<style>
      ' .getCSS($config) .  $googlefont . '
		  .page-heading { font-size: 50px; font-weight: 700; color: ' . $mainColor . ';}
		  body { font-size: 18px; color: #333; }
		  article { display: block; text-align: left; max-width: 120px; margin: 0 auto; }
		  a { color: ' . $mainColor . '; text-decoration: none; }
		  a:hover { color: ' . $mainColor . '; text-decoration: none; }
		  .logo { display: block; margin: 0 auto 15px; float: none; width: 80%;}
		 
		 .button {
		  background-color: ' . $mainColor . ';
		  border: none;
		  color: white;
		  padding: 15px 32px;
		  text-align: center;
		  text-decoration: none;
		  display: inline-block;
		  font-size: 16px;
		}

		.button:hover {
		  color: #fff;
		  font-weight: 800;
		}
		</style>
		</head>
		<body>
			<div class="info-content">
                <div class="content-row">
				    <div id="logo"><img src="' . $config['logo_path'] . '" alt="Logo" class="logo"></div>
                    <div class="page-heading">' . $config['page_heading'] . '</div>
                </div>
				<br>
				
				<div id="content">' . convertMarkdownToHtml($config['page_text']) . '</div>
				<a href="' . basename(__DIR__) . '/" class="button">' . __('Login') . '</a>
			</div>
			</body>

		</html>';
		file_put_contents(dirname(__DIR__, 1) . '/' . $config['redirect_page'], $page);
}

// Function to create a new .htaccess file with default content
function createHtaccess($config, $return = false) {
    $default_htaccess = [
        '###QSAMMSTART###',
        'RewriteEngine On',
        'RewriteBase /',
        '###AUTHSTART###',
        '###AUTHEND###',
        'RewriteCond %{REQUEST_URI}  !(\.png|\.webp|\.xml|\.svg|\.ico|\.txt)$',
        'RewriteCond %{REQUEST_URI} !^/' . $config['redirect_page'] . '$',
        'RewriteCond %{REQUEST_URI} !^/' . basename(__DIR__) . '/(.*)',
        'RewriteRule ^(.*)$ ' . $config['redirect_page'] . ' [R=307,L]',
        '###QSAMMEND###'
    ];
    if ($return) {
        return $default_htaccess;
    } else {
        file_put_contents('../.htaccess', implode(PHP_EOL, $default_htaccess));
    }
}

function createPHPHtaccess() {
    $PHP_htaccess = ['
    # Block access to specific files
    <Files "debug.log">
       Order allow,deny
       Deny from all
    </Files>
    <Files "config.php">
        Order allow,deny
        Deny from all
    </Files>
'];
file_put_contents('.htaccess', implode(PHP_EOL, $PHP_htaccess));
}

// Simple Markdown to HTML conversion
function convertMarkdownToHtml($markdown) {
    // Headers
    for ($i = 6; $i >= 1; $i--) {
        $markdown = preg_replace('/^#{' . $i . '}\s*(.*)$/m', '<h' . $i . '>$1</h' . $i . '>', $markdown);
    }

    // Bold
    $markdown = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $markdown);

    // Italic
    $markdown = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $markdown);

    // Blockquotes
    $markdown = preg_replace('/^\>\s*(.*)$/m', '<blockquote>$1</blockquote>', $markdown);

    // Ordered List
    $markdown = preg_replace('/^\d\.\s*(.*)$/m', '<ol><li>$1</li></ol>', $markdown);
    $markdown = preg_replace('/(<\/ol>\s*<ol>)+/', '', $markdown);

    // Unordered List
    $markdown = preg_replace('/^\-\s*(.*)$/m', '<ul><li>$1</li></ul>', $markdown);
    $markdown = preg_replace('/(<\/ul>\s*<ul>)+/', '', $markdown);

    // Links
    $markdown = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $markdown);

    // Images
    $markdown = preg_replace('/\!\[(.*?)\]\((.*?)\)/', '<img src="$2" alt="$1">', $markdown);

    // Inline Code
    $markdown = preg_replace('/\`(.*?)\`/', '<code>$1</code>', $markdown);

    // Newlines to <br>
    $markdown = preg_replace('/\n/', '<br>', $markdown);

    return $markdown;
}

// Convert time shortcodes to seconds for calculations and vice versa
function convertTime($input) {
  $units = [
      'MM' => 2592000, // Months (30 days)
      'W' => 604800,   // Weeks
      'D' => 86400,    // Days
      'H' => 3600,     // Hours
      'M' => 60,       // Minutes
      'S' => 1         // Seconds
  ];

  // Converting from a string like "2MM" to seconds (case-insensitive)
  if (preg_match('/^(\d+)(MM|W|D|H|M|S)$/i', $input, $matches)) {
      $value = $matches[1];
      $unit = strtoupper($matches[2]); // Convert to uppercase for uniformity
      return $value * $units[$unit];
  }

  // Converting from seconds to the largest possible unit
  if (is_numeric($input)) {
      $seconds = (int)$input;
      foreach ($units as $unit => $sec) {
          if ($seconds % $sec == 0) {
              return ($seconds / $sec) . $unit;
          }
      }

      // If no exact conversion is possible, use the next smaller unit
      foreach ($units as $unit => $sec) {
          if ($seconds >= $sec) {
              return floor($seconds / $sec) . $unit;
          }
      }

      return $seconds . 's'; // Default to seconds if very small value
  }

  return false;
}

// Write to debug log
function _log($message, $log_override = false) {
    static $loggingEnabled = null;

    if ($loggingEnabled === null) {
        global $config;
        $loggingEnabled = $config['enable_logging'] ?? false;
    }

    if ($loggingEnabled || $log_override) {
        // Set the path of the log file
        $logFile = 'debug.log';

        // Sanitize the message to remove any control characters
        $sanitizedMessage = filter_var($message, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK);

        // Get the current date and time
        $currentDateTime = date('Y-m-d H:i:s');

        // Format the log entry
        $logEntry = $currentDateTime . ' - ' .  $_SERVER['REMOTE_ADDR'] . ' - ' . $sanitizedMessage . PHP_EOL;

        // Write the log entry to the file
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

// Read debug log 
function getDebuglog($count = 1) {
    // Path to the debug.log file
    $logFile = __DIR__ . '/debug.log';

    $return = '';
    // Check if the file exists
    if (file_exists($logFile)) {
        // Read the file into an array, one line per array element
        $lines = file($logFile);

        // Get the last XX lines
        $lastlines = array_slice($lines, -$count);

        $return .= '<h2>Last ' . $count . ' entries in debug.log</h2></div><div class="form-row">';
        $return .= '<div style="background-color: #f2f2f2; padding: 10px; border: 1px solid #ccc; font-family: monospace;">';

        // Loop through the last 20 lines and output them
        foreach ($lastlines as $line) {
            $return .= htmlspecialchars($line) . '<br>';
        }

        $return .= '</div>';
    } else {
        $return .= '<p>Log file not found.</p>';
    }

    return $return . '</div>';
}

// Define CSS for all pages
function getCSS($config) {
    $mainColor = $config['main_color'] ?? '#007bff';
    $backgroundColor = $config['background_color'] ?? '#007bff';
    
    return '
    /* General style for the entire page */
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
        background-color: ' . $backgroundColor . ';
        font-family: Arial, sans-serif;
    }

    /* Styles for the login page */

    /* login-wrapper style to center form */
    .login-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100%;
    }

    /* Form container style */
    #login-content {
        background: #fff;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 30px 60px 0 rgba(0,0,0,0.3);
        text-align: center;
        max-width: 450px;
        width: 90%;
        position: relative; /* Centering the form */
    }

    /* Style for form input container */
    .container {
        max-width: 340px;
        width: 100%;
        margin: auto;
    }

    /* Style for each input field */
    .input-box {
        position: relative;
        margin-bottom: 20px;
    }

    /* Style for input elements */
    .input-box input {
        width: 100%;
        padding: 15px;
        border: 2px solid #ccc;
        border-radius: 6px;
        font-size: 16px;
        background-color: transparent;
        transition: border-color 0.3s;
    }

    /* Style for input elements when focused or filled */
    .input-box input:focus,
    .input-box input:valid {
        border-color: #4070f4; /* Highlight color */
    }

    /* Style for labels of input fields */
    .input-box label {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        font-size: 16px;
        pointer-events: none;
        transition: all 0.3s ease;
    }

    /* Style for label when input is focused or filled */
    .input-box input:focus ~ label,
    .input-box input:valid ~ label {
        top: -10px;
        left: 10px;
        color: #4070f4; /* Highlight color */
        font-size: 12px;
        background-color: #fff;
        padding: 0 5px;
        border-radius: 5px;
    }

    /* Style for the submit button */
    input[type=submit] {
        background-color: ' . $mainColor . ';
        color: white;
        padding: 15px 80px;
        border: none;
        border-radius: 5px;
        font-size: 13px;
        text-transform: uppercase;
        cursor: pointer;
        transition: background-color 0.3s;
        
    }

    /* Style for the spacer below the login button */

    .login-spacer {
        margin-bottom: 80px; /* Increased bottom margin */
    }

    /* Hover effect for the submit button */
    input[type=submit]:hover {
        background-color: #39ace7;
    }

    /* Footer style */
    #login-footer {
        background-color: #f6f6f6;
        border-top: 1px solid #dce8f1;
        padding: 25px;
        text-align: center;
        border-radius: 0 0 10px 10px;
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
    }

    /* Style for anchor tag in the footer */
    #login-footer a {
        color: #92badd;
        display: inline-block;
        text-decoration: none;
        font-weight: 400;
        position: relative;
    }

    /* Hover effect for anchor tag */
    #login-footer a:hover, #login-footer a:focus {
        color: #0d0d0d;
        text-decoration: none;
    }

    /* Underline effect for anchor tag */
    #login-footer a:after {
        content: \'\';
        position: absolute;
        width: 100%;
        height: 2px;
        background-color: #4070f4;
        left: 0;
        bottom: -10px;
        opacity: 0;
        transition: opacity 0.3s;
    }

    #login-footer a:hover:after, #login-footer a:focus:after {
        opacity: 1;
    }

    /* General animation style */
    .login-fadein {
        animation: login-fadein 1s;
    }

    @keyframes login-fadein {
        from {
            opacity: 0;
            transform: translate3d(0, -100%, 0);
        }
        to {
            opacity: 1;
            transform: none;
        }
    }

    /* Focus outline removal */
    *:focus {
        outline: none;
    } 

    /* Form icon style */
    #icon {
    width:60%;
    }

    /* Box sizing for all elements */
    * {
    box-sizing: border-box;
    }

    /* Style for active form title */
    h2.login-h2 {
    color: #0d0d0d;
    text-align: center;
    font-size: 16px;
    font-weight: 600;
    text-transform: uppercase;
    display: inline-block;
    margin: 40px 8px 10px 8px;
    border-bottom: 2px solid #5fbae9;
    }
    
    /* Styles for config page */

    body { font-family: Arial, sans-serif; }
    .config-form { 
        max-width: 800px; 
        width: 90%; 
        margin: 20px auto; 
        padding: 20px; 
        border: 1px solid #ccc; 
        border-radius: 5px; 
        background-color: #f8f8f8; 
        box-sizing: border-box;
    }

    .config-form .form-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 15px;
    }

    .config-form .form-row > div {
        flex: 1 1 40%; /* 40% width, two items per row */
        padding: 0 10px; /* Spacing between fields */
    }

    .config-form label { 
        display: block; 
        margin-bottom: 5px; 
    }

    .config-form input[type="text"], 
    .config-form input[type="password"], 
    .config-form select, 
    .config-form textarea {
        width: 100%; 
        padding: 8px; 
        border: 1px solid #ddd; 
        border-radius: 3px; 
        box-sizing: border-box;
    }

    .config-form .password-container {
        position: relative;
        display: flex;
        align-items: center;
    }

    .config-form .toggle-password {
        position: absolute; 
        right: 10px; 
        cursor: pointer; 
        user-select: none;
    }

    .config-form input[type="submit"] {
        width: auto; 
        padding: 10px 20px; 
        border: none; 
        border-radius: 3px; 
        background-color: #007bff; 
        color: white; 
        cursor: pointer; 
        float: right;
        margin-top: 10px;
    }

    .config-form input[type="submit"]:hover { 
        background-color: #0056b3; 
    }

    .config-readonly-input {
        background-color: #e0e0e0; /* Grey background */
        color: #686868; /* Darker text */
    }

    @media (max-width: 600px) {
      .config-form .form-row, 
      .config-form .form-row > div {
          width: 100%;
          padding: 0;
          flex-basis: 100%; /* Ensures each div takes full width */
          margin-bottom: 10px;
      }
      .config-form .form-row {
          flex-direction: column; /* Stacks the divs vertically */
      }

      .page-heading {
        font-size: 35px !important;
      }
    }

    .logo {
        display: block; /* Makes logo take the full width */
        margin: 0 auto 15px; /* Centers the logo and adds space below */
        float: none; /* Removes float */
        width: 50%; /* Adjust logo size for mobile */
    }

    .headingtext {
        font-size: 24px; /* Adjusts title size for mobile */
    }

    .config-form {
        padding: 10px; /* Reduces padding for smaller screens */
    }

    #content {
        text-align: justify; /* Improves readability */
    }
    
    .info-content { 
        max-width: 1200px; 
        width: 90%; 
        margin: 20px auto; 
        padding: 20px; 
        border: 1px solid #ccc; 
        border-radius: 5px; 
        background-color: #f8f8f8; 
        box-sizing: border-box;
    }

    .info-content .content-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 15px;
    }

    /* Change mouse icon for debug log on admin page */
    details summary  {
        cursor: pointer;
    }

    ';
}

// Define HTML header for all pages
function getHTML($browserLang) {
    return '
    <!DOCTYPE html>
    <html lang="' . $browserLang . '">
      <head>
        <meta charset="UTF-8">
        <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible"/>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    ';
}

// Display login form 
echo getHTML($browserLang);
?>

<title><?= __('Login form'); ?></title>
    <style>
        <?= getCSS($config); ?>
    </style>
  </head>
  <body>
    <div class="login-wrapper login-fadein">
      <div id="login-content">
        <h2 class="login-h2"><?= __('Please login'); ?></h2>

        <!-- Icon -->
        <div class="fadeIn first">
          <svg width="50" height="50" viewBox="-42 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="m333.671875 123.308594c0 33.886718-12.152344 63.21875-36.125 87.195312-23.972656 23.972656-53.308594 36.125-87.195313 
              36.125h-.058593c-33.84375-.011718-63.160157-12.164062-87.132813-36.125-23.976562-23.976562-36.125-53.308594-36.125-87.195312 0-33.875 12.148438-63.210938 36.125-87.183594 23.960938-23.964844 53.277344-36.1132812 
              87.132813-36.125h.058593c33.875 0 63.210938 12.152344 87.195313 36.125 23.972656 23.972656 36.125 53.308594 36.125 87.183594zm0 0" fill="#ffbb85"/><path d="m427.167969 423.945312c0 26.734376-8.503907 48.378907-25.253907 
              64.320313-16.554687 15.753906-38.449218 23.734375-65.070312 23.734375h-246.53125c-26.621094 0-48.515625-7.980469-65.058594-23.734375-16.761718-15.953125-25.253906-37.59375-25.253906-64.320313 0-10.28125.339844-20.453124 
              1.019531-30.234374.691407-10 2.089844-20.882813 4.152344-32.363282 2.078125-11.574218 4.75-22.515625 7.949219-32.515625 3.320312-10.351562 7.8125-20.5625 13.371094-30.34375 5.773437-10.152343 12.554687-18.996093 20.15625-26.277343 
              7.96875-7.621094 17.710937-13.742188 28.972656-18.203126 11.222656-4.4375 23.664062-6.6875 36.976562-6.6875 5.222656 0 10.28125 2.136719 20.03125 8.488282 6.101563 3.980468 13.132813 8.511718 20.894532 13.472656 6.703124 4.273438 
              15.78125 8.28125 27.003906 11.902344 9.863281 3.191406 19.875 4.972656 29.765625 5.28125 1.089843.039062 2.179687.058594 3.269531.058594 10.984375 0 22.09375-1.800782 33.046875-5.339844 11.222656-3.621094 20.3125-7.628906 
              27.011719-11.902344 7.84375-5.011719 14.875-9.539062 20.886718-13.460938 9.757813-6.363281 14.808594-8.5 20.042969-8.5 13.300781 0 25.742188 2.25 36.972657 6.6875 11.261718 4.460938 21.003906 10.59375 28.964843 18.203126 
              7.613281 7.28125 14.394531 16.125 20.164063 26.277343 5.5625 9.789063 10.0625 19.992188 13.371094 30.332031 3.203124 10.011719 5.882812 20.953126 7.960937 32.535157 2.050781 11.492187 3.453125 22.375 4.140625 32.347656.691406 
              9.75 1.03125 19.921875 1.042969 30.242187zm0 0" fill="#6aa9ff"/><path d="m210.351562 246.628906h-.058593v-246.628906h.058593c33.875 0 63.210938 12.152344 87.195313 36.125 23.972656 23.972656 36.125 53.308594 36.125 87.183594 0 
              33.886718-12.152344 63.21875-36.125 87.195312-23.972656 23.972656-53.308594 36.125-87.195313 36.125zm0 0" fill="#f5a86c"/><path d="m427.167969 423.945312c0 26.734376-8.503907 48.378907-25.253907 64.320313-16.554687 15.753906-38.449218 
              23.734375-65.070312 23.734375h-126.550781v-225.535156c1.089843.039062 2.179687.058594 3.269531.058594 10.984375 0 22.09375-1.800782 33.046875-5.339844 11.222656-3.621094 20.3125-7.628906 27.011719-11.902344 7.84375-5.011719 14.875-9.539062 
              20.886718-13.460938 9.757813-6.363281 14.808594-8.5 20.042969-8.5 13.300781 0 25.742188 2.25 36.972657 6.6875 11.261718 4.460938 21.003906 10.59375 28.964843 18.203126 7.613281 7.28125 14.394531 16.125 20.164063 26.277343 5.5625 9.789063 
              10.0625 19.992188 13.371094 30.332031 3.203124 10.011719 5.882812 20.953126 7.960937 32.535157 2.050781 11.492187 3.453125 22.375 4.140625 32.347656.691406 9.75 1.03125 19.921875 1.042969 30.242187zm0 0" fill="#2682ff"/></svg>
        </div>

        <!-- Login Form -->
        <form action="" method="post">
          <div class="container">
            <div class="input-box">
                <input type="text" id="login" class="fadeIn second" name="username" placeholder=" " required autofocus>
                <label for="login"><?= __('Username'); ?></label>
            </div>
            <div class="input-box">
                <input type="password" id="password" class="fadeIn third" name="password" placeholder=" " spellcheck="false" required>
                <label for="password"><?= __('Password'); ?></label>
            </div>
          </div>
          <div>
            <?php if (isset($msg)) echo '<span style="color:red">' . htmlspecialchars($msg) . '!</span>' ?>
          </div>
          <input name="submit" type="submit" class="fadeIn fourth" value="<?= __('Authenticate'); ?>">
          <div class="login-spacer"></div>
        </form>

        <!-- Note -->
        <div id="login-footer">
          <a class="underlineHover" href="#"><?= __('Authorized access only'); ?></a>
        </div>
      </div>
    </div>
  </body>
</html>