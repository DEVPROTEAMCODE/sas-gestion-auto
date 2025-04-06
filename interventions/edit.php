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

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer l'ID de l'intervention
    $intervention_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (empty($intervention_id)) {
        $_SESSION['error'] = 'ID d\'intervention invalide.';
        header('Location: index.php');
        exit;
    }
    
    // Récupérer les données du formulaire
    $vehicule_id = isset($_POST['vehicule_id']) ? intval($_POST['vehicule_id']) : 0;
    $technicien_id = isset($_POST['technicien_id']) && !empty($_POST['technicien_id']) ? intval($_POST['technicien_id']) : null;
    $date_prevue = isset($_POST['date_prevue']) && !empty($_POST['date_prevue']) ? $_POST['date_prevue'] : null;
    $date_debut = isset($_POST['date_debut']) && !empty($_POST['date_debut']) ? $_POST['date_debut'] : null;
    $date_fin = isset($_POST['date_fin']) && !empty($_POST['date_fin']) ? $_POST['date_fin'] : null;
    $date_prochain_ct = isset($_POST['date_prochain_ct']) && !empty($_POST['date_prochain_ct']) ? $_POST['date_prochain_ct'] : null;
    $kilometrage = isset($_POST['kilometrage']) && !empty($_POST['kilometrage']) ? intval($_POST['kilometrage']) : null;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $diagnostique = isset($_POST['diagnostique']) ? trim($_POST['diagnostique']) : '';
    $commentaire = isset($_POST['commentaire']) ? trim($_POST['commentaire']) : '';
    $statut = isset($_POST['statut']) ? $_POST['statut'] : 'En attente';
    $create_commande = isset($_POST['create_commande']) ? true : false;
    $commande_id = isset($_POST['commande_id']) && !empty($_POST['commande_id']) ? intval($_POST['commande_id']) : null;
    $update_commande = isset($_POST['update_commande']) ? intval($_POST['update_commande']) : 0;
    
    // Articles et offres sélectionnés
    $selected_articles = isset($_POST['selected_articles']) ? json_decode($_POST['selected_articles'], true) : [];
    $selected_offres = isset($_POST['selected_offres']) ? json_decode($_POST['selected_offres'], true) : [];
    
    // Validation des données
    $errors = [];
    
    if (empty($vehicule_id)) {
        $errors[] = 'Veuillez sélectionner un véhicule.';
    }
    
    if (empty($description)) {
        $errors[] = 'La description est obligatoire.';
    }
    
    // Si aucune erreur, procéder à la mise à jour
    if (empty($errors)) {
        try {
            // Connexion à la base de données
            $database = new Database();
            $db = $database->getConnection();
            
            // Démarrer une transaction
            $db->beginTransaction();
            
            // Mettre à jour l'intervention
            $query = "UPDATE interventions SET 
                      vehicule_id = :vehicule_id, 
                      technicien_id = :technicien_id, 
                      date_prevue = :date_prevue, 
                      date_debut = :date_debut, 
                      date_fin = :date_fin, 
                      date_prochain_ct = :date_prochain_ct, 
                      kilometrage = :kilometrage, 
                      description = :description, 
                      diagnostique = :diagnostique, 
                      commentaire = :commentaire, 
                      statut = :statut,
                      date_modification = NOW()
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':vehicule_id', $vehicule_id);
            $stmt->bindParam(':technicien_id', $technicien_id);
            $stmt->bindParam(':date_prevue', $date_prevue);
            $stmt->bindParam(':date_debut', $date_debut);
            $stmt->bindParam(':date_fin', $date_fin);
            $stmt->bindParam(':date_prochain_ct', $date_prochain_ct);
            $stmt->bindParam(':kilometrage', $kilometrage);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':diagnostique', $diagnostique);
            $stmt->bindParam(':commentaire', $commentaire);
            $stmt->bindParam(':statut', $statut);
            $stmt->bindParam(':id', $intervention_id);
            
            if ($stmt->execute()) {
                // Supprimer les articles et offres existants
                $query = "DELETE FROM interventions_articles WHERE intervention_id = :intervention_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':intervention_id', $intervention_id);
                $stmt->execute();
                
                $query = "DELETE FROM interventions_offres WHERE intervention_id = :intervention_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':intervention_id', $intervention_id);
                $stmt->execute();
                
                // Insérer les nouveaux articles sélectionnés
                if (!empty($selected_articles)) {
                    $query = "INSERT INTO interventions_articles (intervention_id, article_id, quantite, prix_unitaire, remise) 
                              VALUES (:intervention_id, :article_id, :quantite, :prix_unitaire, :remise)";
                    $stmt = $db->prepare($query);
                    
                    foreach ($selected_articles as $article) {
                        $stmt->bindParam(':intervention_id', $intervention_id);
                        $stmt->bindParam(':article_id', $article['id']);
                        $stmt->bindParam(':quantite', $article['quantite']);
                        $stmt->bindParam(':prix_unitaire', $article['prix_unitaire']);
                        $stmt->bindParam(':remise', $article['remise']);
                        $stmt->execute();
                    }
                }
                
                // Insérer les nouvelles offres sélectionnées
                if (!empty($selected_offres)) {
                    $query = "INSERT INTO interventions_offres (intervention_id, offre_id, quantite, prix_unitaire, remise) 
                              VALUES (:intervention_id, :offre_id, :quantite, :prix_unitaire, :remise)";
                    $stmt = $db->prepare($query);
                    
                    foreach ($selected_offres as $offre) {
                        $stmt->bindParam(':intervention_id', $intervention_id);
                        $stmt->bindParam(':offre_id', $offre['id']);
                        $stmt->bindParam(':quantite', $offre['quantite']);
                        $stmt->bindParam(':prix_unitaire', $offre['prix_unitaire']);
                        $stmt->bindParam(':remise', $offre['remise']);
                        $stmt->execute();
                    }
                }
                
                // Créer une commande si demandé
                if ($create_commande && !$commande_id) {
                    // Récupérer les informations du client à partir du véhicule
                    $query = "SELECT v.client_id FROM vehicules v WHERE v.id = :vehicule_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':vehicule_id', $vehicule_id);
                    $stmt->execute();
                    $client_id = $stmt->fetchColumn();
                    
                    if ($client_id) {
                        $numero_commande = 'CMD-' . date('YmdHis') . '-' . rand(1000, 9999);
                        // Créer la commande
                        $query = "INSERT INTO commandes (ID_client ,Numero_Commande, vehicule_id, date_creation, Statut_Commande, user_id, intervention_id) 
                                  VALUES (:client_id, :numero_commande ,:vehicule_id, NOW(), 'En attente', :user_id, :intervention_id)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':client_id', $client_id);
                        $stmt->bindParam(':numero_commande', $numero_commande);
                        $stmt->bindParam(':vehicule_id', $vehicule_id);
                        $stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $stmt->bindParam(':intervention_id', $intervention_id);
                        
                        if ($stmt->execute()) {
                            $commande_id = $db->lastInsertId();
                            
                            // Mettre à jour l'intervention avec l'ID de la commande
                            $query = "UPDATE interventions SET commande_id = :commande_id WHERE id = :intervention_id";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':commande_id', $commande_id);
                            $stmt->bindParam(':intervention_id', $intervention_id);
                            $stmt->execute();

                           
                            // Ajouter les articles à la commande
                            if (!empty($selected_articles)) {
                                $montant_ht = round(($article['prix_unitaire']*$article['quantite']) * (1 - $article['remise'] / 100), 2);
                                $query = "INSERT INTO commande_details (ID_Commande , article_id, quantite, prix_unitaire, remise,montant_ht) 
                                          VALUES (:commande_id, :article_id, :quantite, :prix_unitaire, :remise,:montant_ht)";
                                $stmt = $db->prepare($query);
                                
                                foreach ($selected_articles as $article) {
                                    $stmt->bindParam(':commande_id', $commande_id);
                                    $stmt->bindParam(':article_id', $article['id']);
                                    $stmt->bindParam(':quantite', $article['quantite']);
                                    $stmt->bindParam(':prix_unitaire', $article['prix_unitaire']);
                                    $stmt->bindParam(':remise', $article['remise']);
                                    $stmt->bindParam(':montant_ht', $montant_ht);
                                    $stmt->execute();
                                }
                            }
                            
                            // Ajouter les offres à la commande
                            if (!empty($selected_offres)) {
                                $query = "INSERT INTO commande_offres (commande_id, offre_id, quantite, prix_unitaire, remise) 
                                          VALUES (:commande_id, :offre_id, :quantite, :prix_unitaire, :remise)";
                                $stmt = $db->prepare($query);
                                
                                foreach ($selected_offres as $offre) {
                                    $stmt->bindParam(':commande_id', $commande_id);
                                    $stmt->bindParam(':offre_id', $offre['id']);
                                    $stmt->bindParam(':quantite', $offre['quantite']);
                                    $stmt->bindParam(':prix_unitaire', $offre['prix_unitaire']);
                                    $stmt->bindParam(':remise', $offre['remise']);
                                    $stmt->execute();
                                }
                            }
                        }
                    }
                }
                // Mettre à jour la commande existante si demandé
                else if ($commande_id && $update_commande) {
                    // Supprimer les articles et offres existants de la commande
                    $query = "DELETE FROM commande_details WHERE ID_Commande = :commande_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':commande_id', $commande_id);
                    $stmt->execute();
                    
                    $query = "DELETE FROM commande_offres WHERE commande_id = :commande_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':commande_id', $commande_id);
                    $stmt->execute();
                    
                    // Ajouter les nouveaux articles à la commande
                    if (!empty($selected_articles)) {
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
                        }
                    }
                    
                    // Ajouter les nouvelles offres à la commande
                    if (!empty($selected_offres)) {
                        $query = "INSERT INTO commande_offres (commande_id, offre_id, quantite, prix_unitaire, remise) 
                                  VALUES (:commande_id, :offre_id, :quantite, :prix_unitaire, :remise)";
                        $stmt = $db->prepare($query);
                        
                        foreach ($selected_offres as $offre) {
                            $stmt->bindParam(':commande_id', $commande_id);
                            $stmt->bindParam(':offre_id', $offre['id']);
                            $stmt->bindParam(':quantite', $offre['quantite']);
                            $stmt->bindParam(':prix_unitaire', $offre['prix_unitaire']);
                            $stmt->bindParam(':remise', $offre['remise']);
                            $stmt->execute();
                        }
                    }
                    
                    // Mettre à jour la date de modification de la commande
                    $query = "UPDATE commandes SET date_modification = NOW() WHERE id = :commande_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':commande_id', $commande_id);
                    $stmt->execute();
                }
                
                // Valider la transaction
                $db->commit();
                
                // Message de succès
                $_SESSION['success'] = 'L\'intervention a été mise à jour avec succès.';
                header('Location: index.php');
                exit;
            } else {
                $db->rollBack();
                $_SESSION['error'] = 'Une erreur est survenue lors de la mise à jour de l\'intervention.';
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Erreur de base de données: ' . $e->getMessage();
            header('Location: index.php');
            exit;
        }
    } else {
        // Afficher les erreurs
        $_SESSION['error'] = implode('<br>', $errors);
        header('Location: index.php');
        exit;
    }
} else {
    // Redirection si accès direct
    header('Location: index.php');
    exit;
}
?>