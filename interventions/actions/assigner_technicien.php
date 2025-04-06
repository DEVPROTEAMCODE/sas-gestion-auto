<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(dirname(__DIR__));

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les données du formulaire
    $intervention_id = isset($_POST['intervention_id']) ? intval($_POST['intervention_id']) : 0;
    $technicien_id = isset($_POST['technicien_id']) ? intval($_POST['technicien_id']) : 0;
    
    // Validation des données
    $errors = [];
    
    if (empty($intervention_id)) {
        $errors[] = 'ID d\'intervention invalide.';
    }
    
    if (empty($technicien_id)) {
        $errors[] = 'Veuillez sélectionner un technicien.';
    }
    
    // Si aucune erreur, procéder à l'assignation
    if (empty($errors)) {
        try {
            // Connexion à la base de données
            $database = new Database();
            $db = $database->getConnection();
            
            // Mettre à jour l'intervention avec le technicien assigné
            $query = "UPDATE interventions SET technicien_id = :technicien_id, date_modification = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':technicien_id', $technicien_id);
            $stmt->bindParam(':id', $intervention_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = 'Le technicien a été assigné avec succès.';
            } else {
                $_SESSION['error'] = 'Une erreur est survenue lors de l\'assignation du technicien.';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Erreur de base de données: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
    
    header('Location: ../index.php');
    exit;
} else {
    // Redirection si accès direct
    header('Location: ../index.php');
    exit;
}
?>