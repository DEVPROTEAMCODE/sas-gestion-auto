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

// Récupérer la liste des véhicules et techniciens pour le formulaire
$database = new Database();
$db = $database->getConnection();

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
        $montant_total_ht = $_POST['montant_total_ht'];
        $notes = $_POST['notes'] ?? '';
        
        // Insert command into database
        $query = "INSERT INTO commandes (
                    Numero_Commande, 
                    Date_Commande, 
                    ID_Client, 
                    Date_Livraison_Prevue, 
                    Statut_Commande, 
                    Montant_Total_HT, 
                    Notes
                  ) VALUES (
                    :numero_commande, 
                    :date_commande, 
                    :id_client, 
                    :date_livraison_prevue, 
                    :statut, 
                    :montant_total_ht, 
                    :notes
                  )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':numero_commande', $numero_commande);
        $stmt->bindParam(':date_commande', $date_commande);
        $stmt->bindParam(':id_client', $id_client);
        $stmt->bindParam(':date_livraison_prevue', $date_livraison_prevue);
        $stmt->bindParam(':statut', $statut);
        $stmt->bindParam(':montant_total_ht', $montant_total_ht);
        $stmt->bindParam(':notes', $notes);
        $stmt->execute();
        $id_commande = $db->lastInsertId();
        
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
            }
        }
        
        // Générer automatiquement une facture si le statut est "Livrée"
        if ($statut === "Livrée") {
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
        
        // Commit transaction
        $db->commit();
        
        // Set success message
        $success_message = "La commande a été créée avec succès et les stocks ont été mis à jour!";
        if (isset($facture_success)) {
            $success_message .= "<br>" . $facture_success;
        }
        
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

// Generate new command number
$newCommandeNumber = 'CMD-' . date('Ymd') . '-' . rand(1000, 9999);

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
include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto">
        <header class="bg-white shadow-sm sticky top-0 z-10">
            <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                <h1 class="text-2xl font-bold text-gray-900">Créer une nouvelle commande</h1>
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
            <?php else: ?>
                <!-- Command Form -->
                <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                    <form id="commandeForm" method="POST" action="" class="space-y-6">
                        <!-- Command Details Section -->
                        <div class="px-4 py-5 sm:px-6 bg-gray-50">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">Informations de la commande</h3>
                            <p class="mt-1 max-w-2xl text-sm text-gray-500">Remplissez les détails pour cette commande.</p>
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
                                            value="<?php echo $newCommandeNumber; ?>" 
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
                                            value="<?php echo date('Y-m-d'); ?>" 
                                            class="pl-10 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
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
                                            value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" 
                                            class="pl-10 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
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
                                                <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['Nom_Client']); ?></option>
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
                                            <option value="En attente" selected>En attente</option>
                                            <option value="Confirmée">Confirmée</option>
                                            <option value="Livrée">Livrée</option>
                                            <option value="Annulée">Annulée</option>
                                        </select>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-500">Sélectionner "Livrée" générera automatiquement une facture.</p>
                                </div>
                            </div>
                        </div>

                       <!-- Articles Section -->
<div class="px-4 py-5 sm:px-6 bg-gray-50 border-t border-gray-200">
    <div class="flex justify-between items-center">
        <div>
            <h3 class="text-lg leading-6 font-medium text-gray-900">Articles commandés</h3>
            <p class="mt-1 max-w-2xl text-sm text-gray-500">Ajoutez les articles à cette commande.</p>
        </div>
        <button type="button" id="add-article-btn" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
            <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Ajouter un article
        </button>
    </div>
</div>

<div class="px-4 py-5 sm:p-6">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Article</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Référence</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantité</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prix unitaire</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200 articles-container">
                <!-- Article Template Row -->
                <tr class="article-row">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <select name="articles[0][id_article]" class="article-select w-full text-sm border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required onchange="updateArticleInfo(this, 0)">
                            <option value="">Sélectionner un article</option>
                            <?php foreach ($articles as $article): ?>
                                <option value="<?php echo $article['id']; ?>" 
                                        data-reference="<?php echo htmlspecialchars($article['reference']); ?>"
                                        data-designation="<?php echo htmlspecialchars($article['designation']); ?>"
                                        data-prix="<?php echo $article['prix_vente_ht']; ?>"
                                        data-stock="<?php echo $article['quantite_stock']; ?>">
                                    <?php echo htmlspecialchars($article['designation']); ?> 
                                    (Stock: <?php echo $article['quantite_stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <input type="text" name="articles[0][reference]" class="w-full text-sm border-gray-300 rounded-md shadow-sm bg-gray-50" readonly>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="relative">
                            <input type="number" name="articles[0][quantite]" min="1" value="1" class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required onchange="calculateRowTotal(0)">
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="relative rounded-md shadow-sm">
                            <input type="number" step="0.01" name="articles[0][prix_unitaire]" class="w-full text-sm pr-12 border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required onchange="calculateRowTotal(0)">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">DH</span>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="relative rounded-md shadow-sm">
                            <input type="text" name="articles[0][total_ht]" class="w-full text-sm pr-12 border-gray-300 rounded-md shadow-sm bg-gray-50" readonly>
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">DH</span>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button type="button" class="text-red-600 hover:text-red-900 focus:outline-none transition-colors duration-200" onclick="removeArticleRow(this)">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Totals Section -->
    <div class="mt-8 border-t border-gray-200 pt-8">
        <div class="flex flex-col sm:flex-row sm:justify-end">
            <div class="w-full sm:w-1/3 bg-gray-50 p-4 rounded-md shadow-sm">
                <div class="flex justify-between py-2 text-sm">
                    <span class="font-medium text-gray-500">Total HT:</span>
                    <span id="total_ht_display" class="font-medium">0,00 DH</span>
                    <input type="hidden" name="montant_total_ht" id="montant_total_ht" value="0">
                </div>
            </div>
        </div>
    </div>
</div>

                        <!-- Notes Section -->
                        <div class="px-4 py-5 sm:px-6 bg-gray-50 border-t border-gray-200">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">Notes et commentaires</h3>
                            <p class="mt-1 max-w-2xl text-sm text-gray-500">Ajoutez des informations supplémentaires concernant cette commande.</p>
                        </div>
                        
                        <div class="px-4 py-5 sm:p-6">
                            <div class="mt-1">
                                <textarea name="notes" rows="3" class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md" placeholder="Ajoutez des notes ou instructions spéciales pour cette commande..."></textarea>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="px-4 py-3 bg-gray-50 text-right sm:px-6 flex justify-end space-x-3 border-t border-gray-200">
                            <a href="index.php" class="inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                Annuler
                            </a>
                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Enregistrer la commande
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Print Template -->
<div id="print-template" class="hidden">
    <div class="print-content p-8">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold">Bon de Commande</h1>
            <p id="print-numero-commande" class="text-lg mt-2"></p>
        </div>
        
        <div class="flex justify-between mb-8">
            <div>
                <h2 class="font-bold text-lg">Client:</h2>
                <p id="print-client" class="mt-1"></p>
            </div>
            <div>
                <div class="mb-2">
                    <span class="font-semibold">Date de commande:</span>
                    <span id="print-date-commande" class="ml-2"></span>
                </div>
                <div>
                    <span class="font-semibold">Date de livraison prévue:</span>
                    <span id="print-date-livraison" class="ml-2"></span>
                </div>
            </div>
        </div>
        
        <table class="w-full mb-8 border-collapse">
            <thead>
                <tr class="bg-gray-200">
                    <th class="border border-gray-400 p-2 text-left">Référence</th>
                    <th class="border border-gray-400 p-2 text-left">Désignation</th>
                    <th class="border border-gray-400 p-2 text-right">Quantité</th>
                    <th class="border border-gray-400 p-2 text-right">Prix unitaire</th>
                    <th class="border border-gray-400 p-2 text-right">Total HT</th>
                </tr>
            </thead>
            <tbody id="print-articles">
                <!-- Articles will be added here dynamically -->
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="border border-gray-400 p-2 text-right font-bold">Total HT:</td>
                    <td id="print-total-ht" class="border border-gray-400 p-2 text-right"></td>
                </tr>
            </tfoot>
        </table>
        
        <div class="mt-8">
            <h3 class="font-bold mb-2">Notes:</h3>
            <p id="print-notes" class="border p-3 min-h-[60px] bg-gray-50"></p>
        </div>
        
        <div class="mt-12 flex justify-between">
            <div>
                <p class="font-bold mb-2">Signature du responsable:</p>
                <div class="h-16 w-48 border-b border-gray-400 mt-12"></div>
            </div>
            <div>
                <p class="font-bold mb-2">Signature du client:</p>
                <div class="h-16 w-48 border-b border-gray-400 mt-12"></div>
            </div>
        </div>
        
        <div class="text-center text-sm text-gray-500 mt-8">
            <p>Document généré le <?php echo date('d/m/Y à H:i'); ?></p>
        </div>
    </div>
</div>

<script>
    // Function to update article information when an article is selected
    function updateArticleInfo(selectElement, rowIndex) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        if (!selectedOption.value) return;
        
        const row = selectElement.closest('.article-row');
        
        // Get data from the selected option
        const reference = selectedOption.dataset.reference || '';
        const prix = selectedOption.dataset.prix || 0;
        const stock = selectedOption.dataset.stock || 0;
        
        // Update the reference field
        row.querySelector('input[name="articles[' + rowIndex + '][reference]"]').value = reference;
        
        // Update the price field
        const priceInput = row.querySelector('input[name="articles[' + rowIndex + '][prix_unitaire]"]');
        priceInput.value = prix;
        
        // Update quantity input with stock constraints
        const quantityInput = row.querySelector('input[name="articles[' + rowIndex + '][quantite]"]');
        quantityInput.setAttribute('max', stock);
        
        // Remove any existing stock info
        const existingStockInfo = row.querySelector('.stock-info');
        if (existingStockInfo) {
            existingStockInfo.remove();
        }
        
        // Add stock information next to quantity field
        const stockInfo = document.createElement('div');
        stockInfo.className = 'text-xs text-gray-500 mt-1 stock-info';
        stockInfo.textContent = `Stock disponible: ${stock}`;
        
        // Add warning if stock is low (less than 5)
        if (parseInt(stock) < 5) {
            stockInfo.className += ' text-orange-500 font-medium';
            stockInfo.textContent += ' (Stock bas)';
        }
        
        quantityInput.parentNode.appendChild(stockInfo);
        
        // Add animation to highlight the updated fields
        priceInput.classList.add('bg-yellow-50');
        setTimeout(() => {
            priceInput.classList.remove('bg-yellow-50');
        }, 1000);
        
        // Calculate the row total
        calculateRowTotal(rowIndex);
    }

    // Function to calculate the total for a specific row
    function calculateRowTotal(rowIndex) {
        const row = document.querySelector('select[name="articles[' + rowIndex + '][id_article]"]').closest('.article-row');
        const quantityInput = row.querySelector('input[name="articles[' + rowIndex + '][quantite]"]');
        const priceInput = row.querySelector('input[name="articles[' + rowIndex + '][prix_unitaire]"]');
        const totalInput = row.querySelector('input[name="articles[' + rowIndex + '][total_ht]"]');
        
        const quantity = parseFloat(quantityInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        const total = quantity * price;
        
        // Update the row total
        totalInput.value = total.toFixed(2) + ' DH';
        
        // Add animation to highlight the updated total
        totalInput.classList.add('bg-green-50');
        setTimeout(() => {
            totalInput.classList.remove('bg-green-50');
        }, 1000);
        
        // Recalculate the grand total
        calculateGrandTotal();
        
        // Check if quantity exceeds stock
        const selectedOption = row.querySelector('.article-select').options[row.querySelector('.article-select').selectedIndex];
        if (selectedOption.value) {
            const stock = parseInt(selectedOption.dataset.stock) || 0;
            
            // Remove previous error messages
            const existingError = row.querySelector('.quantity-error');
            if (existingError) {
                existingError.remove();
            }
            
            // Clear error styling
            quantityInput.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
            
            // Check if quantity exceeds stock
            if (quantity > stock) {
                quantityInput.classList.add('border-red-500', 'ring-1', 'ring-red-500');
                
                const errorMsg = document.createElement('div');
                errorMsg.className = 'text-xs text-red-500 mt-1 quantity-error';
                errorMsg.textContent = `Quantité dépasse le stock disponible (${stock})`;
                quantityInput.parentNode.appendChild(errorMsg);
            }
        }
    }

    // Function to calculate the grand total of all rows
    function calculateGrandTotal() {
        let grandTotal = 0;
        const totalInputs = document.querySelectorAll('input[name*="[total_ht]"]');
        
        totalInputs.forEach(input => {
            // Remove the DH suffix if present and convert to float
            const value = input.value.replace(' DH', '');
            grandTotal += parseFloat(value) || 0;
        });
        
        // Update the displayed totals with DH currency
        document.getElementById('total_ht_display').textContent = formatCurrency(grandTotal);
        
        // Update hidden inputs for form submission
        document.getElementById('montant_total_ht').value = grandTotal.toFixed(2);
    }

    // Function to add a new article row
    function addArticleRow() {
        const container = document.querySelector('.articles-container');
        const templateRow = container.querySelector('.article-row').cloneNode(true);
        const rowCount = container.querySelectorAll('.article-row').length;
        
        // Update names and indices
        const inputs = templateRow.querySelectorAll('input, select');
        inputs.forEach(input => {
            const name = input.getAttribute('name');
            if (name) {
                input.setAttribute('name', name.replace(/\[\d+\]/, '[' + rowCount + ']'));
            }
            
            if (input.classList.contains('article-select')) {
                input.setAttribute('onchange', 'updateArticleInfo(this, ' + rowCount + ')');
            }
            
            if (input.getAttribute('onchange') && input.getAttribute('onchange').includes('calculateRowTotal')) {
                input.setAttribute('onchange', 'calculateRowTotal(' + rowCount + ')');
            }
            
            // Reset values
            if (!input.readOnly && input.type !== 'hidden') {
                if (input.type === 'number' && input.name.includes('quantite')) {
                    input.value = 1;
                } else {
                    input.value = '';
                }
            } else if (input.readOnly) {
                input.value = '';
            }
        });
        
        // Reset select
        const select = templateRow.querySelector('select');
        select.selectedIndex = 0;
        
        // Remove any stock info or error messages
        const stockInfo = templateRow.querySelector('.stock-info');
        if (stockInfo) stockInfo.remove();
        
        const quantityError = templateRow.querySelector('.quantity-error');
        if (quantityError) quantityError.remove();
        
        // Add the new row with a fade-in animation
        templateRow.classList.add('opacity-0');
        container.appendChild(templateRow);
        
        // Trigger reflow to enable animation
        templateRow.offsetHeight;
        
        // Fade in the new row
        templateRow.classList.add('transition-opacity', 'duration-300');
        templateRow.classList.remove('opacity-0');
        
        // Scroll to the new row
        templateRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Function to remove an article row
    function removeArticleRow(button) {
        const rows = document.querySelectorAll('.article-row');
        if (rows.length > 1) {
            const row = button.closest('.article-row');
            
            // Add fade-out animation
            row.classList.add('transition-opacity', 'duration-300', 'opacity-0');
            
            // Remove the row after animation completes
            setTimeout(() => {
                row.remove();
                calculateGrandTotal();
                
                // Update indices for remaining rows
                document.querySelectorAll('.article-row').forEach((row, index) => {
                    const inputs = row.querySelectorAll('input, select');
                    inputs.forEach(input => {
                        const name = input.getAttribute('name');
                        if (name) {
                            input.setAttribute('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                        }
                        
                        if (input.classList.contains('article-select')) {
                            input.setAttribute('onchange', 'updateArticleInfo(this, ' + index + ')');
                        }
                        
                        if (input.getAttribute('onchange') && input.getAttribute('onchange').includes('calculateRowTotal')) {
                            input.setAttribute('onchange', 'calculateRowTotal(' + index + ')');
                        }
                    });
                });
            }, 300);
        } else {
            // Show a toast notification if trying to remove the last row
            showToast("Impossible de supprimer la dernière ligne", "warning");
        }
    }

    // Function to check stock availability for all articles
    function checkStockAvailability() {
        const articleRows = document.querySelectorAll('.article-row');
        let allStockAvailable = true;
        let firstErrorRow = null;
        
        // Clear previous error messages
        document.querySelectorAll('.stock-error').forEach(el => el.remove());
        
        // Check each article row
        articleRows.forEach(row => {
            const articleSelect = row.querySelector('.article-select');
            if (!articleSelect.value) return; // Skip empty rows
            
            const articleId = articleSelect.value;
            const quantityInput = row.querySelector('input[name*="[quantite]"]');
            const requestedQuantity = parseInt(quantityInput.value) || 0;
            
            // Get current stock from data attribute
            const currentStock = parseInt(articleSelect.options[articleSelect.selectedIndex].dataset.stock) || 0;
            
            if (requestedQuantity > currentStock) {
                allStockAvailable = false;
                
                // Mark the field with error
                quantityInput.classList.add('border-red-500', 'ring-1', 'ring-red-500');
                
                // Add error message if it doesn't exist
                if (!row.querySelector('.stock-error')) {
                    const errorMsg = document.createElement('p');
                    errorMsg.className = 'mt-1 text-sm text-red-600 stock-error';
                    errorMsg.textContent = `Stock insuffisant. Disponible: ${currentStock}`;
                    quantityInput.parentNode.appendChild(errorMsg);
                }
                
                if (!firstErrorRow) {
                    firstErrorRow = row;
                }
            } else {
                // Remove error styling
                quantityInput.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
                
                // Remove error message if it exists
                const errorMsg = row.querySelector('.stock-error');
                if (errorMsg) {
                    errorMsg.remove();
                }
            }
        });
        
        if (!allStockAvailable && firstErrorRow) {
            firstErrorRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            showToast("Certains articles n'ont pas de stock suffisant", "error");
        }
        
        return allStockAvailable;
    }

    // Show toast notification
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg text-white transform transition-all duration-300 translate-y-full';
        
        // Set background color based on type
        switch(type) {
            case 'success':
                toast.classList.add('bg-green-600');
                break;
            case 'error':
                toast.classList.add('bg-red-600');
                break;
            case 'warning':
                toast.classList.add('bg-yellow-500');
                break;
            default:
                toast.classList.add('bg-blue-600');
        }
        
        toast.textContent = message;
        document.body.appendChild(toast);
        
        // Trigger reflow to enable animation
        toast.offsetHeight;
        
        // Show toast
        toast.classList.remove('translate-y-full');
        
        // Hide toast after 3 seconds
        setTimeout(() => {
            toast.classList.add('translate-y-full');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
    }

    // Format currency with DH symbol
    function formatCurrency(amount) {
        return new Intl.NumberFormat('fr-MA', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount) + ' DH';
    }

    // Function to prepare and print the command
    function printCommand() {
        // Get form data
        const numeroCommande = document.getElementById('numero_commande').value;
        const dateCommande = document.getElementById('date_commande').value;
        const dateLivraison = document.getElementById('date_livraison_prevue').value;
        const clientSelect = document.getElementById('id_client');
        const client = clientSelect.options[clientSelect.selectedIndex]?.text || '';
        const notes = document.querySelector('textarea[name="notes"]').value;
        
        // Format dates for display
        const formattedDateCommande = new Date(dateCommande).toLocaleDateString('fr-FR');
        const formattedDateLivraison = new Date(dateLivraison).toLocaleDateString('fr-FR');
        
        // Update print template with command info
        document.getElementById('print-numero-commande').textContent = 'N° ' + numeroCommande;
        document.getElementById('print-client').textContent = client;
        document.getElementById('print-date-commande').textContent = formattedDateCommande;
        document.getElementById('print-date-livraison').textContent = formattedDateLivraison;
        document.getElementById('print-notes').textContent = notes || 'Aucune note';
        
        // Get totals
        const totalHT = document.getElementById('total_ht_display').textContent;
        
        // Update totals in print template
        document.getElementById('print-total-ht').textContent = totalHT;
        
        // Clear and populate articles table
        const articlesContainer = document.getElementById('print-articles');
        articlesContainer.innerHTML = '';
        
        document.querySelectorAll('.article-row').forEach(row => {
            const articleSelect = row.querySelector('.article-select');
            if (articleSelect.value) {
                const articleName = articleSelect.options[articleSelect.selectedIndex].text;
                const reference = row.querySelector('input[name*="[reference]"]').value;
                const quantity = row.querySelector('input[name*="[quantite]"]').value;
                const price = row.querySelector('input[name*="[prix_unitaire]"]').value;
                const total = row.querySelector('input[name*="[total_ht]"]').value;
                
                // Create table row
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="border border-gray-400 p-2">${reference}</td>
                    <td class="border border-gray-400 p-2">${articleName.split('(Stock')[0].trim()}</td>
                    <td class="border border-gray-400 p-2 text-right">${quantity}</td>
                    <td class="border border-gray-400 p-2 text-right">${price} DH</td>
                    <td class="border border-gray-400 p-2 text-right">${total}</td>
                `;
                articlesContainer.appendChild(tr);
            }
        });
        
        // Open print dialog
        const printContent = document.getElementById('print-template').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Bon de Commande - ${numeroCommande}</title>
                <meta charset="UTF-8">
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        margin: 0; 
                        padding: 20px;
                        color: #333;
                    }
                    table { 
                        width: 100%; 
                        border-collapse: collapse; 
                        margin-bottom: 20px; 
                    }
                    th, td { 
                        border: 1px solid #ddd; 
                        padding: 8px; 
                    }
                    th { 
                        background-color: #f2f2f2; 
                        text-align: left; 
                    }
                    .text-right { 
                        text-align: right; 
                    }
                    .text-center { 
                        text-align: center; 
                    }
                    .font-bold { 
                        font-weight: bold; 
                    }
                    .header {
                        text-align: center;
                        margin-bottom: 20px;
                        border-bottom: 2px solid #333;
                        padding-bottom: 10px;
                    }
                    .header h1 {
                        margin-bottom: 5px;
                    }
                    .footer {
                        margin-top: 30px;
                        text-align: center;
                        font-size: 12px;
                        color: #666;
                    }
                    @media print {
                        body { 
                            margin: 0; 
                            padding: 15px; 
                        }
                        button { 
                            display: none; 
                        }
                        @page {
                            size: A4;
                            margin: 1cm;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>Bon de Commande</h1>
                    <p>${numeroCommande}</p>
                </div>
                ${printContent}
                <div class="footer">
                    <p>Document généré le ${new Date().toLocaleDateString('fr-FR')} à ${new Date().toLocaleTimeString('fr-FR')}</p>
                </div>
                <div class="text-center" style="margin-top: 20px;">
                    <button onclick="window.print(); setTimeout(function() { window.close(); }, 500);" 
                            style="padding: 10px 20px; background-color: #4f46e5; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Imprimer
                    </button>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    // Validate form before submission
    function validateForm(showErrors = true) {
        let isValid = true;
        let hasArticles = false;
        
        // Check if client is selected
        const client = document.getElementById('id_client');
        if (!client.value) {
            isValid = false;
            if (showErrors) {
                client.classList.add('border-red-500', 'ring-1', 'ring-red-500');
                const errorMsg = document.createElement('p');
                errorMsg.className = 'mt-1 text-sm text-red-600';
                errorMsg.textContent = 'Veuillez sélectionner un client';
                
                // Only add error message if it doesn't exist already
                if (!client.parentNode.querySelector('.text-red-600')) {
                    client.parentNode.appendChild(errorMsg);
                }
            }
        } else {
            client.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
            const errorMsg = client.parentNode.querySelector('.text-red-600');
            if (errorMsg) {
                errorMsg.remove();
            }
        }
        
        // Check if at least one article is selected
        document.querySelectorAll('.article-select').forEach(select => {
            if (select.value) {
                hasArticles = true;
            }
        });
        
        if (!hasArticles) {
            isValid = false;
            if (showErrors) {
                const container = document.querySelector('.articles-container');
                
                // Only add error message if it doesn't exist already
                if (!container.nextElementSibling || !container.nextElementSibling.classList.contains('text-red-600')) {
                    const errorMsg = document.createElement('p');
                    errorMsg.className = 'mt-2 text-sm text-red-600';
                    errorMsg.textContent = 'Veuillez sélectionner au moins un article';
                    container.parentNode.insertBefore(errorMsg, container.nextElementSibling);
                }
            }
        } else {
            const errorMsg = document.querySelector('.articles-container + .text-red-600');
            if (errorMsg) {
                errorMsg.remove();
            }
        }
        
        // Validate each
        // Validate each article row
        document.querySelectorAll('.article-row').forEach(row => {
            const articleSelect = row.querySelector('.article-select');
            
            if (articleSelect.value) {
                // Check if quantity is valid
                const quantityInput = row.querySelector('input[name*="[quantite]"]');
                if (!quantityInput.value || parseInt(quantityInput.value) < 1) {
                    isValid = false;
                    if (showErrors) {
                        quantityInput.classList.add('border-red-500', 'ring-1', 'ring-red-500');
                        
                        // Only add error message if it doesn't exist already
                        if (!quantityInput.parentNode.querySelector('.text-red-600')) {
                            const errorMsg = document.createElement('p');
                            errorMsg.className = 'mt-1 text-sm text-red-600';
                            errorMsg.textContent = 'Quantité invalide';
                            quantityInput.parentNode.appendChild(errorMsg);
                        }
                    }
                } else {
                    quantityInput.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
                    const errorMsg = quantityInput.parentNode.querySelector('.text-red-600');
                    if (errorMsg) {
                        errorMsg.remove();
                    }
                }
                
                // Check if price is valid
                const priceInput = row.querySelector('input[name*="[prix_unitaire]"]');
                if (!priceInput.value || parseFloat(priceInput.value) <= 0) {
                    isValid = false;
                    if (showErrors) {
                        priceInput.classList.add('border-red-500', 'ring-1', 'ring-red-500');
                        
                        // Only add error message if it doesn't exist already
                        if (!priceInput.parentNode.querySelector('.text-red-600')) {
                            const errorMsg = document.createElement('p');
                            errorMsg.className = 'mt-1 text-sm text-red-600';
                            errorMsg.textContent = 'Prix invalide';
                            priceInput.parentNode.appendChild(errorMsg);
                        }
                    }
                } else {
                    priceInput.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
                    const errorMsg = priceInput.parentNode.querySelector('.text-red-600');
                    if (errorMsg) {
                        errorMsg.remove();
                    }
                }
            }
        });
        
        // Check stock availability
        if (isValid) {
            isValid = checkStockAvailability();
        }
        
        return isValid;
    }

    // Initialize event listeners when the DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Add click event for the add article button
        document.getElementById('add-article-btn').addEventListener('click', addArticleRow);
        
        // Add click event for the print button
        document.getElementById('print-btn').addEventListener('click', function(e) {
            e.preventDefault();
            
            // Check if form is valid before printing
            if (validateForm(false)) {
                printCommand();
            } else {
                showToast("Veuillez remplir correctement tous les champs obligatoires avant d'imprimer", "warning");
            }
        });
        
        // Add click event for the print success button if it exists
        const printSuccessBtn = document.getElementById('print-success-btn');
        if (printSuccessBtn) {
            printSuccessBtn.addEventListener('click', function(e) {
                e.preventDefault();
                printCommand();
            });
        }
        
        // Initialize tooltips for better UX
        initTooltips();
        
        // Add event listener for status change to show info about automatic invoice generation
        const statutSelect = document.getElementById('statut');
        if (statutSelect) {
            statutSelect.addEventListener('change', function() {
                const infoText = this.parentNode.nextElementSibling;
                if (this.value === 'Livrée') {
                    infoText.textContent = 'Une facture sera automatiquement générée pour cette commande.';
                    infoText.classList.add('text-indigo-600', 'font-medium');
                } else {
                    infoText.textContent = 'Sélectionner "Livrée" générera automatiquement une facture.';
                    infoText.classList.remove('text-indigo-600', 'font-medium');
                }
            });
        }
    });

    // Initialize tooltips
    function initTooltips() {
        const tooltips = document.querySelectorAll('[data-tooltip]');
        tooltips.forEach(tooltip => {
            tooltip.addEventListener('mouseenter', showTooltip);
            tooltip.addEventListener('mouseleave', hideTooltip);
        });
    }

    function showTooltip(e) {
        const tooltipText = this.getAttribute('data-tooltip');
        const tooltip = document.createElement('div');
        tooltip.className = 'absolute z-10 px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm tooltip';
        tooltip.textContent = tooltipText;
        tooltip.style.top = (this.offsetTop - 40) + 'px';
        tooltip.style.left = (this.offsetLeft + this.offsetWidth / 2) + 'px';
        tooltip.style.transform = 'translateX(-50%)';
        document.body.appendChild(tooltip);
        
        // Store reference to the tooltip
        this.tooltip = tooltip;
    }

    function hideTooltip() {
        if (this.tooltip) {
            this.tooltip.remove();
            this.tooltip = null;
        }
    }

    // Submit form handler
    document.getElementById('commandeForm').addEventListener('submit', function(event) {
        if (!validateForm(true)) {
            event.preventDefault();
            
            // Show error toast
            showToast("Veuillez corriger les erreurs dans le formulaire", "error");
            
            // Scroll to the first error
            const firstError = document.querySelector('.border-red-500');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            // Show loading indicator
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Enregistrement...
            `;
            
            // Inform user if they're creating a command with "Livrée" status
            const statutSelect = document.getElementById('statut');
            if (statutSelect && statutSelect.value === 'Livrée') {
                showToast("Une facture sera automatiquement générée", "info");
            }
        }
    });
</script>

<?php include $root_path . '/includes/footer.php'; ?>

