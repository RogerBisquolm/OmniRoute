#!/bin/bash
set -e

echo "Starting replication configuration script in background..."

# Function to check if local MariaDB is running
wait_for_local_db() {
    until [ "$(cat /proc/1/comm 2>/dev/null)" = "mysqld" ] || [ "$(cat /proc/1/comm 2>/dev/null)" = "mariadbd" ]; do
        echo "Waiting for MariaDB server process to occupy PID 1..."
        sleep 2
    done

    until mariadb-admin ping -h localhost -uroot -p"${MARIADB_ROOT_PASSWORD}" --silent; do
        echo "Waiting for local MariaDB to start..."
        sleep 2
    done
}

# Function to check if master MariaDB is running
wait_for_master_db() {
    until mariadb-admin ping -h mariadb-master -ureplicator -preplicator_password --silent; do
        echo "Waiting for MariaDB Master to start..."
        sleep 2
    done
}

wait_for_local_db

# Check if replica is already running
slave_status=$(mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" -e "SHOW SLAVE STATUS\G")
if echo "$slave_status" | grep -q "Master_Host"; then
    echo "Replica replication is already configured."
else
    echo "Configuring replica replication..."
    wait_for_master_db
    
    # Configure slave to replicate from master using GTID
    mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" -e "CHANGE MASTER TO MASTER_HOST='mariadb-master', MASTER_USER='replicator', MASTER_PASSWORD='replicator_password', MASTER_PORT=3306, MASTER_CONNECT_RETRY=10, MASTER_USE_GTID=current_pos;"
    mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" -e "START SLAVE;"
    echo "Replica replication started successfully."
fi
