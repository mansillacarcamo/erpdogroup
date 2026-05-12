#!/bin/bash
# Script de deploy invocado por .cpanel.yml
# Sincroniza el clon git con public_html del subdominio, excluyendo
# BD, uploads y configs locales para preservar datos de produccion.
set -e

SRC=/home/dogroupc/repositories/erpdogroup
DST=/home/dogroupc/public_html/gestion.dogroup.cl

/usr/bin/rsync -av --delete \
  --exclude=.git \
  --exclude=.gitignore \
  --exclude=.cpanel.yml \
  --exclude=ocdogroup.db \
  --exclude=ocdogroup.db-journal \
  --exclude=ocdogroup.db-wal \
  --exclude=ocdogroup.db-shm \
  --exclude=uploads \
  --exclude=backups \
  --exclude=smtp_config.php \
  --exclude='*.zip' \
  --exclude='*.ps1' \
  --exclude=response.html \
  --exclude=resp.html \
  --exclude='_test_*.php' \
  --exclude='_migrar_*.php' \
  --exclude='tools/webhook_deploy.log' \
  "$SRC/" "$DST/"

echo "Deploy OK"
