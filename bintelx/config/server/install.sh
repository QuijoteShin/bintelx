#!/usr/bin/bash
# git configs
git config core.autocrlf input

# deps
sudo add-apt-repository ppa:ondrej/php # Press enter when prompted.
sudo apt update
sudo apt install php8.4 php8.4-{cli,bz2,curl,mbstring,intl,fpm,zip,gd,xml,bcmath,opcache} -y
# sudo apt install nginx mysql -y
# Certs
openssl req \
    -newkey rsa:2048 -x509 -nodes -new -reqexts SAN -extensions SAN -sha256 -days 3650 -extensions EXT \
    -keyout dev.local.key \
    -out dev.local.crt \
    -config <( \
        printf '
[req]
default_bits = 2048
prompt = no
string_mask = default
default_md = sha256
x509_extensions = x509_ext
distinguished_name = dn

[dn]
C = CL
ST = Chile
L = Chile
O = Bintel
OU = Technology Group
emailAddress = bintelx@bintel.com
CN = dev.local

[SAN]
subjectAltName = @alt_names

[EXT]
subjectAltName = @alt_names
keyUsage=digitalSignature
extendedKeyUsage=serverAuth
[x509_ext]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
# No basicConstraints extension is equal to CA:False
# basicConstraints = critical, CA:False
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = *.dev.local
DNS.2 = dev.local
')

# nginx config
# sudo ln -s /var/www/bintelx/bintelx/config/server/nginx.bintelx.dev.localhost.conf /etc/nginx/sites-available/
# sudo ln -s /etc/nginx/sites-available/nginx.bintelx.dev.localhost.conf /etc/nginx/sites-enabled/
sudo ufw allow 'Nginx Full'
sudo service php8.4-fpm restart
sudo service nginx restart
sudo mysql < schema.sql
sudo mysql bnx_labtronic < ../../doc/DataCaptureService.sql
mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql