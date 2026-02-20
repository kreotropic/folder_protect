#!/bin/bash

# ==========================================
# Folder Protection - Manual Test Script
# ==========================================
# Usage: ./manual_curl_test.sh [path_to_protect]
# Example: ./manual_curl_test.sh /files/teste
#
# Prerequisites:
# - curl
# - A protected folder on the server (configure credentials below)
# ==========================================

# --- CONFIGURATION ---
BASE_URL="${NC_URL:-http://localhost:8080}"
USER="${NC_USER:-ncadmin}"
PASS="${NC_PASS:-yura}"
# Default path to test (must be protected in Nextcloud)
TEST_PATH=${1:-"/files/$USER/teste"}

# --- COLORS ---
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check for common path mistake (missing username in path)
if [[ "$TEST_PATH" == "/files/"* ]]; then
    PATH_USER=$(echo "$TEST_PATH" | cut -d'/' -f3)
    if [[ "$PATH_USER" != "$USER" && "$PATH_USER" != "__groupfolders" ]]; then
        echo -e "${YELLOW}⚠️  WARNING: Path '$TEST_PATH' implies user '$PATH_USER', but config uses '$USER'.${NC}"
        echo -e "${YELLOW}   Likely fix: Use /files/$USER/$(echo "$TEST_PATH" | cut -d'/' -f4-)${NC}\n"
    fi
fi

echo -e "${YELLOW}🛡️  Starting Folder Protection Tests against: $BASE_URL${NC}"
echo -e "Target Path: $TEST_PATH"
echo "---------------------------------------------------"

# Function to perform request and validate response
validate_protection() {
    local method=$1
    local endpoint=$2
    local dest_header=$3
    
    echo -e "\nTesting ${YELLOW}$method${NC} on $endpoint..."
    
    # Temp files for headers and body
    local headers_file=$(mktemp)
    local body_file=$(mktemp)
    
    # Build curl command
    local cmd="curl -s -X $method -u \"$USER:$PASS\" -D \"$headers_file\" -o \"$body_file\" \"$BASE_URL/remote.php/dav$endpoint\""
    
    if [ ! -z "$dest_header" ]; then
        cmd="$cmd -H \"Destination: $BASE_URL/remote.php/dav$dest_header\""
    fi
    
    # Execute
    eval $cmd
    
    # Parse results
    local http_code=$(grep "HTTP/" "$headers_file" | tail -1 | awk '{print $2}')
    local protected_header=$(grep -i "X-NC-Folder-Protected: true" "$headers_file")
    local action_header=$(grep -i "X-NC-Protection-Action" "$headers_file" | cut -d':' -f2 | xargs)
    local reason_header=$(grep -i "X-NC-Protection-Reason" "$headers_file" | cut -d':' -f2 | xargs)
    
    # Validation Logic
    if [ "$http_code" == "423" ]; then
        echo -e "${GREEN}✅ Status 423 Locked received.${NC}"
        
        if [ ! -z "$protected_header" ]; then
            echo -e "${GREEN}✅ Header X-NC-Folder-Protected: true found.${NC}"
        else
            echo -e "${RED}❌ Header X-NC-Folder-Protected MISSING.${NC}"
        fi
        
        if [ ! -z "$action_header" ]; then
            echo -e "${GREEN}✅ Action: $action_header${NC}"
        else
            echo -e "${RED}❌ Header X-NC-Protection-Action MISSING.${NC}"
        fi
        
        if [ ! -z "$reason_header" ]; then
            echo -e "${GREEN}✅ Reason: $reason_header${NC}"
        else
            echo -e "${YELLOW}⚠️  Header X-NC-Protection-Reason missing (optional)${NC}"
        fi
        
    elif [ "$http_code" == "404" ]; then
        echo -e "${RED}❌ Failed: Got 404 Not Found. Does the folder exist?${NC}"
    elif [ "$http_code" == "401" ]; then
        echo -e "${RED}❌ Failed: Got 401 Unauthorized.${NC}"
        echo -e "${YELLOW}💡 Hint: Check if the path includes the username (e.g., /files/$USER/folder).${NC}"
        echo -e "${YELLOW}   Current URL: $BASE_URL/remote.php/dav$endpoint${NC}"
        echo -e "${YELLOW}   Possible causes: 2FA enabled (use App Password), Brute Force (wait/reset), or server config stripping headers.${NC}"
        echo "Response Body:"
        cat "$body_file"
    elif [ "$http_code" == "204" ] || [ "$http_code" == "201" ] || [ "$http_code" == "200" ]; then
        echo -e "${RED}❌ CRITICAL FAIL: Operation allowed ($http_code)! Protection NOT active.${NC}"
    else
        echo -e "${RED}❌ Failed: Got HTTP $http_code (Expected 423)${NC}"
        echo "Response Body:"
        cat "$body_file"
    fi
    
    # Cleanup
    rm "$headers_file" "$body_file"
}

# 1. Test DELETE (Should be blocked)
validate_protection "DELETE" "$TEST_PATH"

# 2. Test MOVE (Rename) (Should be blocked)
validate_protection "MOVE" "$TEST_PATH" "$TEST_PATH-renamed"

# 3. Test COPY (Should be blocked)
validate_protection "COPY" "$TEST_PATH" "$TEST_PATH-copy"

# 4. Test PROPFIND (Should be blocked on the root of protected folder)
validate_protection "PROPFIND" "$TEST_PATH"

echo -e "\n---------------------------------------------------"
echo "Tests Completed."