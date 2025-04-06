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

// Vérifier si l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID utilisateur non valide']);
    exit;
}

$id = intval($_GET['id']);

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les informations de l'utilisateur
    $query = "SELECT id, username, nom, prenom, email, role, date_creation, derniere_connexion, actif
              FROM users
              WHERE id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Convertir les dates pour l'affichage
        if ($user['date_creation']) {
            $user['date_creation_formatted'] = date('d/m/Y H:i', strtotime($user['date_creation']));
        } else {
            $user['date_creation_formatted'] = 'N/A';
        }
        
        if ($user['derniere_connexion']) {
            $user['derniere_connexion_formatted'] = date('d/m/Y H:i', strtotime($user['derniere_connexion']));
        } else {
            $user['derniere_connexion_formatted'] = 'Jamais';
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
}
?>
