@echo off
setlocal enabledelayedexpansion
REM Tạo bài học hoàn chỉnh từ YouTube video
REM Usage: create_lesson.bat "youtube_url" [language] [output_file]

if "%~1"=="" (
    echo ============================================================
    echo TẠO BÀI HỌC HOÀN CHỈNH TỪ VIDEO YOUTUBE
    echo ============================================================
    echo.
    echo Usage: create_lesson.bat "youtube_url" [language] [output_file]
    echo.
    echo Tham số:
    echo   youtube_url   : Link video YouTube (bắt buộc)
    echo   language      : Ngôn ngữ (vi hoặc en, mặc định: en)
    echo   output_file   : File lưu kết quả (tùy chọn, mặc định: lesson_output.md)
    echo.
    echo Ví dụ:
    echo   create_lesson.bat "https://youtube.com/watch?v=abc" en lesson.md
    echo   create_lesson.bat "https://youtube.com/watch?v=abc" vi
    echo   create_lesson.bat "https://youtube.com/watch?v=abc"
    echo.
    echo Bài học sẽ bao gồm:
    echo   - Tiêu đề hấp dẫn
    echo   - Mục tiêu học tập cụ thể
    echo   - Các khái niệm chính (chi tiết)
    echo   - Các bước thực hiện / Điểm quan trọng
    echo   - Ví dụ minh họa
    echo   - Tóm tắt
    echo   - Câu hỏi ôn tập
    echo ============================================================
    exit /b 1
)

set URL=%~1
set LANGUAGE=%~2
set OUTPUT_FILE=%~3

if "%LANGUAGE%"=="" set LANGUAGE=en
if "%OUTPUT_FILE%"=="" set OUTPUT_FILE=lesson_output.md

echo ============================================================
echo TẠO BÀI HỌC HOÀN CHỈNH
echo ============================================================
echo Video URL: %URL%
echo Language: %LANGUAGE%
echo Output: %OUTPUT_FILE%
echo.
echo Quá trình này sẽ mất 5-15 phút tùy độ dài video...
echo ============================================================
echo.

REM Chạy với lesson mode, chunk lớn hơn để giữ nhiều chi tiết
"C:\Users\KHAC CUONG\AppData\Local\Programs\Python\Python314\python.exe" quickstart.py ^
  --url "%URL%" ^
  --mode lesson ^
  --language %LANGUAGE% ^
  --combine ^
  --chunk-words 600 ^
  --max-length 400 ^
  --min-length 150 > "%OUTPUT_FILE%"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ============================================================
    echo ✓ HOÀN THÀNH!
    echo ============================================================
    echo Bài học đã được lưu vào: %OUTPUT_FILE%
    echo.
    echo Bạn có thể:
    echo   1. Mở file bằng editor để xem: notepad %OUTPUT_FILE%
    echo   2. Xem trên VS Code hoặc editor hỗ trợ Markdown
    echo   3. Convert sang PDF bằng Markdown viewer
    echo ============================================================
) else (
    echo.
    echo ============================================================
    echo ✗ LỖI
    echo ============================================================
    echo Có lỗi xảy ra trong quá trình tạo bài học.
    echo Kiểm tra lại URL hoặc kết nối mạng.
    echo ============================================================
)

endlocal
