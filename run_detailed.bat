@echo off
setlocal enabledelayedexpansion
REM Tóm tắt chi tiết - giữ nhiều ý chính
REM Usage: run_detailed.bat "youtube_url"

if "%~1"=="" (
    echo Usage: run_detailed.bat "youtube_url"
    echo Example: run_detailed.bat "https://www.youtube.com/watch?v=8Jx6gN7ZFKk"
    echo.
    echo Chế độ này tạo tóm tắt chi tiết hơn, giữ nhiều ý chính
    echo (Khoảng 30-40%% độ dài gốc)
    exit /b 1
)

echo ========================================
echo Detailed Summary Mode
echo Model: distilbart
echo Output: Chi tiết, giữ nhiều ý chính
echo ========================================
echo.

REM Không dùng --combine để giữ tất cả các tóm tắt từng phần
REM Tăng max-length lên 300 (thay vì 100)
REM Tăng min-length lên 100 (thay vì 20)
REM Tăng chunk-words lên 500 (thay vì 250)
"C:\Users\KHAC CUONG\AppData\Local\Programs\Python\Python314\python.exe" quickstart.py --url "%~1" --mode plain --chunk-words 500 --max-length 300 --min-length 100

echo.
echo ========================================
echo Done!
echo ========================================

endlocal
