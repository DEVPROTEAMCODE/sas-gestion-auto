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

// Traiter le formulaire de modification d'utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer et valider les données du formulaire
    $id = intval($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $actif = isset($_POST['actif']) ? 1 : 0;
    
    // Validation des données
    $errors = [];
    
    if ($id <= 0) {
        $errors[] = "ID utilisateur invalide.";
    }
    
    if (empty($username)) {
        $errors[] = "Le nom d'utilisateur est obligatoire.";
    }
    
    if (empty($nom)) {
        $errors[] = "Le nom est obligatoire.";
    }
    
    if (empty($prenom)) {
        $errors[] = "Le prénom est obligatoire.";
    }
    
    if (empty($email)) {
        $errors[] = "L'email est obligatoire.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide.";
    }
    
    if (empty($role)) {
        $errors[] = "Le rôle est obligatoire.";
    } elseif (!in_array($role, ['admin', 'manager', 'technicien', 'comptable'])) {
        $errors[] = "Le rôle sélectionné n'est pas valide.";
    }
    
    // Si aucune erreur, procéder à la modification
    if (empty($errors)) {
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
            
            // Vérifier si le nom d'utilisateur ou l'email existe déjà pour un autre utilisateur
            $check_duplicates_query = "SELECT COUNT(*) as count FROM users WHERE (username = :username OR email = :email) AND id != :id";
            $check_duplicates_stmt = $db->prepare($check_duplicates_query);
            $check_duplicates_stmt->bindParam(':username', $username);
            $check_duplicates_stmt->bindParam(':email', $email);
            $check_duplicates_stmt->bindParam(':id', $id);
            $check_duplicates_stmt->execute();
            $result = $check_duplicates_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $_SESSION['error'] = "Le nom d'utilisateur ou l'email existe déjà.";
                header('Location: index.php?type=admin');
                exit;
            }
            
            // Préparer la requête de mise à jour
            $query = "UPDATE users SET 
                      username = :username,
                      nom = :nom,
                      prenom = :prenom,
                      email = :email,
                      role = :role,
                      actif = :actif
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':prenom', $prenom);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':actif', $actif);
            $stmt->bindParam(':id', $id);
            
            // Exécuter la requête
            if ($stmt->execute()) {
                $_SESSION['success'] = "L'utilisateur a été modifié avec succès.";
            } else {
                $_SESSION['error'] = "Une erreur est survenue lors de la modification de l'utilisateur.";
            }
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
        }
    } else {
        // S'il y a des erreurs, les stocker dans la session
        $_SESSION['error'] = implode("<br>", $errors);
    }
    
    // Rediriger vers la page d'administration
    header('Location: index.php?type=admin');
    exit;
}
?>
