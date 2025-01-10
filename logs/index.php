<?php
// Define the path to the log file
$logFile = 'app.log';

// Function to send no-cache headers
function sendNoCacheHeaders() {
    // HTTP/1.1
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    // HTTP/1.0
    header("Pragma: no-cache");
    // Proxies
    header("Expires: 0");
}

// Check if the log file exists and is readable
if (file_exists($logFile) && is_readable($logFile)) {
    // Send no-cache headers
    sendNoCacheHeaders();
    
    // Set the appropriate Content-Type header
    header('Content-Type: text/plain');

    // Optional: To improve performance for large files, you can read and output the file in chunks
    // Here's a simple way to output the entire file at once
    readfile($logFile);
    exit;
} else {
    // Send no-cache headers
    sendNoCacheHeaders();

    // Send a 404 Not Found header
    header("HTTP/1.1 404 Not Found");
    header('Content-Type: text/plain');

    // Optional: Display a custom 404 message
    echo "404 Not Found: The requested log file does not exist.";
    exit;
}
?>
