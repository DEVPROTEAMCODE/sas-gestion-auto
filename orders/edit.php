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

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// ID de l'utilisateur pour l'historique
$user_id = $_SESSION['user_id'];
$username = $currentUser['name']; // Nom d'utilisateur pour l'historique

// Récupérer la liste des véhicules et techniciens pour le formulaire
$database = new Database();
$db = $database->getConnection();

// Vérifier si un ID est passé dans l'URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id_commande = $_GET['id'];
$commande = null;
$commande_details = [];

// Récupérer les informations de la commande
try {
    $query = "SELECT c.*, cl.id as client_id,
                     CASE 
                        WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
                        ELSE CONCAT(cl.nom, ' - ', cl.raison_sociale)
                     END AS Nom_Client
              FROM commandes c
              LEFT JOIN clients cl ON c.ID_Client = cl.id
              WHERE c.ID_Commande= :id_commande";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id_commande', $id_commande);
    $stmt->execute();
    $commande = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$commande) {
        header('Location: index.php');
        exit;
    }

    // Récupérer les détails de la commande
    $query = "SELECT cd.*, a.designation, a.reference 
              FROM commande_details cd
              LEFT JOIN articles a ON cd.article_id = a.id
              WHERE cd.ID_Commande = :id_commande";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id_commande', $id_commande);
    $stmt->execute();
    $commande_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Erreur lors de la récupération des données: " . $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Get form data
        $numero_commande = $_POST['numero_commande'];
        $date_commande = $_POST['date_commande'];
        $date_livraison_prevue = $_POST['date_livraison_prevue'];
        $id_client = $_POST['id_client'];
        $statut = $_POST['statut'];
        $ancien_statut = $commande['Statut_Commande']; // Stocker l'ancien statut pour vérification
        $montant_total_ht = $_POST['montant_total_ht'];
        $notes = $_POST['notes'] ?? '';
        
        // Update command in database
        $query = "UPDATE commandes SET
                    Numero_Commande = :numero_commande, 
                    Date_Commande = :date_commande, 
                    ID_Client = :id_client, 
                    Date_Livraison_Prevue = :date_livraison_prevue, 
                    Statut_Commande = :statut, 
                    Montant_Total_HT = :montant_total_ht, 
                    Notes = :notes
                  WHERE ID_Commande = :id_commande";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':numero_commande', $numero_commande);
        $stmt->bindParam(':date_commande', $date_commande);
        $stmt->bindParam(':id_client', $id_client);
        $stmt->bindParam(':date_livraison_prevue', $date_livraison_prevue);
        $stmt->bindParam(':statut', $statut);
        $stmt->bindParam(':montant_total_ht', $montant_total_ht);
        $stmt->bindParam(':notes', $notes);
        $stmt->bindParam(':id_commande', $id_commande);
        $stmt->execute();
        
        // Get current order details to restore stock before updating
        $query = "SELECT cd.article_id, cd.quantite, cd.prix_unitaire, a.designation 
                  FROM commande_details cd
                  LEFT JOIN articles a ON cd.article_id = a.id
                  WHERE cd.ID_Commande = :id_commande";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id_commande', $id_commande);
        $stmt->execute();
        $old_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Restore stock quantities from previous order details and log in history
        foreach ($old_details as $item) {
            // Update stock by adding back the quantity
            $updateStock = $db->prepare("UPDATE articles SET quantite_stock = quantite_stock + :quantite WHERE id = :id_article");
            $updateStock->bindParam(':quantite', $item['quantite']);
            $updateStock->bindParam(':id_article', $item['article_id']);
            $updateStock->execute();
            
            // Log in history - Annulation (reverting previous order items)
            $commentaire = "Annulation article lors de la modification de la commande #" . $numero_commande;
            $insertHistory = $db->prepare("INSERT INTO historique_articles 
                                          (ID_Article, ID_Commande, Type_Operation, Date_Operation, 
                                           Quantite, Prix_Unitaire, Utilisateur, Commentaire) 
                                           VALUES 
                                          (:id_article, :id_commande, 'Annulation', NOW(), 
                                           :quantite, :prix_unitaire, :utilisateur, :commentaire)");
            $insertHistory->bindParam(':id_article', $item['article_id']);
            $insertHistory->bindParam(':id_commande', $id_commande);
            $insertHistory->bindParam(':quantite', $item['quantite']);
            $insertHistory->bindParam(':prix_unitaire', $item['prix_unitaire']);
            $insertHistory->bindParam(':utilisateur', $username);
            $insertHistory->bindParam(':commentaire', $commentaire);
            $insertHistory->execute();
        }
        
        // Supprimer les anciennes lignes de commande
        $query = "DELETE FROM commande_details WHERE ID_Commande = :id_commande";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id_commande', $id_commande);
        $stmt->execute();
        
        // Process articles and update stock
        if (isset($_POST['articles']) && is_array($_POST['articles'])) {
            foreach ($_POST['articles'] as $article) {
                // Skip empty rows
                if (empty($article['id_article'])) {
                    continue;
                }
                
                $id_article = $article['id_article'];
                $quantite = $article['quantite'];
                $prix_unitaire = $article['prix_unitaire'];
                
                // Calculate line totals
                $total_ht = $quantite * $prix_unitaire;
                
                // Get article information for history
                $getArticleInfo = $db->prepare("SELECT designation FROM articles WHERE id = :id_article");
                $getArticleInfo->bindParam(':id_article', $id_article);
                $getArticleInfo->execute();
                $articleInfo = $getArticleInfo->fetch(PDO::FETCH_ASSOC);
                $articleName = $articleInfo['designation'] ?? "Article #" . $id_article;
                
                // Check current stock level
                $checkStock = $db->prepare("SELECT quantite_stock FROM articles WHERE id = :id_article");
                $checkStock->bindParam(':id_article', $id_article);
                $checkStock->execute();
                $currentStock = $checkStock->fetchColumn();
                
                // Verify sufficient stock is available
                if ($currentStock < $quantite) {
                    throw new Exception("Stock insuffisant pour l'article ID: $id_article. Stock disponible: $currentStock, Quantité demandée: $quantite");
                }
                
                // Update stock quantity
                $updateStock = $db->prepare("UPDATE articles SET quantite_stock = quantite_stock - :quantite WHERE id = :id_article");
                $updateStock->bindParam(':quantite', $quantite);
                $updateStock->bindParam(':id_article', $id_article);
                $updateStock->execute();
                
                // Insert command line
                $query = "INSERT INTO commande_details (
                            ID_Commande, 
                            article_id, 
                            quantite, 
                            prix_unitaire,
                            montant_ht
                          ) VALUES (
                            :id_commande,
                            :id_article,
                            :quantite,
                            :prix_unitaire,
                            :total_ht
                          )";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id_commande', $id_commande);
                $stmt->bindParam(':id_article', $id_article);
                $stmt->bindParam(':quantite', $quantite);
                $stmt->bindParam(':prix_unitaire', $prix_unitaire);
                $stmt->bindParam(':total_ht', $total_ht);
                
                $stmt->execute();
                
                // Log in history - Commande (new order items)
                $commentaire = "Article " . $articleName . " ajouté lors de la modification de la commande #" . $numero_commande;
                $insertHistory = $db->prepare("INSERT INTO historique_articles 
                                              (ID_Article, ID_Commande, Type_Operation, Date_Operation, 
                                               Quantite, Prix_Unitaire, Utilisateur, Commentaire) 
                                               VALUES 
                                              (:id_article, :id_commande, 'Commande', NOW(), 
                                               :quantite, :prix_unitaire, :utilisateur, :commentaire)");
                $insertHistory->bindParam(':id_article', $id_article);
                $insertHistory->bindParam(':id_commande', $id_commande);
                $insertHistory->bindParam(':quantite', $quantite);
                $insertHistory->bindParam(':prix_unitaire', $prix_unitaire);
                $insertHistory->bindParam(':utilisateur', $username);
                $insertHistory->bindParam(':commentaire', $commentaire);
                $insertHistory->execute();
            }
        }
        
        // Générer automatiquement une facture si le statut est changé à "Livrée"
        if ($statut === "Livrée" && $ancien_statut !== "Livrée") {
            // Vérifier si une facture existe déjà pour cette commande
            $query = "SELECT COUNT(*) FROM factures WHERE ID_Commande = :id_commande";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id_commande', $id_commande);
            $stmt->execute();
            $facture_existe = $stmt->fetchColumn() > 0;
            
            if (!$facture_existe) {
                // Générer un numéro de facture unique
                $numero_facture = 'FAC-' . date('Ymd') . '-' . rand(1000, 9999);
                $date_facture = date('Y-m-d'); // Date du jour
                $statut_facture = 'Émise';
                
                // Insérer la facture dans la table factures
                $query = "INSERT INTO factures (
                            Numero_Facture, 
                            Date_Facture, 
                            ID_Client, 
                            ID_Commande, 
                            Montant_Total_HT, 
                            Statut_Facture, 
                            Notes, 
                            Created_At
                          ) VALUES (
                            :numero_facture, 
                            :date_facture, 
                            :id_client, 
                            :id_commande, 
                            :montant_total_ht, 
                            :statut_facture, 
                            :notes, 
                            NOW()
                          )";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':numero_facture', $numero_facture);
                $stmt->bindParam(':date_facture', $date_facture);
                $stmt->bindParam(':id_client', $id_client);
                $stmt->bindParam(':id_commande', $id_commande);
                $stmt->bindParam(':montant_total_ht', $montant_total_ht);
                $stmt->bindParam(':statut_facture', $statut_facture);
                $stmt->bindParam(':notes', $notes);
                $stmt->execute();
                
                $id_facture = $db->lastInsertId();
                
                // Copier les détails de la commande dans la table facture_details
                if (isset($_POST['articles']) && is_array($_POST['articles'])) {
                    foreach ($_POST['articles'] as $article) {
                        // Skip empty rows
                        if (empty($article['id_article'])) {
                            continue;
                        }
                        
                        $id_article = $article['id_article'];
                        $quantite = $article['quantite'];
                        $prix_unitaire = $article['prix_unitaire'];
                        $total_ht = $quantite * $prix_unitaire;
                        
                        // Insérer les détails de la facture
                        $query = "INSERT INTO facture_details (
                                    ID_Facture, 
                                    article_id, 
                                    quantite, 
                                    prix_unitaire, 
                                    montant_ht
                                  ) VALUES (
                                    :id_facture,
                                    :id_article,
                                    :quantite,
                                    :prix_unitaire,
                                    :total_ht
                                  )";
                        
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':id_facture', $id_facture);
                        $stmt->bindParam(':id_article', $id_article);
                        $stmt->bindParam(':quantite', $quantite);
                        $stmt->bindParam(':prix_unitaire', $prix_unitaire);
                        $stmt->bindParam(':total_ht', $total_ht);
                        
                        $stmt->execute();
                    }
                }
                
                // Ajouter un message de succès supplémentaire pour la facture
                $facture_success = "Une facture a été automatiquement générée avec le numéro $numero_facture";
            }
        }
        
        // Commit transaction
        $db->commit();
        
        // Set success message
        $success_message = "La commande a été mise à jour avec succès et les stocks ont été ajustés!";
        if (isset($facture_success)) {
            $success_message .= "<br>" . $facture_success;
        }
        
        // Refresh command data
        $query = "SELECT c.*, cl.id as client_id,
                     CASE 
                        WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
                        ELSE CONCAT(cl.nom, ' - ', cl.raison_sociale)
                     END AS Nom_Client
                  FROM commandes c
                  LEFT JOIN clients cl ON c.ID_Client = cl.id
                  WHERE c.ID_Commande = :id_commande";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id_commande', $id_commande);
        $stmt->execute();
        $commande = $stmt->fetch(PDO::FETCH_ASSOC);

        // Refresh command details
        $query = "SELECT cd.*, a.designation, a.reference 
                  FROM commande_details cd
                  LEFT JOIN articles a ON cd.article_id = a.id
                  WHERE cd.ID_Commande = :id_commande";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id_commande', $id_commande);
        $stmt->execute();
        $commande_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $db->rollBack();
        $error_message = "Erreur: " . $e->getMessage();
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        $error_message = "Erreur: " . $e->getMessage();
    }
}

// Fetch clients list
$clients = [];
try {
    $query = "SELECT id, 
                     CASE 
                        WHEN type_client_id = 1 THEN CONCAT(prenom, ' ', nom)
                        ELSE CONCAT(nom, ' - ', raison_sociale)
                     END AS Nom_Client
              FROM clients 
              ORDER BY Nom_Client";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// Fetch articles list with stock information
$articles = [];
try {
    $query = "SELECT * FROM articles ORDER BY reference";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// Vérifier si une facture existe déjà pour cette commande
$facture_existante = false;
try {
    $query = "SELECT COUNT(*) FROM factures WHERE ID_Commande = :id_commande";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id_commande', $id_commande);
    $stmt->execute();
    $facture_existante = $stmt->fetchColumn() > 0;
} catch (PDOException $e) {
    // Ignorer l'erreur
}

include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto">
        <header class="bg-white shadow-sm sticky top-0 z-10">
            <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                <h1 class="text-2xl font-bold text-gray-900">Modifier la commande</h1>
                <div class="flex space-x-3">
                    <button id="print-btn" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Imprimer
                    </button>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                        <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />
                        </svg>
                        Retour
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <?php if (isset($error_message)): ?>
                <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-md shadow-sm" role="alert">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo $error_message; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($success_message)): ?>
                <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-md shadow-sm" role="alert">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo $success_message; ?></p>
                            <div class="mt-2 flex space-x-3">
                                <a href="index.php" class="text-sm font-medium text-green-700 underline hover:text-green-600">Retour à la liste des commandes</a>
                                <button id="print-success-btn" class="text-sm font-medium text-green-700 underline hover:text-green-600">Imprimer la commande</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Command Form -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <form id="edit-commande-form" method="POST" action="" class="space-y-6">
                    <!-- Command Details Section -->
                    <div class="px-4 py-5 sm:px-6 bg-gray-50">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Informations de la commande</h3>
                        <p class="mt-1 max-w-2xl text-sm text-gray-500">Modifiez les détails de cette commande.</p>
                    </div>
                    
                    <div class="px-4 py-5 sm:p-6">
                        <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <!-- Command Number -->
                            <div class="sm:col-span-2">
                                <label for="numero_commande" class="block text-sm font-medium text-gray-700">Numéro de commande</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 sm:text-sm">#</span>
                                    </div>
                                    <input type="text" name="numero_commande" id="numero_commande" 
                                        value="<?php echo htmlspecialchars($commande['Numero_Commande']); ?>" 
                                        class="pl-8 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md bg-gray-50" >
                                </div>
                            </div>

                            <!-- Command Date -->
                            <div class="sm:col-span-2">
                                <label for="date_commande" class="block text-sm font-medium text-gray-700">Date de commande</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <input type="date" name="date_commande" id="date_commande" 
                                        value="<?php echo htmlspecialchars($commande['Date_Commande']); ?>" 
                                        class="date-picker pl-10 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                </div>
                            </div>

                            <!-- Expected Delivery Date -->
                            <div class="sm:col-span-2">
                                <label for="date_livraison_prevue" class="block text-sm font-medium text-gray-700">Date de livraison prévue</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <input type="date" name="date_livraison_prevue" id="date_livraison_prevue" 
                                        value="<?php echo htmlspecialchars($commande['Date_Livraison_Prevue']); ?>" 
                                        class="date-picker pl-10 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                </div>
                            </div>

                            <!-- Client Selection -->
                            <div class="sm:col-span-3">
                                <label for="id_client" class="block text-sm font-medium text-gray-700">Client</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                    <select id="id_client" name="id_client" required
                                        class="pl-10 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                        <option value="">Sélectionner un client</option>
                                        <?php foreach ($clients as $client): ?>
                                            <option value="<?php echo $client['id']; ?>" <?php echo ($client['id'] == $commande['client_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($client['Nom_Client']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Status Selection -->
                            <div class="sm:col-span-3">
                                <label for="statut" class="block text-sm font-medium text-gray-700">Statut</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <select id="statut" name="statut" required
                                        class="pl-10 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                                        <option value="En attente" <?php echo ($commande['Statut_Commande'] == 'En attente') ? 'selected' : ''; ?>>En attente</option>
                                        <option value="En cours" <?php echo ($commande['Statut_Commande'] == 'En cours') ? 'selected' : ''; ?>>En cours</option>
                                        <option value="Préparée" <?php echo ($commande['Statut_Commande'] == 'Préparée') ? 'selected' : ''; ?>>Préparée</option>
                                        <option value="Livrée" <?php echo ($commande['Statut_Commande'] == 'Livrée') ? 'selected' : ''; ?>>Livrée</option>
                                        <option value="Annulée" <?php echo ($commande['Statut_Commande'] == 'Annulée') ? 'selected' : ''; ?>>Annulée</option>
                                    </select>
                                </div>
                                <?php if ($commande['Statut_Commande'] !== 'Livrée' && !$facture_existante): ?>
                                <p class="mt-1 text-sm text-gray-500">Si vous changez le statut à "Livrée", une facture sera automatiquement générée.</p>
                                <?php elseif ($facture_existante): ?>
                                <p class="mt-1 text-sm text-amber-600">Une facture a déjà été générée pour cette commande.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Notes -->
                            <div class="sm:col-span-6">
                                <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                                <div class="mt-1">
                                    <textarea id="notes" name="notes" rows="3" 
                                        class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"><?php echo htmlspecialchars($commande['Notes'] ?? ''); ?></textarea>
                                </div>
                                <p class="mt-2 text-sm text-gray-500">Notes internes sur cette commande.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Command Items Section -->
                    <div class="px-4 py-5 sm:px-6 bg-gray-50">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Articles commandés</h3>
                        <p class="mt-1 max-w-2xl text-sm text-gray-500">Ajoutez, modifiez ou supprimez des articles de cette commande.</p>
                    </div>

                    <div class="px-4 py-5 sm:p-6">
                        <div class="overflow-x-auto">
                            <table id="articles-table" class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Article</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Référence</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock Dispo</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantité</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prix unitaire</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total HT</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="articles-container">
                                    <?php if (empty($commande_details)): ?>
                                    <tr class="article-row">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select name="articles[0][id_article]" class="article-select shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" required>
                                                <option value="">Sélectionner un article</option>
                                                <?php foreach ($articles as $article): ?>
                                                <option value="<?php echo $article['id']; ?>" data-reference="<?php echo htmlspecialchars($article['reference']); ?>" data-stock="<?php echo htmlspecialchars($article['quantite_stock']); ?>" data-price="<?php echo htmlspecialchars($article['prix_vente_ht']); ?>">
                                                    <?php echo htmlspecialchars($article['designation']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap article-reference">-</td>
                                        <td class="px-6 py-4 whitespace-nowrap article-stock">-</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="articles[0][quantite]" min="1" value="1" class="article-quantity shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" required>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="articles[0][prix_unitaire]" step="0.01" min="0" value="0.00" class="article-price shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" required>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap article-total">0.00 DH</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button type="button" class="delete-article-btn text-red-600 hover:text-red-900">
                                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($commande_details as $index => $detail): ?>
                                    <tr class="article-row">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select name="articles[<?php echo $index; ?>][id_article]" class="article-select shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" required>
                                                <option value="">Sélectionner un article</option>
                                                <?php foreach ($articles as $article): ?>
                                                <option value="<?php echo $article['id']; ?>" 
                                                    data-reference="<?php echo htmlspecialchars($article['reference']); ?>" 
                                                    data-stock="<?php echo htmlspecialchars($article['quantite_stock']); ?>" 
                                                    data-price="<?php echo htmlspecialchars($article['prix_vente_ht']); ?>"
                                                    <?php echo ($article['id'] == $detail['article_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($article['designation']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap article-reference"><?php echo htmlspecialchars($detail['reference']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap article-stock">
                                            <?php 
                                            // Find the current stock for this article
                                            $currentStock = 0;
                                            foreach ($articles as $article) {
                                                if ($article['id'] == $detail['article_id']) {
                                                    $currentStock = $article['quantite_stock'];
                                                    break;
                                                }
                                            }
                                            echo $currentStock;
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="articles[<?php echo $index; ?>][quantite]" min="1" value="<?php echo htmlspecialchars($detail['quantite']); ?>" class="article-quantity shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" required>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="articles[<?php echo $index; ?>][prix_unitaire]" step="0.01" min="0" value="<?php echo htmlspecialchars($detail['prix_unitaire']); ?>" class="article-price shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" required>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap article-total"><?php echo number_format($detail['montant_ht'], 2, '.', ' '); ?> DH</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button type="button" class="delete-article-btn text-red-600 hover:text-red-900">
                                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="7" class="px-6 py-4">
                                            <button type="button" id="add-article-btn" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                                <svg class="-ml-0.5 mr-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                                </svg>
                                                Ajouter un article
                                            </button>
                                        </td>
                                    </tr>
                                    <tr class="bg-gray-50">
                                        <td colspan="5" class="px-6 py-4 text-right font-medium">Total HT:</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="montant_total_ht" id="montant_total_ht" value="<?php echo htmlspecialchars($commande['Montant_Total_HT']); ?>" step="0.01" min="0" readonly class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md bg-gray-50">
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="px-4 py-3 bg-gray-50 text-right sm:px-6 space-x-3">
                        <a href="index.php" class="inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Annuler
                        </a>
                        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<!-- Print Template -->
<div id="print-template" class="hidden">
    <div class="print-header">
        <h1>Commande #<?php echo htmlspecialchars($commande['Numero_Commande']); ?></h1>
        <div class="print-date">Date: <?php echo htmlspecialchars(date('d/m/Y', strtotime($commande['Date_Commande']))); ?></div>
    </div>
    
    <div class="print-client">
        <h2>Client</h2>
        <p><?php echo htmlspecialchars($commande['Nom_Client']); ?></p>
    </div>
    
    <div class="print-details">
        <h2>Détails de la commande</h2>
        <table>
            <thead>
                <tr>
                    <th>Article</th>
                    <th>Référence</th>
                    <th>Quantité</th>
                    <th>Prix unitaire</th>
                    <th>Total HT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commande_details as $detail): ?>
                <tr>
                    <td><?php echo htmlspecialchars($detail['designation']); ?></td>
                    <td><?php echo htmlspecialchars($detail['reference']); ?></td>
                    <td><?php echo htmlspecialchars($detail['quantite']); ?></td>
                    <td><?php echo htmlspecialchars(number_format($detail['prix_unitaire'], 2, ',', ' ')); ?> DH</td>
                    <td><?php echo htmlspecialchars(number_format($detail['montant_ht'], 2, ',', ' ')); ?> DH</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-right">Total HT:</td>
                    <td><?php echo htmlspecialchars(number_format($commande['Montant_Total_HT'], 2, ',', ' ')); ?> DH</td>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <div class="print-notes">
        <h2>Notes</h2>
        <p><?php echo htmlspecialchars($commande['Notes'] ?? 'Aucune note'); ?></p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Variables for article management
    let articleRowTemplate;
    let articlesContainer = document.getElementById('articles-container');
    let addArticleBtn = document.getElementById('add-article-btn');
    let totalInput = document.getElementById('montant_total_ht');
    
    // Get the template from the first row
    if (articlesContainer.querySelector('.article-row')) {
        articleRowTemplate = articlesContainer.querySelector('.article-row').cloneNode(true);
    }
    
    // Function to update article row indexes
    function updateArticleIndexes() {
        document.querySelectorAll('#articles-container .article-row').forEach((row, index) => {
            row.querySelectorAll('select, input').forEach(input => {
                let name = input.getAttribute('name');
                if (name) {
                    input.setAttribute('name', name.replace(/articles\[\d+\]/, `articles[${index}]`));
                }
            });
        });
    }
    
    // Function to calculate totals
    function calculateTotals() {
        let total = 0;
        document.querySelectorAll('#articles-container .article-row').forEach(row => {
            const quantity = parseFloat(row.querySelector('.article-quantity').value) || 0;
            const price = parseFloat(row.querySelector('.article-price').value) || 0;
            const lineTotal = quantity * price;
            row.querySelector('.article-total').textContent = lineTotal.toFixed(2) + ' DH';
            total += lineTotal;
        });
        totalInput.value = total.toFixed(2);
    }
    
    // Function to handle article selection change
    function handleArticleSelection(select) {
        const row = select.closest('.article-row');
        const selectedOption = select.options[select.selectedIndex];
        const reference = selectedOption.dataset.reference || '-';
        const stock = selectedOption.dataset.stock || '0';
        const price = selectedOption.dataset.price || '0.00';
        
        row.querySelector('.article-reference').textContent = reference;
        row.querySelector('.article-stock').textContent = stock;
        row.querySelector('.article-price').value = price;
        
        calculateTotals();
    }
    
    // Add event listener for the "Add Article" button
    if (addArticleBtn && articleRowTemplate) {
        addArticleBtn.addEventListener('click', function() {
            const newRow = articleRowTemplate.cloneNode(true);
            
            // Reset values in the new row
            newRow.querySelector('.article-select').selectedIndex = 0;
            newRow.querySelector('.article-reference').textContent = '-';
            newRow.querySelector('.article-stock').textContent = '-';
            newRow.querySelector('.article-quantity').value = 1;
            newRow.querySelector('.article-price').value = '0.00';
            newRow.querySelector('.article-total').textContent = '0.00 DH';
            
            // Add event listeners to the new row
            addRowEventListeners(newRow);
            
            // Append the new row
            articlesContainer.appendChild(newRow);
            
            // Update indexes
            updateArticleIndexes();
        });
    }
    
    // Function to add event listeners to a row
    function addRowEventListeners(row) {
        // Article selection change
        const select = row.querySelector('.article-select');
        if (select) {
            select.addEventListener('change', function() {
                handleArticleSelection(this);
            });
        }
        
        // Quantity change
        const quantityInput = row.querySelector('.article-quantity');
        if (quantityInput) {
            quantityInput.addEventListener('input', calculateTotals);
        }
        
        // Price change
        const priceInput = row.querySelector('.article-price');
        if (priceInput) {
            priceInput.addEventListener('input', calculateTotals);
        }
        
        // Delete button
        const deleteBtn = row.querySelector('.delete-article-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function() {
                if (document.querySelectorAll('#articles-container .article-row').length > 1) {
                    row.remove();
                    updateArticleIndexes();
                    calculateTotals();
                } else {
                    alert('Vous devez conserver au moins un article dans la commande.');
                }
            });
        }
    }
    
    // Add event listeners to existing rows
    document.querySelectorAll('#articles-container .article-row').forEach(row => {
        addRowEventListeners(row);
    });
    
    // Calculate totals on page load
    calculateTotals();
    
    // Print functionality
    document.getElementById('print-btn').addEventListener('click', function() {
        const printContent = document.getElementById('print-template').innerHTML;
        const originalContent = document.body.innerHTML;
        
        document.body.innerHTML = printContent;
        window.print();
        document.body.innerHTML = originalContent;
        
        // Reattach event listeners after restoring content
        document.addEventListener('DOMContentLoaded', function() {
            // Your initialization code here
        });
    });
    
    if (document.getElementById('print-success-btn')) {
        document.getElementById('print-success-btn').addEventListener('click', function() {
            const printContent = document.getElementById('print-template').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = printContent;
            window.print();
            document.body.innerHTML = originalContent;
            
            // Reattach event listeners after restoring content
            location.reload();
        });
    }
});
</script>

<style>
@media print {
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 20px;
    }
    
    .print-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 20px;
        border-bottom: 1px solid #ccc;
        padding-bottom: 10px;
    }
    
    .print-client, .print-details, .print-notes {
        margin-bottom: 20px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    
    th, td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    
    th {
        background-color: #f2f2f2;
    }
    
    tfoot td {
        font-weight: bold;
    }
}
</style>

<?php include $root_path . '/includes/footer.php'; ?>
