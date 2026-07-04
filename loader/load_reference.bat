@echo off
cd /d "%~dp0..\.."
echo Loading PTAD reference data (countries + section_types)...
"C:\xampp\php\php.exe" loader\run.php --reference
pause
