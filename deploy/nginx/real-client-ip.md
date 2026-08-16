# PHP 站点获取真实客户端 IP（短信日志 / 注册登录 IP）
#
# 根因：application/config.php 曾把 http_agent_ip 设为 REMOTE_ADDR，
# 在 Nginx / 阿里云 SLB 后拿到的是机房出口 IP，短信记录全是阿里云 IP。
#
# 现已改为 HTTP_X_REAL_IP，并增加 FansHubClientIp 解析 CDN 头。
# 请确认站点 server 块对 PHP 反代有（缺则补上后 nginx -t && reload）：
#
#   location ~ \.php$ {
#       ...
#       # 若前面还有 CDN/SLB，用其传入的真实 IP；直连用户用 $remote_addr
#       set_real_ip_from 10.0.0.0/8;
#       set_real_ip_from 172.16.0.0/12;
#       set_real_ip_from 192.168.0.0/16;
#       # 阿里云 SLB / CDN 网段按控制台补充
#       real_ip_header X-Forwarded-For;
#       real_ip_recursive on;
#
#       # 传给 PHP-FPM（必须）
#       fastcgi_param HTTP_X_REAL_IP $remote_addr;
#       # 或在 http/server 级：
#       # proxy_set_header X-Real-IP $remote_addr;
#       # proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
#   }
#
# 说明：调大狗/UNA 接口时，对方看到的仍是「服务器出口 IP」（需加白名单）。
# 本修复保证入库的 sms/login IP 是用户真实 IP，不是阿里云机房 IP。
