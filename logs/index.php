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

// Function to get the last N lines of a file
function tailFile($filepath, $lines = 100) {
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

// Check if the request is for fetching log data
if (isset($_GET['action']) && $_GET['action'] === 'getLog') {
    // Send no-cache headers
    sendNoCacheHeaders();

    // Check if the log file exists and is readable
    if (file_exists($logFile) && is_readable($logFile)) {
        // Fetch the last 100 lines of the log file
        $logContent = tailFile($logFile, $linesToDisplay);
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
            }

            // Add the line with its level to the processed log lines
            $processedLogLines[] = [
                'line' => htmlspecialchars($line),
                'level' => $level,
                'isError' => $isError
            ];
        }

        // Prepare the JSON response
        $response = [
            'hasErrors' => $hasErrors,
            'logLines' => $processedLogLines
        ];

        // Return the JSON response
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        // Log file does not exist or is not readable
        http_response_code(404);
        echo json_encode(['error' => 'Log file not found']);
        exit;
    }
}

// If not an AJAX request, serve the HTML page
sendNoCacheHeaders();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>App Log Viewer</title>
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

        /* Style for the 404 error page */
        .error-wrapper {
            width: 100%;
            max-width: 600px;
            background-color: #f8d7da;
            padding: 30px;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            text-align: center;
        }

        .error-wrapper h1 {
            margin-bottom: 15px;
            color: #721c24;
            font-size: 1.5em;
        }

        .error-wrapper p {
            margin: 10px 0;
            color: #721c24;
            font-size: 1em;
        }
    </style>
    <script>
        // Function to fetch log data from the server
        async function fetchLog() {
            try {
                const response = await fetch('<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?action=getLog', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    cache: 'no-store' // Prevent caching
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();

                if (data.error) {
                    console.error('Error fetching log:', data.error);
                    return;
                }

                const logContainer = document.getElementById('logContent');
                logContainer.innerHTML = ''; // Clear existing content

                let hasErrors = false;

                data.logLines.forEach(entry => {
                    const span = document.createElement('span');
                    span.className = 'log-' + entry.level;
                    span.textContent = entry.line;
                    logContainer.appendChild(span);
                    logContainer.appendChild(document.createElement('br'));

                    if (entry.isError) {
                        hasErrors = true;
                    }
                });

                // Auto-scroll logic
                if (hasErrors) {
                    // Scroll to the last error
                    const errorElements = document.getElementsByClassName('log-ERROR');
                    if (errorElements.length > 0) {
                        const lastError = errorElements[errorElements.length - 1];
                        lastError.scrollIntoView({ behavior: 'smooth', block: 'end' });
                    }
                } else {
                    // Scroll to the bottom
                    logContainer.scrollTop = logContainer.scrollHeight;
                }

            } catch (error) {
                console.error('Failed to fetch log:', error);
            }
        }

        // Fetch log data every 5 seconds
        setInterval(fetchLog, 2500);

        // Fetch log data on initial page load
        window.onload = fetchLog;
    </script>
</head>
<body>
<?php
// If not an AJAX request, serve the HTML page
// Check if the request is not for fetching log data
if (!isset($_GET['action']) || $_GET['action'] !== 'getLog') {
    // Check if the log file exists and is readable
    if (file_exists($logFile) && is_readable($logFile)) {
        ?>
        <div class="log-wrapper">
            <h1>App Log - Last <?php echo $linesToDisplay; ?> Lines</h1>
            <div class="log-container" id="logContent">
                <!-- Log entries will be injected here by JavaScript -->
            </div>
        </div>
        <?php
    } else {
        // Display custom 404 error page
        ?>
        <div class="error-wrapper">
            <h1>404 Not Found</h1>
            <p>The requested log file does not exist.</p>
            <p>This page will attempt to reload every 5 seconds.</p>
        </div>
        <?php
    }
}
?>
</body>
</html>
