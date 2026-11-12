#!/bin/sh
set -eu
umask 077
cd "$(dirname "$0")"
command -v docker >/dev/null || { echo 'Install Docker with Compose first.'; exit 1; }
docker info >/dev/null 2>&1 || { echo 'Start Docker Engine first.'; exit 1; }
if [ ! -f .env ]; then
  cp .env.example .env
  # Generate secrets inside a disposable official PHP container; PHP is not needed on host.
  docker run --rm -v "$(pwd):/work" -w /work --entrypoint php php:8.3-cli-bookworm -r '
    $p=".env";$s=file_get_contents($p);
    foreach(["DB_PASSWORD","DB_ROOT_PASSWORD","NEO4J_PASSWORD","MEILISEARCH_KEY"] as $k){
      $v=bin2hex(random_bytes(32));$s=preg_match("/^".$k."=.*$/m",$s)?preg_replace("/^".$k."=.*$/m",$k."=".$v,$s):$s."\n".$k."=".$v."\n";
    }
    foreach(["APP_PORT"=>"8088","MAIL_PORT"=>"8025","DEMO_PASSWORD"=>"Abcd@1234"] as $k=>$v){
      $s=preg_match("/^".$k."=.*$/m",$s)?preg_replace("/^".$k."=.*$/m",$k."=".$v,$s):$s."\n".$k."=".$v."\n";
    }
    file_put_contents($p,$s);'
fi
if [ "${1:-}" = '--light' ]; then
  docker compose -f compose.yaml up -d --build --remove-orphans --wait --wait-timeout 900
else
  docker compose -f compose.yaml -f compose.full.yaml up -d --build --remove-orphans --wait --wait-timeout 900
fi
echo 'Ready. Open APP_PORT (default http://localhost:8088); Mailpit on MAIL_PORT (default http://localhost:8025).'
