#!/bin/bash

# Test script untuk register endpoint

BASE_URL="http://localhost:8443"

echo "=== Testing OTP Send ==="
curl -X POST "$BASE_URL/api/auth/otp/send" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"whatsapp": "085156334793"}' \
  -v

echo -e "\n\n=== Testing OTP Verify ==="
# Ganti OTP dengan yang diterima dari response sebelumnya
curl -X POST "$BASE_URL/api/auth/otp/verify" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"whatsapp": "085156334793", "otp": "123456"}' \
  -v

echo -e "\n\n=== Testing Register ==="
curl -X POST "$BASE_URL/register" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Test User",
    "email": "test@example.com",
    "password": "password123",
    "whatsapp": "085156334793"
  }' \
  -v
