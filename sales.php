<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

if($_SERVER['REQUEST_METHOD'] == 'GET') {
    getSales();
}

function getSales() {
    global $db;
    $userId = $_GET['user_id'] ?? null;
    $fromDate = $_GET['from_date'] ?? null;
    $toDate = $_GET['to_date'] ?? null;
    
    try {
        $query = "SELECT s.*, u.name as user_name, g.id as game_number 
                  FROM sales s 
                  JOIN users u ON s.user_id = u.id 
                  JOIN games g ON s.game_id = g.id 
                  WHERE 1=1";
        
        $params = array();
        
        if($userId && is_numeric($userId)) {
            $query .= " AND s.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        if($fromDate && isValidDate($fromDate)) {
            $query .= " AND DATE(s.created_at) >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        
        if($toDate && isValidDate($toDate)) {
            $query .= " AND DATE(s.created_at) <= :to_date";
            $params[':to_date'] = $toDate;
        }
        
        $query .= " ORDER BY s.created_at DESC";
        
        $stmt = $db->prepare($query);
        foreach($params as $key => $value) {
            if ($key === ':user_id') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format numeric fields properly
        foreach($sales as &$sale) {
            $sale['id'] = (int)$sale['id'];
            $sale['game_id'] = (int)$sale['game_id'];
            $sale['user_id'] = (int)$sale['user_id'];
            $sale['game_number'] = (int)$sale['game_number'];
            $sale['total_pool'] = (float)$sale['total_pool'];
            $sale['prize_amount'] = (float)$sale['prize_amount'];
            $sale['commission'] = (float)$sale['commission'];
            $sale['user_commission'] = (float)$sale['user_commission'];
            $sale['user_commission_rate'] = (float)$sale['user_commission_rate'];
        }
        
        echo json_encode($sales);
    } catch(PDOException $e) {
        error_log("Get sales error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error fetching sales data']);
    }
}

function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// Additional function to get sales statistics
function getSalesStats() {
    global $db;
    $userId = $_GET['user_id'] ?? null;
    $fromDate = $_GET['from_date'] ?? null;
    $toDate = $_GET['to_date'] ?? null;
    
    try {
        $query = "SELECT 
                    COUNT(*) as total_games,
                    SUM(total_pool) as total_sales,
                    SUM(prize_amount) as total_prizes,
                    SUM(commission) as total_commission,
                    SUM(user_commission) as user_total_commission,
                    AVG(user_commission_rate) as avg_commission_rate
                  FROM sales s 
                  WHERE 1=1";
        
        $params = array();
        
        if($userId && is_numeric($userId)) {
            $query .= " AND s.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        if($fromDate && isValidDate($fromDate)) {
            $query .= " AND DATE(s.created_at) >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        
        if($toDate && isValidDate($toDate)) {
            $query .= " AND DATE(s.created_at) <= :to_date";
            $params[':to_date'] = $toDate;
        }
        
        $stmt = $db->prepare($query);
        foreach($params as $key => $value) {
            if ($key === ':user_id') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Format numeric fields
        $stats['total_games'] = (int)($stats['total_games'] ?? 0);
        $stats['total_sales'] = (float)($stats['total_sales'] ?? 0);
        $stats['total_prizes'] = (float)($stats['total_prizes'] ?? 0);
        $stats['total_commission'] = (float)($stats['total_commission'] ?? 0);
        $stats['user_total_commission'] = (float)($stats['user_total_commission'] ?? 0);
        $stats['avg_commission_rate'] = (float)($stats['avg_commission_rate'] ?? 0);
        
        echo json_encode($stats);
    } catch(PDOException $e) {
        error_log("Get sales stats error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error fetching sales statistics']);
    }
}

// Handle stats request
if (isset($_GET['action']) && $_GET['action'] == 'stats') {
    getSalesStats();
}
?>