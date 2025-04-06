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

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Initialiser la réponse
$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

// Vérifier si la requête est de type POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    $errors = [];
    
    // Validation des champs obligatoires
    if (empty($_POST['code_fournisseur'])) {
        $errors['code_fournisseur'] = 'Le code fournisseur est requis';
    }
    
    if (empty($_POST['raison_sociale'])) {
        $errors['raison_sociale'] = 'La raison sociale est requise';
    }
    
    // Si aucune erreur, créer le fournisseur
    if (empty($errors)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Vérifier si le code fournisseur existe déjà
            $query = "SELECT COUNT(*) FROM fournisseurs WHERE Code_Fournisseur = :code_fournisseur";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':code_fournisseur', $_POST['code_fournisseur']);
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                $response['message'] = 'Ce code fournisseur existe déjà';
                $response['errors']['code_fournisseur'] = 'Ce code fournisseur existe déjà';
            } else {
                // Préparer la requête d'insertion
                $query = "INSERT INTO fournisseurs (
                    Code_Fournisseur, 
                    Raison_Sociale, 
                    Adresse, 
                    Code_Postal, 
                    Ville, 
                    Telephone, 
                    Email, 
                    Contact_Principal, 
                    Conditions_Paiement_Par_Defaut, 
                    Delai_Livraison_Moyen, 
                    Actif, 
                    Date_Creation
                ) VALUES (
                    :code_fournisseur, 
                    :raison_sociale, 
                    :adresse, 
                    :code_postal, 
                    :ville, 
                    :telephone, 
                    :email, 
                    :contact_principal, 
                    :conditions_paiement, 
                    :delai_livraison, 
                    :actif, 
                    NOW()
                )";
                
                $stmt = $db->prepare($query);
                
                // Binder les paramètres
                $stmt->bindParam(':code_fournisseur', $_POST['code_fournisseur']);
                $stmt->bindParam(':raison_sociale', $_POST['raison_sociale']);
                $stmt->bindParam(':adresse', $_POST['adresse']);
                $stmt->bindParam(':code_postal', $_POST['code_postal']);
                $stmt->bindParam(':ville', $_POST['ville']);
                $stmt->bindParam(':telephone', $_POST['telephone']);
                $stmt->bindParam(':email', $_POST['email']);
                $stmt->bindParam(':contact_principal', $_POST['contact_principal']);
                $stmt->bindParam(':conditions_paiement', $_POST['conditions_paiement']);
                $stmt->bindParam(':delai_livraison', $_POST['delai_livraison']);
                $stmt->bindParam(':actif', $_POST['actif']);
                
                // Exécuter la requête
                if ($stmt->execute()) {
                    // Récupérer l'ID du fournisseur nouvellement créé
                    $fournisseur_id = $db->lastInsertId();
                    
                    $response['success'] = true;
                    $response['message'] = 'Fournisseur ajouté avec succès';
                    $response['fournisseur_id'] = $fournisseur_id;
                    
                    // Rediriger vers la page des fournisseurs
                    header('Location: index.php');
                    exit;
                } else {
                    $response['message'] = 'Erreur lors de l\'ajout du fournisseur';
                }
            }
        } catch (PDOException $e) {
            // Gérer les erreurs de base de données
            $response['message'] = 'Erreur de base de données: ' . $e->getMessage();
        }
    } else {
        $response['message'] = 'Veuillez corriger les erreurs dans le formulaire';
        $response['errors'] = $errors;
    }
}

// Si on arrive ici, c'est qu'il y a eu une erreur
// Stocker les erreurs en session pour les afficher après la redirection
$_SESSION['form_errors'] = $response['errors'];
$_SESSION['form_message'] = $response['message'];
$_SESSION['form_data'] = $_POST; // Pour repopuler le formulaire

// Rediriger vers la page des fournisseurs
header('Location: ../fournisseurs.php?error=true');
exit;
?>
