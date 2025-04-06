<?php
// Désactiver l'affichage des erreurs pour la production
// error_reporting(0);

// Chemin racine de l'application
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Définir l'en-tête de la réponse comme JSON
header('Content-Type: application/json');

// Vérifier si l'ID est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de technicien manquant'
    ]);
    exit;
}

$id = intval($_GET['id']);

// Valider l'ID
if ($id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de technicien invalide'
    ]);
    exit;
}

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // Préparer la requête
    $query = "SELECT id, nom, prenom, date_naissance, specialite, email, user_name 
              FROM technicien 
              WHERE id = :id 
              LIMIT 1";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $technicien = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'technicien' => $technicien
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Technicien non trouvé'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de base de données: ' . $e->getMessage()
    ]);
}
?>