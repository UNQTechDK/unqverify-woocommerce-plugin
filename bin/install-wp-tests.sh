#!/usr/bin/env bash
# Installs the WordPress test suite and a test database inside the wp-env
# tests-cli container. Run this once after `wp-env start`:
#
#   npx wp-env run tests-cli bash /var/www/html/wp-content/plugins/aldersverificering-woocommerce/bin/install-wp-tests.sh wordpress_test root password mysql latest
#
# Arguments (all optional; defaults shown):
#   $1 DB_NAME    (wordpress_test)
#   $2 DB_USER    (root)
#   $3 DB_PASS    (password)
#   $4 DB_HOST    (mysql)
#   $5 WP_VERSION (latest)

set -e

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-password}"
DB_HOST="${4:-mysql}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
    if command -v curl >/dev/null 2>&1; then
        curl -s "$1" > "$2"
    elif command -v wget >/dev/null 2>&1; then
        wget -nv -O "$2" "$1"
    else
        echo "Error: neither curl nor wget is available." >&2
        exit 1
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION == 'latest' ]]; then
    download "https://api.wordpress.org/core/version-check/1.7/" /tmp/wp-latest.json
    BODY=$(cat /tmp/wp-latest.json)
    WP_VERSION=$(echo "$BODY" | grep -o '"version":"[^"]*"' | head -1 | sed 's/"version":"//;s/"//')
    WP_TESTS_TAG="tags/$WP_VERSION"
else
    WP_TESTS_TAG="tags/$WP_VERSION"
fi

# Install WordPress core (needed for test bootstrapping).
if [ ! -d "$WP_CORE_DIR" ]; then
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" /tmp/wordpress.tar.gz
    tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    rm /tmp/wordpress.tar.gz
fi

# Install WordPress test library.
if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet --ignore-externals \
        "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" \
        "$WP_TESTS_DIR/includes"
    svn co --quiet --ignore-externals \
        "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" \
        "$WP_TESTS_DIR/data"
fi

# Create wp-tests-config.php.
if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    download \
        "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" \
        "$WP_TESTS_DIR/wp-tests-config.php"
    # Multisite not needed.
    WP_CORE_DIR_ESC=$(echo "$WP_CORE_DIR" | sed 's/\//\\\//g')
    sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR_ESC}/':g" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/"   "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/"           "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/"           "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|localhost|$DB_HOST|"                  "$WP_TESTS_DIR/wp-tests-config.php"
fi

# Create the test database.
mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true

echo "Done. Run phpunit --testsuite integration inside the tests-cli container."
