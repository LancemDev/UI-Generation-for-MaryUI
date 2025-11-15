#!/bin/bash

echo "=== Testing SKYLARR Flow ==="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: FastAPI Backend Health
echo "1. Testing FastAPI Backend..."
if curl -s http://127.0.0.1:8001/ | grep -q "Hello"; then
    echo -e "${GREEN}✓ FastAPI backend is running${NC}"
else
    echo -e "${RED}✗ FastAPI backend is not responding${NC}"
    exit 1
fi

# Test 2: Code Generation Endpoint
echo ""
echo "2. Testing Code Generation Endpoint..."
RESPONSE=$(curl -s -X POST http://127.0.0.1:8001/generate/code \
    -H "Content-Type: application/json" \
    -d '{"prompt":"create a simple button component"}')

if echo "$RESPONSE" | grep -q '"success":true'; then
    echo -e "${GREEN}✓ Code generation endpoint works${NC}"
    echo "   Response preview: $(echo "$RESPONSE" | head -c 100)..."
else
    echo -e "${RED}✗ Code generation endpoint failed${NC}"
    echo "   Response: $RESPONSE"
fi

# Test 3: Chat Streaming Endpoint
echo ""
echo "3. Testing Chat Streaming Endpoint..."
STREAM_RESPONSE=$(timeout 5 curl -s -X POST http://127.0.0.1:8001/chat/stream \
    -H "Content-Type: application/json" \
    -d '{"messages":[{"role":"user","content":"hello"}]}' || echo "timeout")

if [ -n "$STREAM_RESPONSE" ] && [ "$STREAM_RESPONSE" != "timeout" ]; then
    echo -e "${GREEN}✓ Chat streaming endpoint works${NC}"
else
    echo -e "${YELLOW}⚠ Chat streaming endpoint may need testing in browser${NC}"
fi

# Test 4: Docker Service
echo ""
echo "4. Testing Docker..."
if docker ps > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Docker is accessible${NC}"
    
    # Check if skylarr-preview image exists
    if docker images | grep -q "skylarr-preview"; then
        echo -e "${GREEN}✓ Docker image 'skylarr-preview:latest' exists${NC}"
    else
        echo -e "${RED}✗ Docker image 'skylarr-preview:latest' not found${NC}"
    fi
else
    echo -e "${RED}✗ Docker is not accessible${NC}"
fi

# Test 5: Laravel App
echo ""
echo "5. Testing Laravel App..."
if curl -s http://127.0.0.1:8000 | grep -q "html\|Laravel"; then
    echo -e "${GREEN}✓ Laravel app is running${NC}"
else
    echo -e "${YELLOW}⚠ Laravel app may not be responding on port 8000${NC}"
fi

# Test 6: Port Availability
echo ""
echo "6. Checking Port Availability..."
PORTS_TO_CHECK=(8000 8001 8002 8003)
for port in "${PORTS_TO_CHECK[@]}"; do
    if lsof -Pi :$port -sTCP:LISTEN -t >/dev/null 2>&1; then
        echo -e "${GREEN}✓ Port $port is in use${NC}"
    else
        echo -e "${YELLOW}⚠ Port $port is available${NC}"
    fi
done

echo ""
echo "=== Test Summary ==="
echo "If all tests pass, the flow should work:"
echo "  1. User sends message in chat → ChatInterface"
echo "  2. ChatInterface detects code generation → dispatches 'generate-code' event"
echo "  3. CodeGenerationEngine receives event → calls AiGateway.generateCode()"
echo "  4. AiGateway calls FastAPI /generate/code → returns PHP code"
echo "  5. CodeGenerationEngine creates Docker container → injects code"
echo "  6. Preview URL is displayed → user can view generated component"

