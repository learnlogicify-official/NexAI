#!/bin/sh
set -e

CONFIG="/var/www/html/config.php"
FRAGMENT="/tmp/moodle-runtime-config.php"

echo "Configuring Moodle runtime settings..."

# Remove previous generated block.
sed -i \
    '/\/\/ BEGIN LEARNLOGICIFY RUNTIME CONFIG/,/\/\/ END LEARNLOGICIFY RUNTIME CONFIG/d' \
    "$CONFIG"

# Write PHP configuration literally.
# Quoted EOF prevents shell/backslash expansion.
cat > "$FRAGMENT" <<'EOF'
// BEGIN LEARNLOGICIFY RUNTIME CONFIG

// PgBouncer transaction-pooling compatibility.
$CFG->dboptions = [
    'dbpersist' => false,
    'dbport' => '5432',
    'dbsocket' => false,
    'dbhandlesoptions' => false,
    'fetchbuffersize' => 0,
];

// Redis sessions.
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

# Insert the file contents immediately before Moodle setup.php.
awk -v fragment="$FRAGMENT" '
/require_once.*lib\/setup\.php/ && !done {
    while ((getline line < fragment) > 0) {
        print line
    }
    close(fragment)
    print ""
    done = 1
}
{
    print
}
' "$CONFIG" > "${CONFIG}.tmp"

mv "${CONFIG}.tmp" "$CONFIG"
rm -f "$FRAGMENT"

echo "Moodle PgBouncer + Redis configuration complete."