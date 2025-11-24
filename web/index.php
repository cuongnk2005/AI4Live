<?php
// Simple PHP frontend to run gemini_lesson.py and show result
// Ensure PHP 7.4+ and Python packages installed: youtube-transcript-api, google-generativeai

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
set_time_limit(900); // allow longer processing for ASR/transcript generation

$config = require __DIR__ . '/config.php';
$PYTHON = $config['python'] ?? 'python';
$DEFAULT_LANG = $config['default_language'] ?? 'vi';
$GEMINI_KEY = $config['gemini_api_key'] ?? '';

$repoRoot = realpath(__DIR__ . '/..');
$scriptPath = $repoRoot . DIRECTORY_SEPARATOR . 'gemini_lesson.py';

$url = isset($_POST['url']) ? trim($_POST['url']) : '';
$lang = isset($_POST['language']) ? trim($_POST['language']) : $DEFAULT_LANG;
$output = '';
$error = '';
$exitCode = null;
$debugInfo = '';

// Debug: Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $debugInfo = "Form submitted! POST data received.";
}

// Process request on any POST (works with AJAX, no need for submit field)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debugInfo .= "\nChecking URL...";
    
    if ($url === '') {
        $error = 'Vui lòng nhập URL YouTube.';
    } elseif (!file_exists($scriptPath)) {
        $error = 'Không tìm thấy gemini_lesson.py tại: ' . $scriptPath;
    } else {
        $debugInfo .= "\nScript found!";
        
        // Helper: extract video ID
        $videoId = '';
        if (preg_match('~([a-zA-Z0-9_-]{11})~', $url, $m)) {
          $videoId = $m[1];
        }

        // Try to prefetch transcript via Python helper script (bypass PHP subprocess network issues)
        $transcriptJsonPath = '';
        if ($videoId !== '') {
          $tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
          if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
          
          $transcriptFile = $tmpDir . DIRECTORY_SEPARATOR . 'transcript_' . $videoId . '_' . $lang . '.json';
          $fetchScript = $repoRoot . DIRECTORY_SEPARATOR . 'fetch_transcript.py';
          
          if (file_exists($fetchScript)) {
            $debugInfo .= "\nFetching transcript using Python helper...";
            
            // Run Python fetch_transcript.py in a separate process
            $fetchCmd = escapeshellarg($PYTHON) . ' ' . escapeshellarg($fetchScript) . ' ' . 
                        escapeshellarg($videoId) . ' ' . escapeshellarg($lang) . ' ' . 
                        escapeshellarg($transcriptFile);
            
            $descriptorspec = [
              0 => ['pipe', 'r'],
              1 => ['pipe', 'w'],
              2 => ['pipe', 'w'],
            ];
            
            $proc = proc_open($fetchCmd, $descriptorspec, $pipes, $repoRoot);
            if (is_resource($proc)) {
              fclose($pipes[0]);
              $fetchOut = stream_get_contents($pipes[1]);
              fclose($pipes[1]);
              $fetchErr = stream_get_contents($pipes[2]);
              fclose($pipes[2]);
              $fetchExit = proc_close($proc);
              
              if ($fetchExit === 0 && file_exists($transcriptFile)) {
                $transcriptJsonPath = $transcriptFile;
                $debugInfo .= "\n✅ Transcript fetched successfully via Python helper!";
              } else {
                $debugInfo .= "\n❌ Transcript fetch failed. Exit: $fetchExit";
                if ($fetchErr) {
                  $debugInfo .= "\nError: " . substr($fetchErr, 0, 200);
                }
              }
            } else {
              $debugInfo .= "\n❌ Failed to run Python helper script";
            }
          } else {
            $debugInfo .= "\n⚠️ fetch_transcript.py not found";
          }
        }

        // Build base args
        $outputFile = $repoRoot . DIRECTORY_SEPARATOR . 'lesson_output.md';
        $baseArgs = [
          escapeshellarg($scriptPath),
          '--url ' . escapeshellarg($url),
          '--language ' . escapeshellarg($lang),
          '--output ' . escapeshellarg($outputFile),
        ];
        if ($transcriptJsonPath !== '') {
          $baseArgs[] = '--transcript-json ' . escapeshellarg($transcriptJsonPath);
        }
        if (!empty($GEMINI_KEY)) {
          $baseArgs[] = '--api-key ' . escapeshellarg($GEMINI_KEY);
        }

        // Define attempts: configured python, Windows py launcher, Windows py -3
        $attemptDefs = [
          ['exe' => escapeshellarg($PYTHON), 'label' => 'configured'],
          ['exe' => 'py', 'label' => 'py'],
          ['exe' => 'py -3', 'label' => 'py -3'],
        ];

        $attemptLogs = [];
        $succeeded = false;
        $output = '';
        $exitCode = null;
        $command = '';

        foreach ($attemptDefs as $def) {
          $pythonExe = $def['exe'];
          $label = $def['label'];

          // Assemble command
          $cmdParts = array_merge([$pythonExe], $baseArgs);
          $command = implode(' ', $cmdParts);

          $debugInfo .= "\nAttempt with: $label";
          $debugInfo .= "\nCommand: $command";
          $debugInfo .= "\nExecuting Python...";

          // Prepare process
          $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
          ];
          
          // Create clean environment for Python subprocess to avoid network conflicts
          $env = [
            'PYTHONIOENCODING' => 'utf-8',
            'PYTHONDONTWRITEBYTECODE' => '1',
            'DISABLE_SOCKET_PATCH' => '1',
            'FORCE_GEMINI_REST' => '1',
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
            'USERPROFILE' => getenv('USERPROFILE'),
            'APPDATA' => getenv('APPDATA'),
            'LOCALAPPDATA' => getenv('LOCALAPPDATA'),
          ];
          
          // Ensure Python can see site-packages even when running under PHP
          $pythonExePath = $PYTHON; // unescaped path from config
          $pythonHome = dirname($pythonExePath);
          $systemSite = $pythonHome . DIRECTORY_SEPARATOR . 'Lib' . DIRECTORY_SEPARATOR . 'site-packages';
          $userSite = '';
          if (preg_match('/Python(\d{3})/i', $pythonExePath, $m)) {
            $pyVerDigits = $m[1]; // e.g., 313
            $appData = getenv('APPDATA'); // e.g., C:\\Users\\<User>\\AppData\\Roaming
            if ($appData) {
              $userSite = $appData . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'Python' . $pyVerDigits . DIRECTORY_SEPARATOR . 'site-packages';
            }
          }
          $paths = [];
          if (is_dir($systemSite)) { $paths[] = $systemSite; }
          if ($userSite && is_dir($userSite)) { $paths[] = $userSite; }
          if (!empty($paths)) {
            $env['PYTHONPATH'] = implode(PATH_SEPARATOR, $paths);
          }
          
          // Thêm thư mục Python vào PATH để đảm bảo DLL loading
          $pythonDir = dirname($pythonExePath);
          $systemPath = getenv('PATH') ?: 'C:\\Windows\\System32;C:\\Windows';
          $env['PATH'] = $pythonDir . PATH_SEPARATOR . $systemPath;

          $proc = proc_open($command, $descriptorspec, $pipes, $repoRoot, $env);
          if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $attemptLogs[] = [
              'label' => $label,
              'exit' => $exitCode,
              'stdout_len' => strlen($stdout),
              'stderr_len' => strlen($stderr),
              'stdout' => $stdout,
              'stderr' => $stderr,
            ];

            $debugInfo .= "\nExit code: $exitCode";
            $debugInfo .= "\nStdout length: " . strlen($stdout);
            $debugInfo .= "\nStderr length: " . strlen($stderr);

            if ($exitCode === 0 && file_exists($outputFile)) {
              $output = file_get_contents($outputFile);
              $succeeded = true;
              break;
            }

            // Heuristic: if python not found, try next attempt
            if (stripos($stderr, 'is not recognized') !== false || stripos($stderr, 'not found') !== false) {
              $debugInfo .= "\nPython not found for attempt '$label', trying next...";
              continue;
            }
          } else {
            $attemptLogs[] = [
              'label' => $label,
              'exit' => null,
              'stdout_len' => 0,
              'stderr_len' => 0,
              'stderr' => 'proc_open failed',
            ];
            $debugInfo .= "\nproc_open failed for attempt '$label'";
          }
        }

        if (!$succeeded) {
          // Build helpful error message
          $msg = "Không thể chạy Python.\n\nCác lần thử:\n";
          foreach ($attemptLogs as $log) {
            $msg .= "- " . $log['label'] . ": exit=" . var_export($log['exit'], true) . ", stdout_len=" . $log['stdout_len'] . ", stderr_len=" . $log['stderr_len'] . "\n";
            if (!empty($log['stdout'])) {
              $msg .= "  stdout: " . $log['stdout'] . "\n";
            }
            if (!empty($log['stderr'])) {
              $msg .= "  stderr: " . $log['stderr'] . "\n";
            }
          }
          $msg .= "\nCách sửa: Mở file web/config.php và đặt 'python' = đường dẫn đầy đủ tới python.exe.\nVí dụ: C:\\Users\\<User>\\AppData\\Local\\Programs\\Python\\Python312\\python.exe\nHoặc đặt biến môi trường hệ thống PYTHON_EXE trỏ tới python.exe.\nBạn cũng có thể cài Python Launcher và để trống để dùng 'py'/'py -3'.";
          $error = $msg;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tạo bài học từ YouTube</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { 
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #343541;
      color: #ececf1;
      display: flex;
      height: 100vh;
      overflow: hidden;
    }
    
    /* Sidebar - rộng hơn giống ChatGPT */
    .sidebar {
      width: 280px;
      background: #202123;
      color: #ececf1;
      display: flex;
      flex-direction: column;
      border-right: 1px solid #2e2f34;
    }
    
    .sidebar-header {
      padding: 12px;
      border-bottom: 1px solid #2e2f34;
    }
    
    .btn-new-lesson {
      width: 100%;
      padding: 14px 16px;
      background: transparent;
      border: 1px solid #4d4d4f;
      color: #ececf1;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      transition: all 0.2s;
      font-weight: 500;
    }
    
    .btn-new-lesson:hover {
      background: #2a2b32;
      border-color: #565869;
    }
    
    .sidebar-search {
      padding: 8px 12px;
      border-bottom: 1px solid #2e2f34;
    }
    
    .search-input {
      width: 100%;
      padding: 10px 12px;
      background: #40414f;
      border: 1px solid #565869;
      border-radius: 6px;
      color: #ececf1;
      font-size: 13px;
      outline: none;
    }
    
    .search-input:focus {
      border-color: #8e8ea0;
    }
    
    .sidebar-lessons {
      flex: 1;
      overflow-y: auto;
      padding: 4px 0;
    }
    
    .sidebar-lessons::-webkit-scrollbar {
      width: 6px;
    }
    
    .sidebar-lessons::-webkit-scrollbar-thumb {
      background: #565869;
      border-radius: 3px;
    }
    
    .lesson-item {
      padding: 12px 12px;
      margin: 2px 8px;
      cursor: pointer;
      transition: all 0.2s;
      border-radius: 6px;
      font-size: 14px;
      color: #ececf1;
      position: relative;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .lesson-item:hover {
      background: #2a2b32;
    }
    
    .lesson-item.active {
      background: #343541;
    }
    
    .lesson-icon {
      font-size: 16px;
      min-width: 20px;
    }
    
    .lesson-content {
      flex: 1;
      overflow: hidden;
    }
    
    .lesson-title {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .lesson-date {
      font-size: 11px;
      color: #8e8ea0;
      margin-top: 3px;
    }
    
    .no-lessons {
      padding: 20px 16px;
      text-align: center;
      color: #8e8ea0;
      font-size: 13px;
      line-height: 1.6;
    }
    
    /* Main Content Area - giống ChatGPT */
    .main-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: #343541;
    }
    
    .chat-container {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      position: relative; /* For absolute positioning of popup */
    }
    
    .chat-container::-webkit-scrollbar {
      width: 8px;
    }
    
    .chat-container::-webkit-scrollbar-thumb {
      background: #565869;
      border-radius: 4px;
    }
    
    .messages-area {
      flex: 1;
      max-width: 900px;
      width: 100%;
      margin: 0 auto;
      padding: 24px;
    }
    
    /* Empty state */
    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      text-align: center;
      color: #8e8ea0;
    }
    
    .empty-state h1 {
      font-size: 32px;
      margin-bottom: 16px;
      color: #ececf1;
    }
    
    .empty-state p {
      font-size: 16px;
      margin-bottom: 32px;
    }
    
    /* Chat Messages - giống ChatGPT */
    .message {
      margin-bottom: 24px;
      animation: fadeIn 0.3s ease-in;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .message-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }
    
    .message-avatar {
      width: 32px;
      height: 32px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      font-weight: 600;
      flex-shrink: 0;
    }
    
    .user-avatar {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }
    
    .ai-avatar {
      background: #10a37f;
      color: white;
    }
    
    .message-role {
      font-weight: 600;
      font-size: 14px;
      color: #ececf1;
    }
    
    .message-content {
      margin-left: 44px;
      padding: 16px;
      background: #444654;
      border-radius: 8px;
      line-height: 1.7;
      font-size: 15px;
      color: #ececf1;
    }
    
    .user-message .message-content {
      background: #343541;
      border: 1px solid #565869;
    }
    
    .message-content h1,
    .message-content h2,
    .message-content h3 {
      margin-top: 16px;
      margin-bottom: 12px;
      color: #fff;
    }
    
    .message-content h1 { font-size: 24px; }
    .message-content h2 { font-size: 20px; }
    .message-content h3 { font-size: 18px; }
    
    .message-content ul,
    .message-content ol {
      margin-left: 24px;
      margin-top: 8px;
      margin-bottom: 8px;
    }
    
    .message-content li {
      margin-bottom: 6px;
    }
    
    .message-content p {
      margin-bottom: 12px;
    }
    
    .message-content code {
      background: #2d2d2d;
      padding: 2px 6px;
      border-radius: 3px;
      font-family: 'Courier New', monospace;
      font-size: 13px;
    }
    
    .message-content pre {
      background: #2d2d2d;
      padding: 12px;
      border-radius: 6px;
      overflow-x: auto;
      margin: 12px 0;
    }
    
    .message-actions {
      margin-left: 44px;
      margin-top: 8px;
      display: flex;
      gap: 12px;
    }
    
    .action-btn {
      padding: 6px 12px;
      background: transparent;
      border: 1px solid #565869;
      color: #ececf1;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    
    .action-btn:hover {
      background: #40414f;
      border-color: #8e8ea0;
    }
    
    /* Input Area - cố định ở dưới cùng */
    .input-area {
      border-top: 1px solid #2e2f34;
      background: #343541;
      padding: 16px;
    }
    
    .input-container {
      max-width: 900px;
      margin: 0 auto;
      background: #40414f;
      border: 1px solid #565869;
      border-radius: 12px;
      padding: 12px 16px;
      display: flex;
      align-items: flex-end;
      gap: 12px;
      transition: all 0.2s;
    }
    
    .input-container:focus-within {
      border-color: #8e8ea0;
      box-shadow: 0 0 0 2px rgba(142, 142, 160, 0.2);
    }
    
    .input-wrapper {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    
    .input-row {
      display: flex;
      gap: 8px;
      align-items: center;
    }
    
    #url {
      flex: 1;
      background: transparent;
      border: none;
      color: #ececf1;
      font-size: 15px;
      outline: none;
      padding: 4px 0;
      font-family: inherit;
    }
    
    #url::placeholder {
      color: #8e8ea0;
    }
    
    #language {
      background: #2d2d2d;
      border: 1px solid #565869;
      color: #ececf1;
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 13px;
      outline: none;
      cursor: pointer;
    }
    
    .submit-btn {
      width: 40px;
      height: 40px;
      background: #10a37f;
      border: none;
      border-radius: 8px;
      color: white;
      font-size: 18px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    
    .submit-btn:hover:not(:disabled) {
      background: #0d8c6a;
    }
    
    .submit-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    
    /* Helper text */
    .input-helper {
      font-size: 11px;
      color: #8e8ea0;
      text-align: center;
      margin-top: 8px;
    }
    
    /* Loading Spinner */
    .loading-overlay { 
      display: none; 
      position: fixed; 
      top: 0; 
      left: 280px;
      right: 0; 
      bottom: 0; 
      background: rgba(0,0,0,0.85); 
      z-index: 9999; 
      align-items: center; 
      justify-content: center;
      flex-direction: column;
    }
    .loading-overlay.show { display: flex; }
    .spinner { 
      width: 60px; 
      height: 60px; 
      border: 6px solid #444654; 
      border-top: 6px solid #10a37f; 
      border-radius: 50%; 
      animation: spin 1s linear infinite; 
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .loading-text { 
      color: #ececf1; 
      margin-top: 20px; 
      font-size: 16px; 
      font-weight: 600; 
    }
    .loading-subtext {
      color: #8e8ea0;
      margin-top: 8px;
      font-size: 13px;
    }
    
    /* Inline Chat Popup */
    .inline-chat-popup {
      position: absolute;
      display: none;
      background: #2d2d2d;
      border: 1px solid #565869;
      border-radius: 8px;
      padding: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.5);
      z-index: 10000;
      min-width: 320px;
      max-width: 400px;
      animation: popupFadeIn 0.2s ease;
    }
    
    @keyframes popupFadeIn {
      from { opacity: 0; transform: translateY(-5px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .inline-chat-popup.show {
      display: block;
    }
    
    .popup-header {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 10px;
      padding-bottom: 8px;
      border-bottom: 1px solid #565869;
    }
    
    .popup-title {
      flex: 1;
      font-size: 13px;
      font-weight: 600;
      color: #10a37f;
    }
    
    .popup-close {
      background: transparent;
      border: none;
      color: #8e8ea0;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 16px;
      line-height: 1;
    }
    
    .popup-close:hover {
      background: #40414f;
      color: #ececf1;
    }
    
    .popup-context {
      background: #1a1a1a;
      padding: 8px 10px;
      border-radius: 6px;
      margin-bottom: 10px;
      max-height: 80px;
      overflow-y: auto;
      font-size: 12px;
      color: #8e8ea0;
      line-height: 1.4;
    }
    
    .popup-context::-webkit-scrollbar {
      width: 4px;
    }
    
    .popup-context::-webkit-scrollbar-thumb {
      background: #565869;
      border-radius: 2px;
    }
    
    .popup-input-row {
      display: flex;
      gap: 8px;
      align-items: flex-start;
    }
    
    .popup-input {
      flex: 1;
      background: #40414f;
      border: 1px solid #565869;
      border-radius: 6px;
      padding: 8px 10px;
      color: #ececf1;
      font-size: 13px;
      outline: none;
      resize: none;
      min-height: 36px;
      max-height: 100px;
      font-family: inherit;
    }
    
    .popup-input:focus {
      border-color: #10a37f;
    }
    
    .popup-send-btn {
      background: #10a37f;
      border: none;
      color: white;
      padding: 8px 12px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
      white-space: nowrap;
      transition: all 0.2s;
    }
    
    .popup-send-btn:hover:not(:disabled) {
      background: #0d8c6a;
    }
    
    .popup-send-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    
    .popup-answer {
      margin-top: 10px;
      padding: 10px;
      background: #1a1a1a;
      border-radius: 6px;
      border-left: 3px solid #10a37f;
      display: none;
      animation: answerFadeIn 0.3s ease;
    }
    
    @keyframes answerFadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .popup-answer.show {
      display: block;
    }
    
    .popup-answer-label {
      font-size: 11px;
      color: #10a37f;
      font-weight: 600;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    
    .popup-answer-text {
      font-size: 13px;
      color: #ececf1;
      line-height: 1.6;
    }
    
    .popup-loading {
      display: none;
      text-align: center;
      padding: 10px;
      color: #8e8ea0;
      font-size: 12px;
    }
    
    .popup-loading.show {
      display: block;
    }
    
    .popup-error {
      display: none;
      background: #7f1d1d;
      color: #fca5a5;
      padding: 8px 10px;
      border-radius: 6px;
      font-size: 12px;
      margin-top: 8px;
    }
    
    .popup-error.show {
      display: block;
    }
    
    /* Text selection highlight */
    ::selection {
      background: #10a37f40;
      color: #ececf1;
    }
  </style>
</head>
<body>
  <!-- Sidebar -->
  <div class="sidebar">
    <div class="sidebar-header">
      <button class="btn-new-lesson" onclick="newLesson()">
        <span>➕</span>
        <span>Bài học mới</span>
      </button>
    </div>
    
    <div class="sidebar-search">
      <input type="text" class="search-input" placeholder="🔍 Tìm kiếm..." id="searchInput" onkeyup="searchLessons()">
    </div>
    
    <div class="sidebar-lessons" id="lessonsList">
      <div class="no-lessons">
        Chua co bai hoc<br><br>
        <button class="btn-new-lesson" onclick="loadLessons()" style="margin: 0; font-size: 13px; padding: 10px 14px;">
          <span>&#8635;</span>
          <span>Tai danh sach</span>
        </button>
      </div>
    </div>
    </div>
  </div>

  <!-- Loading Overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">🤖 Đang tạo bài học...</div>
    <div class="loading-subtext">Quá trình này có thể mất 30-90 giây. Vui lòng chờ...</div>
  </div>
  
  <!-- Main Content -->
  <div class="main-content">
    <div class="chat-container" id="chatContainer">
      <!-- Inline Chat Popup -->
      <div class="inline-chat-popup" id="inlineChatPopup">
        <div class="popup-header">
          <div class="popup-title">💬 Hỏi AI</div>
          <button class="popup-close" onclick="closeInlineChat()">✕</button>
        </div>
        <div class="popup-context" id="popupContext"></div>
        <div class="popup-input-row">
          <textarea 
            class="popup-input" 
            id="popupInput" 
            placeholder="Đặt câu hỏi về đoạn text này..."
            rows="1"
            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();askInlineQuestion();}"
          ></textarea>
          <button class="popup-send-btn" id="popupSendBtn" onclick="askInlineQuestion()">
            ▶ Gửi
          </button>
        </div>
        <div class="popup-loading" id="popupLoading">⏳ Đang hỏi AI...</div>
        <div class="popup-error" id="popupError"></div>
        <div class="popup-answer" id="popupAnswer">
          <div class="popup-answer-label">🤖 AI trả lời:</div>
          <div class="popup-answer-text" id="popupAnswerText"></div>
        </div>
      </div>
      
      <div class="messages-area" id="messagesArea">
        <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <!-- Empty State -->
        <div class="empty-state">
          <h1>📚 AI4Live - Học từ YouTube</h1>
          <p>Dán link YouTube vào ô bên dưới để tạo bài học với AI</p>
        </div>
        <?php else: ?>
          <?php if ($debugInfo !== ''): ?>
            <div class="message">
              <div class="message-header">
                <div class="message-avatar" style="background: #f59e0b;">⚙️</div>
                <div class="message-role">System Debug</div>
              </div>
              <div class="message-content" style="background: #2d2d2d; font-family: monospace; font-size: 12px;">
                <?= nl2br(htmlspecialchars($debugInfo, ENT_QUOTES, 'UTF-8')) ?>
              </div>
            </div>
          <?php endif; ?>
          
          <!-- User Message -->
          <div class="message user-message">
            <div class="message-header">
              <div class="message-avatar user-avatar">👤</div>
              <div class="message-role">Bạn</div>
            </div>
            <div class="message-content">
              📹 <strong>YouTube:</strong> <?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?><br>
              🌐 <strong>Ngôn ngữ:</strong> <?= $lang === 'vi' ? 'Tiếng Việt 🇻🇳' : 'English 🇺🇸' ?>
            </div>
            <textarea id="rawMarkdown" style="display:none;"><?= htmlspecialchars($output, ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          
          <?php if ($error !== ''): ?>
          <!-- Error Message -->
          <div class="message">
            <div class="message-header">
              <div class="message-avatar" style="background: #ef4444;">❌</div>
              <div class="message-role">Lỗi</div>
            </div>
            <div class="message-content" style="background: #7f1d1d; border-left: 4px solid #ef4444;">
              <pre style="white-space: pre-wrap; font-family: inherit; margin: 0;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></pre>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if ($output !== ''): ?>
          <!-- AI Response -->
          <div class="message">
            <div class="message-header">
              <div class="message-avatar ai-avatar">🤖</div>
              <div class="message-role"> AI</div>
            </div>
            <div class="message-content" id="aiResponse">
              <?php
                // Markdown renderer with proper bold handling (preserves <strong>)
                $md = $output;
                $lines = preg_split('/\r?\n/', $md);
                $htmlParts = [];
                $inUL = false; $inOL = false; $paraLines = [];

                $renderBold = function($text) {
                  // First escape HTML
                  $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
                  // Then replace **text** with <strong>text</strong>
                  return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
                };

                $flushPara = function() use (&$paraLines, &$htmlParts) {
                  if (!empty($paraLines)) {
                    $htmlParts[] = '<p>' . implode(' ', $paraLines) . '</p>';
                    $paraLines = [];
                  }
                };
                $closeLists = function() use (&$inUL, &$inOL, &$htmlParts) {
                  if ($inUL) { $htmlParts[] = '</ul>'; $inUL = false; }
                  if ($inOL) { $htmlParts[] = '</ol>'; $inOL = false; }
                };

                foreach ($lines as $line) {
                  $trim = rtrim($line);
                  if ($trim === '') { $flushPara(); $closeLists(); continue; }

                  // Headings (support bold inside)
                  if (preg_match('/^###\s+(.+)/', $trim, $m)) { $flushPara(); $closeLists(); $htmlParts[] = '<h3>' . $renderBold($m[1]) . '</h3>'; continue; }
                  if (preg_match('/^##\s+(.+)/', $trim, $m)) { $flushPara(); $closeLists(); $htmlParts[] = '<h2>' . $renderBold($m[1]) . '</h2>'; continue; }
                  if (preg_match('/^#\s+(.+)/', $trim, $m))  { $flushPara(); $closeLists(); $htmlParts[] = '<h1>' . $renderBold($m[1]) . '</h1>'; continue; }

                  // Unordered list item
                  if (preg_match('/^[\-*+]\s+(.*)/', $trim, $m)) {
                    $flushPara();
                    if ($inOL) { $htmlParts[] = '</ol>'; $inOL = false; }
                    if (!$inUL) { $htmlParts[] = '<ul>'; $inUL = true; }
                    $htmlParts[] = '<li>' . $renderBold($m[1]) . '</li>';
                    continue;
                  }

                  // Ordered list item
                  if (preg_match('/^(\d+)\.\s+(.*)/', $trim, $m)) {
                    $flushPara();
                    if ($inUL) { $htmlParts[] = '</ul>'; $inUL = false; }
                    if (!$inOL) { $htmlParts[] = '<ol>'; $inOL = true; }
                    $htmlParts[] = '<li>' . $renderBold($m[2]) . '</li>';
                    continue;
                  }

                  // Paragraph line
                  $paraLines[] = $renderBold($trim);
                }

                $flushPara();
                $closeLists();
                echo implode("\n", $htmlParts);
              ?>
            </div>
            <div class="message-actions">
              <button class="action-btn" onclick="copyToClipboard()">
                📋 Sao chép
              </button>
              <button class="action-btn" onclick="saveLessonLocal(this)">
                Luu bai hoc
              </button>
              <button class="action-btn" onclick="downloadMarkdown()">
                ⬇️ Tải xuống
              </button>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Input Area - Fixed at bottom -->
    <div class="input-area">
      <form method="post" action="" id="lessonForm">
        <div class="input-container">
          <div class="input-wrapper">
            <div class="input-row">
              <input 
                type="text" 
                id="url" 
                name="url" 
                placeholder="Dán link YouTube vào đây... (VD: https://www.youtube.com/watch?v=...)" 
                value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" 
                required 
                autocomplete="off"
              />
              <select id="language" name="language" title="Chọn ngôn ngữ">
                <option value="vi" <?= $lang === 'vi' ? 'selected' : '' ?>>🇻🇳 VI</option>
                <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>🇺🇸 EN</option>
              </select>
            </div>
          </div>
          <button type="submit" class="submit-btn" title="Tạo bài học">
            ▶
          </button>
        </div>
        <div class="input-helper">
          💡 Mẹo: Sử dụng video có phụ đề để có kết quả tốt nhất
        </div>
      </form>
    </div>
  </div>

  <script>
    let allLessons = [];
    let currentLessonContent = '';
    let lessonMarkdown = <?= json_encode($output) ?>;
    const computeHash = (text) => text ? `${text.length}:${text.slice(0, 32)}` : '';
    let lastSavedHash = computeHash(lessonMarkdown);

    function deriveLessonTitle() {
      const raw = lessonMarkdown || '';
      const lines = raw.split(/\r?\n/).map(l => l.trim());
      let heading = lines.find(l => l.startsWith('# '));
      if (heading) {
        return heading.replace(/^#+\s*/, '') || 'Bai hoc';
      }
      const firstLine = lines.find(l => l.length > 0);
      return firstLine || 'Bai hoc';
    }
    
    // Auto-scroll to bottom on page load if there's content
    window.addEventListener('load', function() {
      const chatContainer = document.getElementById('chatContainer');
      if (document.querySelector('.message')) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
      }
    });
    
    // Load lessons from local folder
    async function loadLessons() {
      const list = document.getElementById('lessonsList');
      list.innerHTML = '<div class="no-lessons">Dang tai...</div>';
      
      try {
        const response = await fetch('get_lessons.php');
        const text = await response.text();
        
        try {
          const lessons = JSON.parse(text);
          allLessons = Array.isArray(lessons) ? lessons : [];
          displayLessons(allLessons);
        } catch {
          list.innerHTML = '<div class=\"no-lessons\">Chua co bai hoc<br><br>Nhap link va luu bai hoc de bat dau</div>';
        }
      } catch (error) {
        list.innerHTML = '<div class=\"no-lessons\">Loi khi tai danh sach</div>';
      }
    }
    // Display lessons in sidebar
    function displayLessons(lessons) {
      const list = document.getElementById('lessonsList');
      
      if (lessons.length === 0) {
        list.innerHTML = "<div class=\"no-lessons\">Chua co bai hoc</div>";
        return;
      }
      
      list.innerHTML = lessons.map(lesson => `
        <div class="lesson-item" onclick="viewLesson('${lesson.link.replace(/'/g, "\\'")}')">
          <div class="lesson-icon">📄</div>
          <div class="lesson-content">
            <div class="lesson-title">${escapeHtml(lesson.title)}</div>
            <div class="lesson-date">${formatDate(lesson.modifiedTime)}</div>
          </div>
        </div>
      `).join('');
    }
    
    // Search lessons
    function searchLessons() {
      const query = document.getElementById('searchInput').value.toLowerCase();
      const filtered = allLessons.filter(lesson => 
        lesson.title.toLowerCase().includes(query)
      );
      displayLessons(filtered);
    }
    
    // Format date
    function formatDate(dateString) {
      const date = new Date(dateString);
      const now = new Date();
      const diff = now - date;
      const days = Math.floor(diff / (1000 * 60 * 60 * 24));
      
      if (days === 0) return 'Hôm nay';
      if (days === 1) return 'Hôm qua';
      if (days < 7) return days + ' ngày trước';
      if (days < 30) return Math.floor(days / 7) + ' tuần trước';
      return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
    }
    
    // Escape HTML
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Simple markdown renderer for saved lessons
    function renderMarkdown(md) {
      let html = md;
      html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
      html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
      html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
      html = html.replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>');
      html = html.replace(/^\* (.+)$/gm, '<li>$1</li>');
      html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
      html = html.replace(/\n\n/g, '</p><p>');
      html = '<p>' + html + '</p>';
      html = html.replace(/<p><h/g, '<h');
      html = html.replace(/<\/h1><\/p>/g, '</h1>');
      html = html.replace(/<\/h2><\/p>/g, '</h2>');
      html = html.replace(/<\/h3><\/p>/g, '</h3>');
      html = html.replace(/<p><ul>/g, '<ul>');
      html = html.replace(/<\/ul><\/p>/g, '</ul>');
      html = html.replace(/<p><\/p>/g, '');
      return html;
    }
    
    // View lesson
    function viewLesson(link) {
      fetch(link)
        .then(r => {
          if (!r.ok) throw new Error('Khong tai duoc bai hoc');
          return r.text();
        })
        .then(text => {
          lessonMarkdown = text;
          const messagesArea = document.getElementById('messagesArea');
          if (messagesArea) {
            messagesArea.innerHTML = `
              <div class="message">
                <div class="message-header">
                  <div class="message-avatar ai-avatar">AI</div>
                  <div class="message-role">Bai hoc da luu</div>
                </div>
                <div class="message-content">
                  ${renderMarkdown(escapeHtml(text))}
                </div>
              </div>
            `;
          }
        })
        .catch(err => alert('Loi khi tai bai hoc: ' + err.message));
    }
    
    // New lesson - reload page
    function newLesson() {
      window.location.href = window.location.pathname;
    }
    
    // Copy to clipboard
    function copyToClipboard() {
      const content = document.getElementById('aiResponse')?.textContent || '';
      navigator.clipboard.writeText(content).then(() => {
        alert('✅ Đã sao chép vào clipboard!');
      }).catch(() => {
        alert('❌ Không thể sao chép');
      });
    }
    
    // Download markdown
    function downloadMarkdown() {
      const content = lessonMarkdown || '';
      const blob = new Blob([content], { type: 'text/markdown' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'bai-hoc-' + Date.now() + '.md';
      a.click();
      URL.revokeObjectURL(url);
    }
    
    // Save lesson locally (manual button or auto)
    function saveLessonLocal(btn = null, silent = false) {
      if (!lessonMarkdown) {
        if (!silent) alert('Chua co noi dung bai hoc de luu.');
        return;
      }

      const currentHash = computeHash(lessonMarkdown);
      if (silent && currentHash === lastSavedHash) {
        return; // already saved this content
      }

      const button = btn || null;
      if (button) {
        button.disabled = true;
        button.textContent = 'Dang luu...';
      }

      const videoTitle = deriveLessonTitle();

      fetch('save_lesson.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'title=' + encodeURIComponent(videoTitle) + '&content=' + encodeURIComponent(lessonMarkdown)
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          lastSavedHash = currentHash;
          allLessons = Array.isArray(allLessons) ? allLessons : [];
          allLessons.unshift({ title: data.title, link: data.link, modifiedTime: data.modifiedTime });
          displayLessons(allLessons);
          if (!silent) alert('Da luu bai hoc vao may.');
        } else if (!silent) {
          alert('Loi: ' + data.error);
        }
      })
      .catch(err => { if (!silent) alert('Loi: ' + err.message); })
      .finally(() => {
        if (button) {
          button.disabled = false;
          button.textContent = 'Luu bai hoc';
        }
      });
    }

    // AJAX form submission
    const form = document.getElementById('lessonForm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    
    form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const formData = new FormData(form);
      const url = formData.get('url');
      const lang = formData.get('language');
      
      if (!url) {
        alert('⚠️ Vui lòng nhập link YouTube');
        return;
      }
      
      // Show loading
      loadingOverlay.classList.add('show');
      
      // Clear previous messages
      const messagesArea = document.getElementById('messagesArea');
      messagesArea.innerHTML = '';
      
      // Add user message immediately
      messagesArea.innerHTML = `
        <div class="message user-message">
          <div class="message-header">
            <div class="message-avatar user-avatar">👤</div>
            <div class="message-role">Bạn</div>
          </div>
          <div class="message-content">
            📹 <strong>YouTube:</strong> ${escapeHtml(url)}<br>
            🌐 <strong>Ngôn ngữ:</strong> ${lang === 'vi' ? 'Tiếng Việt 🇻🇳' : 'English 🇺🇸'}
          </div>
        </div>
        
        <div class="message">
          <div class="message-header">
            <div class="message-avatar ai-avatar">🤖</div>
            <div class="message-role">AI</div>
          </div>
          <div class="message-content">
            ⏳ Đang xử lý yêu cầu của bạn...<br>
            <small style="color: #8e8ea0;">Đang tải transcript từ YouTube...</small>
          </div>
        </div>
      `;
      
      // Auto-scroll
      const chatContainer = document.getElementById('chatContainer');
      chatContainer.scrollTop = chatContainer.scrollHeight;
      
      // Update loading text
      let seconds = 0;
      const interval = setInterval(() => {
        seconds += 10;
        const loadingText = document.querySelector('.loading-text');
        if (loadingText) {
          if (seconds < 30) {
            loadingText.textContent = '📥 Đang tải transcript từ YouTube...';
          } else if (seconds < 60) {
            loadingText.textContent = '🔍 Đang phân tích nội dung...';
          } else {
            loadingText.textContent = '✨ Đang tạo bài học với AI...';
          }
        }
      }, 10000);
      
      try {
        const response = await fetch('', {
          method: 'POST',
          body: formData
        });
        
        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Extract messages area
        const newMessages = doc.querySelector('.messages-area');
        if (newMessages) {
          messagesArea.innerHTML = newMessages.innerHTML;
        }

        const rawMd = doc.querySelector('#rawMarkdown');
        lessonMarkdown = rawMd ? (rawMd.textContent || '') : '';
        const newHash = computeHash(lessonMarkdown);
        if (lessonMarkdown && newHash !== lastSavedHash) {
          saveLessonLocal(null, true); // auto-save to history on success
        }
        
        // Auto-scroll to bottom
        chatContainer.scrollTop = chatContainer.scrollHeight;
        
      } catch (error) {
        messagesArea.innerHTML += `
          <div class="message">
            <div class="message-header">
              <div class="message-avatar" style="background: #ef4444;">❌</div>
              <div class="message-role">Lỗi</div>
            </div>
            <div class="message-content" style="background: #7f1d1d;">
              ❌ Không thể kết nối đến server: ${escapeHtml(error.message)}
            </div>
          </div>
        `;
      } finally {
        clearInterval(interval);
        loadingOverlay.classList.remove('show');
      }
    });
    
    // ========================================
    // INLINE CHAT FUNCTIONALITY
    // ========================================
    let selectedText = '';
    let selectionRange = null;
    
    // Handle text selection in AI response
    document.addEventListener('mouseup', function(e) {
      // Only work on AI response content
      const target = e.target.closest('.message-content');
      if (!target) return;
      
      const selection = window.getSelection();
      const text = selection.toString().trim();
      
      // Must select at least 5 characters
      if (text.length < 5) {
        closeInlineChat();
        return;
      }
      
      selectedText = text;
      selectionRange = selection.getRangeAt(0);
      
      // Position popup near selection
      const rect = selectionRange.getBoundingClientRect();
      const popup = document.getElementById('inlineChatPopup');
      const chatContainer = document.getElementById('chatContainer');
      
      // Get popup dimensions (measure it first)
      popup.style.display = 'block';
      popup.style.visibility = 'hidden';
      const popupWidth = popup.offsetWidth || 320;
      const popupHeight = popup.offsetHeight || 300;
      popup.style.display = '';
      popup.style.visibility = '';
      
      // Calculate viewport and scroll info
      const viewportHeight = window.innerHeight;
      const viewportWidth = window.innerWidth;
      const scrollY = chatContainer.scrollTop;
      const containerRect = chatContainer.getBoundingClientRect();
      
      // Calculate position relative to chat container
      const selectionTop = rect.top - containerRect.top + scrollY;
      const selectionBottom = rect.bottom - containerRect.top + scrollY;
      const selectionLeft = rect.left - containerRect.left;
      const selectionCenter = selectionLeft + (rect.width / 2);
      
      // Default: show below selection
      let top = selectionBottom + 10;
      let left = selectionCenter - (popupWidth / 2);
      
      // Check if popup fits below selection
      const spaceBelow = viewportHeight - rect.bottom;
      const spaceAbove = rect.top - containerRect.top;
      
      if (spaceBelow < popupHeight + 20 && spaceAbove > spaceBelow) {
        // Show above if more space above
        top = selectionTop - popupHeight - 10;
      }
      
      // Keep popup within horizontal bounds (relative to container)
      const containerWidth = chatContainer.offsetWidth;
      if (left < 10) {
        left = 10;
      }
      if (left + popupWidth > containerWidth - 10) {
        left = containerWidth - popupWidth - 10;
      }
      
      // Set position (absolute within chat container)
      popup.style.top = top + 'px';
      popup.style.left = left + 'px';
      popup.classList.add('show');
      
      // Display selected text in context area
      const contextDiv = document.getElementById('popupContext');
      contextDiv.textContent = selectedText;
      
      // Reset popup state
      document.getElementById('popupInput').value = '';
      document.getElementById('popupAnswer').classList.remove('show');
      document.getElementById('popupError').classList.remove('show');
      document.getElementById('popupLoading').classList.remove('show');
      
      // Focus input
      setTimeout(() => {
        document.getElementById('popupInput').focus();
      }, 100);
    });
    
    // Close popup when clicking outside
    document.addEventListener('mousedown', function(e) {
      const popup = document.getElementById('inlineChatPopup');
      if (!popup.contains(e.target) && popup.classList.contains('show')) {
        closeInlineChat();
      }
    });
    
    // Close inline chat
    function closeInlineChat() {
      const popup = document.getElementById('inlineChatPopup');
      popup.classList.remove('show');
      selectedText = '';
      selectionRange = null;
      
      // Clear selection
      if (window.getSelection) {
        window.getSelection().removeAllRanges();
      }
    }
    
    // Ask inline question to Gemini
    async function askInlineQuestion() {
      const question = document.getElementById('popupInput').value.trim();
      
      if (!question) {
        alert('⚠️ Vui lòng nhập câu hỏi');
        return;
      }
      
      if (!selectedText) {
        alert('⚠️ Không có văn bản được chọn');
        return;
      }
      
      // Disable button and show loading
      const sendBtn = document.getElementById('popupSendBtn');
      const loading = document.getElementById('popupLoading');
      const errorDiv = document.getElementById('popupError');
      const answerDiv = document.getElementById('popupAnswer');
      
      sendBtn.disabled = true;
      loading.classList.add('show');
      errorDiv.classList.remove('show');
      answerDiv.classList.remove('show');
      
      try {
        const response = await fetch('ask_gemini.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            context: selectedText,
            question: question
          })
        });
        
        const data = await response.json();
        
        if (data.error) {
          errorDiv.textContent = '❌ ' + data.error;
          errorDiv.classList.add('show');
        } else if (data.answer) {
          document.getElementById('popupAnswerText').textContent = data.answer;
          answerDiv.classList.add('show');
          
          // Clear input after successful answer
          document.getElementById('popupInput').value = '';
        }
        
      } catch (error) {
        errorDiv.textContent = '❌ Lỗi kết nối: ' + error.message;
        errorDiv.classList.add('show');
      } finally {
        loading.classList.remove('show');
        sendBtn.disabled = false;
      }
    }
    
    // Auto-resize textarea
    document.getElementById('popupInput')?.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
    
    // ========================================
    // END INLINE CHAT
    // ========================================
    
    // Auto-load lessons on page load
    if (document.getElementById('lessonsList').querySelector('.no-lessons')) {
      setTimeout(loadLessons, 500);
    }
  </script>
</body>
</html>

