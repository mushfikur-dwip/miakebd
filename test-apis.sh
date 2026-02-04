#!/bin/bash

# Test API endpoints
# Get API_KEY and BASE_URL from .env
source .env

BASE_URL="${APP_URL}/api"
API_KEY="${VITE_API_KEY}"

echo "=========================================="
echo "Testing ShopKing APIs"
echo "=========================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Test 1: Login (to get token)
echo "1. Testing Login API..."
LOGIN_RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en" \
  -d '{
    "email": "admin@example.com",
    "password": "123456"
  }')

echo "Login Response: $LOGIN_RESPONSE"

# Extract token
TOKEN=$(echo $LOGIN_RESPONSE | grep -o '"token":"[^"]*' | grep -o '[^"]*$')

if [ -n "$TOKEN" ]; then
    echo -e "${GREEN}✓ Login successful${NC}"
    echo "Token: $TOKEN"
else
    echo -e "${RED}✗ Login failed${NC}"
    exit 1
fi

echo ""
echo "=========================================="

# Test 2: Profile Change Password API
echo "2. Testing Change Password API..."
CHANGE_PASSWORD_RESPONSE=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X PUT "${BASE_URL}/profile/change-password" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en" \
  -d '{
    "old_password": "wrong_password",
    "new_password": "new_password123",
    "confirm_password": "new_password123"
  }')

HTTP_STATUS=$(echo "$CHANGE_PASSWORD_RESPONSE" | grep -o "HTTP_STATUS:[0-9]*" | cut -d':' -f2)
RESPONSE_BODY=$(echo "$CHANGE_PASSWORD_RESPONSE" | sed 's/HTTP_STATUS:[0-9]*//')

echo "Change Password Response: $RESPONSE_BODY"
echo "HTTP Status: $HTTP_STATUS"

if [ "$HTTP_STATUS" = "422" ]; then
    echo -e "${GREEN}✓ Change password API is working (validation working)${NC}"
elif [ "$HTTP_STATUS" = "400" ]; then
    echo -e "${RED}✗ Change password API returned 400 Bad Request${NC}"
else
    echo -e "${GREEN}✓ Change password API responded with status $HTTP_STATUS${NC}"
fi

echo ""
echo "=========================================="

# Test 3: Logout API
echo "3. Testing Logout API..."
LOGOUT_RESPONSE=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X POST "${BASE_URL}/auth/logout" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "x-api-key: ${API_KEY}" \
  -H "x-localization: en")

HTTP_STATUS=$(echo "$LOGOUT_RESPONSE" | grep -o "HTTP_STATUS:[0-9]*" | cut -d':' -f2)
RESPONSE_BODY=$(echo "$LOGOUT_RESPONSE" | sed 's/HTTP_STATUS:[0-9]*//')

echo "Logout Response: $RESPONSE_BODY"
echo "HTTP Status: $HTTP_STATUS"

if [ "$HTTP_STATUS" = "200" ] || [ "$HTTP_STATUS" = "204" ]; then
    echo -e "${GREEN}✓ Logout API is working${NC}"
elif [ "$HTTP_STATUS" = "400" ]; then
    echo -e "${RED}✗ Logout API returned 400 Bad Request${NC}"
else
    echo "Logout API responded with status $HTTP_STATUS"
fi

echo ""
echo "=========================================="
echo "API Testing Complete"
echo "=========================================="
