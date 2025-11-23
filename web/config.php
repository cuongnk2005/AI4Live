<?php
return [
    // Update this if your Python is elsewhere; falls back to env PYTHON_EXE in index.php.
    'python' => 'C:\\Python313\\python.exe',
    'default_language' => 'vi',
<<<<<<< HEAD
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: 'AIzaSyDgrWF9UqYd4pYMJBKdqrwTexM9vTycO0o',
=======
    // Leave empty to use GEMINI_API_KEY from environment or form input.
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
>>>>>>> fork/main
];
