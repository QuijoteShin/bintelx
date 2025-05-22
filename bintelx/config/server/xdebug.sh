#!/bin/bash
set -e

cleanup() {
    if [[ -f "/var/www/cdc/xdebug-3.4.3.tgz" ]]; then
        rm -f "/var/www/cdc/xdebug-3.4.3.tgz"
    fi
    if [[ -d "/var/www/cdc/xdebug-3.4.3" ]]; then
        rm -R "/var/www/cdc/xdebug-3.4.3"
    fi
}

trap cleanup SIGINT
trap cleanup ERR

ROOT="/var/www/cdc"

tar -xvzf "/var/www/cdc/xdebug-3.4.3.tgz" --directory "$ROOT"
cd "/var/www/cdc/xdebug-3.4.3"

phpize
./configure --enable-xdebug --with-php-config=/bin/php-config
make

trap cleanup EXIT

PATH_TO_XDEBUG_SO="/usr/lib/php/20240924"
ZEND_EXTENSION='zend_extension = xdebug'
FILE="/etc/php/8.4/cli/conf.d/99-xdebug.ini"

sudo mkdir -p "$PATH_TO_XDEBUG_SO"

if [[ -f "$PATH_TO_XDEBUG_SO/xdebug.so" ]]; then
    sudo rm "$PATH_TO_XDEBUG_SO/xdebug.so"
fi

sudo cp /var/www/cdc/xdebug-3.4.3/modules/xdebug.so "$PATH_TO_XDEBUG_SO"
if [[ ! -f "${FILE}" ]]; then
  sudo touch "${FILE}"
fi
LAST_LINE=$(tail -n 1 "${FILE}")
if [[ "$LAST_LINE" != "${ZEND_EXTENSION}" ]]; then
  echo "${ZEND_EXTENSION}" | sudo tee -a "${FILE}"
fi