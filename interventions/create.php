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
    $_SESSION['error'] = 'Méthode non autorisée';
    header('Location: index.php');
    exit;
}

try {
    // Initialiser la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer et valider les données du formulaire
    $vehicule_id = filter_input(INPUT_POST, 'vehicule_id', FILTER_VALIDATE_INT);
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    $technicien_id = filter_input(INPUT_POST, 'technicien_id', FILTER_VALIDATE_INT) ?: null;
    $date_prevue = !empty($_POST['date_prevue']) ? $_POST['date_prevue'] : null;
    $date_debut = !empty($_POST['date_debut']) ? $_POST['date_debut'] : null;
    $date_fin = !empty($_POST['date_fin']) ? $_POST['date_fin'] : null;
    $date_prochain_ct = !empty($_POST['date_prochain_ct']) ? $_POST['date_prochain_ct'] : null;
    $kilometrage = filter_input(INPUT_POST, 'kilometrage', FILTER_VALIDATE_INT) ?: null;
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));
    $diagnostique = htmlspecialchars(trim($_POST['diagnostique'] ?? ''));
    $commentaire = htmlspecialchars(trim($_POST['commentaire'] ?? ''));
    $statut = htmlspecialchars(trim($_POST['statut'] ?? 'En attente'));
    $create_commande = isset($_POST['create_commande']) && $_POST['create_commande'] === 'on';
    
    // Valider les données obligatoires
    if (!$vehicule_id || empty($description)) {
        $_SESSION['error'] = 'Veuillez remplir tous les champs obligatoires';
        header('Location: index.php');
        exit;
    }
    
    // Démarrer une transaction
    $db->beginTransaction();
    
    // Insérer l'intervention
    $query = "INSERT INTO interventions (
                vehicule_id, client_id, technicien_id, date_creation, date_prevue, 
                date_debut, date_fin, date_prochain_ct, kilometrage, description, 
                diagnostique, commentaire, statut
              ) VALUES (
                :vehicule_id, :client_id, :technicien_id, NOW(), :date_prevue, 
                :date_debut, :date_fin, :date_prochain_ct, :kilometrage, :description, 
                :diagnostique, :commentaire, :statut
              )";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':vehicule_id', $vehicule_id, PDO::PARAM_INT);
    $stmt->bindParam(':client_id', $client_id, PDO::PARAM_INT);
    $stmt->bindParam(':technicien_id', $technicien_id, PDO::PARAM_INT);
    $stmt->bindParam(':date_prevue', $date_prevue);
    $stmt->bindParam(':date_debut', $date_debut);
    $stmt->bindParam(':date_fin', $date_fin);
    $stmt->bindParam(':date_prochain_ct', $date_prochain_ct);
    $stmt->bindParam(':kilometrage', $kilometrage, PDO::PARAM_INT);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':diagnostique', $diagnostique);
    $stmt->bindParam(':commentaire', $commentaire);
    $stmt->bindParam(':statut', $statut);
    
    $stmt->execute();
    $intervention_id = $db->lastInsertId();
    
    // Traiter les articles sélectionnés
    if (!empty($_POST['selected_articles'])) {
        $articles = json_decode($_POST['selected_articles'], true);
        
        if (!empty($articles)) {
            $queryArticle = "INSERT INTO interventions_articles (
                              intervention_id, article_id, quantite, prix_unitaire, remise, from_offre
                            ) VALUES (
                              :intervention_id, :article_id, :quantite, :prix_unitaire, :remise, :from_offre
                            )";
            
            $stmtArticle = $db->prepare($queryArticle);
            
            foreach ($articles as $article) {
                $stmtArticle->bindParam(':intervention_id', $intervention_id, PDO::PARAM_INT);
                $stmtArticle->bindParam(':article_id', $article['id'], PDO::PARAM_INT);
                $stmtArticle->bindParam(':quantite', $article['quantite'], PDO::PARAM_INT);
                $stmtArticle->bindParam(':prix_unitaire', $article['prix_unitaire']);
                $stmtArticle->bindParam(':remise', $article['remise']);
                $from_offre = isset($article['from_offre']) ? $article['from_offre'] : null;
                $stmtArticle->bindParam(':from_offre', $from_offre, PDO::PARAM_INT);
                
                $stmtArticle->execute();
            }
        }
    }
    
    // Traiter les offres sélectionnées
    if (!empty($_POST['selected_offres'])) {
        $offres = json_decode($_POST['selected_offres'], true);
        
        if (!empty($offres)) {
            $queryOffre = "INSERT INTO interventions_offres (
                            intervention_id, offre_id, quantite, prix_unitaire, remise
                          ) VALUES (
                            :intervention_id, :offre_id, :quantite, :prix_unitaire, :remise
                          )";
            
            $stmtOffre = $db->prepare($queryOffre);
            
            foreach ($offres as $offre) {
                $stmtOffre->bindParam(':intervention_id', $intervention_id, PDO::PARAM_INT);
                $stmtOffre->bindParam(':offre_id', $offre['id'], PDO::PARAM_INT);
                $stmtOffre->bindParam(':quantite', $offre['quantite'], PDO::PARAM_INT);
                $stmtOffre->bindParam(':prix_unitaire', $offre['prix_unitaire']);
                $stmtOffre->bindParam(':remise', $offre['remise']);
                
                $stmtOffre->execute();
            }
        }
    }
    
    // Créer une commande si demandé
    $commande_id = null;
    if ($create_commande) {
        // Générer un numéro de commande unique
        $date = date('Ymd');
        $time = date('His');
        $random = mt_rand(1000, 9999);
        $numero_commande = "CMD-{$date}{$time}-{$random}";
        
        // Insérer la commande
        $queryCommande = "INSERT INTO commandes (
                            Numero_Commande, ID_client, vehicule_id, intervention_id,
                            Date_Commande, Statut_Commande, Date_Creation, user_id
                          ) VALUES (
                            :numero_commande, :client_id, :vehicule_id, :intervention_id,
                            CURDATE(), 'En attente', NOW(), :user_id
                          )";
        
        $stmtCommande = $db->prepare($queryCommande);
        $stmtCommande->bindParam(':numero_commande', $numero_commande);
        $stmtCommande->bindParam(':client_id', $client_id, PDO::PARAM_INT);
        $stmtCommande->bindParam(':vehicule_id', $vehicule_id, PDO::PARAM_INT);
        $stmtCommande->bindParam(':intervention_id', $intervention_id, PDO::PARAM_INT);
        $stmtCommande->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        
        $stmtCommande->execute();
        $commande_id = $db->lastInsertId();
        
        // Mettre à jour l'intervention avec l'ID de la commande
        $queryUpdateIntervention = "UPDATE interventions SET commande_id = :commande_id WHERE id = :intervention_id";
        $stmtUpdateIntervention = $db->prepare($queryUpdateIntervention);
        $stmtUpdateIntervention->bindParam(':commande_id', $commande_id, PDO::PARAM_INT);
        $stmtUpdateIntervention->bindParam(':intervention_id', $intervention_id, PDO::PARAM_INT);
        $stmtUpdateIntervention->execute();
        
        // Ajouter les articles à la commande
        if (!empty($articles)) {
          $total = 0;
            $queryCommandeArticle = "INSERT INTO commande_details (
              ID_Commande, article_id, quantite, prix_unitaire, remise, montant_ht
          ) VALUES (
              :commande_id, :article_id, :quantite, :prix_unitaire, :remise, :montant_ht
          )";
          
          $stmtCommandeArticle = $db->prepare($queryCommandeArticle);
          
          foreach ($articles as $article) {
              $montant_ht = round($article['prix_unitaire'] * $article['quantite'] * (1 - $article['remise'] / 100), 2);
              $stmtCommandeArticle->bindParam(':commande_id', $commande_id, PDO::PARAM_INT);
              $stmtCommandeArticle->bindParam(':article_id', $article['id'], PDO::PARAM_INT);
              $stmtCommandeArticle->bindParam(':quantite', $article['quantite'], PDO::PARAM_INT);
              $stmtCommandeArticle->bindParam(':prix_unitaire', $article['prix_unitaire']);
              $stmtCommandeArticle->bindParam(':remise', $article['remise']);
              $stmtCommandeArticle->bindParam(':montant_ht', $montant_ht);
              $stmtCommandeArticle->execute();
              $total += $montant_ht;
          } 
          $query = "UPDATE commandes SET Montant_Total_HT = :Montant_Total_HT WHERE ID_Commande = :commande_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':commande_id', $commande_id);
        $stmt->bindParam(':Montant_Total_HT', $total);
        $stmt->execute();
        }
       
        // Ajouter les offres à la commande
        if (!empty($offres)) {
            $queryCommandeOffre = "INSERT INTO commande_offres (
                                    commande_id, offre_id, quantite, prix_unitaire, remise
                                  ) VALUES (
                                    :commande_id, :offre_id, :quantite, :prix_unitaire, :remise
                                  )";
            
            $stmtCommandeOffre = $db->prepare($queryCommandeOffre);
            
            foreach ($offres as $offre) {
                $stmtCommandeOffre->bindParam(':commande_id', $commande_id, PDO::PARAM_INT);
                $stmtCommandeOffre->bindParam(':offre_id', $offre['id'], PDO::PARAM_INT);
                $stmtCommandeOffre->bindParam(':quantite', $offre['quantite'], PDO::PARAM_INT);
                $stmtCommandeOffre->bindParam(':prix_unitaire', $offre['prix_unitaire']);
                $stmtCommandeOffre->bindParam(':remise', $offre['remise']);
                
                $stmtCommandeOffre->execute();
            }
        }
    }
    
    // Valider la transaction
    $db->commit();
    
    // Enregistrer l'action dans les logs
    if (function_exists('logAction')) {
        logAction($db, $_SESSION['user_id'], 'Création', 'interventions', $intervention_id, 
                 "Création d'une intervention pour le véhicule #$vehicule_id");
    }
    
    $_SESSION['success'] = 'L\'intervention a été créée avec succès' . 
                          ($commande_id ? ' et une commande associée a été générée' : '');
    
} catch (PDOException $e) {
    // Annuler la transaction en cas d'erreur
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    $_SESSION['error'] = 'Erreur de base de données: ' . $e->getMessage();
}

// Rediriger vers la page d'index
header('Location: index.php');
exit;
?>
