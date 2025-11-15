@echo off
REM Chạy nhanh với cấu hình tối ưu
REM Usage: run_fast.bat <youtube_url>

setlocal enabledelayedexpansion

if "%~1"=="" (
    echo Usage: run_fast.bat "youtube_url"
    echo Example: run_fast.bat "https://www.youtube.com/watch?v=8Jx6gN7ZFKk"
    echo Note: Use quotes around the URL if it contains special characters like ^&
    exit /b 1
)

echo ========================================
echo Fast Summarization Mode
echo Model: distilbart (balanced speed/quality)
echo Mode: plain (simple summary)
echo ========================================
echo.

"C:\Users\KHAC CUONG\AppData\Local\Programs\Python\Python314\python.exe" quickstart.py --url "%~1" --combine --mode plain --chunk-words 250 --max-length 100 --min-length 20

echo.
echo ========================================
echo Done!
echo ========================================

endlocal
