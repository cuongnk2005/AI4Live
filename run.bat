@echo off
setlocal enabledelayedexpansion
REM Tối ưu cho tốc độ: dùng mô hình nhỏ, plain mode
REM Note: Đặt URL trong dấu ngoặc kép nếu có ký tự đặc biệt như &
"C:\Users\KHAC CUONG\AppData\Local\Programs\Python\Python314\python.exe" quickstart.py %*
endlocal
