<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(dirname(__DIR__));

// Inclure les fichiers de configuration et de fonctions
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/functions.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithError('Accès non autorisé.', 'index.php');
}

try {
    // Récupérer l'ID de l'intervention
    $intervention_id = filter_input(INPUT_POST, 'intervention_id', FILTER_VALIDATE_INT);
    if (!$intervention_id) {
        throw new Exception('ID d\'intervention invalide.');
    }

    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();

    // Démarrer une transaction
    $db->beginTransaction();

    // Vérifier si l'intervention existe et récupérer ses informations
    $intervention = getInterventionById($db, $intervention_id);
    if (!$intervention) {
        throw new Exception('Intervention non trouvée.');
    }

    // Vérifier si l'intervention n'est pas déjà liée à une commande
    if (!empty($intervention['commande_id'])) {
        throw new Exception('Cette intervention est déjà liée à une commande.');
    }

    // Créer la commande avec le calcul de Montant_Total_HT
    $commande_id = createCommande($db, $intervention, $_SESSION['user_id']);

    // Mettre à jour l'intervention avec l'ID de la commande
    updateInterventionCommande($db, $intervention_id, $commande_id);

    // Ajouter les articles et les offres à la commande
    $articles = getInterventionArticles($db, $intervention_id);
    $offres = getInterventionOffres($db, $intervention_id);

    if (!empty($articles)) {
        insertCommandeArticles($db, $commande_id, $articles);
    }

    if (!empty($offres)) {
        insertCommandeOffres($db, $commande_id, $offres);
    }

    // Valider la transaction
    $db->commit();

    redirectWithSuccess('La commande a été créée avec succès à partir de l\'intervention.', '../../orders/view.php?id=' . $commande_id);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    redirectWithError('Erreur : ' . $e->getMessage(), 'index.php');
}

// Fonction pour rediriger avec un message d'erreur
function redirectWithError($message, $location) {
    $_SESSION['error'] = $message;
    header('Location: ' . $location);
    exit;
}

// Fonction pour rediriger avec un message de succès
function redirectWithSuccess($message, $location) {
    $_SESSION['success'] = $message;
    header('Location: ' . $location);
    exit;
}

// Fonction pour récupérer une intervention par ID
function getInterventionById($db, $intervention_id) {
    $query = "
        SELECT i.*, v.client_id 
        FROM interventions i
        JOIN vehicules v ON i.vehicule_id = v.id
        WHERE i.id = :id
    ";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $intervention_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fonction pour créer une commande avec le calcul de Montant_Total_HT
function createCommande($db, $intervention, $user_id) {
    // Récupérer les articles et les offres associés à l'intervention
    $articles = getInterventionArticles($db, $intervention['id']);
    $offres = getInterventionOffres($db, $intervention['id']);

    // Calculer le Montant_Total_HT
    $montant_total_ht = 0;

    // Calculer le montant total HT des articles
    foreach ($articles as $article) {
        $montant_total_ht += calculateMontantHT($article['prix_unitaire'], $article['quantite'], $article['remise']);
    }

    // Calculer le montant total HT des offres
    foreach ($offres as $offre) {
        $montant_total_ht += calculateMontantHT($offre['prix_unitaire'], $offre['quantite'], $offre['remise']);
    }

    // Générer un numéro de commande unique
    $numero_commande = generateCommandeNumber();

    // Insérer la commande dans la table 'commandes'
    $query = "
        INSERT INTO commandes (
            ID_client, Numero_Commande, vehicule_id, date_creation, Statut_Commande, user_id, intervention_id, Montant_Total_HT
        ) VALUES (
            :client_id, :numero_commande, :vehicule_id, NOW(), 'En attente', :user_id, :intervention_id, :montant_total_ht
        )
    ";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':client_id', $intervention['client_id'], PDO::PARAM_INT);
    $stmt->bindValue(':numero_commande', $numero_commande);
    $stmt->bindValue(':vehicule_id', $intervention['vehicule_id'], PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':intervention_id', $intervention['id'], PDO::PARAM_INT);
    $stmt->bindValue(':montant_total_ht', $montant_total_ht);
    $stmt->execute();
    return $db->lastInsertId();
}

// Fonction pour générer un numéro de commande unique
function generateCommandeNumber() {
    return 'CMD-' . date('YmdHis') . '-' . rand(1000, 9999);
}

// Fonction pour mettre à jour l'intervention avec l'ID de commande
function updateInterventionCommande($db, $intervention_id, $commande_id) {
    $query = "UPDATE interventions SET commande_id = :commande_id WHERE id = :intervention_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':commande_id', $commande_id, PDO::PARAM_INT);
    $stmt->bindValue(':intervention_id', $intervention_id, PDO::PARAM_INT);
    $stmt->execute();
}

// Fonction pour récupérer les articles d'une intervention
function getInterventionArticles($db, $intervention_id) {
    $query = "
        SELECT ia.article_id, ia.quantite, ia.prix_unitaire, ia.remise 
        FROM interventions_articles ia 
        WHERE ia.intervention_id = :intervention_id
    ";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':intervention_id', $intervention_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour récupérer les offres d'une intervention
function getInterventionOffres($db, $intervention_id) {
    $query = "
        SELECT io.offre_id, io.quantite, io.prix_unitaire, io.remise 
        FROM interventions_offres io 
        WHERE io.intervention_id = :intervention_id
    ";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':intervention_id', $intervention_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour insérer les articles dans une commande
function insertCommandeArticles($db, $commande_id, $articles) {
    $query = "
        INSERT INTO commande_details (ID_Commande, article_id, quantite, prix_unitaire, remise, montant_ht) 
                VALUES (:commande_id, :article_id, :quantite, :prix_unitaire, :remise, :montant_ht)
    ";
    $stmt = $db->prepare($query);

    foreach ($articles as $article) {
        // Calculer le montant HT pour chaque article
        $montant_ht = calculateMontantHT($article['prix_unitaire'], $article['quantite'], $article['remise']);
        $stmt->bindValue(':commande_id', $commande_id, PDO::PARAM_INT);
        $stmt->bindValue(':article_id', $article['article_id'], PDO::PARAM_INT);
        $stmt->bindValue(':quantite', $article['quantite'], PDO::PARAM_INT);
        $stmt->bindValue(':prix_unitaire', $article['prix_unitaire']);
        $stmt->bindValue(':remise', $article['remise']);
        $stmt->bindValue(':montant_ht', $montant_ht);
        $stmt->execute();
    }
}

// Fonction pour insérer les offres dans une commande
function insertCommandeOffres($db, $commande_id, $offres) {
    $query = "
        INSERT INTO commande_offres (commande_id, offre_id, quantite, prix_unitaire, remise, montant_ht) 
        VALUES (:commande_id, :offre_id, :quantite, :prix_unitaire, :remise, :montant_ht)
    ";
    $stmt = $db->prepare($query);

    foreach ($offres as $offre) {
        // Calculer le montant HT pour chaque offre
        $montant_ht = calculateMontantHT($offre['prix_unitaire'], $offre['quantite'], $offre['remise']);
        $stmt->bindValue(':commande_id', $commande_id, PDO::PARAM_INT);
        $stmt->bindValue(':offre_id', $offre['offre_id'], PDO::PARAM_INT);
        $stmt->bindValue(':quantite', $offre['quantite'], PDO::PARAM_INT);
        $stmt->bindValue(':prix_unitaire', $offre['prix_unitaire']);
        $stmt->bindValue(':remise', $offre['remise']);
        $stmt->bindValue(':montant_ht', $montant_ht);
        $stmt->execute();
    }
}

// Fonction pour calculer le montant HT
function calculateMontantHT($prix_unitaire, $quantite, $remise) {
    return round($prix_unitaire * $quantite * (1 - $remise / 100), 2);
}

?>