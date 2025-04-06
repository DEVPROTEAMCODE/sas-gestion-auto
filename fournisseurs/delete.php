<?php
// Démarrer la session
session_start();

$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $success = false;

    // Vérification et nettoyage des données
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($id > 0) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Vérifier si le fournisseur est utilisé dans d'autres tables (par exemple, commandes ou produits)
            $query_check = "SELECT COUNT(*) FROM commandes WHERE Fournisseur_ID = :id";
            $stmt_check = $db->prepare($query_check);
            $stmt_check->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_check->execute();
            
            if ($stmt_check->fetchColumn() > 0) {
                // Le fournisseur est utilisé, on ne peut pas le supprimer
                $_SESSION['error_message'] = "Ce fournisseur ne peut pas être supprimé car il est associé à des commandes existantes.";
                header("Location: ../fournisseurs.php?error=in_use");
                exit;
            }
            
            // Option 1: Suppression physique
            $query = "DELETE FROM fournisseurs WHERE id = :id";
            
            // Option 2: Suppression logique (alternative)
            // $query = "UPDATE fournisseurs SET Actif = 0, Date_Suppression = NOW() WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $success = true;
                $_SESSION['success_message'] = "Fournisseur supprimé avec succès";
                header("Location: ../fournisseurs.php?success=deleted");
                exit;
            } else {
                $errors['database'] = "Erreur lors de la suppression du fournisseur.";
            }
        } catch (PDOException $e) {
            $errors['database'] = "Erreur: " . $e->getMessage();
        }
    } else {
        $errors['id'] = "ID de fournisseur invalide.";
    }
    
    // Si on arrive ici, c'est qu'il y a eu une erreur
    $_SESSION['error_message'] = isset($errors['database']) ? $errors['database'] : "Une erreur s'est produite lors de la suppression du fournisseur.";
    header('Location: index.php');
    exit;
}

// Si la méthode n'est pas POST, rediriger vers la page principale
header("Location: ../fournisseurs.php");
exit;
?>
