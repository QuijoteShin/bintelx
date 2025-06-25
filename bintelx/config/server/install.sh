#!/usr/bin/bash
# git configs
git config core.autocrlf input

# deps
sudo add-apt-repository ppa:ondrej/php # Press enter when prompted.
sudo apt install curl gnupg2 ca-certificates lsb-release ubuntu-keyring
sudo apt update
sudo apt install php8.4 php8.4-{cli,bz2,curl,mbstring,intl,fpm,zip,gd,xml,bcmath,opcache} -y

#curl https://nginx.org/keys/nginx_signing.key | gpg --dearmor | sudo tee /usr/share/keyrings/nginx-archive-keyring.gpg >/dev/null
#echo "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] http://nginx.org/packages/mainline/ubuntu `lsb_release -cs` nginx" | sudo tee /etc/apt/sources.list.d/nginx.list

# quic support
curl https://nginx.org/keys/nginx_signing.key | gpg --dearmor | sudo tee /usr/share/keyrings/nginx-archive-keyring.gpg >/dev/null
echo "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] https://packages.nginx.org/nginx-quic/ubuntu `lsb_release -cs` nginx-quic" | sudo tee /etc/apt/sources.list.d/nginx.list

sudo apt update


echo "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] \
  https://packages.nginx.org/nginx-quic/ubuntu `lsb_release -cs` nginx-quic" \

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

sudo mysql < schema.sql
sudo mysql bnx_labtronic < ../../doc/DataCaptureService.sql
mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql


## nginx custom build

mkdir nginx_quic_build
cd nginx_quic_build

sudo apt install gnupg2 build-essential cmake libpcre3 libpcre3-dev zlib1g zlib1g-dev libssl-dev curl  -y

sudo apt install libpcre3 libpcre3-dev zlib1g zlib1g-dev libssl-dev -y


sudo apt install libtext-template-perl
# Or, if apt cpan fails:
# sudo cpan Text::Template

#git clone https://github.com/quictls/openssl.git quictls
#cd quictls
#./Configure --prefix=/opt/quictls --openssldir=/opt/quictls/ssl no-tests shared linux-x86_64
#make -j$(nproc) # Use all CPU cores for faster compilation
#sudo make install # Install the compiled library to /opt/quictls

git clone https://boringssl.googlesource.com/boringssl boringssl
mkdir boringssl/build
cd boringssl/build
cmake -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DCMAKE_INSTALL_PREFIX=/opt/boringssl -DBUILD_SHARED_LIBS=ON ..
make -j$(nproc) # Use all CPU cores for faster compilation
sudo make install
cd ../..
echo "/opt/boringssl/lib" | sudo tee /etc/ld.so.conf.d/boringssl.conf > /dev/null
sudo ldconfig


BACKUP_TIMESTAMP=$(date +%Y%m%d%H%M%S) #

sudo systemctl stop nginx
sudo cp -r /etc/nginx /etc/nginx_backup_${BACKUP_TIMESTAMP}
echo "* * * NGINX configuration backed up to /etc/nginx_backup_${BACKUP_TIMESTAMP}"
sudo apt purge nginx nginx-common nginx-full nginx-core -y
sudo apt autoremove -y
sudo rm -rf /etc/nginx
sudo rm -rf /var/cache/nginx

cd ..
# ubuntu noble
wget https://nginx.org/download/nginx-1.25.3.tar.gz
tar -zxvf nginx-1.25.3.tar.gz
cd nginx-1.25.3


./configure \
    --prefix=/etc/nginx \
    --sbin-path=/usr/sbin/nginx \
    --modules-path=/usr/lib/nginx/modules \
    --conf-path=/etc/nginx/nginx.conf \
    --error-log-path=/var/log/nginx/error.log \
    --http-log-path=/var/log/nginx/access.log \
    --pid-path=/var/run/nginx.pid \
    --lock-path=/var/run/nginx.lock \
    --http-client-body-temp-path=/var/cache/nginx/client_temp \
    --http-proxy-temp-path=/var/cache/nginx/proxy_temp \
    --http-fastcgi-temp-path=/var/cache/nginx/fastcgi_temp \
    --http-uwsgi-temp-path=/var/cache/nginx/uwsgi_temp \
    --http-scgi-temp-path=/var/cache/nginx/scgi_temp \
    --user=www-data \
    --group=www-data \
    --with-compat \
    --with-file-aio \
    --with-threads \
    --with-http_ssl_module \
    --with-http_v2_module \
    --with-http_realip_module \
    --with-http_addition_module \
    --with-http_sub_module \
    --with-http_dav_module \
    --with-http_flv_module \
    --with-http_mp4_module \
    --with-http_gunzip_module \
    --with-http_gzip_static_module \
    --with-http_auth_request_module \
    --with-http_random_index_module \
    --with-http_secure_link_module \
    --with-http_slice_module \
    --with-http_stub_status_module \
    --with-http_v3_module \
    --with-debug \
    --with-cc-opt="-I/opt/boringssl/include" \
    --with-ld-opt="-L/opt/boringssl/lib -lssl -lcrypto -lpthread -ldl" # Added -ldl for dynamic linking, standard for this setup.
    #--with-cc-opt="-I/opt/quictls/include" \
    #--with-ld-opt="-L/opt/quictls/lib -lssl -lcrypto"

make -j$(nproc)
sudo make install

# sudo useradd --system --no-create-home --shell /bin/false www-data # debian default
sudo mkdir -p /var/cache/nginx
sudo chown -R www-data:www-data /var/cache/nginx

sudo cp -r /etc/nginx_backup_${BACKUP_TIMESTAMP}/* /etc/nginx/

sudo systemctl daemon-reload
sudo systemctl enable nginx


# nginx config
# sudo ln -s /var/www/bintelx/bintelx/config/server/nginx.bintelx.dev.localhost.conf /etc/nginx/sites-available/
# sudo ln -s /etc/nginx/sites-available/nginx.bintelx.dev.localhost.conf /etc/nginx/sites-enabled/
sudo ufw allow 'Nginx Full'
sudo service php8.4-fpm restart
sudo service nginx restart