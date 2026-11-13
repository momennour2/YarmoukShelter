#!/usr/bin/env bash
# Post-deploy security smoke tests.  Usage:  ./security_test.sh https://your-site.vercel.app
# Needs only curl. Every line prints PASS or FAIL.  Run it AFTER deploying; it makes ~8 failed logins (the lock-out test).
set -u
BASE="${1:?usage: $0 https://your-domain}"
BASE="${BASE%/}"
pass=0; fail=0
ok(){ echo "PASS  $1"; pass=$((pass+1)); }
no(){ echo "FAIL  $1"; fail=$((fail+1)); }
chk(){ if eval "$2"; then ok "$1"; else no "$1"; fi; }

H="$(curl -sI "$BASE/login.php")"
HOST="${BASE#https://}"; HOST="${HOST#http://}"

chk "HTTP is redirected to HTTPS"            '[[ "$(curl -s -o /dev/null -w "%{http_code}" "http://$HOST/login.php")" =~ ^30[1278]$ ]]'
chk "HSTS header present"                    'grep -qi "^strict-transport-security" <<<"$H"'
chk "X-Content-Type-Options: nosniff"        'grep -qi "^x-content-type-options: nosniff" <<<"$H"'
chk "X-Frame-Options DENY (clickjacking)"    'grep -qi "^x-frame-options: deny" <<<"$H"'
chk "Content-Security-Policy present"        'grep -qi "^content-security-policy" <<<"$H"'
chk "Referrer-Policy present"                'grep -qi "^referrer-policy" <<<"$H"'
chk "Pages are not cacheable (no-store)"     'grep -qi "^cache-control:.*no-store" <<<"$H"'
chk "No CORS header (same-origin only)"      '! curl -sI -H "Origin: https://evil.example" "$BASE/login.php" | grep -qi "^access-control-allow-origin"'
chk "X-Powered-By hidden"                    '! grep -qi "^x-powered-by" <<<"$H"'

C="$(curl -s -D - -o /dev/null -c /tmp/ys_cookies "$BASE/login.php" | grep -i "^set-cookie")"
chk "Session cookie: Secure"                 'grep -qi "secure" <<<"$C"'
chk "Session cookie: HttpOnly"               'grep -qi "httponly" <<<"$C"'
chk "Session cookie: SameSite"               'grep -qi "samesite" <<<"$C"'
chk "Session cookie: __Host- prefix"         'grep -q "__Host-" <<<"$C"'

for f in api/config.php config.php database/schema.sql database/owner_account.sql docs/README_V5_AR.md .git/config .env vercel.json api/app/functions.php; do
  code="$(curl -s -o /tmp/ys_body -w "%{http_code}" "$BASE/$f")"
  chk "Not exposed: /$f (HTTP $code)"        '[[ "$code" != "200" ]] || ! grep -qiE "db_pass|CREATE TABLE|<\?php|\"routes\"" /tmp/ys_body'
done

chk "Staff pages need login (index.php redirects)" '[[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/index.php?page=accounts")" =~ ^(302|303|307|200)$ ]] && ! curl -s "$BASE/index.php?page=accounts" | grep -q "إعادة تعيين كلمة مرور نازح"'
chk "Settings page hidden from anonymous"    '! curl -s "$BASE/index.php?page=settings" | grep -q "الشعارات"'
chk "ID lookup endpoint refuses anonymous"   '! curl -s "$BASE/index.php?page=lookup&nid=123456789" | grep -q "\"ok\":true"'
chk "HH list export refuses anonymous"       '! curl -s -D - "$BASE/index.php?page=hh_list" -o /dev/null | grep -qi "spreadsheetml"'
chk "Unknown logo slot returns 404"          '[[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/api/asset.php?k=../../etc/passwd")" == "404" ]]'

chk "Login POST without CSRF token is rejected" 'curl -s -c /tmp/ys_c2 -b /tmp/ys_c2 -X POST -d "kind=staff&username=admin&password=x" "$BASE/login.php" | grep -qiE "csrf|الجلسة|غير صالح|منتهي|رمز"'
echo "-- lock-out test (6 wrong passwords for a fake user) --"
TOK="$(curl -s -c /tmp/ys_c3 -b /tmp/ys_c3 "$BASE/login.php?as=staff" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
last=""
for i in 1 2 3 4 5 6 7; do
  last="$(curl -s -c /tmp/ys_c3 -b /tmp/ys_c3 -X POST --data-urlencode "csrf=$TOK" -d "kind=staff&username=zz_probe_user&password=wrong$i" "$BASE/login.php")"
done
chk "Brute force is throttled after repeated failures" 'grep -q "15 دقيقة" <<<"$last"'

echo; echo "Result: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
