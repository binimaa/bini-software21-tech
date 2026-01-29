<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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
        getGames();
        break;
    case 'POST':
        $input = json_decode(file_get_contents("php://input"), true);
        if ($input && isset($input['action'])) {
            switch($input['action']) {
                case 'create':
                    createGame($input);
                    break;
                case 'complete':
                    completeGame($input);
                    break;
            }
        }
        break;
    case 'PUT':
        $input = json_decode(file_get_contents("php://input"), true);
        updateGame($input);
        break;
}

function getGames() {
    global $db;
    $userId = $_GET['user_id'] ?? null;
    
    try {
        $query = "SELECT g.*, u.name as user_name FROM games g 
                  JOIN users u ON g.user_id = u.id";
        
        $params = array();
        
        if($userId && is_numeric($userId)) {
            $query .= " WHERE g.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        $query .= " ORDER BY g.created_at DESC";
        
        $stmt = $db->prepare($query);
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        
        $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON fields and format data
        foreach($games as &$game) {
            $game['id'] = (int)$game['id'];
            $game['user_id'] = (int)$game['user_id'];
            $game['selected_cards'] = json_decode($game['selected_cards'], true) ?: [];
            $game['pattern_requirements'] = json_decode($game['pattern_requirements'], true) ?: [];
            $game['called_numbers'] = json_decode($game['called_numbers'], true) ?: [];
            $game['bet_amount'] = (float)$game['bet_amount'];
            $game['total_pool'] = (float)$game['total_pool'];
            $game['prize_pool'] = (float)$game['prize_pool'];
            $game['commission'] = (float)$game['commission'];
            $game['user_commission'] = (float)$game['user_commission'];
            $game['winner_prize'] = $game['winner_prize'] ? (float)$game['winner_prize'] : null;
            $game['winner_card'] = $game['winner_card'] ? (int)$game['winner_card'] : null;
        }
        
        echo json_encode($games);
    } catch(PDOException $e) {
        error_log("Get games error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error fetching games']);
    }
}

function createGame($data) {
    global $db;
    
    // Validate required fields
    $required = ['user_id', 'selected_cards', 'pattern_requirements', 'winning_strategy', 
                 'bet_amount', 'total_pool', 'prize_pool', 'commission', 'user_commission'];
    
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    // Validate data types
    if (!is_numeric($data['user_id']) || !is_array($data['selected_cards']) || 
        !is_array($data['pattern_requirements']) || !is_numeric($data['bet_amount']) ||
        !is_numeric($data['total_pool']) || !is_numeric($data['prize_pool']) ||
        !is_numeric($data['commission']) || !is_numeric($data['user_commission'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data types provided']);
        return;
    }
    
    // Validate positive amounts
    if ($data['bet_amount'] <= 0 || $data['total_pool'] <= 0 || $data['prize_pool'] < 0 ||
        $data['commission'] < 0 || $data['user_commission'] < 0) {
        echo json_encode(['success' => false, 'message' => 'Amounts must be positive numbers']);
        return;
    }
    
    try {
        // Verify user exists
        $userQuery = "SELECT id FROM users WHERE id = :user_id";
        $userStmt = $db->prepare($userQuery);
        $userStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
        $userStmt->execute();
        
        if (!$userStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        
        $query = "INSERT INTO games (user_id, selected_cards, pattern_requirements, winning_strategy, 
                  custom_strategy, bet_amount, total_pool, prize_pool, commission, user_commission, status) 
                  VALUES (:user_id, :selected_cards, :pattern_requirements, :winning_strategy, 
                  :custom_strategy, :bet_amount, :total_pool, :prize_pool, :commission, :user_commission, 'active')";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':selected_cards', json_encode($data['selected_cards']), PDO::PARAM_STR);
        $stmt->bindParam(':pattern_requirements', json_encode($data['pattern_requirements']), PDO::PARAM_STR);
        $stmt->bindParam(':winning_strategy', $data['winning_strategy'], PDO::PARAM_STR);
        $stmt->bindParam(':custom_strategy', $data['custom_strategy'] ?? '', PDO::PARAM_STR);
        $stmt->bindParam(':bet_amount', $data['bet_amount'], PDO::PARAM_STR);
        $stmt->bindParam(':total_pool', $data['total_pool'], PDO::PARAM_STR);
        $stmt->bindParam(':prize_pool', $data['prize_pool'], PDO::PARAM_STR);
        $stmt->bindParam(':commission', $data['commission'], PDO::PARAM_STR);
        $stmt->bindParam(':user_commission', $data['user_commission'], PDO::PARAM_STR);
        
        if($stmt->execute()) {
            $gameId = $db->lastInsertId();
            echo json_encode(['success' => true, 'game_id' => $gameId, 'message' => 'Game created successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create game']);
        }
    } catch(PDOException $e) {
        error_log("Create game error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function completeGame($data) {
    global $db;
    
    // Validate required fields
    $required = ['game_id', 'user_id', 'winner_card', 'winner_pattern', 'winner_prize', 
                 'called_numbers', 'total_pool', 'commission', 'user_commission', 'user_commission_rate'];
    
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    // Validate data types
    if (!is_numeric($data['game_id']) || !is_numeric($data['user_id']) || 
        !is_numeric($data['winner_card']) || !is_array($data['called_numbers']) ||
        !is_numeric($data['winner_prize']) || !is_numeric($data['total_pool']) ||
        !is_numeric($data['commission']) || !is_numeric($data['user_commission']) ||
        !is_numeric($data['user_commission_rate'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data types provided']);
        return;
    }
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Verify game exists and is active
        $gameQuery = "SELECT status FROM games WHERE id = :game_id AND user_id = :user_id";
        $gameStmt = $db->prepare($gameQuery);
        $gameStmt->bindParam(':game_id', $data['game_id'], PDO::PARAM_INT);
        $gameStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
        $gameStmt->execute();
        $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$game) {
            throw new Exception('Game not found');
        }
        
        if ($game['status'] !== 'active') {
            throw new Exception('Game is not active');
        }
        
        // Update game status
        $updateGameQuery = "UPDATE games SET status = 'completed', winner_card = :winner_card, 
                           winner_pattern = :winner_pattern, winner_prize = :winner_prize, 
                           called_numbers = :called_numbers WHERE id = :game_id";
        
        $updateStmt = $db->prepare($updateGameQuery);
        $updateStmt->bindParam(':game_id', $data['game_id'], PDO::PARAM_INT);
        $updateStmt->bindParam(':winner_card', $data['winner_card'], PDO::PARAM_INT);
        $updateStmt->bindParam(':winner_pattern', $data['winner_pattern'], PDO::PARAM_STR);
        $updateStmt->bindParam(':winner_prize', $data['winner_prize'], PDO::PARAM_STR);
        $updateStmt->bindParam(':called_numbers', json_encode($data['called_numbers']), PDO::PARAM_STR);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update game');
        }
        
        // Update user commission
        $updateUserQuery = "UPDATE users SET commission_earned = commission_earned + :commission 
                           WHERE id = :user_id";
        $updateUserStmt = $db->prepare($updateUserQuery);
        $updateUserStmt->bindParam(':commission', $data['user_commission'], PDO::PARAM_STR);
        $updateUserStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
        
        if (!$updateUserStmt->execute()) {
            throw new Exception('Failed to update user commission');
        }
        
        // Create sales record
        $salesQuery = "INSERT INTO sales (game_id, user_id, total_pool, prize_amount, commission, 
                       user_commission, user_commission_rate) 
                       VALUES (:game_id, :user_id, :total_pool, :prize_amount, :commission, 
                       :user_commission, :user_commission_rate)";
        
        $salesStmt = $db->prepare($salesQuery);
        $salesStmt->bindParam(':game_id', $data['game_id'], PDO::PARAM_INT);
        $salesStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
        $salesStmt->bindParam(':total_pool', $data['total_pool'], PDO::PARAM_STR);
        $salesStmt->bindParam(':prize_amount', $data['winner_prize'], PDO::PARAM_STR);
        $salesStmt->bindParam(':commission', $data['commission'], PDO::PARAM_STR);
        $salesStmt->bindParam(':user_commission', $data['user_commission'], PDO::PARAM_STR);
        $salesStmt->bindParam(':user_commission_rate', $data['user_commission_rate'], PDO::PARAM_STR);
        
        if (!$salesStmt->execute()) {
            throw new Exception('Failed to create sales record');
        }
        
        // Commit transaction
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Game completed successfully']);
        
    } catch(Exception $e) {
        // Rollback transaction
        $db->rollback();
        error_log("Complete game error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function updateGame($data) {
    global $db;
    
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        echo json_encode(['success' => false, 'message' => 'Valid game ID is required']);
        return;
    }
    
    try {
        // Build dynamic update query based on provided fields
        $updateFields = [];
        $params = [':id' => $data['id']];
        
        if (isset($data['status']) && in_array($data['status'], ['active', 'completed', 'cancelled'])) {
            $updateFields[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        
        if (isset($data['called_numbers']) && is_array($data['called_numbers'])) {
            $updateFields[] = "called_numbers = :called_numbers";
            $params[':called_numbers'] = json_encode($data['called_numbers']);
        }
        
        if (empty($updateFields)) {
            echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
            return;
        }
        
        $query = "UPDATE games SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Game updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update game']);
        }
    } catch(PDOException $e) {
        error_log("Update game error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}
?>