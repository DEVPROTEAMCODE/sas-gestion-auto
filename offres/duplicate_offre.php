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

// Vérifier si l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setFlashMessage('error', 'ID d\'offre non valide');
    header('Location: index.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les informations de l'offre
    $query = "SELECT * FROM offres WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        setFlashMessage('error', 'Offre non trouvée');
        header('Location: index.php');
        exit;
    }
    
    $offre = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Générer un nouveau code
    $newCode = $offre['code'] . '_COPY';
    
    // Vérifier si le nouveau code existe déjà
    $checkQuery = "SELECT COUNT(*) FROM offres WHERE code = :code";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':code', $newCode);
    $checkStmt->execute();
    
    if ($checkStmt->fetchColumn() > 0) {
        // Ajouter un timestamp au code pour le rendre unique
        $newCode = $offre['code'] . '_COPY_' . time();
    }
    
    // Générer un nouveau nom
    $newNom = $offre['nom'] . ' (copie)';
    
    // Dupliquer l'image si elle existe
    $newImage = null;
    if ($offre['image']) {
        $sourcePath = $root_path . '/uploads/offres/' . $offre['image'];
        if (file_exists($sourcePath)) {
            $extension = pathinfo($offre['image'], PATHINFO_EXTENSION);
            $newImage = uniqid() . '_copy.' . $extension;
            $destPath = $root_path . '/uploads/offres/' . $newImage;
            copy($sourcePath, $destPath);
        }
    }
    
    // Démarrer une transaction
    $db->beginTransaction();
    
    // Insérer la nouvelle offre
    $insertQuery = "INSERT INTO offres (
                        code, nom, description, categorie_id, date_debut, date_fin, 
                        type_remise, valeur_remise, actif, priorite, conditions, image,
                        date_creation, createur_id
                    ) VALUES (
                        :code, :nom, :description, :categorie_id, :date_debut, :date_fin, 
                        :type_remise, :valeur_remise, :actif, :priorite, :conditions, :image,
                        NOW(), :createur_id
                    )";
    
    $insertStmt = $db->prepare($insertQuery);
    
    $insertStmt->bindValue(':code', $newCode);
    $insertStmt->bindValue(':nom', $newNom);
    $insertStmt->bindValue(':description', $offre['description']);
    $insertStmt->bindValue(':categorie_id', $offre['categorie_id']);
    $insertStmt->bindValue(':date_debut', $offre['date_debut']);
    $insertStmt->bindValue(':date_fin', $offre['date_fin']);
    $insertStmt->bindValue(':type_remise', $offre['type_remise']);
    $insertStmt->bindValue(':valeur_remise', $offre['valeur_remise']);
    $insertStmt->bindValue(':actif', 0); // La copie est inactive par défaut
    $insertStmt->bindValue(':priorite', $offre['priorite']);
    $insertStmt->bindValue(':conditions', $offre['conditions']);
    $insertStmt->bindValue(':image', $newImage);
    $insertStmt->bindValue(':createur_id', $_SESSION['user_id']);
    
    $insertStmt->execute();
    $newId = $db->lastInsertId();
    
        // Récupérer les articles liés à l'offre d'origine
        $articlesQuery = "SELECT article_id, remise_specifique FROM offres_articles WHERE offre_id = :offre_id";
        $articlesStmt = $db->prepare($articlesQuery);
        $articlesStmt->bindParam(':offre_id', $id, PDO::PARAM_INT);
        $articlesStmt->execute();
        $articles = $articlesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Dupliquer les liens avec les articles
        if (!empty($articles)) {
            $insertArticleQuery = "INSERT INTO offres_articles (offre_id, article_id, remise_specifique) 
                                  VALUES (:offre_id, :article_id, :remise_specifique)";
            $insertArticleStmt = $db->prepare($insertArticleQuery);
            
            foreach ($articles as $article) {
                $insertArticleStmt->bindValue(':offre_id', $newId);
                $insertArticleStmt->bindValue(':article_id', $article['article_id']);
                $insertArticleStmt->bindValue(':remise_specifique', $article['remise_specifique']);
                $insertArticleStmt->execute();
            }
        }
        
        // Valider la transaction
        $db->commit();
        
        // Enregistrer l'action dans les logs
        logAction($db, $_SESSION['user_id'], 'Duplication', 'offres', $newId, 
                  "Duplication de l'offre {$offre['nom']} ({$offre['code']}) vers {$newNom} ({$newCode})");
        
        setFlashMessage('success', 'L\'offre a été dupliquée avec succès');
        
    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        setFlashMessage('error', 'Erreur lors de la duplication de l\'offre: ' . $e->getMessage());
    }
    
    header('Location: index.php');
    exit;
    
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
    
