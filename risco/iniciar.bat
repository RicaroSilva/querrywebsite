@echo off
chcp 65001 >nul
title Analise de risco - servidor
cd /d "%~dp0"

set PORT=8080
set PHP=
if exist "%~dp0php\php.exe" set "PHP=%~dp0php\php.exe"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP for /f "delims=" %%p in ('where php 2^>nul') do if not defined PHP set "PHP=%%p"
if not defined PHP (
  echo Nao encontrei o PHP. Extraia o zip do PHP para a pasta "php" ao lado deste ficheiro ou para C:\php
  echo Download: https://windows.php.net/download  ^(VS16 x64 Non Thread Safe, zip^)
  pause
  exit /b 1
)

if not exist "%~dp0config.php" (
  copy "%~dp0config.example.php" "%~dp0config.php" >nul
  echo Criei o config.php. Preencha os dados da base de dados e volte a abrir este ficheiro.
  notepad "%~dp0config.php"
  exit /b 1
)

for %%i in ("%PHP%") do set "EXT=%%~dpiext"

echo.
echo  Pagina a correr. NAO FECHE ESTA JANELA enquanto quiser que a pagina funcione.
echo.
echo  Neste PC:          http://localhost:%PORT%
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
  for /f "tokens=* delims= " %%b in ("%%a") do echo  Enviar a colegas:  http://%%b:%PORT%
)
echo.

start "" http://localhost:%PORT%
rem -n ignora o php.ini: as extensoes necessarias sao carregadas aqui, nao e preciso configurar nada
"%PHP%" -n -d extension_dir="%EXT%" -d extension=pdo_pgsql -d extension=zip -d display_errors=0 -S 0.0.0.0:%PORT% -t "%~dp0."
pause
