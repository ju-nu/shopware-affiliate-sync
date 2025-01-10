<?php
// Define the path to the main log file
$logFile = 'app.log';

// (Optional) Define the path to the error log file
$errorLogFile = 'error.log';

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

// Function to parse a log line and extract log level
function parseLogLevel($line) {
    // Example log format:
    // [2025-01-10 09:55:08] sync.INFO: Sales-Channel 'Real-Markt' => ID 01922d9b5f607262ae90a5366344cfff 
    $pattern = '/\] (\w+)\.(\w+):/';
    if (preg_match($pattern, $line, $matches)) {
        // $matches[1] is the channel, $matches[2] is the level_name
        return strtoupper($matches[2]);
    }
    return 'INFO'; // Default level
}

// Function to save error lines to a separate error log file
function saveErrorLine($errorLogFile, $line) {
    // Append the error line to the error log file
    file_put_contents($errorLogFile, $line, FILE_APPEND | LOCK_EX);
}

// Send no-cache headers
sendNoCacheHeaders();

if (file_exists($logFile) && is_readable($logFile)) {
    // Set the Content-Type to HTML
    header('Content-Type: text/html; charset=utf-8');

    // Fetch the last 50 lines of the log file
    $logContent = tailFile($logFile, $linesToDisplay);

    // Split the log content into individual lines
    $logLines = explode("\n", trim($logContent));

    // Initialize an array to hold processed log entries
    $processedLogLines = [];

    // Variable to track if there are any errors
    $hasErrors = false;

    foreach ($logLines as $line) {
        if (empty($line)) {
            continue; // Skip empty lines
        }

        // Determine the log level
        $level = parseLogLevel($line);

        // Check if the line is an error or higher severity
        $isError = in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);

        if ($isError) {
            $hasErrors = true;

            // (Optional) Save the error line to a separate error log
            // Uncomment the following line if you want to enable this feature
            // saveErrorLine($errorLogFile, $line . "\n");
        }

        // Add the line with its level to the processed log lines
        $processedLogLines[] = [
            'line' => htmlspecialchars($line),
            'level' => $level,
            'isError' => $isError
        ];
    }

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
            /* Styling for different log levels */
            .log-DEBUG { color: gray; }
            .log-INFO { color: black; }
            .log-WARNING { color: orange; }
            .log-ERROR { color: red; font-weight: bold; }
            .log-CRITICAL { color: darkred; font-weight: bold; }
            .log-ALERT { color: maroon; font-weight: bold; }
            .log-EMERGENCY { color: darkmagenta; font-weight: bold; }
        </style>
        <script>
            window.onload = function() {
                var pre = document.getElementById('logContent');

                <?php if ($hasErrors): ?>
                    // If there are error lines, scroll to the last error
                    var errorLines = document.getElementsByClassName('log-ERROR');
                    if (errorLines.length > 0) {
                        var lastError = errorLines[errorLines.length - 1];
                        lastError.scrollIntoView({ behavior: 'smooth', block: 'end' });
                    }
                <?php else: ?>
                    // Otherwise, scroll to the bottom
                    pre.scrollTop = pre.scrollHeight;
                <?php endif; ?>
            };
        </script>
    </head>
    <body>
        <h1>App Log - Last <?php echo $linesToDisplay; ?> Lines</h1>
        <pre id="logContent">
<?php
foreach ($processedLogLines as $entry) {
    $cssClass = 'log-' . $entry['level'];
    // Ensure that only predefined classes are used to prevent CSS injection
    $allowedClasses = ['log-DEBUG', 'log-INFO', 'log-WARNING', 'log-ERROR', 'log-CRITICAL', 'log-ALERT', 'log-EMERGENCY'];
    if (!in_array($cssClass, $allowedClasses)) {
        $cssClass = 'log-INFO'; // Default to INFO if unknown level
    }
    echo '<span class="' . $cssClass . '">' . $entry['line'] . '</span>' . "\n";
}
?>
        </pre>
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
