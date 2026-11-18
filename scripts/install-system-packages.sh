#!/bin/sh
set -eu

max_attempts=${APT_INSTALL_MAX_ATTEMPTS:-3}
retry_delay=${APT_INSTALL_RETRY_DELAY_SECONDS:-10}

case "$max_attempts" in
  ''|*[!0-9]*|0) echo "APT_INSTALL_MAX_ATTEMPTS must be a positive integer" >&2; exit 2 ;;
esac
case "$retry_delay" in
  ''|*[!0-9]*) echo "APT_INSTALL_RETRY_DELAY_SECONDS must be a non-negative integer" >&2; exit 2 ;;
esac
[ "$#" -gt 0 ] || { echo "at least one package is required" >&2; exit 2; }

attempt=1
while :; do
  if apt-get -o Acquire::Retries=3 -o APT::Update::Error-Mode=any update && \
     DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install -y --no-install-recommends "$@"; then
    exit 0
  fi
  if [ "$attempt" -ge "$max_attempts" ]; then
    echo "apt package bootstrap failed after $attempt attempt(s)" >&2
    exit 1
  fi
  echo "apt package bootstrap attempt $attempt failed; retrying" >&2
  sleep "$retry_delay"
  attempt=$((attempt + 1))
done
