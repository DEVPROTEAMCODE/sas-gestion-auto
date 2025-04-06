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
        createOffre($db);
        break;
    case 'update':
        updateOffre($db);
        break;
    case 'delete':
        deleteOffre($db);
        break;
    default:
        setFlashMessage('error', 'Action non reconnue');
        header('Location: index.php');
        exit;
}

// Fonction pour créer une offre
function createOffre($db) {
    // Valider les données
    // Remplacement de FILTER_SANITIZE_STRING (déprécié) par htmlspecialchars
    $code = htmlspecialchars(trim($_POST['code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $nom = htmlspecialchars(trim($_POST['nom'] ?? ''), ENT_QUOTES, 'UTF-8');
    $date_debut = htmlspecialchars(trim($_POST['date_debut'] ?? ''), ENT_QUOTES, 'UTF-8');
    $valeur_remise = filter_input(INPUT_POST, 'valeur_remise', FILTER_VALIDATE_FLOAT);
    
    if (empty($code) || empty($nom) || empty($date_debut) || $valeur_remise <= 0) {
        setFlashMessage('error', 'Tous les champs obligatoires doivent être remplis correctement');
        header('Location: index.php');
        exit;
    }
    
    // Vérifier si le code existe déjà
    try {
        $checkQuery = "SELECT COUNT(*) FROM offres WHERE code = :code";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':code', $code);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setFlashMessage('error', 'Ce code d\'offre existe déjà');
            header('Location: index.php');
            exit;
        }
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur lors de la vérification du code: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
    
    // Récupérer les autres champs
    $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    $categorie_id = filter_input(INPUT_POST, 'categorie_id', FILTER_VALIDATE_INT) ?: null;
    $date_fin = htmlspecialchars(trim($_POST['date_fin'] ?? ''), ENT_QUOTES, 'UTF-8') ?: null;
    $type_remise = htmlspecialchars(trim($_POST['type_remise'] ?? ''), ENT_QUOTES, 'UTF-8');
    $priorite = filter_input(INPUT_POST, 'priorite', FILTER_VALIDATE_INT) ?: 0;
    $conditions = htmlspecialchars(trim($_POST['conditions'] ?? ''), ENT_QUOTES, 'UTF-8');
    $actif = filter_input(INPUT_POST, 'actif', FILTER_VALIDATE_INT) ?: 1;
    
    // Gérer le téléchargement de l'image
    $image = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $image = uploadImage($_FILES['image'], 'offres');
        if (!$image) {
            setFlashMessage('error', 'Erreur lors du téléchargement de l\'image');
            header('Location: index.php');
            exit;
        }
    }
    
    try {
        // Démarrer une transaction
        $db->beginTransaction();
        
        // Préparer la requête
        $query = "INSERT INTO offres (
                    code, nom, description, categorie_id, date_debut, date_fin, 
                    type_remise, valeur_remise, actif, priorite, conditions, image,
                    date_creation, createur_id
                ) VALUES (
                    :code, :nom, :description, :categorie_id, :date_debut, :date_fin, 
                    :type_remise, :valeur_remise, :actif, :priorite, :conditions, :image,
                    NOW(), :createur_id
                )";
                
        $stmt = $db->prepare($query);
        
        // Lier les paramètres
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':nom', $nom);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':categorie_id', $categorie_id);
        $stmt->bindParam(':date_debut', $date_debut);
        $stmt->bindParam(':date_fin', $date_fin);
        $stmt->bindParam(':type_remise', $type_remise);
        $stmt->bindParam(':valeur_remise', $valeur_remise);
        $stmt->bindParam(':actif', $actif);
        $stmt->bindParam(':priorite', $priorite);
        $stmt->bindParam(':conditions', $conditions);
        $stmt->bindParam(':image', $image);
        $stmt->bindParam(':createur_id', $_SESSION['user_id']);
        
        // Exécuter la requête
        $stmt->execute();
        $offre_id = $db->lastInsertId();
        
        // Enregistrer les articles sélectionnés
        if (isset($_POST['articles']) && is_array($_POST['articles'])) {
            foreach ($_POST['articles'] as $article_id) {
                $queryArticle = "INSERT INTO offres_articles (offre_id, article_id) VALUES (:offre_id, :article_id)";
                $stmtArticle = $db->prepare($queryArticle);
                $stmtArticle->bindParam(':offre_id', $offre_id);
                $stmtArticle->bindParam(':article_id', $article_id);
                $stmtArticle->execute();
            }
        }
        
        // Valider la transaction
        $db->commit();
        
        // Enregistrer l'action dans les logs
        logAction($db, $_SESSION['user_id'], 'Création', 'offres', $offre_id, 
                  "Création de l'offre: $nom ($code)");
        
        setFlashMessage('success', 'L\'offre a été créée avec succès');
        
    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        $db->rollBack();
        setFlashMessage('error', 'Erreur de base de données: ' . $e->getMessage());
    }
    
    // Redirection
    header('Location: index.php');
    exit;
}

// Fonction pour mettre à jour une offre
function updateOffre($db) {
    global $root_path;
    // Valider l'ID
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        setFlashMessage('error', 'ID d\'offre non valide');
        header('Location: index.php');
        exit;
    }
    
    // Valider les données obligatoires
    $code = htmlspecialchars(trim($_POST['code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $nom = htmlspecialchars(trim($_POST['nom'] ?? ''), ENT_QUOTES, 'UTF-8');
    $date_debut = htmlspecialchars(trim($_POST['date_debut'] ?? ''), ENT_QUOTES, 'UTF-8');
    $valeur_remise = filter_input(INPUT_POST, 'valeur_remise', FILTER_VALIDATE_FLOAT);
    
    if (empty($code) || empty($nom) || empty($date_debut) || $valeur_remise <= 0) {
        setFlashMessage('error', 'Tous les champs obligatoires doivent être remplis correctement');
        header('Location: index.php');
        exit;
    }
    
    // Vérifier si le code existe déjà pour une autre offre
    try {
        $checkQuery = "SELECT COUNT(*) FROM offres WHERE code = :code AND id != :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':code', $code);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setFlashMessage('error', 'Ce code d\'offre existe déjà pour une autre offre');
            header('Location: index.php');
            exit;
        }
    } catch (PDOException $e) {
        setFlashMessage('error', 'Erreur lors de la vérification du code: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
    
    // Récupérer les autres champs
    $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    $categorie_id = filter_input(INPUT_POST, 'categorie_id', FILTER_VALIDATE_INT) ?: null;
    $date_fin = htmlspecialchars(trim($_POST['date_fin'] ?? ''), ENT_QUOTES, 'UTF-8') ?: null;
    $type_remise = htmlspecialchars(trim($_POST['type_remise'] ?? ''), ENT_QUOTES, 'UTF-8');
    $priorite = filter_input(INPUT_POST, 'priorite', FILTER_VALIDATE_INT) ?: 0;
    $conditions = htmlspecialchars(trim($_POST['conditions'] ?? ''), ENT_QUOTES, 'UTF-8');
    $actif = filter_input(INPUT_POST, 'actif', FILTER_VALIDATE_INT) ?: 0;
    
    try {
        // Récupérer l'image actuelle
        $queryImage = "SELECT image FROM offres WHERE id = :id";
        $stmtImage = $db->prepare($queryImage);
        $stmtImage->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtImage->execute();
        $currentImage = $stmtImage->fetchColumn();
        
        // Gérer le téléchargement de la nouvelle image
        $image = $currentImage;
        
        // Si l'utilisateur veut supprimer l'image
        if (isset($_POST['delete_image']) && $_POST['delete_image'] == 'on') {
            if ($currentImage) {
                // Supprimer le fichier
                $imagePath = $root_path . '/uploads/offres/' . $currentImage;
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
            $image = null;
        }
        // Si l'utilisateur télécharge une nouvelle image
        elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Supprimer l'ancienne image si elle existe
            if ($currentImage) {
                $imagePath = $root_path . '/uploads/offres/' . $currentImage;
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
            
            // Télécharger la nouvelle image
            $image = uploadImage($_FILES['image'], 'offres');
            if (!$image) {
                setFlashMessage('error', 'Erreur lors du téléchargement de l\'image');
                header('Location: index.php');
                exit;
            }
        }
        
        // Démarrer une transaction
        $db->beginTransaction();
        
        // Préparer la requête
        $query = "UPDATE offres SET 
                    code = :code,
                    nom = :nom,
                    description = :description,
                    categorie_id = :categorie_id,
                    date_debut = :date_debut,
                    date_fin = :date_fin,
                    type_remise = :type_remise,
                    valeur_remise = :valeur_remise,
                    actif = :actif,
                    priorite = :priorite,
                    conditions = :conditions,
                    image = :image,
                    date_modification = NOW()
                  WHERE id = :id";
                
        $stmt = $db->prepare($query);
        
        // Lier les paramètres
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':nom', $nom);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':categorie_id', $categorie_id);
        $stmt->bindParam(':date_debut', $date_debut);
        $stmt->bindParam(':date_fin', $date_fin);
        $stmt->bindParam(':type_remise', $type_remise);
        $stmt->bindParam(':valeur_remise', $valeur_remise);
        $stmt->bindParam(':actif', $actif);
        $stmt->bindParam(':priorite', $priorite);
        $stmt->bindParam(':conditions', $conditions);
        $stmt->bindParam(':image', $image);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        // Exécuter la requête
        $stmt->execute();
        
        // Supprimer les anciens liens avec les articles
        $queryDeleteArticles = "DELETE FROM offres_articles WHERE offre_id = :offre_id";
        $stmtDeleteArticles = $db->prepare($queryDeleteArticles);
        $stmtDeleteArticles->bindParam(':offre_id', $id, PDO::PARAM_INT);
        $stmtDeleteArticles->execute();
        
        // Enregistrer les nouveaux articles sélectionnés
        if (isset($_POST['articles']) && is_array($_POST['articles'])) {
            foreach ($_POST['articles'] as $article_id) {
                $queryArticle = "INSERT INTO offres_articles (offre_id, article_id) VALUES (:offre_id, :article_id)";
                $stmtArticle = $db->prepare($queryArticle);
                $stmtArticle->bindParam(':offre_id', $id);
                $stmtArticle->bindParam(':article_id', $article_id);
                $stmtArticle->execute();
            }
        }
        
        // Valider la transaction
        $db->commit();
        
        // Enregistrer l'action dans les logs
        logAction($db, $_SESSION['user_id'], 'Modification', 'offres', $id, 
                  "Modification de l'offre: $nom ($code)");
        
        setFlashMessage('success', 'L\'offre a été mise à jour avec succès');
        
    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        $db->rollBack();
        setFlashMessage('error', 'Erreur de base de données: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
}

// Fonction pour supprimer une offre
function deleteOffre($db) {
    global $root_path;
    // Valider l'ID
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        setFlashMessage('error', 'ID d\'offre non valide');
        header('Location: index.php');
        exit;
    }
    
    try {
        // Récupérer les informations de l'offre pour le log et l'image
        $infoQuery = "SELECT code, nom, image FROM offres WHERE id = :id";
        $infoStmt = $db->prepare($infoQuery);
        $infoStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $infoStmt->execute();
        $offreInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$offreInfo) {
            setFlashMessage('error', 'Offre non trouvée');
            header('Location: index.php');
            exit;
        }
        
        // Supprimer l'image si elle existe
        if ($offreInfo['image']) {
            $imagePath = $root_path . '/uploads/offres/' . $offreInfo['image'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        
        // Démarrer une transaction
        $db->beginTransaction();
        
        // Supprimer les liens avec les articles
        $queryDeleteArticles = "DELETE FROM offres_articles WHERE offre_id = :offre_id";
        $stmtDeleteArticles = $db->prepare($queryDeleteArticles);
        $stmtDeleteArticles->bindParam(':offre_id', $id, PDO::PARAM_INT);
        $stmtDeleteArticles->execute();
        
        // Préparer la requête de suppression
        $query = "DELETE FROM offres WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        // Exécuter la requête
        $stmt->execute();
        
        // Valider la transaction
        $db->commit();
        
        // Enregistrer l'action dans les logs
        logAction($db, $_SESSION['user_id'], 'Suppression', 'offres', $id, 
                  "Suppression de l'offre: {$offreInfo['nom']} ({$offreInfo['code']})");
        
        setFlashMessage('success', 'L\'offre a été supprimée avec succès');
        
    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        $db->rollBack();
        setFlashMessage('error', 'Erreur de base de données: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
}

// Fonction pour télécharger une image
function uploadImage($file, $directory) {
    global $root_path;
    
    // Vérifier si le répertoire existe, sinon le créer
    $uploadDir = $root_path . '/uploads/' . $directory . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Vérifier le type de fichier
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        return false;
    }
    
    // Générer un nom de fichier unique
    $fileName = uniqid() . '_' . basename($file['name']);
    $uploadPath = $uploadDir . $fileName;
    
    // Déplacer le fichier téléchargé
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return $fileName;
    }
    
    return false;
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
?>
