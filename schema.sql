CREATE DATABASE habesha_bingo_pro_v2;
USE habesha_bingo_pro_v2;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    shop_name VARCHAR(100) NOT NULL,
    credit DECIMAL(10,2) DEFAULT 0.00,
    balance DECIMAL(10,2) DEFAULT 0.00,
    commission_percent DECIMAL(5,2) DEFAULT 10.00,
    role ENUM('admin', 'user') DEFAULT 'user',
    is_restricted BOOLEAN DEFAULT FALSE,
    commission_earned DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Games table
CREATE TABLE games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selected_cards JSON NOT NULL,
    pattern_requirements JSON NOT NULL,
    winning_strategy VARCHAR(50) NOT NULL,
    custom_strategy TEXT,
    bet_amount DECIMAL(10,2) NOT NULL,
    total_pool DECIMAL(10,2) NOT NULL,
    prize_pool DECIMAL(10,2) NOT NULL,
    commission DECIMAL(10,2) NOT NULL,
    user_commission DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    winner_card INT,
    winner_pattern VARCHAR(50),
    winner_prize DECIMAL(10,2),
    called_numbers JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sales table
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    user_id INT NOT NULL,
    total_pool DECIMAL(10,2) NOT NULL,
    prize_amount DECIMAL(10,2) NOT NULL,
    commission DECIMAL(10,2) NOT NULL,
    user_commission DECIMAL(10,2) NOT NULL,
    user_commission_rate DECIMAL(5,2) NOT NULL,
    status VARCHAR(50) DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Settings table
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default admin user with plain text password
INSERT INTO users (username, password, name, shop_name, credit, balance, commission_percent, role) 
VALUES ('admin', 'admin123', 'Administrator', 'Main Shop', 50000.00, 45000.00, 15.00, 'admin');

-- Insert additional sample users for testing with plain text passwords
INSERT INTO users (username, password, name, shop_name, credit, balance, commission_percent, role) 
VALUES 
('user1', 'user123', 'John Doe', 'John Shop', 25000.00, 22000.00, 12.00, 'user'),
('user2', 'user123', 'Jane Smith', 'Jane Shop', 30000.00, 28000.00, 8.00, 'user');

-- Insert default settings
INSERT INTO settings (setting_key, setting_value) VALUES 
('voice_speed', '4'),
('voice_type', 'default'),
('winner_percentage', '71.2'),
('commission_percentage', '28.8');