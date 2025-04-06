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

// Activer l'affichage des erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Fonction de journalisation améliorée
function log_debug($message) {
    error_log("[DEBUG] " . date('Y-m-d H:i:s') . " - " . $message);
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Vérifier si la requête est de type POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Récupérer les données du formulaire
$article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
$type_mouvement = isset($_POST['type_mouvement']) ? $_POST['type_mouvement'] : '';
$commentaire = isset($_POST['commentaire']) ? $_POST['commentaire'] : '';
$current_stock = isset($_POST['current_stock']) ? intval($_POST['current_stock']) : 0;

// Déterminer la quantité en fonction du type de mouvement
$quantite = 0;
switch ($type_mouvement) {
    case 'reception':
        $quantite = isset($_POST['quantite_reception']) ? intval($_POST['quantite_reception']) : 0;
        break;
    case 'modification':
        $quantite = isset($_POST['quantite_modification']) ? intval($_POST['quantite_modification']) : 0;
        break;
    case 'retour':
        $quantite = isset($_POST['quantite_retour']) ? intval($_POST['quantite_retour']) : 0;
        break;
}

// Récupérer le fournisseur si présent et non vide
$fournisseur_id = isset($_POST['fournisseur_id']) && !empty($_POST['fournisseur_id']) ? intval($_POST['fournisseur_id']) : null;

log_debug("Données reçues: article_id=$article_id, type=$type_mouvement, quantité=$quantite, commentaire=$commentaire, fournisseur_id=" . ($fournisseur_id ?? 'NULL'));

// Validation des données
if ($article_id <= 0 || empty($type_mouvement) || $quantite < 0) {
    $_SESSION['error'] = "Tous les champs obligatoires doivent être remplis correctement.";
    header('Location: index.php');
    exit;
}

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    log_debug("Connexion à la base de données établie");
    
    // Récupérer les informations de l'article
    $query = "SELECT reference, designation, quantite_stock, prix_achat FROM articles WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $article_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Article introuvable.");
    }
    
    $article = $stmt->fetch(PDO::FETCH_ASSOC);
    $stock_actuel = $article['quantite_stock'];
    $prix_achat = $article['prix_achat'];
    
    log_debug("Article trouvé: stock actuel=$stock_actuel, prix_achat=$prix_achat");
    
    // Calculer le nouveau stock en fonction du type de mouvement
    $nouveau_stock = $stock_actuel;
    switch ($type_mouvement) {
        case 'reception':
            $nouveau_stock = $stock_actuel + $quantite;
            $type_operation_db = 'Réception';
            break;
        case 'modification':
            $nouveau_stock = $quantite; // Modification directe du stock
            $type_operation_db = 'Modification';
            break;
        case 'retour':
            $nouveau_stock = $stock_actuel - $quantite;
            // Vérifier si le stock devient négatif
            if ($nouveau_stock < 0) {
                throw new Exception("Stock insuffisant pour effectuer cette opération.");
            }
            $type_operation_db = 'Modification';
            break;
        default:
            throw new Exception("Type de mouvement non valide.");
    }
    
    log_debug("Nouveau stock calculé: $nouveau_stock, type opération: $type_operation_db");
    
    // Démarrer une transaction
    $db->beginTransaction();
    log_debug("Transaction démarrée");
    
    // Mettre à jour le stock de l'article
    $updateQuery = "UPDATE articles SET quantite_stock = :nouveau_stock, derniere_mise_a_jour = NOW() WHERE id = :id";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindValue(':nouveau_stock', $nouveau_stock, PDO::PARAM_INT);
    $updateStmt->bindValue(':id', $article_id, PDO::PARAM_INT);
    
    log_debug("Exécution de la requête UPDATE: $updateQuery avec nouveau_stock=$nouveau_stock, id=$article_id");
    $result = $updateStmt->execute();
    
    if (!$result) {
        $error = $updateStmt->errorInfo();
        log_debug("Échec de la mise à jour du stock: " . print_r($error, true));
        throw new Exception("Échec de la mise à jour du stock: " . implode(", ", $error));
    }
    
    log_debug("Stock mis à jour avec succès");
    
    // Générer un ID_Commande et ID_Ligne_Commande factice (puisqu'ils ne peuvent pas être NULL)
    $id_commande_factice = 0;
    $id_ligne_commande_factice = 0;
    
    // Préparer le commentaire avec les détails du mouvement
    $commentaire_auto = "";
    
    // Ajouter un commentaire automatique sur l'évolution du stock
    if ($type_mouvement == 'modification') {
        if ($nouveau_stock > $stock_actuel) {
            $commentaire_auto = "Ajustement et augmentation de stock de " . ($nouveau_stock - $stock_actuel) . " unités. ";
        } elseif ($nouveau_stock < $stock_actuel) {
            $commentaire_auto = "Ajustement et diminution de stock de " . ($stock_actuel - $nouveau_stock) . " unités. ";
        } else {
            $commentaire_auto = "Ajustement de stock sans changement de quantité. ";
        }
    } elseif ($type_mouvement == 'reception') {
        $commentaire_auto = "Réception de " . $quantite . " unités. ";
    } elseif ($type_mouvement == 'retour') {
        $commentaire_auto = "Retour de " . $quantite . " unités. ";
    }
    
    $commentaire_complet = $commentaire_auto . $commentaire;
    
    // Enregistrer le mouvement de stock dans la table historique_articles
    // Préparation de la requête avec ou sans ID_Fournisseur selon qu'il est fourni ou non
    if ($fournisseur_id !== null) {
        $historique_query = "INSERT INTO historique_articles 
                            (ID_Article, ID_Commande, ID_Ligne_Commande, Type_Operation, Date_Operation, 
                            Quantite, Prix_Unitaire, Utilisateur, Commentaire, ID_Fournisseur) 
                            VALUES 
                            (:article_id, :id_commande, :id_ligne_commande, :type_operation, NOW(), 
                            :quantite, :prix_unitaire, :utilisateur, :commentaire, :fournisseur_id)";
    } else {
        $historique_query = "INSERT INTO historique_articles 
                            (ID_Article, ID_Commande, ID_Ligne_Commande, Type_Operation, Date_Operation, 
                            Quantite, Prix_Unitaire, Utilisateur, Commentaire) 
                            VALUES 
                            (:article_id, :id_commande, :id_ligne_commande, :type_operation, NOW(), 
                            :quantite, :prix_unitaire, :utilisateur, :commentaire)";
    }
    
    $historique_stmt = $db->prepare($historique_query);
    $historique_stmt->bindValue(':article_id', $article_id, PDO::PARAM_INT);
    $historique_stmt->bindValue(':id_commande', $id_commande_factice, PDO::PARAM_INT);
    $historique_stmt->bindValue(':id_ligne_commande', $id_ligne_commande_factice, PDO::PARAM_INT);
    $historique_stmt->bindValue(':type_operation', $type_operation_db, PDO::PARAM_STR);
    
    // Pour la quantité, utiliser la différence pour les réceptions et retours
    $quantite_historique = $type_mouvement == 'modification' ? abs($nouveau_stock - $stock_actuel) : $quantite;
    $historique_stmt->bindValue(':quantite', $quantite_historique, PDO::PARAM_STR);
    
    $historique_stmt->bindValue(':prix_unitaire', $prix_achat, PDO::PARAM_STR);
    $historique_stmt->bindValue(':utilisateur', (string)$_SESSION['user_id'], PDO::PARAM_STR);
    $historique_stmt->bindValue(':commentaire', $commentaire_complet, PDO::PARAM_STR);
    
    // Ajouter le fournisseur_id seulement s'il est défini
    if ($fournisseur_id !== null) {
        $historique_stmt->bindValue(':fournisseur_id', $fournisseur_id, PDO::PARAM_INT);
    }
    
    // Déboguer la requête avant exécution
    log_debug("Exécution de la requête INSERT historique avec les valeurs:");
    log_debug("ID_Article: $article_id");
    log_debug("ID_Commande: $id_commande_factice");
    log_debug("ID_Ligne_Commande: $id_ligne_commande_factice");
    log_debug("Type_Operation: $type_operation_db");
    log_debug("Quantite: $quantite_historique");
    log_debug("Prix_Unitaire: $prix_achat");
    log_debug("Utilisateur: " . $_SESSION['user_id']);
    log_debug("Commentaire: $commentaire_complet");
    if ($fournisseur_id !== null) {
        log_debug("ID_Fournisseur: $fournisseur_id");
    }
    
    $result = $historique_stmt->execute();
    
    if (!$result) {
        $error = $historique_stmt->errorInfo();
        log_debug("Échec de l'enregistrement dans l'historique: " . print_r($error, true));
        throw new Exception("Échec de l'enregistrement dans l'historique: " . implode(", ", $error));
    }
    
    log_debug("Historique enregistré avec succès");
    
    // Valider la transaction
    $db->commit();
    log_debug("Transaction validée avec succès");
    
    // Message de succès
//     $_SESSION['success'] = "Le stock a été mis à jour avec succès. Nouveau stock: " . $nouveau_stock;
//     header('Location: index.php');
// exit;
} catch (Exception $e) {
    // Annuler la transaction en cas d'erreur
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
        log_debug("Transaction annulée suite à une erreur");
    }
    
    $_SESSION['error'] = "Une erreur s'est produite: " . $e->getMessage();
    log_debug("Exception: " . $e->getMessage());
}

// Rediriger vers la page d'index
log_debug("Redirection vers index.php");
header('Location: index.php');
exit;
