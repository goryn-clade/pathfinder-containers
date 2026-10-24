#!/bin/sh
set -e

# The map history logs are a bind mount (./logs), so the image cannot own them.
# Started as root: hand anything not owned by nobody to nobody, then run the
# server as nobody. nobody (65534) is also the pf container's php-fpm user.
# Keep LOG_DIR in step with LogFileHandler::STREAM_ROOT and the compose volume.
LOG_DIR=/var/www/html/pathfinder/history/map
RUN_AS=

if [ "$(id -u)" = 0 ]; then
    # -h and find (no -L): change symlinks themselves, never their targets.
    { mkdir -p "$LOG_DIR" && find "$LOG_DIR" ! -user nobody -exec chown -h nobody:nobody {} +; } \
        || echo "WARNING: could not give $LOG_DIR to nobody. Map history logs may not be written." >&2
    RUN_AS="su-exec nobody"
fi

exec $RUN_AS /usr/local/bin/php cmd.php "$@"
