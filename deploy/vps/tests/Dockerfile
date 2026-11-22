# A throwaway Ubuntu 24.04 with systemd, nginx, SSH and ufw (as Ubuntu server ships it), like the relay VPS, for
# deploy/vps/tests/container.sh.
FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update -q && apt-get install -y -q --no-install-recommends systemd systemd-sysv dbus nginx openssh-server \
      sudo iproute2 iptables ufw needrestart python3 curl ca-certificates jq procps \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /root/.ssh && chmod 700 /root/.ssh
# A stand-in relay: answers /healthz on 127.0.0.1:8787 while /run/relay-healthy exists, hangs otherwise.
RUN printf '%s\n' \
      'import http.server, os, sys, time' \
      'if os.path.exists("/etc/fake-relay-leak"):' \
      '    print("{\"event\":\"oops\",\"auth\":\"eyJhbGciOiJIUzI1NiJ9.eyJzaWQiOiJ4In0.sig\"}")' \
      '    for i in range(2500): print("{\"event\":\"filler\",\"n\":%d,\"pad\":\"%s\"}" % (i, "x" * 60))' \
      '    sys.stdout.flush()' \
      'class H(http.server.BaseHTTPRequestHandler):' \
      '    def do_GET(self):' \
      '        if not os.path.exists("/run/relay-healthy"): time.sleep(30); return' \
      '        b = b"{\"status\":\"ok\",\"version\":\"test\"}"' \
      '        self.send_response(200); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)' \
      '    def log_message(self, *a): pass' \
      'http.server.ThreadingHTTPServer(("127.0.0.1", 8787), H).serve_forever()' > /usr/local/bin/fake-relay.py \
    && printf '%s\n' '[Unit]' 'Description=stand-in relay' '[Service]' 'ExecStart=/usr/bin/python3 /usr/local/bin/fake-relay.py' \
      'Restart=on-failure' '[Install]' 'WantedBy=multi-user.target' > /etc/systemd/system/assist-relay.service \
    && systemctl enable assist-relay nginx ssh
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
