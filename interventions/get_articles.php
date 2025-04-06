<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
} else {
    error_log("Fichier database.php introuvable");
    echo json_encode(['success' => false, 'message' => 'Erreur de configuration: fichier database.php introuvable']);
    exit;
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer l'ID de la catégorie (optionnel)
$categorie_id = isset($_GET['categorie_id']) ? intval($_GET['categorie_id']) : 0;

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        error_log("Échec de la connexion à la base de données");
        echo json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données']);
        exit;
    }
    
    // Construire la requête en fonction de la présence ou non d'une catégorie
    $query = "SELECT a.* FROM articles a";
    
    if ($categorie_id > 0) {
        $query .= " WHERE a.categorie_id = :categorie_id";
    }
    
    $query .= " ORDER BY a.designation ASC";
    
    $stmt = $db->prepare($query);
    
    if ($categorie_id > 0) {
        $stmt->bindParam(':categorie_id', $categorie_id);
    }
    
    $success = $stmt->execute();
    
    if (!$success) {
        error_log("Erreur d'exécution de la requête: " . print_r($stmt->errorInfo(), true));
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'exécution de la requête']);
        exit;
    }
    
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Nombre d'articles trouvés: " . count($articles));
    
    echo json_encode(['success' => true, 'articles' => $articles]);
} catch (PDOException $e) {
    error_log("Exception PDO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Exception générale: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
?>
