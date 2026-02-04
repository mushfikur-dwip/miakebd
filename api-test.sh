#!/bin/bash

API_KEY="i7a05ls4-r0i2-22h9-7161-f046d16528q495522"
BASE_URL="https://public_html.test/api"

echo "=========================================="
echo "API Testing"
echo "=========================================="

# Login
echo "1. Logging in..."
LOGIN_RESP=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en" \
  -d '{"email":"admin@example.com","password":"123456"}')

TOKEN=$(echo $LOGIN_RESP | python3 -c "import sys, json; print(json.load(sys.stdin).get('token', ''))" 2>/dev/null)

if [ -z "$TOKEN" ]; then
    echo "❌ Login failed"
    echo "$LOGIN_RESP"
    exit 1
fi

echo "✅ Login successful"
echo "Token: ${TOKEN:0:30}..."
echo ""

# Test Logout
echo "2. Testing Logout..."
LOGOUT_RESP=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "${BASE_URL}/auth/logout" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en")

HTTP_CODE=$(echo "$LOGOUT_RESP" | grep -o "HTTP_CODE:[0-9]*" | cut -d: -f2)
BODY=$(echo "$LOGOUT_RESP" | sed 's/HTTP_CODE:[0-9]*//')

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "204" ]; then
    echo "✅ Logout successful (HTTP $HTTP_CODE)"
else
    echo "❌ Logout failed (HTTP $HTTP_CODE)"
fi
echo "Response: $BODY"
echo ""

# Login again for change password test
echo "3. Logging in again for password test..."
LOGIN_RESP=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en" \
  -d '{"email":"admin@example.com","password":"123456"}')

TOKEN=$(echo $LOGIN_RESP | python3 -c "import sys, json; print(json.load(sys.stdin).get('token', ''))" 2>/dev/null)
echo "✅ Logged in again"
echo ""

# Test Change Password with wrong old password
echo "4. Testing Change Password (with wrong old password)..."
CHANGE_PASS_RESP=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X PUT "${BASE_URL}/profile/change-password" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en" \
  -d '{"old_password":"wrongpassword","new_password":"newpass123","confirm_password":"newpass123"}')

HTTP_CODE=$(echo "$CHANGE_PASS_RESP" | grep -o "HTTP_CODE:[0-9]*" | cut -d: -f2)
BODY=$(echo "$CHANGE_PASS_RESP" | sed 's/HTTP_CODE:[0-9]*//')

if [ "$HTTP_CODE" = "422" ]; then
    echo "✅ Validation working correctly (HTTP $HTTP_CODE)"
elif [ "$HTTP_CODE" = "400" ]; then
    echo "❌ Bad Request error (HTTP $HTTP_CODE)"
else
    echo "Response HTTP $HTTP_CODE"
fi
echo "Response: $BODY"

echo ""
echo "=========================================="
echo "Testing Complete"
echo "=========================================="
