@echo off
setlocal enabledelayedexpansion
REM Tóm tắt với độ dài tùy chỉnh
REM Usage: run_custom.bat "youtube_url" [target_words]
REM Example: run_custom.bat "https://youtube.com/..." 1000

if "%~1"=="" (
    echo Usage: run_custom.bat "youtube_url" [target_words]
    echo.
    echo Examples:
    echo   run_custom.bat "url" 1000   - Tóm tắt thành khoảng 1000 từ
    echo   run_custom.bat "url" 500    - Tóm tắt thành khoảng 500 từ
    echo   run_custom.bat "url"        - Mặc định: khoảng 40%% độ dài gốc
    exit /b 1
)

set URL=%~1
set TARGET_WORDS=%~2

if "%TARGET_WORDS%"=="" (
    set TARGET_WORDS=1000
    echo Sử dụng độ dài mặc định: ~1000 từ
)

echo ========================================
echo Custom Summary Mode
echo Target output: ~%TARGET_WORDS% words
echo ========================================
echo.

REM Tính toán tham số dựa trên target_words
REM Giả sử: 1 token ≈ 0.75 từ, mỗi chunk tạo ra 1 tóm tắt
REM Nếu target = 1000 từ, cần khoảng 4-5 chunks với max-length=300

set /a MAX_LENGTH=300
set /a MIN_LENGTH=100
set /a CHUNK_WORDS=500

if %TARGET_WORDS% LSS 500 (
    set /a MAX_LENGTH=150
    set /a MIN_LENGTH=50
    set /a CHUNK_WORDS=300
    set COMBINE_FLAG=--combine
) else if %TARGET_WORDS% LSS 1000 (
    set /a MAX_LENGTH=250
    set /a MIN_LENGTH=80
    set /a CHUNK_WORDS=400
    set COMBINE_FLAG=
) else (
    set /a MAX_LENGTH=350
    set /a MIN_LENGTH=120
    set /a CHUNK_WORDS=600
    set COMBINE_FLAG=
)

echo Parameters: chunk=%CHUNK_WORDS% words, max=%MAX_LENGTH% tokens, min=%MIN_LENGTH% tokens
echo.

"C:\Users\KHAC CUONG\AppData\Local\Programs\Python\Python314\python.exe" quickstart.py --url "%URL%" --mode plain --chunk-words %CHUNK_WORDS% --max-length %MAX_LENGTH% --min-length %MIN_LENGTH% %COMBINE_FLAG%

echo.
echo ========================================
echo Done!
echo ========================================

endlocal
