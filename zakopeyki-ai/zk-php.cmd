@echo off
rem Обёртка для запуска PHP CLI с локальным конфигом проекта.
rem Использование: zk-php.cmd bin\run_e2e.php | zk-php.cmd vendor\bin\phpunit tests\
"C:\MAMP\bin\php\php8.1.0\php.exe" -c "%~dp0php-cli.ini" %*
