#!/bin/sh
set -e

CONFIG="/var/www/html/config.php"

echo "Configuring Moodle runtime settings..."

# Remove our previously generated block if it exists.
sed -i '/\/\/ BEGIN LEARNLOGICIFY RUNTIME CONFIG/,/\/\/ END LEARNLOGICIFY RUNTIME CONFIG/d' "$CONFIG"

RUNTIME_CONFIG=$(cat <<'EOF'
// BEGIN LEARNLOGICIFY RUNTIME CONFIG

// PgBouncer compatibility.
// Required for PostgreSQL through PgBouncer transaction pooling.
$CFG->dboptions = [
    'dbpersist' => false,
    'dbport' => '5432',
    'dbsocket' => false,
    'dbhandlesoptions' => false,
    'fetchbuffersize' => 0,
];

// Redis-backed Moodle sessions.
$CFG->session_handler_class = '\core\session\redis';
$CFG->session_redis_host = getenv('REDISHOST');
$CFG->session_redis_port = (int)getenv('REDISPORT');
$CFG->session_redis_auth = getenv('REDISPASSWORD');
$CFG->session_redis_database = 0;
$CFG->session_redis_prefix = 'mdl_session_';
$CFG->session_redis_acquire_lock_timeout = 120;
$CFG->session_redis_lock_expire = 7200;

// END LEARNLOGICIFY RUNTIME CONFIG
EOF
)

awk -v runtimeconfig="$RUNTIME_CONFIG" '
/require_once.*lib\/setup\.php/ && !done {
    print runtimeconfig
    print ""
    done=1
}
{ print }
' "$CONFIG" > "${CONFIG}.tmp"

mv "${CONFIG}.tmp" "$CONFIG"

echo "Moodle PgBouncer + Redis configuration complete."