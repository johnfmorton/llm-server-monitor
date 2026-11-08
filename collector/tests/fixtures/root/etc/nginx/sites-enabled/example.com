include forge-conf/1001/before/*;
server {
    listen 443 ssl;
    server_name example.com;
    root /home/forge/example-abc.on-forge.com/current/public;
    ssl_certificate /etc/nginx/ssl/example.com/server.crt;
    include forge-conf/1001/site.conf;
}
