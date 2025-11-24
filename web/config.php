<?php
return [
    // Update this if your Python is elsewhere; falls back to env PYTHON_EXE in index.php.
    'python' => 'C:\\Python313\\python.exe',
    'default_language' => 'vi',
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: 'AIzaSyAqGvgEt7ZBmjog8uoKCIdOQZNVa7aqqxI',

];
