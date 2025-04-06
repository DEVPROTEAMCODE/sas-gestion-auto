<?php
// Démarrer la session
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/functions.php';

// Vérifier si la méthode est POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Récupérer l'action
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Initialiser la base de données
$database = new Database();
$db = $database->getConnection();

// Traiter selon l'action
switch ($action) {
    case 'create':
        createFournisseur($db);
        break;
    case 'update':
        updateFournisseur($db);
        break;
    case 'delete':
        deleteFournisseur($db);
        break;
    default:
        setFlashMessage('error', 'Action non reconnue');
        header('Location: index.php');
        exit;
}

// Fonction pour créer un fournisseur
function createFournisseur($db) {
    // Valider les données
    $code = filter_input(INPUT_POST, 'code_fournisseur', FILTER_SANITIZE_STRING);
    $raison = filter_input(INPUT_POST, 'raison_sociale', FILTER_SANITIZE_STRING);
    
    if (empty($code) || empty($raison)) {
        setFlashMessage('error', 'Le code fournisseur et la raison sociale sont obligatoires');
        header('Location: index.php');
        exit;
    }
    
    // Vérifier si le code existe déjà
    try {
        $checkQuery = "SELECT COUNT(*) FROM fournisseurs WHERE Code_Fournisseur = :code";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':code', $code);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setFlashMessage('error', 'Ce code fournisseur existe déjà');
            header('Location: index.php');
            exit;
        }
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur lors de la vérification du code: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
    
    // Récupérer les autres champs
    $adresse = filter_input(INPUT_POST, 'adresse', FILTER_SANITIZE_STRING);
    $codePostal = filter_input(INPUT_POST, 'code_postal', FILTER_SANITIZE_STRING);
    $ville = filter_input(INPUT_POST, 'ville', FILTER_SANITIZE_STRING);
    $telephone = filter_input(INPUT_POST, 'telephone', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $contact = filter_input(INPUT_POST, 'contact_principal', FILTER_SANITIZE_STRING);
    $conditions = filter_input(INPUT_POST, 'conditions_paiement', FILTER_SANITIZE_STRING);
    $delai = filter_input(INPUT_POST, 'delai_livraison', FILTER_VALIDATE_INT);
    $actif = filter_input(INPUT_POST, 'actif', FILTER_VALIDATE_INT);
    
    try {
        // Préparer la requête
        $query = "INSERT INTO fournisseurs (
                    Code_Fournisseur, Raison_Sociale, Adresse, Code_Postal, Ville, 
                    Telephone, Email, Contact_Principal, Conditions_Paiement_Par_Defaut, 
                    Delai_Livraison_Moyen, Actif, Date_Creation
                ) VALUES (
                    :code, :raison, :adresse, :codePostal, :ville, 
                    :telephone, :email, :contact, :conditions, 
                    :delai, :actif, NOW()
                )";
                
        $stmt = $db->prepare($query);
        
        // Lier les paramètres
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':raison', $raison);
        $stmt->bindParam(':adresse', $adresse);
        $stmt->bindParam(':codePostal', $codePostal);
        $stmt->bindParam(':ville', $ville);
        $stmt->bindParam(':telephone', $telephone);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':contact', $contact);
        $stmt->bindParam(':conditions', $conditions);
        $stmt->bindParam(':delai', $delai);
        $stmt->bindParam(':actif', $actif);
        
        // Exécuter la requête
        if ($stmt->execute()) {
            // Enregistrer l'action dans les logs
            logAction($db, $_SESSION['user_id'], 'Création', 'fournisseurs', $db->lastInsertId(), 
                      "Création du fournisseur: $raison ($code)");
            
            setFlashMessage('success', 'Le fournisseur a été créé avec succès');
        } else {
            setFlashMessage('error', 'Erreur lors de la création du fournisseur');
        }
        
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur de base de données: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
}

// Fonction pour mettre à jour un fournisseur
function updateFournisseur($db) {
    // Valider l'ID
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        setFlashMessage('error', 'ID de fournisseur non valide');
        header('Location: index.php');
        exit;
    }
    
    // Valider les données obligatoires
    $code = filter_input(INPUT_POST, 'code_fournisseur', FILTER_SANITIZE_STRING);
    $raison = filter_input(INPUT_POST, 'raison_sociale', FILTER_SANITIZE_STRING);
    
    if (empty($code) || empty($raison)) {
        setFlashMessage('error', 'Le code fournisseur et la raison sociale sont obligatoires');
        header('Location: index.php');
        exit;
    }
    
    // Vérifier si le code existe déjà pour un autre fournisseur
    try {
        $checkQuery = "SELECT COUNT(*) FROM fournisseurs WHERE Code_Fournisseur = :code AND ID_Fournisseur != :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':code', $code);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setFlashMessage('error', 'Ce code fournisseur existe déjà pour un autre fournisseur');
            header('Location: index.php');
            exit;
        }
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur lors de la vérification du code: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
    
    // Récupérer les autres champs
    $adresse = filter_input(INPUT_POST, 'adresse', FILTER_SANITIZE_STRING);
    $codePostal = filter_input(INPUT_POST, 'code_postal', FILTER_SANITIZE_STRING);
    $ville = filter_input(INPUT_POST, 'ville', FILTER_SANITIZE_STRING);
    $telephone = filter_input(INPUT_POST, 'telephone', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $contact = filter_input(INPUT_POST, 'contact_principal', FILTER_SANITIZE_STRING);
    $conditions = filter_input(INPUT_POST, 'conditions_paiement', FILTER_SANITIZE_STRING);
    $delai = filter_input(INPUT_POST, 'delai_livraison', FILTER_VALIDATE_INT);
    $actif = filter_input(INPUT_POST, 'actif', FILTER_VALIDATE_INT);
    
    try {
        // Préparer la requête
        $query = "UPDATE fournisseurs SET 
                    Code_Fournisseur = :code,
                    Raison_Sociale = :raison,
                    Adresse = :adresse,
                    Code_Postal = :codePostal,
                    Ville = :ville,
                    Telephone = :telephone,
                    Email = :email,
                    Contact_Principal = :contact,
                    Conditions_Paiement_Par_Defaut = :conditions,
                    Delai_Livraison_Moyen = :delai,
                    Actif = :actif
                  WHERE ID_Fournisseur = :id";
                
        $stmt = $db->prepare($query);
        
        // Lier les paramètres
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':raison', $raison);
        $stmt->bindParam(':adresse', $adresse);
        $stmt->bindParam(':codePostal', $codePostal);
        $stmt->bindParam(':ville', $ville);
        $stmt->bindParam(':telephone', $telephone);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':contact', $contact);
        $stmt->bindParam(':conditions', $conditions);
        $stmt->bindParam(':delai', $delai);
        $stmt->bindParam(':actif', $actif);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        // Exécuter la requête
        if ($stmt->execute()) {
            // Enregistrer l'action dans les logs
            logAction($db, $_SESSION['user_id'], 'Modification', 'fournisseurs', $id, 
                      "Modification du fournisseur: $raison ($code)");
            
            setFlashMessage('success', 'Le fournisseur a été mis à jour avec succès');
        } else {
            setFlashMessage('error', 'Erreur lors de la mise à jour du fournisseur');
        }
        
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur de base de données: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
}

// Fonction pour supprimer un fournisseur
function deleteFournisseur($db) {
    // Valider l'ID
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        setFlashMessage('error', 'ID de fournisseur non valide');
        header('Location: index.php');
        exit;
    }
    
    try {
        // Vérifier si le fournisseur a des commandes associées
        $checkQuery = "SELECT COUNT(*) FROM commandes WHERE ID_Fournisseur = :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setFlashMessage('error', 'Ce fournisseur ne peut pas être supprimé car il a des commandes associées');
            header('Location: index.php');
            exit;
        }
        
        // Récupérer les informations du fournisseur pour le log
        $infoQuery = "SELECT Code_Fournisseur, Raison_Sociale FROM fournisseurs WHERE ID_Fournisseur = :id";
        $infoStmt = $db->prepare($infoQuery);
        $infoStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $infoStmt->execute();
        $fournisseurInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);
        
        // Préparer la requête de suppression
        $query = "DELETE FROM fournisseurs WHERE ID_Fournisseur = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        // Exécuter la requête
        if ($stmt->execute()) {
            // Enregistrer l'action dans les logs
            logAction($db, $_SESSION['user_id'], 'Suppression', 'fournisseurs', $id, 
                      "Suppression du fournisseur: {$fournisseurInfo['Raison_Sociale']} ({$fournisseurInfo['Code_Fournisseur']})");
            
            setFlashMessage('success', 'Le fournisseur a été supprimé avec succès');
        } else {
            setFlashMessage('error', 'Erreur lors de la suppression du fournisseur');
        }
        
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur de base de données: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
}

// Fonction pour enregistrer une action dans les logs
function logAction($db, $userId, $action, $entite, $entiteId, $details) {
    try {
        $query = "INSERT INTO logs (user_id, action, entite, entite_id, details, date_action, adresse_ip) 
                  VALUES (:userId, :action, :entite, :entiteId, :details, NOW(), :ip)";
        $stmt = $db->prepare($query);
        
        $ip = $_SERVER['REMOTE_ADDR'];
        
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':entite', $entite);
        $stmt->bindParam(':entiteId', $entiteId, PDO::PARAM_INT);
        $stmt->bindParam(':details', $details);
        $stmt->bindParam(':ip', $ip);
        
        $stmt->execute();
    } catch (PDOException $e) {
        // Log l'erreur mais continue l'exécution
        error_log('Erreur lors de l\'enregistrement du log: ' . $e->getMessage());
    }
}

// Fonction pour définir un message flash
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}
