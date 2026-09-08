@echo off
REM Pornește serverul local Golden Pastry pe http://127.0.0.1:8100
setlocal enabledelayedexpansion
set "PHPEXE="
for /d %%d in ("C:\laragon\bin\php\php-*") do set "PHPEXE=%%d\php.exe"
if not defined PHPEXE (
  echo Nu gasesc PHP in C:\laragon\bin\php. Instaleaza Laragon Full.
  pause
  exit /b 1
)
cd /d "%~dp0"
echo Folosesc: %PHPEXE%
echo Deschide http://127.0.0.1:8100/
"%PHPEXE%" -S 127.0.0.1:8100 -t . .claude\dev-server.php
pause
