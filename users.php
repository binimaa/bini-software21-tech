<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        if(isset($_GET['action']) && $_GET['action'] == 'login') {
            login();
        } else {
            getUsers();
        }
        break;
    case 'POST':
        $input = json_decode(file_get_contents("php://input"), true);
        if ($input && isset($input['action'])) {
            switch($input['action']) {
                case 'create':
                    createUser($input);
                    break;
                case 'update':
                    updateUser($input);
                    break;
                case 'change_password':
                    changePassword($input);
                    break;
            }
        }
        break;
    case 'DELETE':
        $input = json_decode(file_get_contents("php://input"), true);
        deleteUser($input);
        break;
}

function login() {
    global $db;
    $username = $_GET['username'] ?? '';
    $password = $_GET['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        return;
    }
    
    try {
        $query = "SELECT * FROM users WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($user) {
            $passwordMatch = false;
            
            // Check if password is hashed (starts with $2y$ for bcrypt)
            if (strpos($user['password'], '$2y$') === 0) {
                // Password is encrypted, use password_verify
                $passwordMatch = password_verify($password, $user['password']);
            } else {
                // Password is plain text, do direct comparison
                $passwordMatch = ($password === $user['password']);
                
                // Automatically encrypt the password for future use
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateQuery = "UPDATE users SET password = :password WHERE id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
                $updateStmt->bindParam(':id', $user['id'], PDO::PARAM_INT);
                $updateStmt->execute();
            }
            
            if ($passwordMatch) {
                // Remove password from response
                unset($user['password']);
                
                // Ensure numeric fields are properly formatted
                $user['id'] = (int)$user['id'];
                $user['credit'] = (float)$user['credit'];
                $user['balance'] = (float)$user['balance'];
                $user['commission_percent'] = (float)$user['commission_percent'];
                $user['commission_earned'] = (float)($user['commission_earned'] ?? 0);
                $user['is_restricted'] = (bool)$user['is_restricted'];
                
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
        }
    } catch(PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Login error occurred']);
    }
}

function getUsers() {
    global $db;
    try {
        $query = "SELECT id, username, name, shop_name, credit, balance, commission_percent, role, is_restricted, commission_earned, created_at FROM users ORDER BY created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format numeric fields properly
        foreach($users as &$user) {
            $user['id'] = (int)$user['id'];
            $user['credit'] = (float)$user['credit'];
            $user['balance'] = (float)$user['balance'];
            $user['commission_percent'] = (float)$user['commission_percent'];
            $user['commission_earned'] = (float)($user['commission_earned'] ?? 0);
            $user['is_restricted'] = (bool)$user['is_restricted'];
        }
        
        echo json_encode($users);
    } catch(PDOException $e) {
        error_log("Get users error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error fetching users']);
    }
}

function createUser($data) {
    global $db;
    
    // Validate required fields
    $required = ['name', 'username', 'password', 'shopName', 'credit', 'percent', 'role'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    // Validate data types and ranges
    if (!is_numeric($data['credit']) || $data['credit'] < 0) {
        echo json_encode(['success' => false, 'message' => 'Credit must be a valid positive number']);
        return;
    }
    
    if (!is_numeric($data['percent']) || $data['percent'] < 0 || $data['percent'] > 100) {
        echo json_encode(['success' => false, 'message' => 'Commission percent must be between 0 and 100']);
        return;
    }
    
    if (!in_array($data['role'], ['admin', 'user'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid role specified']);
        return;
    }
    
    if (strlen($data['password']) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long']);
        return;
    }
    
    try {
        // Check if username already exists
        $checkQuery = "SELECT COUNT(*) FROM users WHERE username = :username";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':username', $data['username'], PDO::PARAM_STR);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            return;
        }
        
        // Always encrypt passwords for new users
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, password, name, shop_name, credit, balance, commission_percent, role) 
                  VALUES (:username, :password, :name, :shop_name, :credit, :balance, :percent, :role)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $data['username'], PDO::PARAM_STR);
        $stmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':shop_name', $data['shopName'], PDO::PARAM_STR);
        $stmt->bindParam(':credit', $data['credit'], PDO::PARAM_STR);
        $stmt->bindParam(':balance', $data['credit'], PDO::PARAM_STR); // Initial balance = credit
        $stmt->bindParam(':percent', $data['percent'], PDO::PARAM_STR);
        $stmt->bindParam(':role', $data['role'], PDO::PARAM_STR);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User created successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create user']);
        }
    } catch(PDOException $e) {
        error_log("Create user error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function updateUser($data) {
    global $db;
    
    // Validate required fields
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        echo json_encode(['success' => false, 'message' => 'Valid user ID is required']);
        return;
    }
    
    // Validate data types and ranges
    if (isset($data['credit']) && (!is_numeric($data['credit']) || $data['credit'] < 0)) {
        echo json_encode(['success' => false, 'message' => 'Credit must be a valid positive number']);
        return;
    }
    
    if (isset($data['percent']) && (!is_numeric($data['percent']) || $data['percent'] < 0 || $data['percent'] > 100)) {
        echo json_encode(['success' => false, 'message' => 'Commission percent must be between 0 and 100']);
        return;
    }
    
    try {
        $query = "UPDATE users SET name = :name, shop_name = :shop_name, credit = :credit, 
                  commission_percent = :percent, is_restricted = :restricted WHERE id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id'], PDO::PARAM_INT);
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':shop_name', $data['shopName'], PDO::PARAM_STR);
        $stmt->bindParam(':credit', $data['credit'], PDO::PARAM_STR);
        $stmt->bindParam(':percent', $data['percent'], PDO::PARAM_STR);
        $stmt->bindParam(':restricted', $data['isRestricted'], PDO::PARAM_BOOL);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update user']);
        }
    } catch(PDOException $e) {
        error_log("Update user error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function changePassword($data) {
    global $db;
    
    // Validate required fields
    if (!isset($data['userId']) || !is_numeric($data['userId'])) {
        echo json_encode(['success' => false, 'message' => 'Valid user ID is required']);
        return;
    }
    
    if (!isset($data['currentPassword']) || !isset($data['newPassword'])) {
        echo json_encode(['success' => false, 'message' => 'Current and new passwords are required']);
        return;
    }
    
    if (strlen($data['newPassword']) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters long']);
        return;
    }
    
    try {
        // Get current password from database
        $query = "SELECT password FROM users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['userId'], PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        
        // Verify current password (handle both encrypted and plain text)
        $currentPasswordMatch = false;
        if (strpos($user['password'], '$2y$') === 0) {
            // Password is encrypted, use password_verify
            $currentPasswordMatch = password_verify($data['currentPassword'], $user['password']);
        } else {
            // Password is plain text, do direct comparison
            $currentPasswordMatch = ($data['currentPassword'] === $user['password']);
        }
        
        if(!$currentPasswordMatch) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            return;
        }
        
        // Always encrypt the new password
        $hashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
        
        $query = "UPDATE users SET password = :password WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
        $stmt->bindParam(':id', $data['userId'], PDO::PARAM_INT);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update password']);
        }
    } catch(PDOException $e) {
        error_log("Change password error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function deleteUser($data) {
    global $db;
    
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        echo json_encode(['success' => false, 'message' => 'Valid user ID is required']);
        return;
    }
    
    try {
        // Check if user exists
        $checkQuery = "SELECT username FROM users WHERE id = :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $data['id'], PDO::PARAM_INT);
        $checkStmt->execute();
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        
        $query = "DELETE FROM users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id'], PDO::PARAM_INT);
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
        }
    } catch(PDOException $e) {
        error_log("Delete user error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}
?>