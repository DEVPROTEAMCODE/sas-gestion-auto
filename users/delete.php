<?php
// Démarrer la session
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
// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer l'ID du technicien à supprimer
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    // Valider l'ID
    if ($id <= 0) {
        $_SESSION['error'] = "ID de technicien invalide";
        header('Location: index.php');
        exit;
    }
    
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        // Vérifier si le technicien existe
        $check_query = "SELECT COUNT(*) FROM technicien WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        if ($check_stmt->fetchColumn() == 0) {
            $_SESSION['error'] = "Ce technicien n'existe pas";
            header('Location: index.php');
            exit;
        }
        
        // Préparer la requête de suppression
        $query = "DELETE FROM technicien WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        // Exécuter la requête
        if ($stmt->execute()) {
            $_SESSION['success'] = "Le technicien a été supprimé avec succès";
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression du technicien";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    }
    
    // Rediriger vers la page des techniciens
    header('Location: index.php');
    exit;
} else {
    // Si la méthode n'est pas POST, rediriger vers la page des techniciens
    header('Location: index.php');
    exit;
}
?>