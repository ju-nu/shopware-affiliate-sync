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
            /* Reset default margins and paddings */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            html, body {
                height: 100%;
                width: 100%;
                overflow: hidden; /* Prevent page scrolling */
                font-family: 'Courier New', Courier, monospace;
                background-color: #ffffff; /* White background */
            }

            /* Flex container to center content */
            body {
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px; /* Space on the sides */
            }

            .log-wrapper {
                width: 100%;
                max-width: 1200px; /* Maximum width for large screens */
                height: 90vh; /* Occupies 90% of viewport height */
                background-color: #f9f9f9; /* Slightly off-white for contrast */
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                display: flex;
                flex-direction: column;
            }

            h1 {
                text-align: center;
                margin-bottom: 20px;
                color: #333333;
                font-size: 1.5em;
            }

            .log-container {
                flex: 1; /* Takes up remaining space */
                background-color: #ffffff;
                padding: 15px;
                border: 1px solid #ccc;
                border-radius: 5px;
                overflow-y: scroll; /* Scroll only this container */
                white-space: pre-wrap; /* Allows line wrapping */
                word-wrap: break-word; /* Break long words */
                font-size: 0.9em;
                line-height: 1.4;
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

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .log-wrapper {
                    padding: 15px;
                }
                h1 {
                    font-size: 1.2em;
                }
                .log-container {
                    font-size: 0.85em;
                }
            }

            @media (max-width: 480px) {
                .log-wrapper {
                    padding: 10px;
                }
                h1 {
                    font-size: 1em;
                }
                .log-container {
                    font-size: 0.8em;
                }
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
        <div class="log-wrapper">
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
            /* Reset default margins and paddings */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            html, body {
                height: 100%;
                width: 100%;
                overflow: hidden; /* Prevent page scrolling */
                font-family: Arial, sans-serif;
                background-color: #f8d7da;
            }

            /* Flex container to center content */
            body {
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px; /* Space on the sides */
            }

            .error-wrapper {
                width: 100%;
                max-width: 600px; /* Maximum width for large screens */
                background-color: #f8d7da;
                padding: 30px;
                border: 1px solid #f5c6cb;
                border-radius: 8px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                text-align: center;
            }

            h1 {
                margin-bottom: 15px;
                color: #721c24;
                font-size: 1.5em;
            }

            p {
                margin: 10px 0;
                color: #721c24;
                font-size: 1em;
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .error-wrapper {
                    padding: 20px;
                }
                h1 {
                    font-size: 1.2em;
                }
                p {
                    font-size: 0.9em;
                }
            }

            @media (max-width: 480px) {
                .error-wrapper {
                    padding: 15px;
                }
                h1 {
                    font-size: 1em;
                }
                p {
                    font-size: 0.85em;
                }
            }
        </style>
    </head>
    <body>
        <div class="error-wrapper">
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
