<?php
// Démarrer la session
session_start();


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'utilisateur est connecté et a les droits d'administrateur
/* if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "Vous n'avez pas les droits nécessaires pour effectuer cette action.";
    header('Location: index.php?type=admin');
    exit;
} */

// Traiter la demande de suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    
    // Ne pas permettre la suppression de l'utilisateur avec ID 1 (admin principal)
    if ($id === 1) {
        $_SESSION['error'] = "Impossible de supprimer l'administrateur principal.";
        header('Location: index.php?type=admin');
        exit;
    }
    
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        // Vérifier si l'utilisateur existe
        $check_query = "SELECT id FROM users WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() === 0) {
            $_SESSION['error'] = "L'utilisateur n'existe pas.";
            header('Location: index.php?type=admin');
            exit;
        }
        
        // Supprimer l'utilisateur
        $query = "DELETE FROM users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "L'utilisateur a été supprimé avec succès.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue lors de la suppression de l'utilisateur.";
        }
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    }
    
    // Rediriger vers la page d'administration
    header('Location: index.php?type=admin');
    exit;
} else {
    $_SESSION['error'] = "Requête invalide.";
    header('Location: index.php?type=admin');
    exit;
}
?>
