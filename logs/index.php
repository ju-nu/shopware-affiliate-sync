<?php
// Define the path to the main log file
$logFile = 'app.log';

// Number of lines to display
$linesToDisplay = 100;

// Function to send no-cache headers
function sendNoCacheHeaders() {
    // HTTP/1.1
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    // HTTP/1.0
    header("Pragma: no-cache");
    // Proxies
    header("Expires: 0");
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
    return 'INFO'; // Default level if not found
}

// Send no-cache headers
sendNoCacheHeaders();

if (file_exists($logFile) && is_readable($logFile)) {
    // Set the Content-Type to HTML
    header('Content-Type: text/html; charset=utf-8');

    // Initialize an array to hold the last 100 lines
    $lastLines = [];

    // Open the log file for reading
    $file = new SplFileObject($logFile, 'r');

    // Iterate through each line of the log file
    while (!$file->eof()) {
        $line = $file->fgets();
        if (trim($line) === '') {
            continue; // Skip empty lines
        }

        // Add the current line to the array
        $lastLines[] = $line;

        // Ensure that only the last 100 lines are kept
        if (count($lastLines) > $linesToDisplay) {
            array_shift($lastLines); // Remove the first (oldest) line
        }
    }

    // Process each line to determine its log level and prepare for display
    $processedLogLines = [];
    $hasErrors = false; // Flag to check if there are any error-level logs

    foreach ($lastLines as $line) {
        // Determine the log level
        $level = parseLogLevel($line);

        // Check if the line is an error or higher severity
        $isError = in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);

        if ($isError) {
            $hasErrors = true;
        }

        // Add the processed line to the array
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
                font-family: 'Courier New', Courier, monospace;
                background-color: #ffffff; /* White background */
                padding: 20px;
                margin: 0;
            }
            .log-container {
                background-color: #f9f9f9; /* Slightly off-white for contrast */
                padding: 15px;
                border: 1px solid #ddd;
                height: 90vh;
                overflow-y: scroll;
                white-space: pre-wrap; /* Allows line wrapping */
                word-wrap: break-word; /* Break long words */
                box-sizing: border-box;
                border-radius: 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            h1 {
                text-align: center;
                margin-top: 0;
                color: #333333;
            }
            /* Enhanced styling for different log levels */
            .log-DEBUG { 
                color: #6c757d; /* Dark Gray */
            }
            .log-INFO { 
                color: #212529; /* Very Dark Gray */
            }
            .log-WARNING { 
                color: #fd7e14; /* Orange */
                background-color: #fff3cd; /* Light Yellow Background */
                padding: 2px 4px;
                border-radius: 3px;
            }
            .log-ERROR { 
                color: #dc3545; /* Red */
                background-color: #f8d7da; /* Light Red Background */
                padding: 2px 4px;
                border-radius: 3px;
                font-weight: bold;
            }
            .log-CRITICAL { 
                color: #c82333; /* Darker Red */
                background-color: #f5c6cb; /* Slightly Darker Light Red */
                padding: 2px 4px;
                border-radius: 3px;
                font-weight: bold;
            }
            .log-ALERT { 
                color: #e83e8c; /* Pinkish */
                background-color: #f8d7da; /* Light Red Background */
                padding: 2px 4px;
                border-radius: 3px;
                font-weight: bold;
            }
            .log-EMERGENCY { 
                color: #6610f2; /* Purple */
                background-color: #e2e3e5; /* Light Gray Background */
                padding: 2px 4px;
                border-radius: 3px;
                font-weight: bold;
            }
        </style>
        <script>
            window.onload = function() {
                var logContainer = document.getElementById('logContent');

                <?php if ($hasErrors): ?>
                    // If there are error lines, scroll to the last error
                    var errorLines = document.getElementsByClassName('log-ERROR');
                    if (errorLines.length > 0) {
                        var lastError = errorLines[errorLines.length - 1];
                        lastError.scrollIntoView({ behavior: 'smooth', block: 'end' });
                    } else {
                        // If no ERROR level but other higher levels
                        logContainer.scrollTop = logContainer.scrollHeight;
                    }
                <?php else: ?>
                    // Otherwise, scroll to the bottom
                    logContainer.scrollTop = logContainer.scrollHeight;
                <?php endif; ?>
            };
        </script>
    </head>
    <body>
        <h1>App Log - Last <?php echo $linesToDisplay; ?> Lines</h1>
        <div class="log-container" id="logContent">
    <?php
    foreach ($processedLogLines as $entry) {
        $cssClass = 'log-' . $entry['level'];
        // Ensure that only predefined classes are used to prevent CSS injection
        $allowedClasses = ['log-DEBUG', 'log-INFO', 'log-WARNING', 'log-ERROR', 'log-CRITICAL', 'log-ALERT', 'log-EMERGENCY'];
        if (!in_array($cssClass, $allowedClasses)) {
            $cssClass = 'log-INFO'; // Default to INFO if unknown level
        }
        echo '<span class="' . $cssClass . '">' . $entry['line'] . '</span>';
    }
    ?>
        </div>
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
        <!-- Auto-refresh the 404 page every 5 seconds -->
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
                margin: 0;
            }
            .error-container {
                border: 1px solid #f5c6cb;
                background-color: #f8d7da;
                padding: 30px;
                border-radius: 5px;
                max-width: 600px;
                text-align: center;
                box-sizing: border-box;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            h1 {
                margin-bottom: 10px;
                color: #721c24;
            }
            p {
                margin: 5px 0;
                color: #721c24;
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
