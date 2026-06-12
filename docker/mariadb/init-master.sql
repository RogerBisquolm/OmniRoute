-- Create replication user
CREATE USER 'replicator'@'%' IDENTIFIED BY 'replicator_password';
GRANT REPLICATION SLAVE ON *.* TO 'replicator'@'%';

-- Ensure the gateway database exists and select it
CREATE DATABASE IF NOT EXISTS gateway_db;
USE gateway_db;

-- Create token tracking logs table
CREATE TABLE IF NOT EXISTS token_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NULL,
    intent VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    prompt_tokens INT NOT NULL,
    completion_tokens INT NOT NULL,
    total_tokens INT NOT NULL,
    latency_ms INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Create index for analytics and lookup queries
CREATE INDEX idx_token_logs_api_key ON token_logs(api_key_id);
CREATE INDEX idx_token_logs_created_at ON token_logs(created_at);

FLUSH PRIVILEGES;
