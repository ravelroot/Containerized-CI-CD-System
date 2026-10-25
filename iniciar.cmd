@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul || (echo PHP 8.2 ou mais recente nao encontrado. Instale em https://windows.php.net/download & pause & exit /b 1)
php bin\vau setup || (pause & exit /b 1)
where docker >nul 2>nul && (
  docker info >nul 2>nul && (
    echo Docker encontrado: iniciando o worker em outra janela.
    start "Vau worker" cmd /k php bin\vau worker
  ) || echo Docker Desktop nao esta rodando. Abra o Docker Desktop e rode: php bin\vau worker
) || echo Docker nao encontrado. O painel sobe, mas os builds precisam do worker com Docker.
php bin\vau serve --host=127.0.0.1 --port=8080
pause
