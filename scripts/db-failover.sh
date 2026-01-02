#!/bin/bash
#
# Coolify Database Failover Script
# Monitors local DB health and switches to witness DB if needed
# Run via cron every minute: * * * * * /opt/coolify/db-failover.sh >> /var/log/db-failover.log 2>&1
#

set -euo pipefail

# ============================================
# CONFIGURATION - ADJUST PER SITE
# ============================================

# Site A config (172.16.15.51, 172.16.15.52)
# PRIMARY_DB="172.16.15.35"
# WITNESS_DB="172.30.15.36"

# Site B config (172.30.15.53, 172.30.15.54, 172.30.15.58)  
# PRIMARY_DB="172.30.15.36"
# WITNESS_DB="172.16.15.35"

# Auto-detect site based on IP
CURRENT_IP=$(hostname -I | awk '{print $1}')
if [[ "$CURRENT_IP" == 172.16.* ]]; then
    SITE="A"
    PRIMARY_DB="172.16.15.35"
    WITNESS_DB="172.30.15.36"
else
    SITE="B"
    PRIMARY_DB="172.30.15.36"
    WITNESS_DB="172.16.15.35"
fi

DB_PORT="5433"
DB_USER="coolify"
DB_NAME="coolify"
COOLIFY_ENV="/data/coolify/source/.env"
COOLIFY_CONTAINER="coolify"
HEALTH_CHECK_URL="http://localhost:8000/api/health"
STATE_FILE="/var/run/db-failover-state"
LOG_PREFIX="[DB-FAILOVER][Site-$SITE]"

# Number of consecutive failures before failover
FAILURE_THRESHOLD=3
# Number of consecutive successes before failback
SUCCESS_THRESHOLD=5

# ============================================
# FUNCTIONS
# ============================================

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX $1"
}

get_current_db() {
    grep "^DB_HOST=" "$COOLIFY_ENV" | cut -d'=' -f2 | tr -d '"'
}

check_db_health() {
    local db_host="$1"
    # Try to connect and run a simple query
    PGPASSWORD="${DB_PASSWORD:-}" psql -h "$db_host" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" -t -A > /dev/null 2>&1
    return $?
}

check_db_writable() {
    local db_host="$1"
    # Check if DB is not in recovery mode (is primary/writable)
    local result
    result=$(PGPASSWORD="${DB_PASSWORD:-}" psql -h "$db_host" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT pg_is_in_recovery();" -t -A 2>/dev/null || echo "error")
    if [[ "$result" == "f" ]]; then
        return 0  # Not in recovery = writable
    else
        return 1  # In recovery or error = not writable
    fi
}

check_coolify_health() {
    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$HEALTH_CHECK_URL" 2>/dev/null || echo "000")
    if [[ "$http_code" == "200" ]]; then
        return 0
    else
        return 1
    fi
}

switch_db() {
    local new_db="$1"
    local current_db
    current_db=$(get_current_db)
    
    if [[ "$current_db" == "$new_db" ]]; then
        log "Already using $new_db, no switch needed"
        return 0
    fi
    
    log "SWITCHING DB: $current_db -> $new_db"
    
    # Update .env file
    sed -i "s/^DB_HOST=.*/DB_HOST=$new_db/" "$COOLIFY_ENV"
    
    # Restart Coolify container to pick up new config
    docker restart "$COOLIFY_CONTAINER" > /dev/null 2>&1
    
    log "DB switched to $new_db, Coolify restarting..."
    
    # Wait for Coolify to come up
    sleep 10
    
    # Verify the switch worked
    if check_coolify_health; then
        log "SUCCESS: Coolify is healthy after switching to $new_db"
        return 0
    else
        log "WARNING: Coolify may still be starting after switch to $new_db"
        return 0
    fi
}

get_failure_count() {
    local db="$1"
    local file="${STATE_FILE}-${db//\./-}-failures"
    if [[ -f "$file" ]]; then
        cat "$file"
    else
        echo "0"
    fi
}

set_failure_count() {
    local db="$1"
    local count="$2"
    local file="${STATE_FILE}-${db//\./-}-failures"
    echo "$count" > "$file"
}

get_success_count() {
    local db="$1"
    local file="${STATE_FILE}-${db//\./-}-successes"
    if [[ -f "$file" ]]; then
        cat "$file"
    else
        echo "0"
    fi
}

set_success_count() {
    local db="$1"
    local count="$2"
    local file="${STATE_FILE}-${db//\./-}-successes"
    echo "$count" > "$file"
}

# ============================================
# MAIN LOGIC
# ============================================

main() {
    log "Starting health check..."
    
    # Get DB password from .env
    if [[ -f "$COOLIFY_ENV" ]]; then
        DB_PASSWORD=$(grep "^DB_PASSWORD=" "$COOLIFY_ENV" | cut -d'=' -f2 | tr -d '"')
    fi
    
    local current_db
    current_db=$(get_current_db)
    log "Current DB: $current_db (Primary: $PRIMARY_DB, Witness: $WITNESS_DB)"
    
    # Check if current DB is healthy and writable
    if check_db_health "$current_db" && check_db_writable "$current_db"; then
        log "Current DB $current_db is healthy and writable"
        set_failure_count "$current_db" 0
        
        # If we're on witness and primary is back, consider failback
        if [[ "$current_db" == "$WITNESS_DB" ]]; then
            if check_db_health "$PRIMARY_DB" && check_db_writable "$PRIMARY_DB"; then
                local success_count
                success_count=$(get_success_count "$PRIMARY_DB")
                success_count=$((success_count + 1))
                set_success_count "$PRIMARY_DB" "$success_count"
                
                log "Primary DB $PRIMARY_DB is healthy (success count: $success_count/$SUCCESS_THRESHOLD)"
                
                if [[ $success_count -ge $SUCCESS_THRESHOLD ]]; then
                    log "Primary DB recovered, initiating FAILBACK"
                    switch_db "$PRIMARY_DB"
                    set_success_count "$PRIMARY_DB" 0
                fi
            else
                set_success_count "$PRIMARY_DB" 0
            fi
        fi
    else
        # Current DB is unhealthy
        local failure_count
        failure_count=$(get_failure_count "$current_db")
        failure_count=$((failure_count + 1))
        set_failure_count "$current_db" "$failure_count"
        
        log "WARNING: Current DB $current_db is unhealthy (failure count: $failure_count/$FAILURE_THRESHOLD)"
        
        if [[ $failure_count -ge $FAILURE_THRESHOLD ]]; then
            # Determine which DB to switch to
            local target_db
            if [[ "$current_db" == "$PRIMARY_DB" ]]; then
                target_db="$WITNESS_DB"
            else
                target_db="$PRIMARY_DB"
            fi
            
            # Check if target DB is healthy before switching
            if check_db_health "$target_db" && check_db_writable "$target_db"; then
                log "FAILOVER: Switching from $current_db to $target_db"
                switch_db "$target_db"
                set_failure_count "$current_db" 0
            else
                log "ERROR: Both DBs are unhealthy! Cannot failover."
                log "  - Primary ($PRIMARY_DB): $(check_db_health "$PRIMARY_DB" && echo 'reachable' || echo 'unreachable')"
                log "  - Witness ($WITNESS_DB): $(check_db_health "$WITNESS_DB" && echo 'reachable' || echo 'unreachable')"
            fi
        fi
    fi
    
    # Also check Coolify health directly
    if ! check_coolify_health; then
        log "WARNING: Coolify health check failed (may be starting up)"
    else
        log "Coolify health check: OK"
    fi
    
    log "Health check complete"
}

# Run main
main
