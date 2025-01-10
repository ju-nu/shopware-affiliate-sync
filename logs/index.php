<?php
// Define the path to the log file
$logFile = 'app.log';

// Number of lines to display
$linesToDisplay = 50;

// Function to send no-cache headers
function sendNoCacheHeaders() {
    // HTTP/1.1
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    // HTTP/1.0
    header("Pragma: no-cache");
    // Proxies
    header("Expires: 0");
}

// Function to get the last N lines of a file
function tailFile($filepath, $lines = 50) {
    $f = new SplFileObject($filepath, 'r');
    $f->seek(PHP_INT_MAX);
    $totalLines = $f->key();
    $startLine = max($totalLines - $lines, 0);
    $f->seek($startLine);

    $output = '';
    while (!$f->eof()) {
        $output .= $f->current();
        $f->next();
    }
    return $output;
}

// Send no-cache headers
sendNoCacheHeaders();

if (file_exists($logFile) && is_readable($logFile)) {
    // Set the Content-Type to HTML
    header('Content-Type: text/html; charset=utf-8');

    // Fetch the last 50 lines of the log file
    $logContent = tailFile($logFile, $linesToDisplay);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>App Log - Last <?php echo $linesToDisplay; ?> Lines</title>
        <!-- Auto-refresh every 5 seconds -->
        <meta http-equiv="refresh" content="5">
        <style>
            body {
                font-family: monospace;
                background-color: #f0f0f0;
                padding: 20px;
            }
            pre {
                background-color: #fff;
                padding: 15px;
                border: 1px solid #ccc;
                height: 80vh;
                overflow-y: scroll;
                white-space: pre-wrap; /* Allows line wrapping */
                word-wrap: break-word; /* Break long words */
            }
            h1 {
                text-align: center;
            }
        </style>
        <script>
            // Scroll to the bottom of the <pre> element after the page loads
            window.onload = function() {
                var pre = document.getElementById('logContent');
                pre.scrollTop = pre.scrollHeight;
            };
        </script>
    </head>
    <body>
        <h1>App Log - Last <?php echo $linesToDisplay; ?> Lines</h1>
        <pre id="logContent"><?php echo htmlspecialchars($logContent); ?></pre>
    </body>
    </html>
    <?php
    exit;
} else {
    // Send a 404 Not Found header
    header("HTTP/1.1 404 Not Found");
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>404 Not Found</title>
        <!-- Optional: Auto-refresh the 404 page as well -->
        <meta http-equiv="refresh" content="5">
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #f8d7da;
                color: #721c24;
                padding: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }
            .error-container {
                border: 1px solid #f5c6cb;
                background-color: #f8d7da;
                padding: 30px;
                border-radius: 5px;
                max-width: 600px;
                text-align: center;
            }
            h1 {
                margin-bottom: 10px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>404 Not Found</h1>
            <p>The requested log file does not exist.</p>
            <p>This page will refresh every 5 seconds.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
