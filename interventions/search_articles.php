<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer le terme de recherche
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (empty($search)) {
    echo json_encode(['success' => false, 'message' => 'Terme de recherche non spécifié.']);
    exit;
}

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // Rechercher les articles correspondant au terme de recherche
    $query = "SELECT a.* FROM articles a
              WHERE a.reference LIKE :search 
              OR a.designation LIKE :search
              ORDER BY a.designation ASC
              LIMIT 50";
    
    $stmt = $db->prepare($query);
    $searchParam = '%' . $search . '%';
    $stmt->bindParam(':search', $searchParam);
    $stmt->execute();
    
    $articles = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $articles[] = $row;
    }
    
    echo json_encode(['success' => true, 'articles' => $articles]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
}
?>